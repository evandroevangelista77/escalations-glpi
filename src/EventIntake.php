<?php

namespace GlpiPlugin\Escalations;

use RuntimeException;
use Throwable;

/**
 * Fonte "evento": recebe o webhook (Zabbix, Wazuh, qualquer sistema que poste JSON) e abre, agrupa ou resolve o
 * escalonamento. Campos aceitos (todos opcionais, exceto um título):
 *   title|name|trigger, message|description, host, severity (0-5 ou nome), status|value (PROBLEM/OK, 1/0),
 *   event_id, trigger_id, dedup_key, entity (id do GLPI), policy_id, url, tags (objeto), source (nome do sistema).
 */
class EventIntake
{
    private const SEVERITY_NAMES = [
        'not classified' => 1, 'information' => 1, 'info' => 1, 'low' => 2, 'warning' => 2, 'baixa' => 2, 'average' => 3, 'medium' => 3, 'média' => 3, 'media' => 3,
        'high' => 4, 'alta' => 4, 'disaster' => 5, 'critical' => 5, 'crítica' => 5, 'critica' => 5,
    ];

    /** Confere o token do webhook (cabeçalho X-Escalations-Token, Authorization: Bearer ou ?token=). */
    public static function authorized(): bool
    {
        $token = Settings::get('webhook_token');
        if ($token === '') {
            return false;
        }
        $got = (string) ($_SERVER['HTTP_X_ESCALATIONS_TOKEN'] ?? '');
        if ($got === '' && preg_match('/^Bearer\s+(.+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
            $got = trim($m[1]);
        }
        if ($got === '') {
            $got = (string) ($_GET['token'] ?? '');
        }
        return $got !== '' && hash_equals($token, $got);
    }

    /** @return array{0: int, 1: array} código HTTP e resposta */
    public static function handle(array $p): array
    {
        if (!Settings::sourceOn('event')) {
            return [403, ['ok' => false, 'error' => 'A origem "eventos por webhook" está desligada (Escalations → Configurações → Origens).']];
        }
        try {
            $title = trim((string) ($p['title'] ?? $p['name'] ?? $p['trigger'] ?? ''));
            if ($title === '') {
                return [422, ['ok' => false, 'error' => 'Informe title (ou name/trigger).']];
            }
            $host = trim((string) ($p['host'] ?? $p['hostname'] ?? ''));
            $message = trim((string) ($p['message'] ?? $p['description'] ?? ''));
            $source = trim((string) ($p['source'] ?? 'webhook'));
            $system = mb_substr($source, 0, 40);
            $severity = self::severity($p['severity'] ?? 3);
            $recovery = self::isRecovery($p);
            $key = IntegrationKey::webhook(trim((string) ($p['dedup_key'] ?? '')), $source, (string) ($p['trigger_id'] ?? $title), $host);

            if ($recovery) {
                foreach (self::openByKey($key) as $e) {
                    try {
                        Escalation::resolve((int) $e['id'], 'evento recuperado');
                    } catch (RuntimeException $exception) {
                        if ($exception->getCode() === 409) {
                            return [200, ['ok' => true, 'action' => 'ignored']];
                        }
                        throw $exception;
                    }
                    Log::write($e, 'webhook', ['status' => 'ok', 'actor' => 'webhook', 'message' => 'Recuperação recebida de ' . $system . ($host ? ' (' . $host . ')' : '') . '.', 'detail' => self::safe($p)]);
                    Dispatcher::flush((int) $e['id']);
                    return [200, ['ok' => true, 'action' => 'resolved', 'trace_id' => $e['trace_id']]];
                }
                Log::write(null, 'webhook', ['status' => 'info', 'actor' => 'webhook', 'message' => 'Recuperação sem escalonamento aberto: ' . $title, 'detail' => self::safe($p)]);
                return [200, ['ok' => true, 'action' => 'ignored']];
            }

            $text = $title . ' ' . $host . ' ' . $message;
            $entity = (int) ($p['entity'] ?? 0);
            if ((int) ($p['policy_id'] ?? 0)) {
                $policy = Policy::get((int) $p['policy_id']);
                $rule = ['id' => 0, 'policies_id' => $policy['id'] ?? 0, 'policy' => $policy];
                if (!$policy || !(int) $policy['is_active']) {
                    return [422, ['ok' => false, 'error' => 'policy_id inválido ou inativo.']];
                }
            } else {
                $rule = Policy::match('event', $severity, $entity, 0, $text);
            }
            if (!$rule) {
                Log::write(null, 'webhook', ['status' => 'warn', 'actor' => 'webhook', 'message' => 'Nenhuma regra de hierarquia casa com o evento: ' . $title . ' (severidade ' . $severity . ').', 'detail' => self::safe($p)]);
                return [202, ['ok' => true, 'action' => 'no_rule']];
            }
            $facts = array_filter([
                'Sistema' => $system, 'Host' => $host, 'Evento' => (string) ($p['event_id'] ?? ''), 'Link' => (string) ($p['url'] ?? ''),
            ] + (is_array($p['tags'] ?? null) ? array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $p['tags']) : []), fn ($v) => $v !== '');
            [$id, $created] = Escalation::open([
                'policies_id' => (int) $rule['policies_id'], 'rules_id' => (int) $rule['id'], 'source' => 'event', 'source_ref' => $system . ($host ? ':' . $host : ''),
                'dedup_key' => $key, 'title' => $title, 'summary' => $message, 'severity' => $severity, 'entities_id' => $entity, 'facts' => $facts, 'actor' => 'webhook',
            ]);
            $e = Escalation::get($id);
            if ($created) {
                Log::write($e, 'webhook', ['status' => 'ok', 'actor' => 'webhook', 'message' => 'Evento recebido de ' . $system . ' e escalonamento aberto.', 'detail' => self::safe($p)]);
                Dispatcher::flush($id);
            }
            return [$created ? 201 : 200, ['ok' => true, 'action' => $created ? 'opened' : 'deduplicated', 'trace_id' => $e['trace_id'], 'id' => $id]];
        } catch (RuntimeException $e) {
            return [422, ['ok' => false, 'error' => $e->getMessage()]];
        } catch (Throwable $e) {
            Log::file('webhook: ' . $e->getMessage());
            return [500, ['ok' => false, 'error' => 'Erro interno ao processar o evento.']];
        }
    }

    private static function severity($v): int
    {
        if (is_numeric($v)) {
            return max(1, min(5, (int) $v));
        }
        return self::SEVERITY_NAMES[mb_strtolower(trim((string) $v))] ?? 3;
    }

    private static function isRecovery(array $p): bool
    {
        $s = mb_strtolower(trim((string) ($p['status'] ?? $p['event_status'] ?? '')));
        if ($s !== '') {
            return in_array($s, ['ok', 'resolved', 'recovery', 'recovered', 'closed', 'normal'], true);
        }
        if (array_key_exists('value', $p)) { // Zabbix {EVENT.VALUE}: 1 = problema, 0 = recuperado
            return (string) $p['value'] === '0';
        }
        return false;
    }

    /** @return list<array> */
    private static function openByKey(string $key): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['dedup_key' => $key, 'state' => ['open', 'acked']], 'LIMIT' => 1]), false);
    }

    /** Payload limitado para o log (sem segredos, no máximo 20 campos). */
    private static function safe(array $p): array
    {
        return array_slice($p, 0, 20, true);
    }
}
