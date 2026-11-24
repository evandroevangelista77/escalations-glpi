<?php

use GlpiPlugin\Escalations\Api;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Outbox;
use GlpiPlugin\Escalations\Perms;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Slo;

/** Definições das tabelas usadas na instalação e nos testes isolados. */
function plugin_escalations_table_definitions(string $sign): array
{
    $int       = "int $sign NOT NULL DEFAULT 0";

    return [
        // hierarquia: SLO de ACK e de resolução, meta, janela, repetição do último nível e dono (recebe avisos de SLO)
        Policy::TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `area` varchar(100) NOT NULL DEFAULT '', `description` text,
            `is_active` tinyint NOT NULL DEFAULT 1, `users_id_owner` $int, `ack_slo_min` int NOT NULL DEFAULT 15, `resolve_slo_min` int NOT NULL DEFAULT 240,
            `slo_target` decimal(5,2) NOT NULL DEFAULT 95.00, `slo_window_days` int NOT NULL DEFAULT 30, `slo_warn_pct` int NOT NULL DEFAULT 80,
            `repeat_min` int NOT NULL DEFAULT 15, `max_repeats` int NOT NULL DEFAULT 3,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `area` (`area`), KEY `is_active` (`is_active`)",
        Policy::LEVELS => "`id` int $sign NOT NULL AUTO_INCREMENT, `policies_id` $int, `position` int NOT NULL DEFAULT 1, `name` varchar(100) NOT NULL DEFAULT '',
            `wait_min` int NOT NULL DEFAULT 15, `channels` varchar(100) NOT NULL DEFAULT 'email,popup',
            PRIMARY KEY (`id`), KEY `policy` (`policies_id`, `position`)",
        // alvos do nível: user, group, groupmgr, supervisor, oncall (reservado)
        Policy::TARGETS => "`id` int $sign NOT NULL AUTO_INCREMENT, `levels_id` $int, `type` varchar(12) NOT NULL, `items_id` $int, `role` varchar(8) NOT NULL DEFAULT 'act',
            PRIMARY KEY (`id`), KEY `level` (`levels_id`)",
        // quando a hierarquia vale (origem, severidade mínima, entidade, categoria, texto, gatilhos de chamado)
        Policy::RULES => "`id` int $sign NOT NULL AUTO_INCREMENT, `policies_id` $int, `name` varchar(150) NOT NULL DEFAULT '', `source` varchar(20) NOT NULL DEFAULT 'any', `plugin_key` varchar(40) NOT NULL DEFAULT '', `type_key` varchar(60) NOT NULL DEFAULT '',
            `severity_min` tinyint NOT NULL DEFAULT 1, `entities_id` int NOT NULL DEFAULT -1, `itilcategories_id` $int, `match_text` varchar(255) NOT NULL DEFAULT '',
            `sla_pct` int NOT NULL DEFAULT 0, `unassigned_min` int NOT NULL DEFAULT 0, `is_active` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`), KEY `policy` (`policies_id`), KEY `source` (`source`, `is_active`)",
        // escalonamento (trace_id é o código de rastreio que aparece em todas as mensagens e logs)
        Escalation::TABLE => "`id` int $sign NOT NULL AUTO_INCREMENT, `trace_id` varchar(24) NOT NULL, `policies_id` $int, `rules_id` $int,
            `source` varchar(20) NOT NULL DEFAULT 'manual', `source_ref` varchar(100) NOT NULL DEFAULT '', `items_id` $int, `dedup_key` varchar(190) DEFAULT NULL,
            `title` varchar(255) NOT NULL, `summary` text, `facts` text, `severity` tinyint NOT NULL DEFAULT 3, `entities_id` $int,
            `state` varchar(10) NOT NULL DEFAULT 'open', `level` int NOT NULL DEFAULT 0, `level_since` datetime DEFAULT NULL, `repeats` int NOT NULL DEFAULT 0,
            `occurrences` int NOT NULL DEFAULT 1, `opened_at` datetime NOT NULL, `last_seen` datetime DEFAULT NULL, `next_action_at` datetime DEFAULT NULL, `silenced_until` datetime DEFAULT NULL,
            `acked_at` datetime DEFAULT NULL, `users_id_ack` $int, `ack_via` varchar(12) DEFAULT NULL, `resolved_at` datetime DEFAULT NULL, `resolved_by` varchar(40) DEFAULT NULL,
            `ack_due_at` datetime DEFAULT NULL, `resolve_due_at` datetime DEFAULT NULL, `ack_warned` tinyint NOT NULL DEFAULT 0, `ack_breached` tinyint NOT NULL DEFAULT 0,
            `resolve_warned` tinyint NOT NULL DEFAULT 0, `resolve_breached` tinyint NOT NULL DEFAULT 0, `users_id_opened` $int,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `trace_id` (`trace_id`), KEY `state_next` (`state`, `next_action_at`), KEY `dedup_key` (`dedup_key`), KEY `policy` (`policies_id`, `opened_at`),
            KEY `ticket` (`source`, `items_id`), KEY `opened_at` (`opened_at`)",
        // cada envio (um por destinatário, canal e rodada); o pop-up é um registro que o navegador consome
        Escalation::DELIVERIES => "`id` int $sign NOT NULL AUTO_INCREMENT, `escalations_id` $int, `trace_id` varchar(24) NOT NULL DEFAULT '', `level` int NOT NULL DEFAULT 0, `round` int NOT NULL DEFAULT 0,
            `kind` varchar(20) NOT NULL DEFAULT 'level', `role` varchar(8) NOT NULL DEFAULT 'act', `channel` varchar(10) NOT NULL, `users_id` $int, `recipient` varchar(255) NOT NULL DEFAULT '',
            `status` varchar(10) NOT NULL DEFAULT 'queued', `attempts` int NOT NULL DEFAULT 0, `next_try` datetime DEFAULT NULL, `provider_id` varchar(190) NOT NULL DEFAULT '', `error` varchar(500) DEFAULT NULL,
            `snooze_until` datetime DEFAULT NULL, `sent_at` datetime DEFAULT NULL, `shown_at` datetime DEFAULT NULL, `date_creation` datetime DEFAULT NULL, `date_mod` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `esc` (`escalations_id`, `level`), KEY `popup` (`users_id`, `channel`, `status`), KEY `retry` (`status`, `next_try`), KEY `provider` (`provider_id`)",
        // logs rastreáveis, encadeados por hash (prev_hash → hash) por trace_id
        Log::TABLE => "`id` bigint $sign NOT NULL AUTO_INCREMENT, `trace_id` varchar(24) NOT NULL, `escalations_id` $int, `seq` int NOT NULL DEFAULT 1, `event` varchar(24) NOT NULL,
            `level` int NOT NULL DEFAULT 0, `channel` varchar(10) NOT NULL DEFAULT '', `target_type` varchar(12) NOT NULL DEFAULT '', `target_id` $int,
            `status` varchar(6) NOT NULL DEFAULT 'info', `message` varchar(500) NOT NULL DEFAULT '', `detail` mediumtext, `users_id` $int, `actor` varchar(12) NOT NULL DEFAULT 'system',
            `ip` varchar(45) NOT NULL DEFAULT '', `duration_ms` int NOT NULL DEFAULT 0, `prev_hash` char(64) NOT NULL DEFAULT '', `hash` char(64) NOT NULL DEFAULT '', `date_creation` datetime(3) NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `trace_seq` (`trace_id`, `seq`), KEY `esc` (`escalations_id`), KEY `event` (`event`), KEY `status` (`status`), KEY `date_creation` (`date_creation`), KEY `users_id` (`users_id`)",
        // tipos de escalonamento que os plugins registram (aparecem na tela de regras)
        Api::SOURCES => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_key` varchar(40) NOT NULL, `type_key` varchar(60) NOT NULL, `label` varchar(150) NOT NULL DEFAULT '',
            `description` varchar(500) NOT NULL DEFAULT '', `default_severity` tinyint NOT NULL DEFAULT 3, `date_creation` datetime DEFAULT NULL, `last_used` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `type` (`plugin_key`, `type_key`)",
        // itens de plugins com prazo acompanhados pelo motor (abre ao consumir X% do prazo)
        Api::TRACKED => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_key` varchar(40) NOT NULL, `type_key` varchar(60) NOT NULL, `ref_key` varchar(120) NOT NULL, `title` varchar(255) NOT NULL,
            `summary` text, `facts` text, `severity` tinyint NOT NULL DEFAULT 3, `entities_id` int NOT NULL DEFAULT 0, `url` varchar(500) NOT NULL DEFAULT '', `start_at` datetime DEFAULT NULL, `due_at` datetime NOT NULL,
            `status` varchar(8) NOT NULL DEFAULT 'active', `escalations_id` int $sign NOT NULL DEFAULT 0, `date_creation` datetime DEFAULT NULL, `date_mod` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `item` (`plugin_key`, `type_key`, `ref_key`), KEY `status_due` (`status`, `due_at`)",
        // permissões: exceções por usuário (permitir/negar, somente notificado, escopo) e por perfil (somente notificado, escopo)
        Perms::USERS => "`id` int $sign NOT NULL AUTO_INCREMENT, `users_id` $int, `grant_bits` int NOT NULL DEFAULT 0, `deny_bits` int NOT NULL DEFAULT 0, `notify_only` tinyint NOT NULL DEFAULT 0,
            `scope` varchar(255) NOT NULL DEFAULT '', `note` varchar(255) NOT NULL DEFAULT '', `users_id_set` $int, `date_mod` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `user` (`users_id`)",
        Perms::PROFILES => "`id` int $sign NOT NULL AUTO_INCREMENT, `profiles_id` $int, `notify_only` tinyint NOT NULL DEFAULT 0, `scope` varchar(255) NOT NULL DEFAULT '', `note` varchar(255) NOT NULL DEFAULT '',
            `date_mod` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `profile` (`profiles_id`)",
        // caixa de saída: cópia exata de cada mensagem enviada (e-mail HTML, Teams, WhatsApp)
        Outbox::TABLE => "`id` bigint $sign NOT NULL AUTO_INCREMENT, `escalations_id` int $sign NOT NULL DEFAULT 0, `deliveries_id` int $sign NOT NULL DEFAULT 0, `trace_id` varchar(24) NOT NULL DEFAULT '',
            `level` int NOT NULL DEFAULT 0, `channel` varchar(10) NOT NULL, `users_id` int $sign NOT NULL DEFAULT 0, `recipient` varchar(255) NOT NULL DEFAULT '', `subject` varchar(255) NOT NULL DEFAULT '',
            `body_html` mediumtext, `body_text` mediumtext, `status` varchar(8) NOT NULL DEFAULT 'sent', `error` varchar(500) DEFAULT NULL, `date_creation` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `esc` (`escalations_id`), KEY `user` (`users_id`), KEY `date_creation` (`date_creation`)",
        // histórico diário de SLO por hierarquia
        Slo::SNAPSHOTS => "`id` int $sign NOT NULL AUTO_INCREMENT, `snap_date` date NOT NULL, `policies_id` $int, `total` int NOT NULL DEFAULT 0, `ack_breaches` int NOT NULL DEFAULT 0, `res_breaches` int NOT NULL DEFAULT 0,
            `mtta_s` int NOT NULL DEFAULT 0, `mttr_s` int NOT NULL DEFAULT 0, `ack_pct` decimal(6,2) NOT NULL DEFAULT 100, `res_pct` decimal(6,2) NOT NULL DEFAULT 100, `budget_pct` decimal(7,1) NOT NULL DEFAULT 100,
            PRIMARY KEY (`id`), UNIQUE KEY `day` (`snap_date`, `policies_id`)",
    ];
}

/** Tabelas, configuração padrão, direitos (com aba em Perfis) e as ações automáticas. */
function plugin_escalations_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $opts      = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    foreach (plugin_escalations_table_definitions($sign) as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    // atualização: papel "escalado / somente notificado" nos alvos dos níveis e nas entregas
    foreach ([Policy::TARGETS, Escalation::DELIVERIES] as $t) {
        if (!$DB->fieldExists($t, 'role')) {
            $DB->doQuery("ALTER TABLE `$t` ADD `role` varchar(8) NOT NULL DEFAULT 'act'");
        }
    }

    // configuração padrão (só o que ainda não existe); gera o token do webhook e a chave de verificação do WhatsApp
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $missing = array_diff_key(Settings::DEFAULTS, $current);
    if ($missing) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }
    if (!Settings::has('webhook_token')) {
        Settings::set(['webhook_token' => bin2hex(random_bytes(24))]);
    }
    if (Settings::get('wa_meta_verify') === '') {
        Settings::set(['wa_meta_verify' => bin2hex(random_bytes(12))]);
    }

    // direito do plugin: administrador (quem edita a configuração) = tudo; demais perfis = só leitura
    $right = Escalation::$rightname;
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => $right])) {
        ProfileRight::addProfileRights([$right]);
        $admin = READ | CREATE | UPDATE | PURGE | Escalation::MANAGE | Escalation::ALL | Escalation::ACK | Escalation::RESOLVE | Escalation::SILENCE | Escalation::CANCEL | Escalation::LOGS | Escalation::SLO;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? $admin : (READ | Escalation::LOGS | Escalation::SLO)], ['profiles_id' => $row['id'], 'name' => $right]);
        }
    }
    // atualização das permissões (uma vez): quem já lia continua vendo logs e SLO; quem tinha "Atualizar" ganha as ações explícitas
    if (Settings::get('perms_migrated') !== '1') {
        foreach ($DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $right]]) as $pr) {
            $r = (int) $pr['rights'];
            $add = (($r & READ) ? (Escalation::LOGS | Escalation::SLO) : 0) | (($r & UPDATE) ? (Escalation::ACK | Escalation::RESOLVE | Escalation::SILENCE | Escalation::CANCEL) : 0);
            if ($add && ($r | $add) !== $r) {
                $DB->update(ProfileRight::getTable(), ['rights' => $r | $add], ['id' => $pr['id']]);
            }
        }
        Settings::set(['perms_migrated' => '1']);
    }
    // vale já na sessão aberta, sem novo login
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $now = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [$right]);
        $_SESSION['glpiactiveprofile'][$right] = (int) ($now[$right] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    CronTask::register(GlpiPlugin\Escalations\Cron::class, 'escalationsrun', MINUTE_TIMESTAMP, [
        'comment' => 'Escalations: sobe níveis, confere SLO, reenvia falhas e observa chamados (rodar a cada minuto)',
        'mode'    => CronTask::MODE_EXTERNAL,
    ]);
    CronTask::register(GlpiPlugin\Escalations\Cron::class, 'escalationsdaily', DAY_TIMESTAMP, [
        'comment' => 'Escalations: histórico diário de SLO e limpeza de logs antigos',
        'mode'    => CronTask::MODE_EXTERNAL,
    ]);
    return true;
}

function plugin_escalations_uninstall(): bool
{
    global $DB;
    foreach ([Perms::PROFILES, Perms::USERS, Outbox::TABLE, Api::TRACKED, Api::SOURCES, Slo::SNAPSHOTS, Log::TABLE, Escalation::DELIVERIES, Escalation::TABLE, Policy::RULES, Policy::TARGETS, Policy::LEVELS, Policy::TABLE] as $table) {
        $DB->dropTable($table, true);
    }
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::DEFAULTS));
    ProfileRight::deleteProfileRights([Escalation::$rightname]);
    CronTask::unregister('escalations');
    unset($_SESSION['glpimenu']);
    return true;
}
