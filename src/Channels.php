<?php

namespace GlpiPlugin\Escalations;

use GLPIMailer;
use Symfony\Component\Mime\Address;
use Throwable;
use UserEmail;

/**
 * Canais de saída. Cada um recebe o contexto da mensagem e devolve
 * ['ok' => bool, 'provider_id' => string, 'error' => string, 'ms' => int, 'detail' => array].
 * O pop-up não passa por aqui: ele é buscado pelo navegador (front/pending.php) e registrado quando aparece.
 */
class Channels
{
    /** Canal em modo simulação: monta e guarda a mensagem na Caixa de saída, mas não envia. */
    public static function sinked(string $channel): bool
    {
        return in_array($channel, Settings::channels(Settings::get('sink')), true);
    }

    /** Resultado de um envio que ficou retido pela simulação: não houve chamada ao provedor. */
    public static function simulationResult(): array
    {
        return ['ok' => true, 'provider_id' => 'simulado', 'error' => '', 'ms' => 0, 'detail' => ['simulado' => true]];
    }

    public static function enabled(string $channel): bool
    {
        return match ($channel) {
            'email'    => Settings::bool('mail_enabled'),
            'popup'    => Settings::bool('popup_enabled'),
            'teams'    => self::sinked('teams') || (Settings::bool('teams_enabled') && Settings::get('teams_url') !== ''),
            'whatsapp' => self::sinked('whatsapp') || (Settings::get('wa_provider') !== 'off' && self::waReady()),
            default    => false,
        };
    }

    private static function waReady(): bool
    {
        return match (Settings::get('wa_provider')) {
            'meta'    => Settings::get('wa_meta_phone_id') !== '' && Settings::get('wa_meta_token') !== '',
            'webhook' => Settings::get('wa_webhook_url') !== '',
            default   => false,
        };
    }

    private static function fail(string $error, int $ms = 0, array $detail = []): array
    {
        return ['ok' => false, 'provider_id' => '', 'error' => $error, 'ms' => $ms, 'detail' => $detail];
    }

    // ------------------------------------------------------------------ e-mail

    /** @param array $to ['id' => usuário, 'email' => endereço, 'name' => nome] */
    public static function mail(array $c, array $to): array
    {
        global $CFG_GLPI;
        $t0 = microtime(true);
        if (empty($CFG_GLPI['notifications_mailing'])) {
            return self::fail('As notificações por e-mail estão desligadas no GLPI (Configurar → Notificações).');
        }
        if (($to['email'] ?? '') === '') {
            return self::fail('Usuário sem e-mail cadastrado.');
        }
        try {
            [$subject, $html, $text] = Message::email($c);
            $mail = new GLPIMailer();
            $em = $mail->getEmail();
            $fromAddr = (string) ($CFG_GLPI['from_email'] ?? '') ?: (string) ($CFG_GLPI['admin_email'] ?? '');
            $fromName = (string) ($CFG_GLPI['from_email_name'] ?? '') ?: (string) ($CFG_GLPI['admin_email_name'] ?? 'Escalations');
            if ($fromAddr !== '') {
                $em->from(new Address($fromAddr, $fromName));
            }
            $em->to(new Address($to['email'], (string) ($to['name'] ?? '')));
            $em->subject($subject);
            $em->text($text);
            $em->html($html);
            $em->getHeaders()->addTextHeader('X-Escalation-Trace', (string) $c['e']['trace_id']);
            $ok = $mail->send();
            $ms = (int) round((microtime(true) - $t0) * 1000);
            return $ok
                ? ['ok' => true, 'provider_id' => '', 'error' => '', 'ms' => $ms, 'detail' => ['to' => $to['email'], 'subject' => $subject]]
                : self::fail('E-mail não enviado: ' . ($mail->getError() ?: 'verifique a configuração de e-mail do GLPI'), $ms, ['to' => $to['email']]);
        } catch (Throwable $e) {
            return self::fail('E-mail: ' . $e->getMessage(), (int) round((microtime(true) - $t0) * 1000));
        }
    }

    public static function userEmail(int $uid): string
    {
        return (string) (UserEmail::getDefaultForUser($uid) ?: '');
    }

    // ------------------------------------------------------------------ Teams

    public static function teams(array $c): array
    {
        $url = Settings::get('teams_url');
        if ($url === '') {
            return self::fail('URL do Teams não configurada.');
        }
        $r = Http::post($url, json_encode(Message::teamsCard($c), JSON_UNESCAPED_UNICODE));
        return ['ok' => $r['ok'], 'provider_id' => '', 'error' => $r['ok'] ? '' : 'Teams: ' . $r['error'] . ($r['body'] !== '' ? ' ' . mb_substr($r['body'], 0, 150) : ''),
            'ms' => $r['ms'], 'detail' => ['host' => Http::host($url), 'http' => $r['code']]];
    }

