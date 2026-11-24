<?php

namespace GlpiPlugin\Escalations;

use Throwable;

/**
 * Observa o que tem prazo além do chamado:
 *  - nativos do GLPI: problemas e mudanças (prazo de solução), projetos e tarefas de projeto (data final prevista);
 *  - itens de plugins acompanhados com Api::track() (prazo informado pelo plugin).
 * Abre o escalonamento ao consumir X% do prazo (X vem da regra, "sla_pct") e fecha quando o item é concluído.
 */
class ItemWatcher
{
    /** table, begin, end: campos de datas; label; url; prio: usa o campo priority. */
    private const DEFS = [
        'problem'     => ['table' => 'glpi_problems', 'begin' => 'date', 'end' => 'time_to_resolve', 'label' => 'Problema', 'url' => '/front/problem.form.php?id=', 'prio' => true, 'cat' => true],
        'change'      => ['table' => 'glpi_changes', 'begin' => 'date', 'end' => 'time_to_resolve', 'label' => 'Mudança', 'url' => '/front/change.form.php?id=', 'prio' => true, 'cat' => true],
        'project'     => ['table' => 'glpi_projects', 'begin' => 'plan_start_date', 'end' => 'plan_end_date', 'label' => 'Projeto', 'url' => '/front/project.form.php?id=', 'prio' => true, 'cat' => false],
        'projecttask' => ['table' => 'glpi_projecttasks', 'begin' => 'plan_start_date', 'end' => 'plan_end_date', 'label' => 'Tarefa de projeto', 'url' => '/front/projecttask.form.php?id=', 'prio' => false, 'cat' => false],
    ];

    public static function scan(): int
    {
        $n = 0;
        foreach (['autoClose', 'openNative', 'openTracked'] as $step) {
            try {
                $n += self::$step();
            } catch (Throwable $e) {
                Log::file('ItemWatcher::' . $step . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Fecha o escalonamento de item nativo concluído, excluído ou cancelado. */
    private static function autoClose(): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['source' => array_keys(self::DEFS), 'state' => ['open', 'acked']], 'LIMIT' => 500]) as $e) {
            try {
                $n += (int) Escalation::withLock((int) $e['id'], NativeItem::close(...), 0);
            } catch (Throwable $x) {
                Log::file('autoClose ' . $e['trace_id'] . ': ' . $x->getMessage());
            }
        }
        return $n;
    }

    private static function openNative(): int
    {
        global $DB;
        $n = 0;
        $active = array_keys(Policy::all(true));
        if (!$active) {
            return 0;
        }
        $finishedStates = null;
        foreach (self::DEFS as $source => $def) {
            if (!Settings::sourceOn($source)) {
                continue;
            }
            $rules = iterator_to_array($DB->request(['FROM' => Policy::RULES, 'WHERE' => ['is_active' => 1, 'policies_id' => $active, 'source' => [$source, 'any'], ['NOT' => ['sla_pct' => 0]]], 'ORDER' => ['severity_min DESC', 'id']]), false);
            $seen = [];
            foreach ($rules as $r) {
                $where = ['is_deleted' => 0, ['NOT' => [$def['end'] => null]]];
                if ($source === 'problem' || $source === 'change') {
                    $where[] = ['NOT' => ['status' => NativeItem::terminalStatuses($source)]];
                } else {
                    $where['percent_done'] = ['<', 100];
                    $where['is_template'] = 0;
                    $finishedStates ??= array_column(iterator_to_array($DB->request([
                        'SELECT' => 'id', 'FROM' => 'glpi_projectstates', 'WHERE' => ['is_finished' => 1],
                    ]), false), 'id');
                    if ($finishedStates) {
                        $where[] = ['NOT' => ['projectstates_id' => $finishedStates]];
                    }
                }
                if ((int) $r['entities_id'] >= 0) {
                    $where['entities_id'] = array_map('intval', array_values(getSonsOf('glpi_entities', (int) $r['entities_id'])));
                }
                if ($def['cat'] && (int) $r['itilcategories_id']) {
                    $where['itilcategories_id'] = (int) $r['itilcategories_id'];
                }
                if ($def['prio'] && (int) $r['severity_min'] > 1) {
                    $where['priority'] = ['>=', (int) $r['severity_min']];
                }
                foreach ($DB->request(['FROM' => $def['table'], 'WHERE' => $where, 'LIMIT' => 300]) as $t) {
                    $id = (int) $t['id'];
                    if (isset($seen[$id]) || ($r['match_text'] !== '' && stripos((string) $t['name'], $r['match_text']) === false)) {
                        continue;
                    }
                    $begin = strtotime((string) ($t[$def['begin']] ?: $t['date_creation'] ?: 'now'));
                    $due = strtotime((string) $t[$def['end']]);
                    $pct = self::pct($begin, $due);
                    if ($pct < (int) $r['sla_pct']) {
                        continue;
                    }
                    $seen[$id] = true;
                    if (self::reopenGuard($source, $id, (int) $r['policies_id'])) {
                        continue;
                    }
                    $sev = $def['prio'] ? TicketWatcher::severityOf((int) $t['priority']) : 3;
                    $why = time() >= $due ? 'O prazo final estourou há ' . Ui::dur(time() - $due) . '.' : 'Já consumiu ' . round($pct) . '% do prazo; restam ' . Ui::dur($due - time()) . '.';
                    try {
                        [, $created] = Escalation::open([
                            'policies_id' => (int) $r['policies_id'], 'rules_id' => (int) $r['id'], 'source' => $source, 'source_ref' => $source . ':' . $id, 'items_id' => $id, 'dedup_key' => $source . ':' . $id,
                            'title' => $def['label'] . ' #' . $id . ' — ' . $t['name'], 'summary' => $why, 'severity' => $sev, 'entities_id' => (int) $t['entities_id'], 'facts' => self::facts($def, $t, $due), 'actor' => 'system',
                        ]);
                        $n += $created ? 1 : 0;
                    } catch (Throwable $x) {
                        Log::file('open ' . $source . ' ' . $id . ': ' . $x->getMessage());
                    }
                }
            }
        }
        return $n;
    }

