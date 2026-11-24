<?php

/**
 * Resposta recebida pelo WhatsApp (Meta Cloud API): o usuário responde "ACK 123" (ou só "ACK") ao aviso e assume o
 * escalonamento. GET = verificação do webhook pela Meta. POST = mensagens, validadas pela assinatura X-Hub-Signature-256
 * com o segredo do app. Sem sessão do GLPI.
 */

use GlpiPlugin\Escalations\Channels;
use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Ui;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify = Settings::get('wa_meta_verify');
    if (($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && $verify !== '' && hash_equals($verify, (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        return;
    }
    http_response_code(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    return;
}

header('Content-Type: application/json; charset=utf-8');
$raw = (string) file_get_contents('php://input', false, null, 0, 262145);
$secret = Settings::get('wa_meta_app_secret');
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($secret === '' || strlen($raw) > 262144 || !str_starts_with($sig, 'sha256=') || !hash_equals(hash_hmac('sha256', $raw, $secret), substr($sig, 7))) {
    Log::write(null, 'inbound', ['channel' => 'whatsapp', 'status' => 'warn', 'actor' => 'whatsapp', 'message' => 'Resposta do WhatsApp recusada: assinatura inválida ou segredo do app não configurado.']);
    http_response_code(403);
    echo json_encode(['ok' => false]);
    return;
}
if (!Settings::bool('wa_ack_reply') || Settings::get('wa_provider') !== 'meta') {
    echo json_encode(['ok' => true, 'ignored' => true]);
    return;
}

global $DB;
$data = json_decode($raw, true) ?: [];
foreach ((array) ($data['entry'] ?? []) as $entry) {
    foreach ((array) ($entry['changes'] ?? []) as $change) {
        foreach ((array) ($change['value']['messages'] ?? []) as $m) {
            $from = preg_replace('/\D+/', '', (string) ($m['from'] ?? ''));
            $text = (string) ($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? '');
            if ($from === '' || !preg_match('/^\s*(ack|ok|assumir|assumo|reconhe[cç]o)\s*#?\s*(\d+)?\s*[.!]?\s*$/iu', $text, $mm)) {
                continue; // só nos interessa o ACK
            }
            // quem é? compara os números do cadastro (últimos 8 dígitos) normalizados
            $uid = 0;
            foreach ($DB->request(['SELECT' => ['id', 'mobile', 'phone'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0,
                ['OR' => ['mobile' => ['LIKE', '%' . substr($from, -8)], 'phone' => ['LIKE', '%' . substr($from, -8)]]]], 'LIMIT' => 20]) as $u) {
                foreach ([$u['mobile'], $u['phone']] as $num) {
                    if ($num !== '' && Channels::normalizePhone((string) $num) === $from) {
                        $uid = (int) $u['id'];
                    }
                }
            }
            if (!$uid) {
                Log::write(null, 'inbound', ['channel' => 'whatsapp', 'status' => 'warn', 'actor' => 'whatsapp', 'message' => 'Resposta de um número que não pertence a nenhum usuário ativo (…' . substr($from, -4) . ').']);
                continue;
            }
            // qual escalonamento? o número informado, ou o último que avisou este usuário e ainda aguarda ACK
            $escId = (int) ($mm[2] ?? 0);
            if (!$escId) {
                foreach ($DB->request(['SELECT' => 'escalations_id', 'FROM' => Escalation::DELIVERIES, 'WHERE' => [
                    'users_id' => $uid, 'channel' => 'whatsapp', 'kind' => 'level', 'status' => ['sent', 'sending', 'uncertain'],
                ], 'ORDER' => ['id DESC'], 'LIMIT' => 10]) as $d) {
                    $cand = Escalation::get((int) $d['escalations_id']);
                    if ($cand && $cand['state'] === 'open') {
                        $escId = (int) $cand['id'];
                        break;
                    }
                }
            }
            $e = $escId ? Escalation::get($escId) : null;
            try {
                if (!$e || !\GlpiPlugin\Escalations\Perms::can('ack', $e, $uid)) {
                    throw new RuntimeException('Não encontrei um escalonamento seu aguardando ACK, ou você só é notificado e não pode assumir.');
                }
                Escalation::ack($escId, $uid, 'whatsapp');
                Dispatcher::flush($escId);
                Log::write($e, 'inbound', ['channel' => 'whatsapp', 'status' => 'ok', 'users_id' => $uid, 'actor' => 'whatsapp', 'message' => 'Resposta "' . trim($text) . '" recebida de ' . Ui::userName($uid) . '.']);
                $reply = Channels::whatsappReply($from, '✅ ACK registrado em ' . $e['trace_id'] . '. A escalada foi interrompida.');
                if (!empty($reply['detail']['simulado'])) {
                    Log::write($e, 'inbound', ['channel' => 'whatsapp', 'status' => 'info', 'users_id' => $uid, 'actor' => 'whatsapp',
                        'message' => 'Confirmação do ACK simulada: nenhuma mensagem foi enviada ao provedor de WhatsApp.', 'detail' => ['simulado' => true]]);
                }
            } catch (Throwable $x) {
                Log::write($e, 'inbound', ['channel' => 'whatsapp', 'status' => 'fail', 'users_id' => $uid, 'actor' => 'whatsapp', 'message' => 'ACK por WhatsApp não registrado: ' . $x->getMessage()]);
                $reply = Channels::whatsappReply($from, '⚠️ ' . $x->getMessage());
                if (!empty($reply['detail']['simulado'])) {
                    Log::write($e, 'inbound', ['channel' => 'whatsapp', 'status' => 'info', 'users_id' => $uid, 'actor' => 'whatsapp',
                        'message' => 'Resposta de erro do ACK simulada: nenhuma mensagem foi enviada ao provedor de WhatsApp.', 'detail' => ['simulado' => true]]);
                }
            }
        }
    }
}
echo json_encode(['ok' => true]);
