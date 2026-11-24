<?php

namespace GlpiPlugin\Escalations;

/**
 * Monitor de SLO (ACK e resolução dos escalonamentos) e de SLA (chamados do GLPI).
 *
 * SLO = meta de cumprimento (ex.: 95% dos escalonamentos com ACK em até 15 min) numa janela móvel de dias.
 * Orçamento de erro = quanto da tolerância (100% − meta) ainda sobra; "burn rate" = velocidade em que ele está sendo
 * gasto agora (24 h) em relação ao ritmo que esgotaria o orçamento na janela: acima de 1 é gasto rápido demais.
 */
class Slo
{
    public const SNAPSHOTS = 'glpi_plugin_escalations_slo_snapshots';

    /** Estatísticas de uma hierarquia na janela. */
    public static function stats(array $policy, ?int $days = null): array
    {
        global $DB;
        $days ??= (int) $policy['slo_window_days'];
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $since24 = date('Y-m-d H:i:s', time() - 86400);
        $s = ['policy' => $policy, 'days' => $days, 'total' => 0, 'open' => 0, 'acked' => 0, 'resolved' => 0, 'ack_breaches' => 0, 'res_breaches' => 0, 'ack_warn' => 0, 'res_warn' => 0,
            'mtta' => null, 'mttr' => null, 'total24' => 0, 'ack_breaches24' => 0, 'res_breaches24' => 0];
        $ackSum = $resSum = $ackN = $resN = 0;
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['policies_id' => $policy['id'], 'opened_at' => ['>=', $since], ['NOT' => ['state' => 'cancelled']]], 'LIMIT' => 20000]) as $e) {
            $s['total']++;
            $recent = $e['opened_at'] >= $since24;
            $s['total24'] += $recent ? 1 : 0;
            $s['open'] += in_array($e['state'], ['open', 'acked'], true) ? 1 : 0;
            $s['ack_breaches'] += (int) $e['ack_breached'];
            $s['res_breaches'] += (int) $e['resolve_breached'];
            $s['ack_warn'] += (int) $e['ack_warned'] && !(int) $e['ack_breached'] ? 1 : 0;
            $s['res_warn'] += (int) $e['resolve_warned'] && !(int) $e['resolve_breached'] ? 1 : 0;
            if ($recent) {
                $s['ack_breaches24'] += (int) $e['ack_breached'];
                $s['res_breaches24'] += (int) $e['resolve_breached'];
            }
            if ($e['acked_at']) {
                $s['acked']++;
                $ackSum += strtotime($e['acked_at']) - strtotime($e['opened_at']);
                $ackN++;
            }
            if ($e['state'] === 'resolved' && $e['resolved_at']) {
                $s['resolved']++;
                $resSum += strtotime($e['resolved_at']) - strtotime($e['opened_at']);
                $resN++;
            }
        }
        $s['mtta'] = $ackN ? (int) round($ackSum / $ackN) : null;
        $s['mttr'] = $resN ? (int) round($resSum / $resN) : null;
        $target = (float) $policy['slo_target'];
        $s['ack'] = self::budget($s['total'], $s['ack_breaches'], $target, $s['total24'], $s['ack_breaches24']);
        $s['res'] = self::budget($s['total'], $s['res_breaches'], $target, $s['total24'], $s['res_breaches24']);
        $worst = min($s['ack']['remaining'], $s['res']['remaining']);
        $s['status'] = $s['total'] === 0 ? 'sem' : ($worst <= 0 ? 'estourado' : ($worst <= 50 || max($s['ack']['burn'], $s['res']['burn']) > 1 ? 'atencao' : 'ok'));
        return $s;
    }

    public const STATUS = ['ok' => ['Dentro da meta', '#22c55e'], 'atencao' => ['Atenção', '#f59e0b'], 'estourado' => ['Meta estourada', '#ef4444'], 'sem' => ['Sem dados', '#94a3b8']];

    /** Conformidade e orçamento de erro. */
    public static function budget(int $total, int $breaches, float $target, int $total24, int $breaches24): array
    {
        $tolerance = max(0.0, 1 - $target / 100);
        $allowed = $tolerance * $total;
        $remaining = $total === 0 ? 100.0 : ($allowed > 0 ? (1 - $breaches / $allowed) * 100 : ($breaches > 0 ? -100.0 : 100.0));
        $rate24 = $total24 ? $breaches24 / $total24 : 0.0;
        return [
            'compliance' => $total ? round((1 - $breaches / $total) * 100, 2) : null,
            'allowed'    => round($allowed, 2),
            'consumed'   => $breaches,
            'remaining'  => max(-100.0, round($remaining, 1)),
            'burn'       => $tolerance > 0 ? round($rate24 / $tolerance, 2) : ($breaches24 ? 99.0 : 0.0),
        ];
    }

    /**
     * Texto do orçamento para as telas. "Restante" só faz sentido enquanto há saldo;
     * com saldo zero ou negativo o orçamento está esgotado.
     */
    public static function remainingLabel(float $remaining): string
    {
        return $remaining > 0 ? number_format($remaining, 0, ',', '') . '% restante' : 'esgotado';
    }

    /** Todas as hierarquias ativas com as estatísticas, mais os totais. */
    public static function overview(): array
    {
        $rows = [];
        $t = ['total' => 0, 'open' => 0, 'ack_breaches' => 0, 'res_breaches' => 0, 'ack_warn' => 0, 'res_warn' => 0, 'mtta' => [], 'mttr' => []];
        foreach (Policy::all() as $p) {
            $s = self::stats($p);
            $rows[] = $s;
            $t['total'] += $s['total'];
            $t['open'] += $s['open'];
            $t['ack_breaches'] += $s['ack_breaches'];
            $t['res_breaches'] += $s['res_breaches'];
            $t['ack_warn'] += $s['ack_warn'];
            $t['res_warn'] += $s['res_warn'];
            if ($s['mtta'] !== null) {
                $t['mtta'][] = $s['mtta'];
            }
            if ($s['mttr'] !== null) {
                $t['mttr'][] = $s['mttr'];
            }
        }
        $t['mtta'] = $t['mtta'] ? (int) round(array_sum($t['mtta']) / count($t['mtta'])) : null;
        $t['mttr'] = $t['mttr'] ? (int) round(array_sum($t['mttr']) / count($t['mttr'])) : null;
        $t['compliance_ack'] = $t['total'] ? round((1 - $t['ack_breaches'] / $t['total']) * 100, 1) : null;
        $t['compliance_res'] = $t['total'] ? round((1 - $t['res_breaches'] / $t['total']) * 100, 1) : null;
        return [$rows, $t];
    }

    /** Escalonamentos ativos com SLO em risco ou estourado. */
    public static function atRisk(int $limit = 50): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['state' => ['open', 'acked'],
            ['OR' => ['ack_warned' => 1, 'ack_breached' => 1, 'resolve_warned' => 1, 'resolve_breached' => 1]]], 'ORDER' => ['opened_at'], 'LIMIT' => $limit]), false);
    }

    /** SLA dos chamados do GLPI na janela: cumprido, estourado e em risco (abertos com prazo). */
    public static function ticketSla(int $days = 30): array
    {
        global $DB;
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $now = date('Y-m-d H:i:s');
        $r = ['days' => $days, 'total' => 0, 'met' => 0, 'breached' => 0, 'open_risk' => 0, 'by_entity' => []];
        foreach ($DB->request(['SELECT' => ['id', 'entities_id', 'status', 'date', 'solvedate', 'closedate', 'time_to_resolve'], 'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0, 'date' => ['>=', $since], ['NOT' => ['time_to_resolve' => null]]], 'LIMIT' => 20000]) as $t) {
            $r['total']++;
            $due = $t['time_to_resolve'];
            $end = $t['solvedate'] ?: $t['closedate'];
            $done = (int) $t['status'] >= 5 && $end;
            $late = $done ? $end > $due : $now > $due;
            if ($late) {
                $r['breached']++;
                $r['by_entity'][(int) $t['entities_id']] = ($r['by_entity'][(int) $t['entities_id']] ?? 0) + 1;
            } else {
                $r['met']++;
                if (!$done) {
                    $begin = strtotime((string) $t['date']);
                    $span = strtotime((string) $due) - $begin;
                    if ($span > 0 && (time() - $begin) / $span >= 0.8) {
                        $r['open_risk']++;
                    }
                }
            }
        }
        arsort($r['by_entity']);
        $r['compliance'] = $r['total'] ? round($r['met'] / $r['total'] * 100, 1) : null;
        return $r;
    }

    // ------------------------------------------------------------------ histórico (um registro por dia e hierarquia)

    public static function snapshot(): int
    {
        global $DB;
        $n = 0;
        $today = date('Y-m-d');
        foreach (Policy::all() as $p) {
            $s = self::stats($p);
            $row = ['snap_date' => $today, 'policies_id' => (int) $p['id'], 'total' => $s['total'], 'ack_breaches' => $s['ack_breaches'], 'res_breaches' => $s['res_breaches'],
                'mtta_s' => (int) $s['mtta'], 'mttr_s' => (int) $s['mttr'], 'ack_pct' => (float) ($s['ack']['compliance'] ?? 100), 'res_pct' => (float) ($s['res']['compliance'] ?? 100),
                'budget_pct' => min($s['ack']['remaining'], $s['res']['remaining'])];
            if (countElementsInTable(self::SNAPSHOTS, ['snap_date' => $today, 'policies_id' => $p['id']])) {
                $DB->update(self::SNAPSHOTS, $row, ['snap_date' => $today, 'policies_id' => $p['id']]);
            } else {
                $DB->insert(self::SNAPSHOTS, $row);
            }
            $n++;
        }
        return $n;
    }

    public static function history(int $policyId, int $days = 14): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::SNAPSHOTS, 'WHERE' => ['policies_id' => $policyId, 'snap_date' => ['>=', date('Y-m-d', time() - $days * 86400)]], 'ORDER' => ['snap_date']]), false);
    }
}
