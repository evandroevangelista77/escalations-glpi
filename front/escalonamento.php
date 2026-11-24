<?php

use GlpiPlugin\Escalations\Actions;
use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\Drawer;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Perms;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;

Menu::guard();
global $DB, $CFG_GLPI;
$self = Menu::url('/front/escalonamento.php');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// ------------------------------------------------------------------ abertura manual
if (!$id && !empty($_GET['novo'])) {
    if (!Escalation::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $go = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $policy = (int) ($_POST['policy'] ?? 0);
            if (!$policy || !Policy::get($policy)) {
                throw new RuntimeException('Escolha a hierarquia.', 422);
            }
            if (trim((string) ($_POST['title'] ?? '')) === '') {
                throw new RuntimeException('Informe o título.', 422);
            }
            [$newId] = Escalation::open([
                'policies_id' => $policy, 'source' => 'manual', 'source_ref' => 'manual', 'title' => (string) $_POST['title'], 'summary' => (string) ($_POST['summary'] ?? ''),
                'severity' => (int) ($_POST['severity'] ?? 3), 'facts' => ['Aberto por' => Ui::userName((int) Session::getLoginUserID())], 'actor' => 'user',
            ]);
            Dispatcher::flush($newId);
            Session::addMessageAfterRedirect('Escalonamento aberto. Consulte as entregas do primeiro nível na ficha.', false, INFO);
            $go = $self . '?id=' . $newId;
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $go = $self . '?novo=1';
        }
        Html::redirect($go);
    }
    Menu::header('Escalar agora', 'escalonamentos');
    echo "<div class='esc-dash'>" . Ui::title('Escalar agora', 'Abre um escalonamento manual: o primeiro nível da hierarquia é avisado na hora.');
    $pol = array_column(Policy::all(true), 'name', 'id');
    echo Ui::formOpen($self . '?novo=1', 'card') . "<div class='card-body'><div class='row g-3'>"
        . Ui::field('Hierarquia', Ui::select('policy', $pol, '', 'required', 'Escolha...'), 'col-md-6')
        . Ui::field('Severidade', Ui::select('severity', array_map(fn ($s) => $s[0], Escalation::SEVERITIES), 3), 'col-md-3')
        . Ui::field('Título', Ui::input('title', '', 'text', "required maxlength='255'"), 'col-12')
        . Ui::field('Descrição', "<textarea class='form-control' name='summary' rows='4'></textarea>", 'col-12')
        . "</div></div><div class='card-footer text-end'>" . Ui::submit('Escalar agora', 'go', '1', 'ti-urgent') . '</div>';
    Ui::closeForm();
    echo '</div>';
    Menu::footer();
    return;
}

$e = $id ? Escalation::get($id) : null;
if (!$e || !Escalation::canSee($e)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// ------------------------------------------------------------------ ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $err = '';
    try {
        if (($_POST['do'] ?? '') === 'verify') {
            [$ok, $n, $at] = Log::verify($e['trace_id']);
            $msg = $ok ? "Cadeia de logs íntegra: $n linha(s) conferidas." : "ATENÇÃO: a cadeia de logs foi adulterada a partir da linha $at.";
            if (!$ok) {
                $err = $msg;
            }
        } else {
            $action = (string) ($_POST['do'] ?? '');
            $msg = Actions::run($action, $id, (int) Session::getLoginUserID(), 'glpi', $_POST);
            if ($action !== 'silence') {
                Dispatcher::flush($id);
            }
        }
    } catch (Throwable $x) {
        $err = $x->getMessage();
    }
    Session::addMessageAfterRedirect($err !== '' ? $err : $msg, false, $err !== '' ? ERROR : INFO);
    Html::redirect($self . '?id=' . $id);
}

// ------------------------------------------------------------------ ficha
Menu::header($e['trace_id'], 'escalonamentos');
$t = Escalation::timing($e);
$sev = Escalation::severity((int) $e['severity']);
$st = Escalation::STATES[$e['state']] ?? [$e['state'], '#94a3b8'];
$policy = Policy::get((int) $e['policies_id']);
$levels = Policy::levels((int) $e['policies_id']);
$history = Escalation::levelHistory($id);
$active = in_array($e['state'], ['open', 'acked'], true);
$uid = (int) Session::getLoginUserID();
$canAct = Escalation::canAct($e, $uid);
$can = fn (string $cap) => Perms::can($cap, $e, $uid);
$facts = json_decode((string) $e['facts'], true) ?: [];
$logs = Log::forEscalation($id);

