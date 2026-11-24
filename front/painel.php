<?php

use GlpiPlugin\Escalations\Drawer;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Slo;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard();
Menu::header('Painel de escalonamentos', 'painel');
global $DB;

$vis = Escalation::visibility();
$active = iterator_to_array($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => array_merge(['state' => ['open', 'acked']], $vis), 'ORDER' => ['severity DESC', 'opened_at'], 'LIMIT' => 300]), false);
$recent = iterator_to_array($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => array_merge(['state' => ['resolved'], 'resolved_at' => ['>=', date('Y-m-d H:i:s', time() - 86400)]], $vis), 'ORDER' => ['resolved_at DESC'], 'LIMIT' => 10]), false);
$open = array_filter($active, fn ($e) => $e['state'] === 'open');
$acked = array_filter($active, fn ($e) => $e['state'] === 'acked');
$risk = array_filter($active, fn ($e) => ($e['ack_warned'] || $e['resolve_warned']) && !$e['ack_breached'] && !$e['resolve_breached']);
$breach = array_filter($active, fn ($e) => $e['ack_breached'] || $e['resolve_breached']);
[$policies, $tot] = Slo::overview();

$failed = iterator_to_array($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['status' => 'failed', 'next_try' => null, 'date_mod' => ['>=', date('Y-m-d H:i:s', time() - 86400)]], 'ORDER' => ['id DESC'], 'LIMIT' => 30]), false);

/** Lista de escalonamentos para uma gaveta de indicador. */
$list = function (array $rows, string $lead): string {
    $items = [];
    foreach ($rows as $e) {
        $sev = Escalation::severity((int) $e['severity']);
        $t = Escalation::timing($e);
        $items[] = [$e['title'], $e['trace_id'] . ' · N' . (int) $e['level'] . ' · aberto há ' . Ui::dur($t['age']), Ui::badge($sev[0], $sev[1]), Escalation::url((int) $e['id']), $sev[1]];
    }
    return Drawer::list($items, $lead, 'Escalonamentos', (string) count($rows), 'Nada nesta condição.');
};
Drawer::add('open', 'Aguardando ACK', 'ti-bell-ringing', '#ef4444', $list(array_values($open), 'Ninguém assumiu ainda. Sem ACK, sobem de nível no prazo da hierarquia.'));
Drawer::add('acked', 'Reconhecidos', 'ti-circle-check', '#3b82f6', $list(array_values($acked), 'Alguém assumiu; a escalada parou, mas o SLO de resolução continua contando.'));
Drawer::add('risk', 'SLO em risco', 'ti-alert-triangle', '#f59e0b', $list(array_values($risk), 'Já passaram do limite de alerta do prazo e ainda não estouraram.'));
Drawer::add('breach', 'SLO estourado', 'ti-flame', '#ef4444', $list(array_values($breach), 'Estouraram o prazo de ACK ou de resolução.'));
$failItems = [];
foreach ($failed as $d) {
    $failItems[] = [$d['trace_id'] . ' · ' . $d['channel'], (string) $d['error'], Ui::e(Ui::dt($d['date_mod'])), Menu::url('/front/logs.php?trace=' . urlencode($d['trace_id']) . '&status=fail'), '#ef4444'];
}
Drawer::add('fail', 'Falhas de entrega (24 h)', 'ti-mail-exclamation', '#ef4444', Drawer::list($failItems, 'Envios que esgotaram as tentativas. O canal reserva foi acionado quando configurado.', 'Falhas', (string) count($failed), 'Nenhuma falha nas últimas 24 horas.'));

$actions = (Escalation::canCreate() ? Ui::button('Escalar agora', Menu::url('/front/escalonamento.php?novo=1'), 'ti-urgent') : '')
    . Ui::button('SLO e SLA', Menu::url('/front/slo.php'), 'ti-gauge', 'btn-outline-secondary');
echo "<div class='esc-dash'>";
echo Ui::title('Painel de escalonamentos', 'O que está aguardando ACK agora, o que está subindo de nível e como estão os SLOs.', $actions);
echo Views::engineBanner();

echo "<div class='esc-kpis'>"
    . Ui::kpi('Aguardando ACK', (string) count($open), 'ninguém assumiu', '#ef4444', 'open')
    . Ui::kpi('Reconhecidos', (string) count($acked), 'em atendimento', '#3b82f6', 'acked')
    . Ui::kpi('SLO em risco', (string) count($risk), 'perto de estourar', '#f59e0b', 'risk')
    . Ui::kpi('SLO estourado', (string) count($breach), 'ativos fora do prazo', '#ef4444', 'breach')
    . Ui::kpi('Tempo médio de ACK', Ui::dur($tot['mtta']), 'média das hierarquias (janela)', '#14b8a6')
    . Ui::kpi('Falhas de entrega', (string) count($failed), 'últimas 24 h', count($failed) ? '#ef4444' : '#22c55e', 'fail')
    . '</div>';

// SLO por hierarquia (resumo)
if ($policies) {
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>SLO por hierarquia</h3><div class='card-actions'>" . Ui::button('Detalhes', Menu::url('/front/slo.php'), 'ti-gauge', 'btn-sm btn-outline-secondary') . '</div></div>'
        . "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr><th>Hierarquia</th><th>ACK</th><th>Resolução</th><th style='min-width:180px'>Orçamento de erro</th><th>Situação</th></tr></thead><tbody>";
    foreach ($policies as $s) {
        $p = $s['policy'];
        [$label, $color] = Slo::STATUS[$s['status']];
        $rem = min($s['ack']['remaining'], $s['res']['remaining']);
        echo '<tr><td><a href="' . Ui::e(Menu::url('/front/hierarquia.php?id=' . (int) $p['id'])) . '">' . Ui::e($p['name']) . '</a><div class="small text-secondary">' . Ui::e($p['area']) . '</div></td>'
            . '<td>' . ($s['ack']['compliance'] === null ? '—' : Ui::e(number_format($s['ack']['compliance'], 1, ',', '')) . '%') . ' <span class="small text-secondary">meta ' . Ui::e(number_format((float) $p['slo_target'], 0)) . '%</span></td>'
            . '<td>' . ($s['res']['compliance'] === null ? '—' : Ui::e(number_format($s['res']['compliance'], 1, ',', '')) . '%') . '</td>'
            . '<td>' . ($s['total'] ? Ui::bar(max(0, $rem), $color) . '<span class="small text-secondary">' . Ui::e(Slo::remainingLabel((float) $rem)) . '</span>' : '<span class="text-secondary">—</span>') . '</td>'
            . '<td>' . Ui::badge($label, $color) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

echo "<div class='card card-body mb-2'><b>Escalonamentos ativos</b><div class='small text-secondary'>Clique numa linha para ver o resumo e abrir a ficha.</div></div>";
$groups = Views::groups(array_merge($active, $recent));
echo Ui::mainTable(Views::COLS, $groups, 'Nenhum escalonamento ativo. Tudo calmo.');
echo '</div>';
Menu::footer();
