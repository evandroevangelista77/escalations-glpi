<?php

namespace GlpiPlugin\Escalations;

use Closure;
use RuntimeException;
use Session;

/**
 * Um escalonamento em andamento: nasce de um chamado, de um evento (webhook) ou da abertura manual, percorre os níveis
 * da hierarquia até alguém dar ACK e termina resolvido ou cancelado. Cada passo vira log rastreável (Log).
 */
class Escalation extends Base
{
    public const TABLE = 'glpi_plugin_escalations_escalations';
    public const DELIVERIES = 'glpi_plugin_escalations_deliveries';

    // direitos extras (bits a partir de 1024)
    public const MANAGE = 1024;
    public const ALL = 2048;
    // direitos de ação e de visão (veja Perms): valem por perfil; "Atualizar" cobre todas as ações
    public const ACK = 4096;
    public const RESOLVE = 8192;
    public const SILENCE = 16384;
    public const CANCEL = 32768;
    public const LOGS = 65536;
    public const SLO = 131072;

    public const STATES = [
        'open'      => ['Aguardando ACK', '#ef4444'],
        'acked'     => ['Reconhecido', '#3b82f6'],
        'resolved'  => ['Resolvido', '#22c55e'],
        'cancelled' => ['Cancelado', '#94a3b8'],
    ];

    public const SEVERITIES = [
        1 => ['Informação', '#3b82f6'],
        2 => ['Baixa', '#14b8a6'],
        3 => ['Média', '#f59e0b'],
        4 => ['Alta', '#f97316'],
        5 => ['Crítica', '#ef4444'],
    ];