$ticketBtn = $e['source'] === 'ticket' && (int) $e['items_id'] ? Ui::button('Abrir chamado', $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $e['items_id'], 'ti-ticket', 'btn-outline-secondary') : '';
echo "<div class='esc-dash'>";
echo Ui::title($e['title'], $e['trace_id'] . ' · ' . ($policy['name'] ?? '—') . ' · ' . (Escalation::SOURCES[$e['source']] ?? $e['source']) . ($e['source_ref'] ? ' (' . $e['source_ref'] . ')' : ''),
    $ticketBtn . Ui::button('Logs deste rastreio', Menu::url('/front/logs.php?trace=' . urlencode($e['trace_id'])), 'ti-file-search', 'btn-outline-secondary'));

// ações (cada botão só aparece se o usuário tem a permissão correspondente)
if ($active && $canAct) {
    echo Ui::formOpen($self, 'card card-body mb-3' . (!empty($_GET['ack']) && $e['state'] === 'open' ? ' esc-highlight' : '')) . "<input type='hidden' name='id' value='" . $id . "'>"
        . "<div class='d-flex flex-wrap gap-2 align-items-end'>";
    if ($e['state'] === 'open' && $can('ack')) {
        echo Ui::submit('Assumir (ACK)', 'do', 'ack', 'ti-hand-stop', 'btn-danger btn-lg');
    } elseif ($e['state'] === 'acked') {
        echo "<span class='text-secondary'>Reconhecido por <b>" . Ui::e(Ui::userName((int) $e['users_id_ack'])) . '</b> em ' . Ui::e(Ui::dt($e['acked_at'])) . ' (' . Ui::e((string) $e['ack_via']) . ').</span>';
    }
    if ($can('resolve')) {
        echo Ui::submit('Resolver', 'do', 'resolve', 'ti-circle-check', 'btn-success');
    }
    if ($e['state'] === 'open' && $can('silence')) {
        echo "<div class='d-flex gap-1'><select class='form-select' name='minutes' style='width:auto'><option value='15'>15 min</option><option value='30' selected>30 min</option><option value='60'>1 h</option><option value='240'>4 h</option></select>"
            . Ui::submit('Silenciar escalada', 'do', 'silence', 'ti-bell-off', 'btn-outline-secondary') . '</div>'
            . Ui::submit('Reenviar avisos', 'do', 'resend', 'ti-send', 'btn-outline-secondary');
    }
    if ($can('cancel')) {
        echo Ui::submit('Cancelar', 'do', 'cancel', 'ti-x', 'btn-outline-danger', "data-confirm='Cancelar este escalonamento? Ele deixa de escalar e some dos pop-ups.'");
    }
    echo '</div>';
    if ($e['silenced_until'] && $e['silenced_until'] > date('Y-m-d H:i:s')) {
        echo "<div class='small text-warning mt-2'><i class='ti ti-bell-off me-1'></i>Escalada pausada até " . Ui::e(Ui::dt($e['silenced_until'])) . '. O SLO continua contando.</div>';
    }
    Ui::closeForm();
} elseif ($active) {
    echo "<div class='alert alert-info'><div><i class='ti ti-info-circle me-1'></i>"
        . (Perms::notifyOnly($uid) ? 'Você está configurado como <b>somente notificado</b>: acompanha este escalonamento, mas não pode agir nele.' : 'Você pode acompanhar este escalonamento, mas não tem permissão para agir nele.') . '</div></div>';
}

