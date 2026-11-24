<?php

use GlpiPlugin\Escalations\Cron;
use GlpiPlugin\Escalations\Drawer;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Slo;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard(false, false, 'slo');
Menu::header('SLO e SLA', 'slo');

[$rows, $tot] = Slo::overview();
$sla = Slo::ticketSla(max(1, min(365, (int) ($_GET['dias'] ?? 30))));
$risk = Slo::atRisk(50);
$h = Cron::health();
$pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '') . '%';
$compColor = fn ($v, $target = 95.0) => $v === null ? '#94a3b8' : ($v >= $target ? '#22c55e' : ($v >= $target - 5 ? '#f59e0b' : '#ef4444'));

echo "<div class='esc-dash'>";
echo Ui::title('SLO e SLA', 'SLO: cumprimento dos prazos de ACK e de resolução dos escalonamentos. SLA: prazo de solução dos chamados do GLPI. O orçamento de erro mostra quanto da tolerância ainda sobra.',
    Ui::button('Hierarquias', Menu::url('/front/hierarquias.php'), 'ti-binary-tree-2', 'btn-outline-secondary'));
echo Views::engineBanner();

// indicadores
$riskItems = [];
foreach ($risk as $e) {
    $sev = Escalation::severity((int) $e['severity']);
    $why = array_filter([$e['ack_breached'] ? 'ACK estourado' : ($e['ack_warned'] ? 'ACK em risco' : ''), $e['resolve_breached'] ? 'resolução estourada' : ($e['resolve_warned'] ? 'resolução em risco' : '')]);
    $riskItems[] = [$e['title'], $e['trace_id'] . ' · ' . implode(' · ', $why), Ui::badge($sev[0], $sev[1]), Escalation::url((int) $e['id']), ($e['ack_breached'] || $e['resolve_breached']) ? '#ef4444' : '#f59e0b'];
}
Drawer::add('risk', 'SLO em risco ou estourado agora', 'ti-flame', '#ef4444', Drawer::list($riskItems, 'Escalonamentos ativos que passaram do limite de alerta ou já estouraram o prazo.', 'Escalonamentos', (string) count($risk), 'Nenhum SLO em risco agora.'));
$entItems = [];
foreach (array_slice($sla['by_entity'], 0, 15, true) as $ent => $n) {
    $entItems[] = [Ui::entities()[$ent] ?? ('Entidade #' . $ent), $n . ' chamado(s) fora do prazo', '', '', '#ef4444'];
}
Drawer::add('sla', 'Chamados fora do SLA por entidade', 'ti-ticket', '#ef4444', Drawer::list($entItems, 'Chamados com prazo de solução que não cumpriram, por entidade (janela de ' . $sla['days'] . ' dias).', 'Fora do prazo', (string) $sla['breached'], 'Nenhum chamado fora do prazo.'));

echo "<div class='esc-kpis'>"
    . Ui::kpi('Cumprimento do ACK', $pct($tot['compliance_ack']), 'todas as hierarquias · janela de cada uma', $compColor($tot['compliance_ack']))
    . Ui::kpi('Cumprimento da resolução', $pct($tot['compliance_res']), $tot['total'] . ' escalonamento(s) na janela', $compColor($tot['compliance_res']))
    . Ui::kpi('Tempo médio de ACK', Ui::dur($tot['mtta']), 'MTTA', '#14b8a6')
    . Ui::kpi('Tempo médio de resolução', Ui::dur($tot['mttr']), 'MTTR', '#6366f1')
    . Ui::kpi('Em risco / estourado agora', (string) count($risk), 'escalonamentos ativos', count($risk) ? '#ef4444' : '#22c55e', 'risk')
    . Ui::kpi('SLA dos chamados', $pct($sla['compliance']), $sla['total'] . ' chamado(s) com prazo · ' . $sla['days'] . ' dias', $compColor($sla['compliance']), 'sla')
    . Ui::kpi('Motor (cron)', $h['ok'] ? 'ativo' : 'PARADO', $h['last'] !== '' ? 'último ciclo ' . Ui::dt($h['last']) . ' (' . $h['ms'] . ' ms)' : 'nunca rodou', $h['ok'] ? '#22c55e' : '#ef4444')
    . '</div>';

// por hierarquia
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>SLO por hierarquia</h3></div><div class='table-responsive'><table class='table table-vcenter card-table'>"
    . "<thead><tr><th>Hierarquia</th><th>Meta / janela</th><th>ACK (≤ prazo)</th><th>Resolução (≤ prazo)</th><th>MTTA</th><th>MTTR</th><th style='min-width:220px'>Orçamento de erro</th><th>Burn 24 h</th><th>Situação</th></tr></thead><tbody>";