    /** Itens de plugins acompanhados com Api::track(). */
    private static function openTracked(): int
    {
        global $DB;
        $n = 0;
        $cache = [];
        if (!Settings::sourceOn('plugin')) {
            return 0;
        }
        foreach ($DB->request(['FROM' => Api::TRACKED, 'WHERE' => ['status' => 'active'], 'LIMIT' => 1000]) as $t) {
            $criteria = ['plugin', (int) $t['severity'], (int) $t['entities_id'], 0, (string) $t['title'], $t['plugin_key'], $t['type_key']];
            $k = serialize($criteria);
            if (!array_key_exists($k, $cache)) {
                $cache[$k] = Policy::match(...$criteria);
            }
            $rule = $cache[$k];
            if (!$rule) {
                continue;
            }
            $threshold = (int) $rule['sla_pct'] ?: 100; // sem percentual na regra, abre ao vencer
            $begin = $t['start_at'] ? strtotime($t['start_at']) : strtotime((string) $t['date_creation']);
            $due = strtotime((string) $t['due_at']);
            $pct = self::pct($begin, $due);
            if ($pct < $threshold) {
                continue;
            }
            try {
                $facts = (json_decode((string) $t['facts'], true) ?: []) + ['Prazo' => Ui::dt($t['due_at'])];
                if ($t['url'] !== '') {
                    $facts['Abrir'] = preg_match('#^https?://#', $t['url']) ? $t['url'] : Ui::absUrl($t['url']);
                }
                $why = (time() >= $due ? 'O prazo estourou há ' . Ui::dur(time() - $due) . '.' : 'Já consumiu ' . round($pct) . '% do prazo; restam ' . Ui::dur($due - time()) . '.') . ($t['summary'] ? "\n" . $t['summary'] : '');
                [$id] = Escalation::open([
                    'policies_id' => (int) $rule['policies_id'], 'rules_id' => (int) $rule['id'], 'source' => 'plugin', 'source_ref' => mb_substr($t['plugin_key'] . ':' . $t['type_key'] . ':' . $t['ref_key'], 0, 100),
                    'dedup_key' => Api::trackedDedup($t['plugin_key'], $t['type_key'], $t['ref_key']), 'title' => $t['title'], 'summary' => $why, 'severity' => (int) $t['severity'], 'entities_id' => (int) $t['entities_id'], 'facts' => $facts, 'actor' => 'plugin',
                ]);
                $DB->update(Api::TRACKED, ['status' => 'opened', 'escalations_id' => $id, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $t['id']]);
                $n++;
            } catch (Throwable $x) {
                Log::file('openTracked ' . $t['id'] . ': ' . $x->getMessage());
            }
        }
        return $n;
    }

    /** Porcentagem do prazo já consumida (100 ou mais se estourou). */
    public static function pct(int $begin, int $due): float
    {
        $now = time();
        if ($due <= $begin) {
            return $now >= $due ? 100.0 : 0.0;
        }
        return max(0.0, ($now - $begin) / ($due - $begin) * 100);
    }

    /** Alguém resolveu o escalonamento deste item há menos de 24 h e ele continua atrasado: não reabre em ciclo. */
    private static function reopenGuard(string $source, int $id, int $policyId): bool
    {
        return (bool) countElementsInTable(Escalation::TABLE, ['source' => $source, 'items_id' => $id, 'policies_id' => $policyId, 'opened_at' => ['>=', date('Y-m-d H:i:s', time() - 86400)]])
            && !countElementsInTable(Escalation::TABLE, ['source' => $source, 'items_id' => $id, 'state' => ['open', 'acked']]);
    }

    private static function facts(array $def, array $t, int $due): array
    {
        $facts = [
            'Tipo' => $def['label'],
            'Entidade' => Ui::entities()[(int) $t['entities_id']] ?? '—',
            'Prazo final' => Ui::dt(date('Y-m-d H:i:s', $due)),
        ];
        if (isset($t['percent_done'])) {
            $facts['Concluído'] = (int) $t['percent_done'] . '%';
        }
        if (!empty($t['users_id']) && (int) $t['users_id']) {
            $facts['Responsável'] = Ui::userName((int) $t['users_id']);
        }
        if (!empty($t['priority'])) {
            $facts['Prioridade'] = \Ticket::getPriorityName((int) $t['priority']);
        }
        $facts['Abrir'] = Ui::absUrl($def['url'] . (int) $t['id']);
        return $facts;
    }
}
