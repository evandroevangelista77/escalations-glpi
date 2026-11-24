<?php

namespace GlpiPlugin\Escalations;

use RuntimeException;
use Throwable;

/**
 * Motor do escalonamento. A cada minuto (ação automática escalationsrun) ele:
 *  1. sobe de nível (ou repete o último) os escalonamentos sem ACK cujo tempo acabou;
 *  2. confere o SLO de ACK e de resolução: aviso ao atingir o limite de alerta e outro ao estourar;
 *  3. observa os chamados (SLA) e fecha o que o chamado ou o evento já resolveu (TicketWatcher);
 *  4. processa entregas novas, interrompidas e reenvios.
 * Tudo vira log rastreável.
 */
class Engine
{
    /** Roda um ciclo. Devolve quantas ações fez. */
    public static function tick(): int
    {
        return Lock::run('tick', self::runCycle(...), 0) ?? 0;
    }

    private static function runCycle(): int
    {
        global $DB;
        $t0 = microtime(true);
        $n = 0;
        Settings::set(['last_tick' => date('Y-m-d H:i:s')]);
        $now = date('Y-m-d H:i:s');

        // 1. subir de nível
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['state' => 'open', ['NOT' => ['next_action_at' => null]], 'next_action_at' => ['<=', $now]], 'LIMIT' => 200]) as $e) {
            if ($e['silenced_until'] && $e['silenced_until'] > $now) {
                continue;
            }
            try {
                $n += Escalation::withLock((int) $e['id'], static fn (?array $current): int =>
                    Transaction::run(static fn (): int => self::advance($current, $e)), 0) ?? 0;
            } catch (Throwable $t) {
                Log::write($e, 'system', ['status' => 'fail', 'message' => 'Erro ao subir o nível: ' . $t->getMessage()]);
                Log::file('advance ' . $e['trace_id'] . ': ' . $t->getMessage());
            }
        }
        // 2. SLO
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['state' => ['open', 'acked']], 'LIMIT' => 500]) as $e) {
            try {
                $n += Escalation::withLock((int) $e['id'], static fn (?array $current): int =>
                    Transaction::run(static fn (): int => self::slo($current)), 0) ?? 0;
            } catch (Throwable $t) {
                Log::file('slo ' . $e['trace_id'] . ': ' . $t->getMessage());
            }
        }
        // 3. chamados e eventos
        try {
            $n += TicketWatcher::scan();
        } catch (Throwable $t) {
            Log::file('scan: ' . $t->getMessage());
        }
        // 4. Entregas confirmadas no banco, após verificar o fechamento das origens.
        try {
            $n += Dispatcher::retries();
        } catch (Throwable $t) {
            Log::file('retries: ' . $t->getMessage());
        }
        Settings::set(['last_tick_ms' => (string) (int) round((microtime(true) - $t0) * 1000)]);
        return $n;
    }

    /** Grava o N1 e suas entregas dentro da transação de abertura. */
    public static function stageFirstLevel(array $e): void
    {
        $level = Policy::level((int) $e['policies_id'], 1);
        if (!$level || !$level['channel_list'] || !$level['targets']) {
            throw new RuntimeException('Configure o nível 1 da hierarquia com ao menos um canal e um alvo.', 422);
        }
        if (!self::beginLevel($e, $level)) {
            throw new RuntimeException('Não foi possível iniciar o nível 1.');
        }
    }

    /** Grava o próximo nível antes dos efeitos, sob a trava da ocorrência. */
    private static function beginLevel(array $e, array $level): bool
    {
        $pos = (int) $level['position'];
        $now = date('Y-m-d H:i:s');
        if (!self::updateProgress($e, ['level' => $pos, 'level_since' => $now, 'repeats' => 0,
            'next_action_at' => date('Y-m-d H:i:s', time() + (int) $level['wait_min'] * 60), 'date_mod' => $now])) {
            return false;
        }
        $from = (int) $e['level'];
        if ($from > 0) {
            $waited = time() - strtotime((string) ($e['level_since'] ?: $e['opened_at']));
            Log::write($e, 'escalate', ['level' => $pos, 'status' => 'warn', 'actor' => 'system',
                'message' => 'Sem ACK após ' . Ui::dur($waited) . ' no nível N' . $from . ': subindo para N' . $pos . '.',
                'detail' => ['from' => $from, 'to' => $pos, 'waited_s' => $waited]]);
        }
        $e = Escalation::get((int) $e['id']);
        $names = Policy::targetNames($level);
        Log::write($e, 'level_start', ['level' => $pos, 'status' => 'ok', 'actor' => 'system',
            'message' => 'Nível N' . $pos . ' · ' . $level['name'] . ' iniciado (' . implode(', ', $names) . '). Sem ACK em ' . (int) $level['wait_min'] . ' min, sobe.',
            'detail' => ['channels' => $level['channel_list'], 'targets' => $names, 'wait_min' => (int) $level['wait_min']]]);
        self::notify($e, $level, 0);
        return true;
    }

    public static function resend(int $id, int $uid): void
    {
        Escalation::withLock($id, static function (?array $e) use ($uid): void {
            if (!$e || $e['state'] !== 'open' || !(int) $e['level']) {
                throw new RuntimeException('Só dá para reenviar enquanto aguarda ACK.', 409);
            }
            if (NativeItem::isClosed($e)) {
                throw new RuntimeException('O item de origem já foi encerrado.', 409);
            }
            $level = Policy::level((int) $e['policies_id'], (int) $e['level']);
            if ($level) {
                Transaction::run(static function () use ($e, $level, $uid): void {
                    global $DB;
                    $round = 100;
                    foreach ($DB->request(['SELECT' => 'round', 'FROM' => Escalation::DELIVERIES, 'WHERE' => [
                        'escalations_id' => $e['id'], 'level' => $e['level'], 'kind' => 'level', 'round' => ['>=', 100],
                    ], 'ORDER' => 'round DESC', 'LIMIT' => 1]) as $last) {
                        $round = (int) $last['round'] + 1;
                    }
                    Log::write($e, 'repeat', ['level' => (int) $e['level'], 'status' => 'info', 'users_id' => $uid, 'message' => 'Reenvio manual dos avisos do nível N' . $e['level'] . ' por ' . Ui::userName($uid) . '.']);
                    self::notify($e, $level, $round);
                });
            }
        });
    }

    /** Avisa os alvos do nível por todos os canais dele. */
    private static function notify(array $e, array $level, int $round): void
    {
        global $DB;
        $roles = self::recipientRoles($e, $level);
        $users = array_keys($roles);
        $channels = $level['channel_list'];
        if (!$users) {
            Log::write($e, 'no_recipient', ['level' => (int) $level['position'], 'status' => 'warn',
                'message' => 'Nenhum usuário ativo encontrado nos alvos do nível N' . $level['position'] . '; seguindo para o próximo nível agora.']);
            $DB->update(Escalation::TABLE, ['next_action_at' => date('Y-m-d H:i:s')], ['id' => $e['id']]);
        }
        if ($users) {
            Bridge::notify($e, $users, '[' . mb_strtoupper(Escalation::severity((int) $e['severity'])[0]) . '] ' . $e['title'], 'Nível N' . $level['position'] . ' · ' . $level['name'] . ': assuma para interromper a escalada.', Bridge::levelLevel((int) $e['severity']));
        }
        foreach ($channels as $ch) {
            if ($ch === 'teams') {
                Dispatcher::enqueue($e, (int) $level['position'], 'teams', 0, $round);
                continue;
            }
            foreach ($roles as $uid => $role) {
                Dispatcher::enqueue($e, (int) $level['position'], $ch, $uid, $round, 'level', $role);
            }
        }
    }

    /** Sem ACK: sobe para o próximo nível, repete o último ou dá a cadeia por esgotada. */
    private static function advance(?array $e, array $candidate): int
    {
        $now = date('Y-m-d H:i:s');
        if (!$e || $e['state'] !== 'open' || !$e['next_action_at'] || $e['next_action_at'] > $now
            || ($e['silenced_until'] && $e['silenced_until'] > $now)
            || $e['next_action_at'] !== $candidate['next_action_at']
            || (int) $e['level'] !== (int) $candidate['level']
            || (int) $e['repeats'] !== (int) $candidate['repeats']
            || NativeItem::isClosed($e)) {
            return 0;
        }
        $pos = (int) $e['level'];
        $policy = Policy::get((int) $e['policies_id']);
        if (!$policy) {
            return 0;
        }
        $next = Policy::level((int) $policy['id'], $pos + 1);
        if ($next) {
            return (int) self::beginLevel($e, $next);
        }
        $level = Policy::level((int) $policy['id'], $pos);
        if ($level && (int) $e['repeats'] < (int) $policy['max_repeats']) {
            $round = (int) $e['repeats'] + 1;
            if (!self::updateProgress($e, ['repeats' => $round, 'next_action_at' => date('Y-m-d H:i:s', time() + (int) $policy['repeat_min'] * 60), 'date_mod' => $now])) {
                return 0;
            }
            Log::write($e, 'repeat', ['level' => $pos, 'status' => 'warn', 'actor' => 'system',
                'message' => 'Último nível sem ACK: repetindo o aviso (' . $round . ' de ' . (int) $policy['max_repeats'] . ').']);
            self::notify(Escalation::get((int) $e['id']), $level, $round);
            return 1;
        }
        if (!self::updateProgress($e, ['next_action_at' => null, 'date_mod' => $now])) {
            return 0;
        }
        Log::write($e, 'exhausted', ['level' => $pos, 'status' => 'fail', 'actor' => 'system',
            'message' => 'A cadeia esgotou sem ACK. Avisando o responsável pela hierarquia.']);
        Dispatcher::stageOwnerNotice($e, 'exhausted');
        return 1;
    }

    private static function updateProgress(array $e, array $changes): bool
    {
        global $DB;
        $DB->update(Escalation::TABLE, $changes, ['id' => $e['id'], 'state' => 'open', 'level' => $e['level'],
            'repeats' => $e['repeats'], 'next_action_at' => $e['next_action_at'], 'silenced_until' => $e['silenced_until']]);
        return $DB->affectedRows() === 1;
    }

    /** Alerta e estouro do SLO de ACK e de resolução (cada um avisa uma vez). */
    private static function slo(?array $e): int
    {
        global $DB;
        if (!$e || !in_array($e['state'], ['open', 'acked'], true) || NativeItem::isClosed($e)) {
            return 0;
        }
        $policy = Policy::get((int) $e['policies_id']);
        if (!$policy) {
            return 0;
        }
        $n = 0;
        $now = time();
        $opened = strtotime((string) $e['opened_at']);
        $warn = (int) $policy['slo_warn_pct'] / 100;
        $checks = [];
        if ($e['state'] === 'open' && $e['ack_due_at']) {
            $checks[] = ['ack', strtotime($e['ack_due_at']), 'ack_warned', 'ack_breached', (int) $policy['ack_slo_min']];
        }
        if ($e['resolve_due_at']) {
            $checks[] = ['resolve', strtotime($e['resolve_due_at']), 'resolve_warned', 'resolve_breached', (int) $policy['resolve_slo_min']];
        }
        foreach ($checks as [$slo, $due, $warnCol, $breachCol, $minutes]) {
            $label = $slo === 'ack' ? 'ACK' : 'resolução';
            $total = max(1, $due - $opened);
            if (!(int) $e[$breachCol] && $now >= $due) {
                $DB->update(Escalation::TABLE, [$breachCol => 1, $warnCol => 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $e['id']]);
                Log::write($e, 'slo_breach', ['status' => 'fail', 'actor' => 'system', 'message' => 'SLO de ' . $label . ' estourado (' . $minutes . ' min). Aberto há ' . Ui::dur($now - $opened) . '.',
                    'detail' => ['slo' => $slo, 'limit_min' => $minutes, 'age_s' => $now - $opened]]);
                Dispatcher::stageOwnerNotice($e, 'slo_breach:' . $slo);
                $n++;
            } elseif (!(int) $e[$warnCol] && !(int) $e[$breachCol] && ($now - $opened) >= $total * $warn) {
                $DB->update(Escalation::TABLE, [$warnCol => 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $e['id']]);
                Log::write($e, 'slo_warn', ['status' => 'warn', 'actor' => 'system', 'message' => 'SLO de ' . $label . ' em risco: ' . (int) $policy['slo_warn_pct'] . '% do prazo de ' . $minutes . ' min já passou.',
                    'detail' => ['slo' => $slo, 'limit_min' => $minutes, 'left_s' => $due - $now]]);
                Dispatcher::stageOwnerNotice($e, 'slo_warn:' . $slo);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ destinatários

    /** Usuários ativos acionados pelo nível (ids), com ou sem poder de agir. */
    public static function recipients(array $e, array $level): array
    {
        return array_keys(self::recipientRoles($e, $level));
    }

    /**
     * Usuários ativos acionados pelo nível: id => papel. "act" = escalado (pode agir); "notify" = só é notificado.
     * Quem aparece nos dois papéis fica como escalado. Quem foi marcado como "somente notificado" nas Permissões (usuário ou
     * perfil) recebe como notificado, mesmo que o nível o liste como escalado.
     */
    public static function recipientRoles(array $e, array $level): array
    {
        global $DB;
        $roles = [];
        $put = function (array $ids, string $role) use (&$roles): void {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id && (($roles[$id] ?? 'notify') !== 'act')) {
                    $roles[$id] = $role;
                }
            }
        };
        foreach ($level['targets'] as $t) {
            $id = (int) $t['items_id'];
            $role = ($t['role'] ?? 'act') === 'notify' ? 'notify' : 'act';
            switch ($t['type']) {
                case 'user':
                    $put([$id], $role);
                    break;
                case 'group':
                case 'groupmgr':
                    $where = ['groups_id' => $id];
                    if ($t['type'] === 'groupmgr') {
                        $where['is_manager'] = 1;
                    }
                    $members = [];
                    foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => $where]) as $r) {
                        $members[] = (int) $r['users_id'];
                    }
                    $put($members, $role);
                    break;
                case 'supervisor':
                    $put(self::supervisorsOf(self::baseUsers($e, (int) $level['position'])), $role);
                    break;
                default: // plantão: v0.2
                    break;
            }
        }
        if (!$roles) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_keys($roles), 'is_active' => 1, 'is_deleted' => 0]]) as $u) {
            $uid = (int) $u['id'];
            $out[$uid] = $roles[$uid] === 'act' && !Perms::notifyOnly($uid) ? 'act' : 'notify';
        }
        return $out;
    }

    /** Quem já foi acionado nos níveis anteriores e os técnicos atribuídos ao chamado: base para "gestor do responsável". */
    private static function baseUsers(array $e, int $pos): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'users_id', 'DISTINCT' => true, 'FROM' => Escalation::DELIVERIES, 'WHERE' => ['escalations_id' => $e['id'], 'level' => ['<', $pos], 'kind' => 'level', ['NOT' => ['users_id' => 0]]]]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        if ($e['source'] === 'ticket' && (int) $e['items_id']) {
            foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => (int) $e['items_id'], 'type' => 2]]) as $r) {
                $ids[] = (int) $r['users_id'];
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function supervisorsOf(array $users): array
    {
        global $DB;
        if (!$users || !$DB->fieldExists('glpi_users', 'users_id_supervisor')) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => 'users_id_supervisor', 'FROM' => 'glpi_users', 'WHERE' => ['id' => $users]]) as $r) {
            if ((int) $r['users_id_supervisor']) {
                $out[] = (int) $r['users_id_supervisor'];
            }
        }
        return $out;
    }
}