foreach ($rows as $s) {
    $p = $s['policy'];
    [$label, $color] = Slo::STATUS[$s['status']];
    $rem = min($s['ack']['remaining'], $s['res']['remaining']);
    $worstIsAck = $s['ack']['remaining'] <= $s['res']['remaining'];
    $b = $worstIsAck ? $s['ack'] : $s['res'];
    $burn = max($s['ack']['burn'], $s['res']['burn']);
    echo '<tr><td><a href="' . Ui::e(Menu::url('/front/hierarquia.php?id=' . (int) $p['id'])) . '">' . Ui::e($p['name']) . '</a><div class="small text-secondary">' . Ui::e($p['area']) . ((int) $p['is_active'] ? '' : ' · inativa') . '</div></td>'
        . '<td>' . Ui::e(number_format((float) $p['slo_target'], 1, ',', '')) . '%<div class="small text-secondary">' . (int) $p['slo_window_days'] . ' dias · ' . $s['total'] . ' escalonamento(s)</div></td>'
        . '<td>' . $pct($s['ack']['compliance']) . '<div class="small text-secondary">≤ ' . (int) $p['ack_slo_min'] . ' min · ' . $s['ack_breaches'] . ' estourado(s)' . ($s['ack_warn'] ? ' · ' . $s['ack_warn'] . ' em risco' : '') . '</div></td>'
        . '<td>' . $pct($s['res']['compliance']) . '<div class="small text-secondary">≤ ' . (int) $p['resolve_slo_min'] . ' min · ' . $s['res_breaches'] . ' estourado(s)' . ($s['res_warn'] ? ' · ' . $s['res_warn'] . ' em risco' : '') . '</div></td>'
        . '<td>' . Ui::e(Ui::dur($s['mtta'])) . '</td><td>' . Ui::e(Ui::dur($s['mttr'])) . '</td>'
        . '<td>' . ($s['total'] ? Ui::bar(max(0, $rem), $color) . '<div class="small text-secondary">' . Ui::e(Slo::remainingLabel((float) $rem)) . ' · ' . (int) $b['consumed'] . ' de ' . Ui::e(number_format($b['allowed'], 1, ',', '')) . ' permitidos</div>' : '<span class="text-secondary">sem dados</span>') . '</td>'
        . '<td>' . ($s['total24'] ? Ui::badge(number_format($burn, 1, ',', '') . '×', $burn > 1 ? '#ef4444' : ($burn > 0.5 ? '#f59e0b' : '#22c55e')) : '<span class="text-secondary">—</span>') . '</td>'
        . '<td>' . Ui::badge($label, $color) . '</td></tr>';
}
echo $rows ? '' : "<tr><td colspan='9' class='text-center text-secondary'>Nenhuma hierarquia cadastrada.</td></tr>";
echo '</tbody></table></div><div class="card-footer small text-secondary">Orçamento de erro = tolerância (100% − meta) × total de escalonamentos. Burn 24 h acima de 1× significa que o orçamento está sendo gasto mais rápido do que a janela comporta.</div></div>';

// tendência diária (histórico gravado pela rotina diária)
$hasHist = false;
$histHtml = "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Tendência (últimos 14 dias)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Hierarquia</th><th>Dia</th><th>ACK</th><th>Resolução</th><th>MTTA</th><th>MTTR</th><th>Orçamento restante</th></tr></thead><tbody>";
foreach (Policy::all() as $p) {
    foreach (array_reverse(Slo::history((int) $p['id'], 14)) as $r) {
        $hasHist = true;
        $histHtml .= '<tr><td>' . Ui::e($p['name']) . '</td><td>' . Ui::e(Ui::date($r['snap_date'])) . '</td><td>' . Ui::e(number_format((float) $r['ack_pct'], 1, ',', '')) . '%</td><td>' . Ui::e(number_format((float) $r['res_pct'], 1, ',', ''))
            . '%</td><td>' . Ui::e(Ui::dur((int) $r['mtta_s'] ?: null)) . '</td><td>' . Ui::e(Ui::dur((int) $r['mttr_s'] ?: null)) . '</td><td>' . Ui::bar(max(0, (float) $r['budget_pct']), (float) $r['budget_pct'] > 50 ? '#22c55e' : ((float) $r['budget_pct'] > 0 ? '#f59e0b' : '#ef4444')) . '</td></tr>';
    }
}
echo $hasHist ? $histHtml . '</tbody></table></div></div>' : "<div class='card card-body mb-3 text-secondary'>O histórico diário começa a ser gravado pela ação automática <b>escalationsdaily</b>.</div>";

// SLA dos chamados
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>SLA dos chamados do GLPI</h3><div class='card-actions'><form method='get' class='d-flex gap-2'>"
    . Ui::select('dias', [7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 180 => '180 dias'], $sla['days'], 'data-autosubmit') . "</form></div></div><div class='card-body'><div class='esc-kpis esc-kpis-sm'>"
    . Ui::kpi('Com prazo', (string) $sla['total'], 'chamados na janela', '#6366f1')
    . Ui::kpi('Dentro do prazo', (string) $sla['met'], $pct($sla['compliance']), '#22c55e')
    . Ui::kpi('Fora do prazo', (string) $sla['breached'], 'estourados', '#ef4444', 'sla')
    . Ui::kpi('Abertos em risco', (string) $sla['open_risk'], '≥ 80% do prazo gasto', '#f59e0b')
    . "</div><div class='small text-secondary mt-2'>Lê o prazo de solução (SLA) nativo do GLPI. Para agir antes de estourar, crie na hierarquia uma regra com gatilho de SLA (ex.: escalar ao consumir 80% do prazo).</div></div></div>";
echo '</div>';
Menu::footer();