// indicadores
$ackTxt = $e['acked_at'] ? 'ACK em ' . Ui::dur((int) $t['time_to_ack']) : ($active ? ($t['ack_left'] < 0 ? 'estourado há ' . Ui::dur(-$t['ack_left']) : 'restam ' . Ui::dur($t['ack_left'])) : '—');
$resTxt = $active ? ($t['resolve_left'] < 0 ? 'estourado há ' . Ui::dur(-$t['resolve_left']) : 'restam ' . Ui::dur($t['resolve_left'])) : ($e['resolved_at'] ? 'em ' . Ui::dur($t['age']) : '—');
echo "<div class='esc-kpis'>"
    . Ui::kpi('Situação', Ui::e($st[0]), $e['state'] === 'resolved' ? 'por ' . (string) $e['resolved_by'] : '', $st[1])
    . Ui::kpi('Severidade', Ui::e($sev[0]), (int) $e['occurrences'] > 1 ? (int) $e['occurrences'] . ' ocorrências agrupadas' : '', $sev[1])
    . Ui::kpi('Nível atual', (int) $e['level'] ? 'N' . (int) $e['level'] : '—', ($levels[(int) $e['level']]['name'] ?? '') . ((int) $e['repeats'] ? ' · repetido ' . (int) $e['repeats'] . '×' : ''), '#f59e0b')
    . Ui::kpi('Aberto há', Ui::dur($t['age']), Ui::dt($e['opened_at']), '#64748b')
    . Ui::kpi('Prazo de ACK (' . (int) ($policy['ack_slo_min'] ?? 0) . ' min)', Ui::e($ackTxt), $e['ack_breached'] ? 'SLO de ACK estourado' : ($e['ack_warned'] ? 'em risco' : 'dentro do SLO'), $e['ack_breached'] ? '#ef4444' : ($e['ack_warned'] ? '#f59e0b' : '#22c55e'))
    . Ui::kpi('Prazo de resolução (' . (int) ($policy['resolve_slo_min'] ?? 0) . ' min)', Ui::e($resTxt), $e['resolve_breached'] ? 'SLO de resolução estourado' : ($e['resolve_warned'] ? 'em risco' : 'dentro do SLO'), $e['resolve_breached'] ? '#ef4444' : ($e['resolve_warned'] ? '#f59e0b' : '#22c55e'))
    . '</div>';

// cadeia (degraus)
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Cadeia de escalonamento</h3></div><div class='card-body'><div class='esc-steps'>";
foreach ($levels as $pos => $l) {
    $h = $history[$pos] ?? null;
    $cls = $h ? ((int) $e['level'] === $pos && $e['state'] === 'open' ? 'now' : 'done') : '';
    if ($e['state'] === 'acked' && (int) $e['level'] === $pos) {
        $cls = 'acked';
    }
    echo "<div class='esc-step $cls'><div class='n'>N" . (int) $pos . '</div><div class="t">' . Ui::e($l['name']) . '</div>'
        . '<div class="m">' . Ui::e(implode(', ', Policy::targetNames($l))) . '</div>'
        . '<div class="m">' . ($h ? 'avisado ' . Ui::e(Ui::dt($h['started'])) : 'sobe em ' . (int) $l['wait_min'] . ' min após o nível anterior') . '</div></div>';
}
echo '</div></div></div>';

// resumo e fatos
echo "<div class='row g-3 mb-3'><div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Resumo</h3></div><div class='card-body'>"
    . ($e['summary'] !== '' ? '<p style="white-space:pre-line">' . Ui::e($e['summary']) . '</p>' : "<p class='text-secondary'>Sem descrição.</p>");
foreach ($facts as $k => $v) {
    $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
    echo "<div class='d-flex gap-2 small'><span class='text-secondary' style='min-width:130px'>" . Ui::e($k) . '</span><span>'
        . (preg_match('#^https?://#', $v) ? '<a href="' . Ui::e($v) . '" target="_blank" rel="noopener">' . Ui::e($v) . '</a>' : Ui::e($v)) . '</span></div>';
}
echo '</div></div></div>';

// entregas por nível
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Entregas</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'><thead><tr><th>Nível</th><th>Destino</th><th>Canal</th><th>Situação</th><th>Quando</th></tr></thead><tbody>";
$colors = ['sent' => '#22c55e', 'shown' => '#22c55e', 'acked' => '#3b82f6', 'queued' => '#94a3b8', 'failed' => '#ef4444', 'uncertain' => '#f59e0b'];
$labels = ['sent' => 'enviado', 'shown' => 'exibido', 'acked' => 'encerrado', 'queued' => 'na fila', 'failed' => 'falhou',
    'sending' => 'em envio', 'uncertain' => 'sem confirmação', 'obsolete' => 'dispensado'];
