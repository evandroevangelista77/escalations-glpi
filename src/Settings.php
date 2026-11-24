<?php

namespace GlpiPlugin\Escalations;

use Config;
use GLPIKey;
use Throwable;

/**
 * Configuração global (contexto plugin:escalations). Segredos (URL do Teams, tokens, chave do WhatsApp)
 * ficam criptografados com a GLPIKey e nunca voltam para a tela nem para os logs.
 */
class Settings
{
    public const CONTEXT = 'plugin:escalations';

    /** Chaves guardadas criptografadas. */
    public const SECRETS = ['teams_url', 'wa_meta_token', 'wa_meta_app_secret', 'wa_webhook_header', 'webhook_token'];

    public const DEFAULTS = [
        // e-mail
        'mail_enabled' => '1',
        'mail_subject' => '[{severity}] {title} — nível {level} ({trace})',
        'mail_intro'   => 'Este escalonamento precisa de uma ação sua. Assuma para interromper a escalada; sem ACK ele sobe para o próximo nível.',
        'mail_footer'  => 'Mensagem automática do plugin Escalations. Conteúdo confidencial.',
        // pop-up
        'popup_enabled' => '1',
        'popup_sound'   => '1',
        'poll_seconds'  => '20',
        'snooze_min'    => '10',
        // Teams (Workflows / Power Automate)
        'teams_enabled' => '0',
        'teams_url'     => '',
        // WhatsApp: off | meta (Cloud API) | webhook (gateway próprio: Evolution, Z-API, WPPConnect...)
        'wa_provider'        => 'off',
        'wa_meta_phone_id'   => '',
        'wa_meta_token'      => '',
        'wa_meta_template'   => '',
        'wa_meta_lang'       => 'pt_BR',
        'wa_meta_app_secret' => '',
        'wa_meta_verify'     => '',
        'wa_webhook_url'     => '',
        'wa_webhook_header'  => '',
        'wa_webhook_body'    => '{"number":"{to}","text":"{text}"}',
        'wa_cc'              => '55',
        'wa_ack_reply'       => '1',
        // origens ativas (CSV): o que o plugin observa e aceita. 'manual' está sempre ativo. Padrão: só o que nasce no GLPI.
        // Para religar: event (webhook Zabbix e outros) e plugin (itens de outros plugins, via Api).
        'sources' => 'ticket,problem,change,project,projecttask',
        // caixa de saída: guarda a cópia de cada mensagem enviada; simulação: canais (CSV: email,teams,whatsapp) que NÃO enviam de verdade
        'outbox' => '1',
        'sink'   => '',
        // permissões: texto do e-mail para quem só é notificado (não age) e marca da migração dos direitos por perfil
        'mail_intro_notify' => 'Você está recebendo este aviso a título de informação. Nenhuma ação é exigida de você: a equipe escalada já foi acionada.',
        'perms_migrated'    => '',
        // ponte com o plugin Avisos (sino): opcional
        'avisos_bridge' => '0',
        // eventos
        'webhook_token' => '',
        // motor
        'fallback_channel'   => 'email',
        'owner_channels'     => 'email,popup',
        'max_attempts'       => '3',
        'log_retention_days' => '365',
        'scan_minutes'       => '5',
        'last_tick'          => '',
        'last_scan'          => '',
        'last_tick_ms'       => '',
    ];

    public static function get(string $key, ?string $default = null): string
    {
        $value = Config::getConfigurationValues(self::CONTEXT)[$key] ?? null;
        if ($value === null || ($value === '' && !in_array($key, ['sources', 'owner_channels'], true))) {
            return (string) ($default ?? self::DEFAULTS[$key] ?? '');
        }
        if (in_array($key, self::SECRETS, true)) {
            try {
                return (string) (new GLPIKey())->decrypt((string) $value);
            } catch (Throwable $e) {
                return '';
            }
        }
        return (string) $value;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /** Há segredo salvo nesta chave? (a tela mostra só "salvo", nunca o valor). */
    public static function has(string $key): bool
    {
        return (Config::getConfigurationValues(self::CONTEXT)[$key] ?? '') !== '';
    }

    public static function set(array $values): void
    {
        $out = [];
        foreach ($values as $k => $v) {
            $v = (string) $v;
            if (in_array($k, self::SECRETS, true) && $v !== '') {
                $v = (new GLPIKey())->encrypt($v);
            }
            $out[$k] = $v;
        }
        Config::setConfigurationValues(self::CONTEXT, $out);
    }

    /** Origens que o usuário pode ligar ou desligar (a manual está sempre ativa). */
    public const SOURCE_LABELS = [
        'ticket' => 'Chamados', 'problem' => 'Problemas', 'change' => 'Mudanças', 'project' => 'Projetos', 'projecttask' => 'Tarefas de projeto',
        'event' => 'Eventos por webhook (Zabbix e outros)', 'plugin' => 'Itens de outros plugins (Api)',
    ];

    public static function sourceOn(string $source): bool
    {
        return $source === 'manual' || in_array($source, array_filter(array_map('trim', explode(',', self::get('sources')))), true);
    }

    /** Lista CSV de canais (ex.: "email,popup") limpa e só com canais conhecidos. */
    public static function channels(string $csv): array
    {
        $list = array_filter(array_map('trim', explode(',', $csv)));
        return array_values(array_intersect(array_keys(Policy::CHANNELS), $list));
    }

    public static function pollSeconds(): int
    {
        return max(10, min(300, self::int('poll_seconds') ?: 20));
    }
}
