<?php

/**
 * Ações do pop-up (ACK, adiar, ciente): devolve JSON. O CSRF é validado pelo GLPI pelo cabeçalho X-Glpi-Csrf-Token
 * das requisições AJAX (no GLPI 12 não há token).
 */

use GlpiPlugin\Escalations\Actions;
use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\Escalation;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

$uid = (int) Session::getLoginUserID();
if ($uid <= 0) {
    $reply(401, ['ok' => false, 'error' => 'Sessão expirada.']);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'Use POST.']);
    return;
}
if (!Escalation::canView()) {
    $reply(403, ['ok' => false, 'error' => 'Sem permissão.']);
    return;
}

try {
    $do = (string) ($_POST['do'] ?? '');
    $delivery = (int) ($_POST['delivery'] ?? 0);
    if ($do === 'ack') {
        $id = (int) ($_POST['id'] ?? 0);
        $msg = Actions::run('ack', $id, $uid, 'popup');
        Dispatcher::flush($id);
    } elseif ($do === 'snooze' && $delivery) {
        Escalation::snooze($delivery, $uid);
        $msg = 'Adiado.';
    } elseif ($do === 'dismiss' && $delivery) {
        Dispatcher::dismiss($delivery, $uid);
        $msg = 'Ciente.';
    } else {
        $reply(422, ['ok' => false, 'error' => 'Ação inválida.']);
        return;
    }
    $reply(200, ['ok' => true, 'message' => $msg]);
} catch (Throwable $e) {
    $reply($e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500, ['ok' => false, 'error' => $e->getMessage()]);
}