    /** Aviso solto no canal (ACK, resolvido), sem cartão completo. */
    public static function teamsText(string $text): array
    {
        $url = Settings::get('teams_url');
        if ($url === '' || !Settings::bool('teams_enabled')) {
            return self::fail('Teams desligado.');
        }
        $card = ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive',
            'content' => ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4',
                'body' => [['type' => 'TextBlock', 'wrap' => true, 'text' => $text]]]]]];
        $r = Http::post($url, json_encode($card, JSON_UNESCAPED_UNICODE));
        return ['ok' => $r['ok'], 'provider_id' => '', 'error' => $r['ok'] ? '' : 'Teams: ' . $r['error'], 'ms' => $r['ms'], 'detail' => ['host' => Http::host($url), 'http' => $r['code']]];
    }

    // ------------------------------------------------------------------ WhatsApp

    /** Número do usuário só com dígitos e com DDI (padrão 55). Vazio se não houver. */
    public static function phone(int $uid): string
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['mobile', 'phone'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $uid]]) as $u) {
            return self::normalizePhone((string) ($u['mobile'] ?: $u['phone']));
        }
        return '';
    }

    public static function normalizePhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if ($d === '') {
            return '';
        }
        $d = ltrim($d, '0');
        if (strlen($d) <= 11) { // sem DDI: DDD + número
            $d = Settings::get('wa_cc') . $d;
        }
        return $d;
    }

    public static function whatsapp(array $c, string $phone): array
    {
        if ($phone === '') {
            return self::fail('Usuário sem celular cadastrado (campo "Celular" do usuário no GLPI).');
        }
        $text = Message::whatsapp($c);
        return Settings::get('wa_provider') === 'meta' ? self::waMeta($c, $phone, $text) : self::waWebhook($phone, $text);
    }

    /** WhatsApp Cloud API (Meta). Com modelo (template) aprovado funciona fora da janela de 24 h; sem modelo, só texto livre. */
    private static function waMeta(array $c, string $phone, string $text): array
    {
        $tpl = Settings::get('wa_meta_template');
        $payload = ['messaging_product' => 'whatsapp', 'to' => $phone];
        if ($tpl !== '') {
            $payload['type'] = 'template';
            $payload['template'] = ['name' => $tpl, 'language' => ['code' => Settings::get('wa_meta_lang') ?: 'pt_BR'], 'components' => [[
                'type' => 'body',
                // parâmetros do modelo: {{1}} título, {{2}} severidade, {{3}} nível, {{4}} rastreio, {{5}} link
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_substr((string) $v, 0, 400)], [
                    $c['e']['title'], $c['sev'][0], 'N' . $c['pos'] . ' · ' . ($c['level']['name'] ?? ''), $c['e']['trace_id'], $c['url_plain'],
                ]),
            ]]];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => false, 'body' => $text];
        }
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(Settings::get('wa_meta_phone_id')) . '/messages';
        $r = Http::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Authorization: Bearer ' . Settings::get('wa_meta_token')]);
        $id = '';
        if ($r['ok']) {
            $id = (string) (json_decode($r['body'], true)['messages'][0]['id'] ?? '');
        }
        $err = '';
        if (!$r['ok']) {
            $err = 'WhatsApp (Meta): ' . (json_decode($r['body'], true)['error']['message'] ?? $r['error']);
        }
        return ['ok' => $r['ok'], 'provider_id' => $id, 'error' => $err, 'ms' => $r['ms'], 'detail' => ['provider' => 'meta', 'to' => substr($phone, 0, 4) . '…' . substr($phone, -2), 'http' => $r['code'], 'template' => $tpl !== '']];
    }

    /** Gateway próprio (Evolution API, Z-API, WPPConnect...): URL + cabeçalho opcional + corpo JSON com {to} e {text}. */
    private static function waWebhook(string $phone, string $text): array
    {
        $url = Settings::get('wa_webhook_url');
        $esc = fn (string $v) => substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
        $body = strtr(Settings::get('wa_webhook_body') ?: '{"number":"{to}","text":"{text}"}', ['{to}' => $esc($phone), '{text}' => $esc($text)]);
        $headers = [];
        if (($h = trim(Settings::get('wa_webhook_header'))) !== '' && str_contains($h, ':')) {
            $headers[] = $h;
        }
        $r = Http::post($url, $body, $headers);
        return ['ok' => $r['ok'], 'provider_id' => '', 'error' => $r['ok'] ? '' : 'WhatsApp (gateway): ' . $r['error'] . ($r['body'] !== '' ? ' ' . mb_substr($r['body'], 0, 150) : ''),
            'ms' => $r['ms'], 'detail' => ['provider' => 'webhook', 'host' => Http::host($url), 'to' => substr($phone, 0, 4) . '…' . substr($phone, -2), 'http' => $r['code']]];
    }

    /** Resposta livre ao usuário pelo WhatsApp (confirmação de ACK). */
    public static function whatsappReply(string $phone, string $text): array
    {
        // A confirmação é automática: na simulação ela também não pode alcançar o provedor.
        if (self::sinked('whatsapp')) {
            return self::simulationResult();
        }
        if (!self::waReady()) {
            return self::fail('WhatsApp não configurado.');
        }
        if (Settings::get('wa_provider') === 'meta') {
            $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(Settings::get('wa_meta_phone_id')) . '/messages';
            $r = Http::post($url, json_encode(['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text', 'text' => ['body' => $text]], JSON_UNESCAPED_UNICODE),
                ['Authorization: Bearer ' . Settings::get('wa_meta_token')]);
            return ['ok' => $r['ok'], 'provider_id' => '', 'error' => $r['ok'] ? '' : $r['error'], 'ms' => $r['ms'], 'detail' => []];
        }
        return self::waWebhook($phone, $text);
    }
}
