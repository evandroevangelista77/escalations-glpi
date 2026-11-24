<?php

namespace GlpiPlugin\Escalations;

use Throwable;

/**
 * Entrega as mensagens: cria um registro por destinatário e canal (tabela deliveries), tenta enviar, repete com espera
 * crescente quando falha e, esgotadas as tentativas, aciona o canal reserva. Tudo fica no log rastreável.
 */
class Dispatcher
{
    /** Espera (minutos) antes da 2ª, 3ª... tentativa. */
    private const BACKOFF = [1, 5, 15, 30];

    /** Grava a intenção na transação que iniciou o nível ou aviso, sem chamar o transporte. */
    public static function enqueue(array $e, int $level, string $channel, int $uid, int $round = 0, string $kind = 'level', string $role = 'act'): ?int
    {
        global $DB;
        $key = ['escalations_id' => (int) $e['id'], 'level' => $level, 'round' => $round, 'kind' => $kind, 'channel' => $channel, 'users_id' => $uid];
        if (countElementsInTable(Escalation::DELIVERIES, $key)) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $recipient = match ($channel) {
            'email'    => $uid ? Channels::userEmail($uid) : '',
            'whatsapp' => $uid ? Channels::phone($uid) : '',
            'teams'    => 'Canal do Teams',
            default    => Ui::userName($uid),
        };
        $DB->insert(Escalation::DELIVERIES, $key + [
            'trace_id' => $e['trace_id'], 'role' => $role === 'notify' ? 'notify' : 'act',
            'recipient' => mb_substr($recipient, 0, 255), 'status' => 'queued', 'attempts' => 0,
            // O legado gravava queued sem distinguir se o transporte já havia sido chamado.
            'next_try' => $channel === 'popup' ? null : $now,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        if ($channel === 'popup') {
            Log::write($e, 'delivery', ['channel' => 'popup', 'status' => 'ok', 'level' => $level, 'target_type' => 'user', 'target_id' => $uid,
                'actor' => 'system', 'message' => 'Pop-up registrado para ' . Ui::userName($uid) . ' (aparece quando o GLPI estiver aberto no navegador dele).',
                'detail' => ['delivery' => $id, 'kind' => $kind, 'round' => $round]]);
        }
        return $id;
    }

    /** Processa os registros já confirmados no banco. Executar fora da transação de origem. */
    public static function retries(?int $escalationId = null): int
    {
        global $DB;
        if (Transaction::isActive()) {
            return 0;
        }
        $where = ['channel' => ['<>', 'popup'], ['OR' => [
            ['status' => ['queued', 'sending']],
            ['status' => 'failed', 'next_try' => ['<=', date('Y-m-d H:i:s')]],
        ]]];
        if ($escalationId !== null) {
            $where['escalations_id'] = $escalationId;
        }
        $n = 0;
        $last = 0;
        // Uma ocorrência ocupada ou uma linha com erro não pode bloquear o restante da fila.
        do {
            $cursor = $last;
            foreach ($DB->request(['SELECT' => ['id', 'escalations_id'], 'FROM' => Escalation::DELIVERIES,
                'WHERE' => $where + ['id' => ['>', $cursor]], 'ORDER' => 'id', 'LIMIT' => 50]) as $r) {
                $last = (int) $r['id'];
                try {
                    $n += Escalation::withLock((int) $r['escalations_id'], static fn (?array $e): bool =>
                        $e && self::attempt($e, (int) $r['id']), 0) ? 1 : 0;
                } catch (Throwable $t) {
                    Log::file('delivery ' . $last . ': ' . $t->getMessage());
                }
                if ($n === 50) {
                    return $n;
                }
            }
        } while ($last !== $cursor);
        return $n;
    }

    /** O processamento imediato é opcional após a gravação. O cron recupera a fila restante. */
    public static function flush(int $escalationId): void
    {
        try {
            self::retries($escalationId);
        } catch (Throwable $t) {
            Log::file('dispatch ' . $escalationId . ': ' . $t->getMessage());
        }
    }

    /** Uma tentativa sob a trava da ocorrência. O estado sending é persistido antes do transporte. */
    private static function attempt(array $e, int $id): bool
    {
        global $DB;
        $d = null;
        foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['id' => $id]]) as $row) {
            $d = $row;
        }
        $now = date('Y-m-d H:i:s');
        if (!$d || $d['channel'] === 'popup' || !in_array($d['status'], ['queued', 'sending', 'failed'], true)
            || ($d['status'] === 'failed' && (!$d['next_try'] || $d['next_try'] > $now))) {
            return false;
        }
        if ($d['status'] === 'sending' || ($d['status'] === 'queued' && !$d['next_try'])) {
            // Se a trava foi adquirida, nenhum outro executor do plugin está enviando esta linha.
            // O provedor pode ter aceitado a tentativa anterior. Evita reenvio e reserva automáticos.
            self::discard($e, $d, 'uncertain', 'Envio sem confirmação local. O provedor pode ter recebido a mensagem.');
            return true;
        }
        if (NativeItem::close($e)) {
            $e = Escalation::get((int) $e['id']);
        }
        if (!self::isRelevant($e, $d)) {
            self::discard($e, $d, 'obsolete', 'Envio dispensado após mudança no estado do escalonamento.');
            return true;
        }