    public const SOURCES = [
        'ticket' => 'Chamado', 'problem' => 'Problema', 'change' => 'Mudança', 'project' => 'Projeto', 'projecttask' => 'Tarefa de projeto',
        'plugin' => 'Plugin', 'event' => 'Evento', 'manual' => 'Manual',
    ];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Escalonamentos' : 'Escalonamento';
    }

    public static function canManage(): bool
    {
        return (bool) Session::haveRight(static::$rightname, self::MANAGE);
    }

    public static function canAll(): bool
    {
        return Perms::can('all');
    }

    /** Ver o plugin: direito de leitura do perfil, salvo exceção do usuário (permitir/negar). */
    public static function canView(): bool
    {
        return Perms::can('view');
    }

    // ------------------------------------------------------------------ leitura

    public static function get(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $r) {
            return $r;
        }
        return null;
    }

    public static function url(int $id): string
    {
        return Menu::url('/front/escalonamento.php?id=' . $id);
    }

    /** Código de rastreio novo (ESC-AAMMDD-XXXXX), único. */
    public static function newTrace(): string
    {
        global $DB;
        do {
            $t = 'ESC-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        } while (countElementsInTable(self::TABLE, ['trace_id' => $t]));
        return $t;
    }

    public static function severity(int $s): array
    {
        return self::SEVERITIES[max(1, min(5, $s))];
    }

    /** Situação dos prazos agora: porcentagem gasta e tempo restante do ACK e da resolução. */
    public static function timing(array $e): array
    {
        $now = time();
        $open = strtotime((string) $e['opened_at']);
        $ackDue = $e['ack_due_at'] ? strtotime($e['ack_due_at']) : null;
        $resDue = $e['resolve_due_at'] ? strtotime($e['resolve_due_at']) : null;
        $ackEnd = $e['acked_at'] ? strtotime($e['acked_at']) : $now;
        $resEnd = $e['resolved_at'] ? strtotime($e['resolved_at']) : $now;
        $pct = fn (?int $due, int $end) => $due && $due > $open ? ($end - $open) / ($due - $open) * 100 : 0.0;
        return [
            'age'          => ($e['resolved_at'] ? strtotime($e['resolved_at']) : $now) - $open,
            'ack_pct'      => $pct($ackDue, $ackEnd),
            'ack_left'     => $ackDue ? $ackDue - $ackEnd : null,
            'resolve_pct'  => $pct($resDue, $resEnd),
            'resolve_left' => $resDue ? $resDue - $resEnd : null,
            'time_to_ack'  => $e['acked_at'] ? strtotime($e['acked_at']) - $open : null,
        ];
    }

    /** Restrição de visibilidade: sem o direito "ver todos", só os escalonamentos em que o usuário foi acionado ou que é dono. */
    public static function visibility(): array
    {
        global $DB;
        if (self::canAll()) {
            $scope = Perms::scope(); // gestor com escopo vê só as hierarquias dele
            return $scope ? [['policies_id' => $scope]] : [];
        }
        $uid = (int) Session::getLoginUserID();
        $ids = [0];
        foreach ($DB->request(['SELECT' => 'escalations_id', 'DISTINCT' => true, 'FROM' => self::DELIVERIES, 'WHERE' => ['users_id' => $uid]]) as $r) {
            $ids[] = (int) $r['escalations_id'];
        }
        $owned = array_keys(array_filter(Policy::all(), fn ($p) => (int) $p['users_id_owner'] === $uid));
        $where = ['OR' => ['id' => $ids]];
        if ($owned) {
            $where['OR']['policies_id'] = $owned;
        }
        return [$where];
    }

    public static function canSee(array $e): bool
    {
        global $DB;
        if (self::canAll()) {
            $scope = Perms::scope();
            if (!$scope || in_array((int) $e['policies_id'], $scope, true)) {
                return true;
            }
        }
        $uid = (int) Session::getLoginUserID();
        if (countElementsInTable(self::DELIVERIES, ['escalations_id' => $e['id'], 'users_id' => $uid])) {
            return true;
        }
        $p = Policy::get((int) $e['policies_id']);
        return $p && (int) $p['users_id_owner'] === $uid;
    }

    /** Pode fazer ALGUMA ação neste escalonamento (veja Perms::can para cada ação)? */
    public static function canAct(array $e, int $uid): bool
    {
        return Perms::canAnyAction($e, $uid);
    }

    /** Lista com filtros. Devolve [linhas, total]. */
    public static function search(array $f, int $page, int $per): array
    {
        global $DB;
        $where = self::visibility();
        foreach (['state', 'source'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $where[$k] = $f[$k];
            }
        }
        if ((int) ($f['severity'] ?? 0)) {
            $where['severity'] = (int) $f['severity'];
        }
        if ((int) ($f['policy'] ?? 0)) {
            $where['policies_id'] = (int) $f['policy'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = ['OR' => ['title' => ['LIKE', '%' . $f['q'] . '%'], 'trace_id' => ['LIKE', '%' . $f['q'] . '%'], 'source_ref' => ['LIKE', '%' . $f['q'] . '%']]];
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = ['opened_at' => ['>=', $f['from'] . ' 00:00:00']];
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = ['opened_at' => ['<=', $f['to'] . ' 23:59:59']];
        }
        if (!empty($f['slo'])) {
            $where[] = ['OR' => ['ack_breached' => 1, 'resolve_breached' => 1, 'ack_warned' => 1, 'resolve_warned' => 1]];
        }
        $total = countElementsInTable(self::TABLE, $where);
        $crit = ['FROM' => self::TABLE, 'ORDER' => ['opened_at DESC'], 'START' => max(0, ($page - 1) * $per), 'LIMIT' => $per];
        if ($where) {
            $crit['WHERE'] = $where;
        }
        return [iterator_to_array($DB->request($crit), false), $total];
    }

    // ------------------------------------------------------------------ coordenação das transições

    /**
     * @template T
     * @param Closure(?array): T $action
     * @return T|null
     */
    public static function withLock(int $id, Closure $action, int $wait = 30): mixed
    {
        return Lock::run('escalation:' . $id, static function () use ($id, $action): mixed {
            global $DB;
            $table = self::TABLE;
            $row = $DB->fetchAssoc($DB->doQuery("SELECT * FROM `$table` WHERE id = $id FOR UPDATE"));
            return $action($row ?: null);
        }, $wait);
    }

    // ------------------------------------------------------------------ abertura

    /**
     * Abre um escalonamento (ou agrupa na abertura que já existe com a mesma dedup_key).
     * $in: policies_id, rules_id, source, source_ref, items_id, dedup_key, title, summary, severity, entities_id, facts[], actor.
     * @param array{id: int, source: string, dedup_key: string}|null $legacy vínculo de acompanhamento da API já existente
     * Devolve [id, criado?].
     */
    public static function open(array $in, ?array $legacy = null): array
    {
        $policy = Policy::get((int) $in['policies_id']);
        if (!$policy) {
            throw new RuntimeException('Hierarquia não encontrada.', 404);
        }
        $key = (string) ($in['dedup_key'] ?? '');
        if (mb_strlen($key) > IntegrationKey::ESCALATION_LIMIT) {
            $key = IntegrationKey::digest('key', $key);
        }
        // Uma trava por instalação preserva a igualdade definida pela collation
        // do banco, inclusive para chaves que diferem em maiúsculas ou acentos.
        while (true) {
            [$id, $created] = $key === '' ? self::findOrCreate($in, $policy, $key, null)
                : Lock::run('open', static fn (): array => self::findOrCreate($in, $policy, $key, $legacy));
            if ($created) {
                break;
            }
            // A ocorrência pode ter sido encerrada desde a consulta acima.
            if (self::withLock($id, static fn (?array $e): bool => self::groupOccurrence($e, $in))) {
                return [$id, false];
            }
        }
        return [$id, true];
    }

    private static function findOrCreate(array $in, array $policy, string $key, ?array $legacy): array
    {
        return Transaction::run(static function () use ($in, $policy, $key, $legacy): array {
            global $DB;
            if (NativeItem::outcome((string) ($in['source'] ?? 'manual'), (int) ($in['items_id'] ?? 0)) !== null) {
                throw new RuntimeException('O item de origem já foi encerrado ou excluído.', 409);
            }
            $duplicate = null;
            if ($key !== '') {
                // Leitura atual mesmo se o chamador já abriu uma transação.
                $quotedKey = $DB->quote($key);
                $table = self::TABLE;
                $query = $DB->doQuery("SELECT id FROM `$table` WHERE dedup_key = $quotedKey AND state IN ('open', 'acked') LIMIT 1 FOR UPDATE");
                $duplicate = $DB->fetchRow($query);
                if (!$duplicate && $legacy) {
                    $id = (int) $legacy['id'];
                    $source = $DB->quote($legacy['source']);
                    $legacyKey = $DB->quote($legacy['dedup_key']);
                    $duplicate = $DB->fetchRow($DB->doQuery("SELECT id FROM `$table` WHERE id = $id AND source = $source AND dedup_key = $legacyKey AND state IN ('open', 'acked') FOR UPDATE"));
                }
            }
            return $duplicate ? [(int) $duplicate[0], false] : [self::recordOpening($in, $policy, $key), true];
        });
    }

    private static function groupOccurrence(?array $e, array $in): bool
    {
        global $DB;
        if (!$e || !in_array($e['state'], ['open', 'acked'], true)) {
            return false;
        }
        if (!(int) $e['level'] && empty($in['no_start'])) {
            throw new RuntimeException('Já existe um escalonamento para esta origem sem nível iniciado.', 409);
        }
        $occurrences = (int) $e['occurrences'] + 1;
        $DB->update(self::TABLE, ['occurrences' => $occurrences, 'last_seen' => date('Y-m-d H:i:s')], ['id' => $e['id'], 'state' => $e['state']]);
        if ($DB->affectedRows() !== 1) {
            return false;
        }
        Log::write($e, 'dedup', ['status' => 'info', 'message' => 'Evento repetido agrupado neste escalonamento (ocorrência ' . $occurrences . ').',
            'actor' => (string) ($in['actor'] ?? 'system'), 'detail' => ['source_ref' => $in['source_ref'] ?? '']]);
        return true;
    }

    private static function recordOpening(array $in, array $policy, string $key): int
    {
        global $DB;
        // opened_ts / no_start: só para ferramentas de demonstração e testes (abrir no passado, sem avisar o nível 1 ainda)
        $ts = (int) ($in['opened_ts'] ?? 0) ?: time();
        $now = date('Y-m-d H:i:s', $ts);
        $row = [
            'trace_id'         => self::newTrace(),
            'policies_id'      => (int) $policy['id'],
            'rules_id'         => (int) ($in['rules_id'] ?? 0),
            'source'           => (string) ($in['source'] ?? 'manual'),
            'source_ref'       => mb_substr((string) ($in['source_ref'] ?? ''), 0, 100),
            'items_id'         => (int) ($in['items_id'] ?? 0),
            'dedup_key'        => $key !== '' ? $key : null,
            'title'            => mb_substr(trim((string) $in['title']) ?: 'Escalonamento sem título', 0, 255),
            'summary'          => (string) ($in['summary'] ?? ''),
            'facts'            => json_encode($in['facts'] ?? [], JSON_UNESCAPED_UNICODE),
            'severity'         => max(1, min(5, (int) ($in['severity'] ?? 3))),
            'entities_id'      => (int) ($in['entities_id'] ?? 0),
            'state'            => 'open',
            'level'            => 0,
            'repeats'          => 0,
            'occurrences'      => 1,
            'opened_at'        => $now,
            'last_seen'        => $now,
            'ack_due_at'       => date('Y-m-d H:i:s', $ts + (int) $policy['ack_slo_min'] * 60),
            'resolve_due_at'   => date('Y-m-d H:i:s', $ts + (int) $policy['resolve_slo_min'] * 60),
            'users_id_opened'  => (int) ($_SESSION['glpiID'] ?? 0),
            'date_mod'         => $now,
        ];
        $DB->insert(self::TABLE, $row);
        $id = (int) $DB->insertId();
        $esc = $row + ['id' => $id];
        Log::write($esc, 'opened', ['status' => 'ok', 'message' => 'Escalonamento aberto (' . (self::SOURCES[$row['source']] ?? $row['source']) . ') na hierarquia "' . $policy['name'] . '".',
            'actor' => (string) ($in['actor'] ?? 'system'), 'detail' => ['source_ref' => $row['source_ref'], 'severity' => $row['severity'], 'rule' => $row['rules_id'],
                'ack_due' => $row['ack_due_at'], 'resolve_due' => $row['resolve_due_at'], 'facts' => $in['facts'] ?? []]]);
        if (empty($in['no_start'])) {
            Engine::stageFirstLevel(self::get($id));
        }
        return $id;
    }

    // ------------------------------------------------------------------ ações

    /** Reconhece (ACK): interrompe a cadeia. $via: glpi, popup, email, whatsapp, webhook. */
    public static function ack(int $id, int $uid, string $via = 'glpi', string $note = ''): void
    {
        self::withLock($id, static fn (?array $e) => Transaction::run(static fn () => self::recordAck($e, $uid, $via, $note)));
    }

    private static function recordAck(?array $e, int $uid, string $via, string $note): void
    {
        global $DB;
        if (!$e) {
            throw new RuntimeException('Escalonamento não encontrado.', 404);
        }
        if ($e['state'] === 'acked') {
            throw new RuntimeException('Já reconhecido por ' . Ui::userName((int) $e['users_id_ack']) . ' em ' . Ui::dt($e['acked_at']) . '.', 409);
        }
        if ($e['state'] !== 'open') {
            throw new RuntimeException('Este escalonamento já foi encerrado.', 409);
        }
        $id = (int) $e['id'];
        $now = date('Y-m-d H:i:s');
        $breached = $e['ack_due_at'] && $now > $e['ack_due_at'] ? 1 : (int) $e['ack_breached'];
        $DB->update(self::TABLE, ['state' => 'acked', 'acked_at' => $now, 'users_id_ack' => $uid, 'ack_via' => $via, 'next_action_at' => null,
            'ack_breached' => $breached, 'date_mod' => $now], ['id' => $id, 'state' => 'open']);
        self::requireTransition();
        $DB->update(self::DELIVERIES, ['status' => 'acked', 'date_mod' => $now], [
            'escalations_id' => $id, 'channel' => 'popup', 'status' => ['queued', 'shown'],
            ['OR' => [['kind' => 'level', 'role' => 'act'], ['kind' => 'slo_warn:ack']]],
        ]);
        $tta = time() - strtotime((string) $e['opened_at']);
        Log::write($e, 'ack', ['status' => 'ok', 'users_id' => $uid, 'actor' => $via === 'whatsapp' ? 'whatsapp' : 'user', 'level' => (int) $e['level'],
            'message' => 'Reconhecido por ' . Ui::userName($uid) . ' (' . $via . ') em ' . Ui::dur($tta) . ($breached ? ' — fora do SLO de ACK' : ' — dentro do SLO de ACK') . '.',
            'detail' => ['via' => $via, 'time_to_ack_s' => $tta, 'slo_breached' => (bool) $breached, 'note' => $note]]);
        Dispatcher::stageAnnouncement($e, 'announce:ack');
    }

    public static function resolve(int $id, string $by = 'user', int $uid = 0, string $note = ''): void
    {
        self::withLock($id, static fn (?array $e) => Transaction::run(static fn () => self::recordResolution($e, $by, $uid, $note)));
    }

    private static function recordResolution(?array $e, string $by, int $uid, string $note): void
    {
        global $DB;
        if (!$e || !in_array($e['state'], ['open', 'acked'], true)) {
            throw new RuntimeException('Este escalonamento já foi encerrado.', 409);
        }
        $id = (int) $e['id'];
        $now = date('Y-m-d H:i:s');
        $breached = $e['resolve_due_at'] && $now > $e['resolve_due_at'] ? 1 : (int) $e['resolve_breached'];
        $DB->update(self::TABLE, ['state' => 'resolved', 'resolved_at' => $now, 'resolved_by' => mb_substr($by, 0, 40), 'next_action_at' => null,
            'resolve_breached' => $breached, 'date_mod' => $now], ['id' => $id, 'state' => $e['state']]);
        self::requireTransition();
        $DB->update(self::DELIVERIES, ['status' => 'acked', 'date_mod' => $now], ['escalations_id' => $id, 'channel' => 'popup', 'status' => ['queued', 'shown']]);
        $total = time() - strtotime((string) $e['opened_at']);
        Log::write($e, 'resolved', ['status' => 'ok', 'users_id' => $uid, 'actor' => $uid ? 'user' : 'system', 'level' => (int) $e['level'],
            'message' => 'Resolvido (' . $by . ') após ' . Ui::dur($total) . ($breached ? ' — fora do SLO de resolução' : ' — dentro do SLO de resolução') . '.',
            'detail' => ['by' => $by, 'duration_s' => $total, 'slo_breached' => (bool) $breached, 'note' => $note]]);
        Dispatcher::stageAnnouncement($e, 'announce:resolve');
    }

    public static function cancel(int $id, string $why, int $uid = 0): void
    {
        self::withLock($id, static fn (?array $e) => Transaction::run(static fn () => self::recordCancellation($e, $why, $uid)));
    }

    private static function recordCancellation(?array $e, string $why, int $uid): void
    {
        global $DB;
        if (!$e || !in_array($e['state'], ['open', 'acked'], true)) {
            throw new RuntimeException('Este escalonamento já foi encerrado.', 409);
        }
        $id = (int) $e['id'];
        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, ['state' => 'cancelled', 'resolved_at' => $now, 'resolved_by' => 'cancelado', 'next_action_at' => null, 'date_mod' => $now], ['id' => $id, 'state' => $e['state']]);
        self::requireTransition();
        $DB->update(self::DELIVERIES, ['status' => 'acked', 'date_mod' => $now], ['escalations_id' => $id, 'channel' => 'popup', 'status' => ['queued', 'shown']]);
        Log::write($e, 'cancelled', ['status' => 'warn', 'users_id' => $uid, 'message' => 'Cancelado: ' . $why]);
    }

    private static function requireTransition(): void
    {
        global $DB;
        if ($DB->affectedRows() !== 1) {
            throw new RuntimeException('O estado do escalonamento mudou. Atualize a página e tente novamente.', 409);
        }
    }

    /** Pausa a escalada (a contagem do SLO continua). */
    public static function silence(int $id, int $minutes, int $uid): void
    {
        self::withLock($id, static fn (?array $e) => self::recordSilence($e, $minutes, $uid));
    }

    private static function recordSilence(?array $e, int $minutes, int $uid): void
    {
        global $DB;
        if (!$e || $e['state'] !== 'open') {
            throw new RuntimeException('Só dá para silenciar um escalonamento aguardando ACK.', 409);
        }
        $minutes = max(1, min(1440, $minutes));
        $until = date('Y-m-d H:i:s', time() + $minutes * 60);
        $DB->update(self::TABLE, ['silenced_until' => $until, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $e['id']]);
        Log::write($e, 'silence', ['status' => 'warn', 'users_id' => $uid, 'message' => 'Escalada pausada por ' . $minutes . ' min (até ' . Ui::dt($until) . '). O SLO continua contando.',
            'detail' => ['minutes' => $minutes, 'until' => $until]]);
    }

    /** "Adiar" no pop-up: some da tela do usuário por alguns minutos (não interrompe a cadeia). */
    public static function snooze(int $deliveryId, int $uid): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::DELIVERIES, 'WHERE' => ['id' => $deliveryId, 'users_id' => $uid, 'channel' => 'popup']]) as $d) {
            $min = max(1, Settings::int('snooze_min') ?: 10);
            $until = date('Y-m-d H:i:s', time() + $min * 60);
            $DB->update(self::DELIVERIES, ['snooze_until' => $until, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $d['id']]);
            $e = self::get((int) $d['escalations_id']);
            if ($e) {
                Log::write($e, 'snooze', ['status' => 'info', 'users_id' => $uid, 'channel' => 'popup', 'level' => (int) $d['level'], 'message' => Ui::userName($uid) . ' adiou o pop-up por ' . $min . ' min.']);
            }
            return;
        }
    }

    // ------------------------------------------------------------------ histórico para e-mail, Teams e tela

    /**
     * Histórico por nível: [posição => [name, started, recipients => [nome => [canal => status]], acked_by]].
     * É o que o e-mail mostra como "histórico das escaladas anteriores".
     */
    public static function levelHistory(int $id): array
    {
        global $DB;
        $e = self::get($id);
        $levels = $e ? Policy::levels((int) $e['policies_id']) : [];
        $out = [];
        foreach ($DB->request(['FROM' => self::DELIVERIES, 'WHERE' => ['escalations_id' => $id, 'kind' => 'level'], 'ORDER' => ['id']]) as $d) {
            $pos = (int) $d['level'];
            $out[$pos] ??= ['name' => $levels[$pos]['name'] ?? ('Nível ' . $pos), 'started' => $d['date_creation'], 'recipients' => []];
            $who = (int) $d['users_id'] ? Ui::userName((int) $d['users_id']) : 'Canal da equipe';
            $out[$pos]['recipients'][$who][$d['channel']] = $d['status'];
            if ($d['date_creation'] < $out[$pos]['started']) {
                $out[$pos]['started'] = $d['date_creation'];
            }
        }
        ksort($out);
        return $out;
    }
}
