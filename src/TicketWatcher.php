<?php

namespace GlpiPlugin\Escalations;

use Throwable;
use Ticket;

/**
 * Fonte "chamado": observa os chamados do GLPI contra as regras das hierarquias e abre o escalonamento quando
 *  - o prazo de solução (SLA) já consumiu X% do tempo (ou estourou), ou
 *  - o chamado está sem responsável atribuído há mais de N minutos.
 * Também encerra o escalonamento quando o chamado é solucionado, fechado ou excluído.
 */
class TicketWatcher
{
    /** Prioridade do GLPI (1 a 6) → severidade do plugin (1 a 5). */
    public static function severityOf(int $priority): int
    {
        return max(1, min(5, $priority));
    }

    public static function scan(): int
    {
        $every = max(1, Settings::int('scan_minutes') ?: 5);
        $last = Settings::get('last_scan');
        if ($last !== '' && time() - strtotime($last) < $every * 60) {
            return 0;
        }
        Settings::set(['last_scan' => date('Y-m-d H:i:s')]);
        return self::autoClose() + (Settings::sourceOn('ticket') ? self::openNew() : 0) + ItemWatcher::scan();
    }

    /** Escalonamento de chamado cujo chamado já foi solucionado, fechado ou excluído. */
    private static function autoClose(): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['source' => 'ticket', 'state' => ['open', 'acked']], 'LIMIT' => 500]) as $e) {
            try {
                $n += (int) Escalation::withLock((int) $e['id'], NativeItem::close(...), 0);
            } catch (Throwable $x) {
                Log::file('autoClose ' . $e['trace_id'] . ': ' . $x->getMessage());
            }
        }
        return $n;
    }

    private static function openNew(): int
    {
        global $DB;
        $n = 0;
        $seen = [];
        $active = array_keys(Policy::all(true));
        if (!$active) {
            return 0;
        }
        $rules = iterator_to_array($DB->request(['FROM' => Policy::RULES, 'WHERE' => ['is_active' => 1, 'policies_id' => $active, 'source' => ['ticket', 'any']], 'ORDER' => ['severity_min DESC', 'id']]), false);
        foreach ($rules as $r) {
            if ((int) $r['sla_pct'] <= 0 && (int) $r['unassigned_min'] <= 0) {
                continue; // regra sem gatilho de chamado (serve só para eventos)
            }
            $where = ['status' => [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING], 'is_deleted' => 0];
            if ((int) $r['entities_id'] >= 0) {
                $where['entities_id'] = array_map('intval', array_values(getSonsOf('glpi_entities', (int) $r['entities_id'])));
            }
            if ((int) $r['itilcategories_id']) {
                $where['itilcategories_id'] = (int) $r['itilcategories_id'];
            }
            if ((int) $r['severity_min'] > 1) {
                $where['priority'] = ['>=', (int) $r['severity_min']];
            }
            foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $where, 'ORDER' => ['priority DESC', 'date'], 'LIMIT' => 300]) as $t) {
                $tid = (int) $t['id'];
                if (isset($seen[$tid]) || ($r['match_text'] !== '' && stripos((string) $t['name'], $r['match_text']) === false)) {
                    continue;
                }
                $why = self::trigger($r, $t);
                if ($why === null) {
                    continue;
                }
                $seen[$tid] = true;
                // já escalou este chamado há pouco e alguém resolveu o escalonamento? Não reabre em ciclo.
                if (countElementsInTable(Escalation::TABLE, ['source' => 'ticket', 'items_id' => $tid, 'policies_id' => $r['policies_id'], 'opened_at' => ['>=', date('Y-m-d H:i:s', time() - 86400)]])
                    && !countElementsInTable(Escalation::TABLE, ['source' => 'ticket', 'items_id' => $tid, 'state' => ['open', 'acked']])) {
                    continue;
                }
                try {
                    [, $created] = Escalation::open([
                        'policies_id' => (int) $r['policies_id'], 'rules_id' => (int) $r['id'], 'source' => 'ticket', 'source_ref' => 'ticket:' . $tid, 'items_id' => $tid,
                        'dedup_key' => 'ticket:' . $tid, 'title' => 'Chamado #' . $tid . ' — ' . $t['name'], 'summary' => $why,
                        'severity' => self::severityOf((int) $t['priority']), 'entities_id' => (int) $t['entities_id'], 'facts' => self::facts($t), 'actor' => 'system',
                    ]);
                    $n += $created ? 1 : 0;
                } catch (Throwable $x) {
                    Log::file('open ticket ' . $tid . ': ' . $x->getMessage());
                }
            }
        }
        return $n;
    }

    /** O chamado dispara a regra? Devolve o motivo ou null. */
    private static function trigger(array $rule, array $t): ?string
    {
        $now = time();
        $begin = strtotime((string) $t['date']);
        if ((int) $rule['sla_pct'] > 0 && !empty($t['time_to_resolve'])) {
            $due = strtotime((string) $t['time_to_resolve']);
            if ($due > $begin) {
                $pct = ($now - $begin) / ($due - $begin) * 100;
                if ($pct >= (int) $rule['sla_pct']) {
                    return $now >= $due
                        ? 'O prazo de solução (SLA) estourou há ' . Ui::dur($now - $due) . '.'
                        : 'O chamado já consumiu ' . round($pct) . '% do prazo de solução; restam ' . Ui::dur($due - $now) . '.';
                }
            }
        }
        if ((int) $rule['unassigned_min'] > 0 && $now - $begin >= (int) $rule['unassigned_min'] * 60) {
            $assigned = countElementsInTable('glpi_tickets_users', ['tickets_id' => $t['id'], 'type' => 2]) + countElementsInTable('glpi_groups_tickets', ['tickets_id' => $t['id'], 'type' => 2]);
            if (!$assigned) {
                return 'O chamado está sem responsável atribuído há ' . Ui::dur($now - $begin) . '.';
            }
        }
        return null;
    }

    private static function facts(array $t): array
    {
        global $DB;
        $requester = '';
        foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $t['id'], 'type' => 1], 'LIMIT' => 1]) as $r) {
            $requester = Ui::userName((int) $r['users_id']);
        }
        $facts = [
            'Chamado' => '#' . $t['id'],
            'Entidade' => Ui::entities()[(int) $t['entities_id']] ?? '—',
            'Prioridade' => \Ticket::getPriorityName((int) $t['priority']),
            'Aberto em' => Ui::dt($t['date']),
            'Prazo de solução' => !empty($t['time_to_resolve']) ? Ui::dt($t['time_to_resolve']) : '—',
        ];
        if ($requester !== '') {
            $facts['Requerente'] = $requester;
        }
        if ((int) $t['itilcategories_id']) {
            $facts['Categoria'] = \Dropdown::getDropdownName('glpi_itilcategories', (int) $t['itilcategories_id']);
        }
        $facts['Chamado no GLPI'] = Ui::absUrl('/front/ticket.form.php?id=' . (int) $t['id']);
        return $facts;
    }
}