        $channel = (string) $d['channel'];
        $announcement = str_starts_with($d['kind'], 'announce:');
        $level = (int) $d['level'] ? Policy::level((int) $e['policies_id'], (int) $d['level']) : null;
        $number = (int) $d['attempts'] + 1;
        $sinked = Channels::sinked($channel);
        $simulated = false;
        // A simulação também vale quando o canal real está desligado: ela não depende do provedor.
        $enabled = $sinked || Channels::enabled($channel);
        // Um canal simulado nunca repete a tentativa nem aciona um canal reserva real.
        $terminal = $announcement || !$enabled || $sinked;
        $copy = [];
        $result = self::failure('Canal desligado ou não configurado.');
        if ($enabled) {
            try {
                if ($announcement) {
                    $text = self::announcementText($e, $d['kind']);
                    $copy = ['Aviso no canal do Teams', '', $text];
                } else {
                    $context = Message::context($e, $level, $d['kind']);
                    $context['role'] = (string) $d['role'];
                    $copy = Outbox::copyOf($channel, $context);
                }
            } catch (Throwable $t) {
                self::finish($e, $d, $number, self::failure($t->getMessage()), [], false, $terminal);
                return true;
            }

            if ($sinked) {
                if ($channel === 'email' && (string) $d['recipient'] === '') {
                    $result = self::failure('Usuário sem e-mail cadastrado.');
                } elseif ($channel === 'whatsapp' && (string) $d['recipient'] === '') {
                    $result = self::failure('Usuário sem celular cadastrado (campo "Celular" do usuário no GLPI).');
                } else {
                    $simulated = true;
                    $result = Channels::simulationResult();
                }
            } else {
                $DB->update(Escalation::DELIVERIES, ['status' => 'sending', 'attempts' => $number, 'next_try' => null, 'date_mod' => $now], [
                    'id' => $id, 'status' => $d['status'], 'attempts' => $d['attempts'],
                ]);
                if ($DB->affectedRows() !== 1) {
                    return false;
                }
                $result = $announcement ? Channels::teamsText($text) + ['provider_id' => ''] : match ($channel) {
                    'email'    => Channels::mail($context, ['id' => (int) $d['users_id'], 'email' => (string) $d['recipient'], 'name' => Ui::userName((int) $d['users_id'])]),
                    'teams'    => Channels::teams($context),
                    'whatsapp' => Channels::whatsapp($context, (string) $d['recipient']),
                    default   => self::failure('Canal desconhecido.'),
                };
            }
        }
        self::finish($e, $d, $number, $result, $copy, $simulated, $terminal);
        return true;
    }

    private static function failure(string $error): array
    {
        return ['ok' => false, 'provider_id' => '', 'error' => $error, 'ms' => 0, 'detail' => []];
    }

    private static function isRelevant(array $e, array $d): bool
    {
        $active = in_array($e['state'], ['open', 'acked'], true);
        return match ($d['kind']) {
            'level' => $e['state'] === 'open' || ($e['state'] === 'acked' && $d['role'] === 'notify'),
            'slo_warn:ack' => $e['state'] === 'open',
            'slo_breach:ack', 'slo_breach:resolve' => $active || $d['channel'] !== 'popup',
            'announce:ack', 'announce:resolve' => true,
            default => $active,
        };
    }

    private static function discard(array $e, array $d, string $status, string $reason): void
    {
        Transaction::run(static function () use ($e, $d, $status, $reason): void {
            global $DB;
            $DB->update(Escalation::DELIVERIES, ['status' => $status, 'next_try' => null, 'error' => $reason,
                'date_mod' => date('Y-m-d H:i:s')], ['id' => $d['id']]);
            Log::write($e, 'delivery', ['channel' => $d['channel'], 'level' => (int) $d['level'],
                'status' => $status === 'uncertain' ? 'warn' : 'info', 'message' => $reason,
                'detail' => ['delivery' => (int) $d['id'], 'kind' => $d['kind'], 'outcome' => $status]]);
        });
    }

    private static function finish(array $e, array $d, int $number, array $result, array $copy, bool $simulated, bool $terminal): void
    {
        $fallback = Transaction::run(static function () use ($e, $d, $number, $result, $copy, $simulated, $terminal): ?int {
            global $DB;
            $now = date('Y-m-d H:i:s');
            $max = max(1, Settings::int('max_attempts') ?: 3);
            $retry = !$result['ok'] && !$terminal && $number < $max;
            $next = $retry ? date('Y-m-d H:i:s', time() + self::BACKOFF[min($number - 1, count(self::BACKOFF) - 1)] * 60) : null;
            $DB->update(Escalation::DELIVERIES, [
                'status' => $result['ok'] ? 'sent' : 'failed', 'attempts' => $number, 'date_mod' => $now,
                'sent_at' => $result['ok'] ? $now : null, 'next_try' => $next,
                'provider_id' => mb_substr((string) $result['provider_id'], 0, 190),
                'error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 500),
            ], ['id' => $d['id']]);
            Outbox::record($e, $d, $copy, $result['ok'] ? ($simulated ? 'sim' : 'sent') : 'failed', $result['error']);

            $label = Policy::CHANNELS[$d['channel']][0] ?? $d['channel'];
            $who = (int) $d['users_id'] ? Ui::userName((int) $d['users_id']) : 'canal da equipe';
            Log::write($e, $number > 1 ? 'retry' : 'delivery', [
                'channel' => $d['channel'], 'level' => (int) $d['level'], 'target_type' => (int) $d['users_id'] ? 'user' : 'channel', 'target_id' => (int) $d['users_id'],
                'status' => $result['ok'] ? 'ok' : 'fail', 'actor' => 'system', 'duration_ms' => (int) $result['ms'],
                'message' => $result['ok']
                    ? $label . ($simulated ? ' simulado para ' : ' enviado para ') . $who . ' (tentativa ' . $number . ').'
                    : $label . ' falhou para ' . $who . ' (tentativa ' . $number . '): ' . $result['error'],
                'detail' => ['delivery' => (int) $d['id'], 'kind' => $d['kind'], 'round' => (int) $d['round'], 'attempt' => $number, 'provider_id' => $result['provider_id']] + $result['detail'],
            ]);
            return !$result['ok'] && !$retry ? self::stageFallback($e, $d) : null;
        });
        if ($fallback !== null) {
            self::attempt($e, $fallback);
        }
    }

    /** A falha definitiva e a intenção do canal reserva são confirmadas juntas. */
    private static function stageFallback(array $e, array $d): ?int
    {
        $fb = Settings::get('fallback_channel');
        if ($fb === '' || $fb === 'none' || $fb === $d['channel'] || !(int) $d['users_id']
            || (!Channels::enabled($fb) && !Channels::sinked($fb))) {
            return null;
        }
        if (Channels::sinked((string) $d['channel']) && !Channels::sinked($fb)) {
            Log::write($e, 'delivery', ['channel' => (string) $d['channel'], 'level' => (int) $d['level'], 'status' => 'warn', 'target_type' => 'user', 'target_id' => (int) $d['users_id'], 'actor' => 'system',
                'message' => (Policy::CHANNELS[$d['channel']][0] ?? $d['channel']) . ' simulado falhou para ' . Ui::userName((int) $d['users_id'])
                    . '; o canal reserva (' . (Policy::CHANNELS[$fb][0] ?? $fb) . ') não foi acionado para não enviar de verdade durante a simulação.']);
            return null;
        }
        $id = self::enqueue($e, (int) $d['level'], $fb, (int) $d['users_id'], (int) $d['round'], $d['kind'], $d['role']);
        if ($id !== null) {
            Log::write($e, 'fallback', ['channel' => $fb, 'level' => (int) $d['level'], 'status' => 'warn', 'target_type' => 'user', 'target_id' => (int) $d['users_id'], 'actor' => 'system',
                'message' => (Policy::CHANNELS[$d['channel']][0] ?? $d['channel']) . ' falhou em definitivo para ' . Ui::userName((int) $d['users_id']) . '; acionando o canal reserva (' . (Policy::CHANNELS[$fb][0] ?? $fb) . ').']);
        }
        return $id;
    }

    /** Registra aviso de SLO ou esgotamento ao dono junto com o marco correspondente. */
    public static function stageOwnerNotice(array $e, string $kind): void
    {
        $policy = Policy::get((int) $e['policies_id']);
        $owner = (int) ($policy['users_id_owner'] ?? 0);
        if (!$owner) {
            Log::write($e, 'no_recipient', ['status' => 'warn', 'message' => 'A hierarquia não tem responsável (dono) para receber o aviso de ' . $kind . '. Defina em Hierarquias.']);
            return;
        }
        Bridge::notify($e, [$owner], ($kind === 'exhausted' ? 'Cadeia esgotada: ' : 'SLO: ') . $e['title'], 'Aviso ao responsável pela hierarquia (' . $kind . ').', 'danger');
        foreach (Settings::channels(Settings::get('owner_channels')) as $ch) {
            self::enqueue($e, 0, $ch, $ch === 'teams' ? 0 : $owner, 0, $kind);
        }
    }

    /** Aviso informativo de transição já confirmada, no Teams. */
    public static function stageAnnouncement(array $e, string $kind): void
    {
        if (Channels::enabled('teams')) {
            self::enqueue($e, 0, 'teams', 0, 0, $kind, 'notify');
        }
    }

    private static function announcementText(array $e, string $kind): string
    {
        $opened = strtotime((string) $e['opened_at']);
        $text = $kind === 'announce:ack'
            ? 'Reconhecido por ' . Ui::userName((int) $e['users_id_ack']) . ' em ' . Ui::dur(strtotime((string) $e['acked_at']) - $opened) . '. A escalada foi interrompida.'
            : 'Resolvido após ' . Ui::dur(strtotime((string) $e['resolved_at']) - $opened) . '.';
        return '[' . $e['trace_id'] . '] ' . $e['title'] . ' — ' . $text;
    }

    // ------------------------------------------------------------------ pop-up

    /**
     * Pop-ups pendentes do usuário (o navegador consulta de tempos em tempos). Na primeira vez que aparecem, o log registra
     * "Pop-up exibido" com o atraso desde o envio. Pop-up de ACK some quando alguém assume; os de SLO ficam até "Ciente".
     */
    public static function pending(int $uid, int $limit = 5): array
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['users_id' => $uid, 'channel' => 'popup', 'status' => ['queued', 'shown']], 'ORDER' => ['id'], 'LIMIT' => 40]) as $d) {
            if ($d['snooze_until'] && $d['snooze_until'] > $now) {
                continue;
            }
            $e = Escalation::get((int) $d['escalations_id']);
            if (!$e) {
                continue;
            }
            if (!self::isRelevant($e, $d) || NativeItem::isClosed($e)) {
                $DB->update(Escalation::DELIVERIES, ['status' => 'acked', 'date_mod' => $now], ['id' => $d['id'], 'status' => ['queued', 'shown']]);
                continue;
            }
            if ($d['status'] === 'queued') {
                $DB->update(Escalation::DELIVERIES, ['status' => 'shown', 'shown_at' => $now, 'date_mod' => $now], ['id' => $d['id'], 'status' => 'queued']);
                if ($DB->affectedRows() !== 1) {
                    continue;
                }
                $lag = time() - strtotime((string) $d['date_creation']);
                Log::write($e, 'popup_shown', ['channel' => 'popup', 'status' => 'ok', 'level' => (int) $d['level'], 'users_id' => $uid, 'target_type' => 'user', 'target_id' => $uid, 'actor' => 'user',
                    'message' => 'Pop-up exibido para ' . Ui::userName($uid) . ' após ' . Ui::dur($lag) . ' na fila.', 'detail' => ['delivery' => (int) $d['id'], 'lag_s' => $lag]]);
            }
            $out[] = Message::popup($e, $d);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** "Ciente" num pop-up de aviso (SLO, cadeia esgotada): encerra a entrega. */
    public static function dismiss(int $deliveryId, int $uid): void
    {
        global $DB;
        $DB->update(Escalation::DELIVERIES, ['status' => 'acked', 'date_mod' => date('Y-m-d H:i:s')], ['id' => $deliveryId, 'users_id' => $uid, 'channel' => 'popup']);
    }
}