$any = false;
foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['escalations_id' => $id], 'ORDER' => ['id'], 'LIMIT' => 200]) as $d) {
    $any = true;
    echo '<tr><td>' . ((int) $d['level'] ? 'N' . (int) $d['level'] : '—') . ($d['kind'] !== 'level' ? ' <span class="badge bg-secondary-lt">' . Ui::e($d['kind']) . '</span>' : '') . '</td>'
        . '<td>' . Ui::e((int) $d['users_id'] ? Ui::userName((int) $d['users_id']) : 'Equipe') . '</td>'
        . '<td><i class="ti ' . Ui::e(Policy::CHANNELS[$d['channel']][1] ?? 'ti-send') . ' me-1"></i>' . Ui::e(Policy::CHANNELS[$d['channel']][0] ?? $d['channel']) . '</td>'
        . '<td>' . Ui::badge($labels[$d['status']] ?? $d['status'], $colors[$d['status']] ?? '#94a3b8') . ($d['error'] ? '<div class="small text-danger">' . Ui::e($d['error']) . '</div>' : '') . ((int) $d['attempts'] > 1 ? '<div class="small text-secondary">' . (int) $d['attempts'] . ' tentativas</div>' : '') . '</td>'
        . '<td class="small">' . Ui::e(Ui::dt($d['date_creation'])) . '</td></tr>';
}
echo $any ? '' : "<tr><td colspan='5' class='text-center text-secondary'>Nenhuma entrega ainda.</td></tr>";
echo '</tbody></table></div></div></div></div>';

// linha do tempo (logs rastreáveis)
[$okChain, $nChain] = Log::verify($e['trace_id']);
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Linha do tempo rastreável</h3><div class='card-actions d-flex gap-2 align-items-center'>"
    . Ui::badge($okChain ? 'Cadeia íntegra · ' . $nChain . ' linhas' : 'CADEIA ADULTERADA', $okChain ? '#22c55e' : '#ef4444', $okChain ? 'ti-shield-check' : 'ti-shield-x') . '</div></div>'
    . "<div class='table-responsive'><table class='table table-vcenter card-table table-sm esc-log'><thead><tr><th>#</th><th>Quando</th><th>Evento</th><th>Canal</th><th>Mensagem</th><th>Quem</th><th>Hash</th></tr></thead><tbody>";
foreach ($logs as $l) {
    [$sl, $sc] = Log::STATUS[$l['status']] ?? [$l['status'], '#64748b'];
    $key = 'l' . (int) $l['id'];
    Drawer::add($key, (Log::EVENTS[$l['event']] ?? $l['event']) . ' · #' . (int) $l['seq'], 'ti-file-search', $sc,
        Drawer::lead((string) $l['message'])
        . Drawer::item('Rastreio', $l['trace_id'] . ' · linha ' . (int) $l['seq'], '', '', '#6366f1')
        . Drawer::item('Quando', Ui::dtSec($l['date_creation']), $l['duration_ms'] ? (int) $l['duration_ms'] . ' ms' : '', '', '#64748b')
        . Drawer::item('Hash', substr((string) $l['hash'], 0, 32) . '…', '', '', '#64748b')
        . Drawer::item('Hash anterior', $l['prev_hash'] !== '' ? substr((string) $l['prev_hash'], 0, 32) . '…' : '(início da cadeia)', '', '', '#64748b')
        . ($l['detail'] ? '<pre class="esc-json">' . Ui::e(json_encode(json_decode((string) $l['detail'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>' : ''));
    echo "<tr class='esc-click' data-drawer='$key' tabindex='0'><td>" . (int) $l['seq'] . '</td><td class="text-nowrap small">' . Ui::e(Ui::dtSec($l['date_creation'])) . '</td>'
        . '<td>' . Ui::badge(Log::EVENTS[$l['event']] ?? $l['event'], $sc) . '</td>'
        . '<td>' . ($l['channel'] !== '' ? '<i class="ti ' . Ui::e(Policy::CHANNELS[$l['channel']][1] ?? 'ti-send') . '"></i> ' . Ui::e(Policy::CHANNELS[$l['channel']][0] ?? $l['channel']) : '') . '</td>'
        . '<td>' . Ui::e($l['message']) . '</td><td class="small">' . Ui::e($l['users_id'] ? Ui::userName((int) $l['users_id']) : $l['actor']) . '</td>'
        . '<td class="small text-secondary"><code>' . Ui::e(substr((string) $l['hash'], 0, 8)) . '</code></td></tr>';
}
echo '</tbody></table></div>';
echo "<div class='card-footer'>" . Ui::formOpen($self, 'd-inline') . "<input type='hidden' name='id' value='" . $id . "'>" . Ui::submit('Verificar integridade agora', 'do', 'verify', 'ti-shield-check', 'btn-sm btn-outline-secondary');
Ui::closeForm();
echo '</div></div>';
echo '</div>';
Menu::footer();
