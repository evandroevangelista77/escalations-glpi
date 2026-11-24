<?php

use GlpiPlugin\Escalations\Api;
use GlpiPlugin\Escalations\Channels;
use GlpiPlugin\Escalations\Cron;
use GlpiPlugin\Escalations\Engine;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Message;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard(false, true);
global $CFG_GLPI, $DB;
$self = Menu::url('/front/config.php');

/** Escalonamento de mentira, só para montar a mensagem de teste (nada é gravado). */
$fake = function (): array {
    $now = date('Y-m-d H:i:s');
    return ['id' => 0, 'trace_id' => 'TESTE-' . date('His'), 'policies_id' => 0, 'title' => 'Teste de canal do Escalations', 'summary' => 'Esta é uma mensagem de teste. Nenhum escalonamento foi aberto.',
        'severity' => 3, 'source' => 'manual', 'source_ref' => 'teste', 'items_id' => 0, 'facts' => '{"Origem":"Tela de configuração"}', 'occurrences' => 1, 'state' => 'open', 'level' => 1,
        'opened_at' => $now, 'ack_due_at' => date('Y-m-d H:i:s', time() + 900), 'resolve_due_at' => date('Y-m-d H:i:s', time() + 14400), 'acked_at' => null, 'resolved_at' => null,
        'next_action_at' => date('Y-m-d H:i:s', time() + 900), 'users_id_ack' => 0, 'ack_breached' => 0, 'resolve_breached' => 0];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $err = '';
    $uid = (int) Session::getLoginUserID();
    try {
        $do = (string) ($_POST['do'] ?? 'save');
        if ($do === 'run') {
            $msg = 'Ciclo executado: ' . Engine::tick() . ' ação(ões).';
        } elseif ($do === 'newtoken') {
            $tok = bin2hex(random_bytes(24));
            Settings::set(['webhook_token' => $tok]);
            Log::write(null, 'system', ['status' => 'warn', 'message' => 'Token do webhook de eventos foi gerado de novo.']);
            $msg = 'Novo token do webhook (copie agora, ele não será mostrado de novo): ' . $tok;
        } elseif (in_array($do, ['test_mail', 'test_teams', 'test_whatsapp'], true)) {
            $c = Message::context($fake(), ['position' => 1, 'name' => 'Teste', 'wait_min' => 15]);
            if ($do === 'test_mail') {
                $to = Channels::userEmail($uid);
                $r = Channels::mail($c, ['id' => $uid, 'email' => $to, 'name' => Ui::userName($uid)]);
                $what = 'E-mail para ' . ($to ?: '(sem e-mail no seu usuário)');
            } elseif ($do === 'test_teams') {
                $r = Channels::teams($c);
                $what = 'Teams';
            } else {
                $phone = Channels::normalizePhone((string) ($_POST['test_phone'] ?? '')) ?: Channels::phone($uid);
                $r = Channels::whatsapp($c, $phone);
                $what = 'WhatsApp para ' . ($phone !== '' ? substr($phone, 0, 4) . '…' . substr($phone, -2) : '(sem número)');
            }
            Log::write(null, 'test', ['status' => $r['ok'] ? 'ok' : 'fail', 'message' => 'Teste de canal — ' . $what . ($r['ok'] ? ': enviado.' : ': ' . $r['error']), 'duration_ms' => (int) $r['ms'], 'detail' => $r['detail']]);
            if ($r['ok']) {
                $msg = 'Teste enviado — ' . $what . ' (' . $r['ms'] . ' ms).';
            } else {
                $err = 'Teste falhou — ' . $what . ': ' . $r['error'];
            }
        } else {
            $in = [];
            foreach (['mail_subject', 'mail_intro', 'mail_intro_notify', 'mail_footer', 'wa_meta_phone_id', 'wa_meta_template', 'wa_meta_lang', 'wa_webhook_url', 'wa_webhook_body', 'wa_cc'] as $k) {
                $in[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            foreach (['mail_enabled', 'popup_enabled', 'popup_sound', 'teams_enabled', 'wa_ack_reply', 'avisos_bridge', 'outbox'] as $k) {
                $in[$k] = !empty($_POST[$k]) ? '1' : '0';
            }
            $wa = (string) ($_POST['wa_provider'] ?? 'off');
            $in['sources'] = implode(',', array_values(array_intersect(array_keys(Settings::SOURCE_LABELS), (array) ($_POST['sources'] ?? []))));
            $in['sink'] = implode(',', array_values(array_intersect(['email', 'teams', 'whatsapp'], (array) ($_POST['sink'] ?? []))));
            $in['wa_provider'] = in_array($wa, ['off', 'meta', 'webhook'], true) ? $wa : 'off';
            $fb = (string) ($_POST['fallback_channel'] ?? 'email');
            $in['fallback_channel'] = in_array($fb, ['none', 'email', 'whatsapp', 'teams'], true) ? $fb : 'email';
            $in['owner_channels'] = implode(',', Settings::channels(implode(',', (array) ($_POST['owner_channels'] ?? []))));
            foreach (['poll_seconds' => [10, 300], 'snooze_min' => [1, 240], 'max_attempts' => [1, 10], 'log_retention_days' => [30, 3650], 'scan_minutes' => [1, 60]] as $k => [$lo, $hi]) {
                $in[$k] = (string) max($lo, min($hi, (int) ($_POST[$k] ?? Settings::DEFAULTS[$k])));
            }
            if ($in['wa_webhook_body'] !== '' && json_decode(str_replace(['{to}', '{text}'], ['5511999999999', 'teste'], $in['wa_webhook_body'])) === null) {
                throw new RuntimeException('O corpo JSON do gateway de WhatsApp é inválido. Use {to} e {text} entre aspas, ex.: {"number":"{to}","text":"{text}"}.', 422);
            }
            // segredos: só grava quando algo foi digitado (vazio mantém o que já está salvo)
            foreach (Settings::SECRETS as $k) {
                if ($k !== 'webhook_token' && trim((string) ($_POST[$k] ?? '')) !== '') {
                    $in[$k] = trim((string) $_POST[$k]);
                }
            }
            Settings::set($in);
            Log::write(null, 'system', ['status' => 'info', 'message' => 'Configuração do plugin alterada.']);
            $msg = 'Configuração salva.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    Session::addMessageAfterRedirect($err !== '' ? $err : $msg, false, $err !== '' ? ERROR : INFO);
    Html::redirect($self);
}

Menu::header('Configurações', 'config');
$f = fn (string $k, string $type = 'text', string $attrs = '') => Ui::input($k, Settings::get($k), $type, $attrs);
$secret = fn (string $k, string $placeholder = '') => Ui::input($k, '', 'password', "autocomplete='new-password' placeholder='" . Ui::e(Settings::has($k) ? '•••••• (salvo; digite para trocar)' : $placeholder) . "'");
$chk = fn (string $k, string $label) => Ui::check($k, Settings::bool($k), $label);
$h = Cron::health();
$ok = fn (bool $b, string $yes, string $no) => $b ? Ui::badge($yes, '#22c55e', 'ti-check') : Ui::badge($no, '#f59e0b', 'ti-alert-triangle');

echo "<div class='esc-dash'>";
echo Ui::title('Configurações', 'Canais de aviso, pop-up, webhook de eventos, canal reserva e retenção dos logs.');
echo Views::engineBanner();

// estado dos canais
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Situação</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-3'><b>Motor (cron)</b><div>" . $ok($h['ok'], 'Ativo', 'Parado ou nunca rodou') . "</div><div class='small text-secondary'>" . ($h['last'] !== '' ? 'Último ciclo ' . Ui::e(Ui::dt($h['last'])) . ' · ' . (int) $h['ms'] . ' ms' : 'Ative a ação automática escalationsrun e o cron do sistema a cada minuto.') . '</div></div>'
    . "<div class='col-md-3'><b>E-mail</b><div>" . $ok(Channels::enabled('email') && !empty($CFG_GLPI['notifications_mailing']), 'Pronto', 'Desligado no GLPI ou aqui') . "</div><div class='small text-secondary'>Usa o envio de e-mail do GLPI.</div></div>"
    . "<div class='col-md-3'><b>Teams</b><div>" . $ok(Channels::enabled('teams'), 'Pronto', 'Desligado') . "</div><div class='small text-secondary'>Webhook do Workflows (Power Automate).</div></div>"
    . "<div class='col-md-3'><b>WhatsApp</b><div>" . $ok(Channels::enabled('whatsapp'), 'Pronto', 'Desligado') . "</div><div class='small text-secondary'>Meta Cloud API ou gateway próprio.</div></div>"
    . '</div></div></div>';

echo Ui::formOpen($self, 'mb-3') . "<input type='hidden' name='do' value='save'>";

// permissões
echo "<div class='card mb-3'><div class='card-body d-flex justify-content-between align-items-center gap-3'><div><b><i class='ti ti-shield-lock me-1'></i>Perfis e permissões</b>"
    . "<div class='small text-secondary'>Direitos de cada perfil (ver, ACK, resolver, silenciar, cancelar, logs, SLO, administrar), perfis que só são notificados, escopo de hierarquias e exceções por usuário.</div></div>"
    . Ui::button('Abrir Permissões', Menu::url('/front/permissoes.php'), 'ti-shield-lock', 'btn-primary') . '</div></div>';

// origens
$on = array_filter(array_map('trim', explode(',', Settings::get('sources'))));
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-filter me-1'></i>Origens ativas</h3></div><div class='card-body'><div class='row g-2'>";
foreach (Settings::SOURCE_LABELS as $k => $label) {
    echo "<div class='col-md-4'>" . Ui::check('sources[]', in_array($k, $on, true), $label, $k) . '</div>';
}
echo "</div><div class='small text-secondary mt-2'>Só o que estiver marcado é observado e aceito. O escalonamento manual (\"Escalar agora\") está sempre ativo. Desligar uma origem não apaga escalonamentos já abertos nem regras: elas só ficam sem efeito até você religar. O webhook devolve 403 e a Api devolve vazio enquanto a origem estiver desligada.</div></div></div>";

// simulação e caixa de saída
$sinkOn = Settings::channels(Settings::get('sink'));
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-flask me-1'></i>Caixa de saída e modo simulação</h3></div><div class='card-body'><div class='row g-2'>"
    . "<div class='col-md-4'>" . $chk('outbox', 'Guardar a cópia de cada mensagem enviada') . '</div>'
    . "<div class='col-md-8'><label class='form-label mb-1'>Simular (montar e guardar, mas <b>não enviar</b>) estes canais:</label>";
foreach (['email', 'teams', 'whatsapp'] as $k) {
    echo Ui::check('sink[]', in_array($k, $sinkOn, true), Policy::CHANNELS[$k][0], $k);
}
echo "</div></div><div class='small text-secondary mt-2'>Use a simulação em ambiente de teste, ou antes de configurar o SMTP, o Teams e o WhatsApp: tudo funciona e aparece em <a href='" . Ui::e(Menu::url('/front/outbox.php')) . "'>Mensagens enviadas</a>, sem sair nada de verdade. E-mail simulado ainda exige que o usuário tenha e-mail cadastrado. Falhas de um canal simulado não acionam um canal reserva real. Os botões de teste em “Testar” são envios reais e explícitos, mesmo com a simulação ligada.</div></div></div>";

// e-mail
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-mail me-1'></i>E-mail</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-12'>" . $chk('mail_enabled', 'Enviar escalonamentos por e-mail') . '</div>'
    . Ui::field('Assunto', $f('mail_subject'), 'col-12', 'Variáveis: {severity} {title} {level} {trace} {policy} {area}')
    . Ui::field('Texto de abertura (escalados)', "<textarea class='form-control' name='mail_intro' rows='2'>" . Ui::e(Settings::get('mail_intro')) . '</textarea>', 'col-md-6')
    . Ui::field('Texto de abertura (somente notificados)', "<textarea class='form-control' name='mail_intro_notify' rows='2'>" . Ui::e(Settings::get('mail_intro_notify')) . '</textarea>', 'col-md-6', 'Quem só é notificado recebe o e-mail sem o botão Assumir.')
    . Ui::field('Rodapé', "<textarea class='form-control' name='mail_footer' rows='2'>" . Ui::e(Settings::get('mail_footer')) . '</textarea>', 'col-md-12')
    . "</div><div class='small text-secondary mt-2'>O corpo do e-mail traz: severidade e resumo, contexto, prazos de ACK e resolução, o histórico das escaladas anteriores (quem foi acionado, por qual canal, quando e se chegou), o próximo passo e os botões Assumir e Abrir no GLPI.</div></div></div>";

// pop-up
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-bell-ringing me-1'></i>Pop-up no GLPI</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-4'>" . $chk('popup_enabled', 'Mostrar pop-up de escalonamento') . $chk('popup_sound', 'Tocar alerta sonoro') . '</div>'
    . Ui::field('Consultar a cada (segundos)', $f('poll_seconds', 'number', "min='10' max='300'"), 'col-md-4', 'Quanto menor, mais rápido aparece e mais requisições.')
    . Ui::field('“Adiar” esconde por (minutos)', $f('snooze_min', 'number', "min='1' max='240'"), 'col-md-4')
    . "</div><div class='small text-secondary mt-2'>O pop-up só aparece para quem está com o GLPI aberto no navegador. Por isso e-mail, Teams e WhatsApp seguem em paralelo.</div></div></div>";

// Teams
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-brand-teams me-1'></i>Teams</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-3'>" . $chk('teams_enabled', 'Enviar para o canal do Teams') . '</div>'
    . Ui::field('URL do webhook (Workflows / Power Automate)', $secret('teams_url', 'https://…'), 'col-md-9', 'No Teams: canal → Workflows → "Postar em um canal quando uma solicitação de webhook é recebida". Os conectores antigos (Incoming Webhook) foram descontinuados.')
    . '</div></div></div>';

// WhatsApp
$wa = Settings::get('wa_provider');
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-brand-whatsapp me-1'></i>WhatsApp</h3></div><div class='card-body'><div class='row g-3'>"
    . Ui::field('Provedor', Ui::select('wa_provider', ['off' => 'Desligado', 'meta' => 'Meta WhatsApp Cloud API (oficial)', 'webhook' => 'Gateway próprio (Evolution API, Z-API, WPPConnect...)'], $wa), 'col-md-5')
    . Ui::field('Código do país (DDI)', $f('wa_cc', 'text', "maxlength='3'"), 'col-md-2', 'Para números sem DDI. Brasil = 55.')
    . "<div class='col-md-5'><label class='form-label'>&nbsp;</label>" . $chk('wa_ack_reply', 'Aceitar resposta “ACK <número>” para assumir pelo WhatsApp') . '</div>'
    . "<div class='col-12'><div class='esc-sec'>Meta Cloud API</div></div>"
    . Ui::field('ID do número de telefone (phone_number_id)', $f('wa_meta_phone_id'), 'col-md-4')
    . Ui::field('Token de acesso permanente', $secret('wa_meta_token'), 'col-md-4')
    . Ui::field('Segredo do app (valida a resposta recebida)', $secret('wa_meta_app_secret'), 'col-md-4')
    . Ui::field('Modelo (template) aprovado', $f('wa_meta_template', 'text', "placeholder='opcional'"), 'col-md-4', 'Obrigatório para avisar fora da janela de 24 h. Parâmetros do corpo: {{1}} título, {{2}} severidade, {{3}} nível, {{4}} rastreio, {{5}} link. Vazio = texto livre.')
    . Ui::field('Idioma do modelo', $f('wa_meta_lang'), 'col-md-2')
    . "<div class='col-md-6'><label class='form-label'>Resposta recebida (configure na Meta)</label><div class='small'>URL de callback: <code>" . Ui::e(Ui::absUrl('/plugins/escalations/front/inbound.php')) . '</code><br>Token de verificação: <code>' . Ui::e(Settings::get('wa_meta_verify')) . '</code></div></div>'
    . "<div class='col-12'><div class='esc-sec'>Gateway próprio</div></div>"
    . Ui::field('URL de envio', $f('wa_webhook_url', 'text', "placeholder='https://gateway/message/sendText/instancia'"), 'col-md-6')
    . Ui::field('Cabeçalho de autenticação', $secret('wa_webhook_header', 'apikey: …'), 'col-md-6', 'Uma linha "Nome: valor". Ex.: apikey: abc123')
    . Ui::field('Corpo JSON', "<textarea class='form-control font-monospace' name='wa_webhook_body' rows='2'>" . Ui::e(Settings::get('wa_webhook_body')) . '</textarea>', 'col-12', 'Use {to} (número com DDI) e {text} (mensagem). Ex.: {"number":"{to}","text":"{text}"}')
    . "</div><div class='small text-secondary mt-2'>O número vem do campo <b>Celular</b> (ou Telefone) do usuário no GLPI.</div></div></div>";

// motor
$ownerCh = Settings::channels(Settings::get('owner_channels'));
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-settings-automation me-1'></i>Motor, canal reserva e logs</h3></div><div class='card-body'><div class='row g-3'>"
    . Ui::field('Tentativas por envio', $f('max_attempts', 'number', "min='1' max='10'"), 'col-md-3', 'Espera 1, 5, 15 min entre elas.')
    . Ui::field('Canal reserva quando o envio falha em definitivo', Ui::select('fallback_channel', ['none' => 'Nenhum', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'teams' => 'Teams'], Settings::get('fallback_channel')), 'col-md-4')
    . "<div class='col-md-5'><label class='form-label'>Avisos ao dono da hierarquia (SLO, cadeia esgotada)</label>";
foreach (Policy::CHANNELS as $k => [$label]) {
    echo Ui::check('owner_channels[]', in_array($k, $ownerCh, true), $label, $k);
}
echo '</div>'
    . "<div class='col-md-5'><label class='form-label'>&nbsp;</label>" . $chk('avisos_bridge', 'Também avisar no sino do plugin Avisos' . (Plugin::isPluginActive('avisos') ? '' : ' (plugin Avisos não está ativo)')) . '</div>'
    . Ui::field('Observar chamados e itens com prazo a cada (min)', $f('scan_minutes', 'number', "min='1' max='60'"), 'col-md-3')
    . Ui::field('Guardar logs de escalonamentos encerrados por (dias)', $f('log_retention_days', 'number', "min='30' max='3650'"), 'col-md-4')
    . '</div></div></div>';
echo "<div class='text-end mb-3'>" . Ui::submit('Salvar configuração', 'do', 'save', 'ti-device-floppy') . '</div>';
Ui::closeForm();

// tipos registrados por plugins
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-plug-connected me-1'></i>Plugins que escalam</h3></div><div class='card-body'>"
    . "<div class='small text-secondary mb-2'>Qualquer plugin pode chamar <code>Api::escalate()</code> (agora) ou <code>Api::track()</code> (acompanhar o prazo) e <code>Api::resolve()</code> (encerrar), da classe <code>GlpiPlugin\\Escalations\\Api</code>. Os tipos aparecem aqui e na tela de regras. Veja o README.</div>";
$anyType = false;
foreach ($DB->request(['FROM' => Api::SOURCES, 'ORDER' => ['plugin_key', 'type_key']]) as $t) {
    $anyType = true;
    echo "<div class='d-flex gap-2 small'><b style='min-width:140px'>" . Ui::e($t['plugin_key']) . '</b><span>' . Ui::e($t['label'] ?: $t['type_key']) . ' <code>' . Ui::e($t['type_key']) . '</code></span><span class="text-secondary ms-auto">último uso '
        . Ui::e(Ui::dt($t['last_used'])) . '</span></div>';
}
echo $anyType ? '' : "<div class='text-secondary small'>Nenhum plugin registrou tipos ainda.</div>";
echo '</div></div>';

// webhook de eventos
$url = Ui::absUrl('/plugins/escalations/front/webhook.php');
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-webhook me-1'></i>Webhook de eventos (Zabbix e outros)</h3></div><div class='card-body'>"
    . "<div class='mb-2'>URL: <code>" . Ui::e($url) . "</code> · token: " . (Settings::has('webhook_token') ? Ui::badge('configurado', '#22c55e', 'ti-lock') : Ui::badge('sem token', '#ef4444')) . '</div>'
    . "<pre class='esc-json'>curl -X POST '" . Ui::e($url) . "' \\\n  -H 'X-Escalations-Token: SEU_TOKEN' -H 'Content-Type: application/json' \\\n  -d '{\"title\":\"Disco cheio em srv01\",\"host\":\"srv01\",\"severity\":4,\"status\":\"PROBLEM\",\"trigger_id\":\"12345\",\"message\":\"/var 95%\"}'</pre>"
    . "<div class='small text-secondary mb-2'><b>severity</b>: 0 a 5 (padrão do Zabbix) ou nome. <b>status</b>: PROBLEM abre, OK/RESOLVED resolve (ou <b>value</b>: 1/0). Eventos repetidos do mesmo <b>trigger_id</b> + <b>host</b> são agrupados num só escalonamento. No Zabbix, crie um media type Webhook que poste esse JSON.</div>"
    . Ui::formOpen($self, 'd-inline') . Ui::submit('Gerar novo token', 'do', 'newtoken', 'ti-key', 'btn-outline-secondary', "data-confirm='O token atual deixa de valer. Atualize o Zabbix com o novo. Continuar?'");
Ui::closeForm();
echo '</div></div>';

// testes
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-flask me-1'></i>Testar</h3></div><div class='card-body'>"
    . "<div class='small text-secondary mb-2'>Salve a configuração antes de testar. Os testes ficam nos logs (evento “Teste de canal”) e não abrem escalonamento. Eles fazem um envio real e explícito ao provedor, inclusive com o modo simulação ligado: servem para confirmar a configuração. Para conferir o conteúdo sem enviar, use a Caixa de saída.</div>"
    . Ui::formOpen($self, 'd-flex flex-wrap gap-2 align-items-end')
    . Ui::submit('Enviar e-mail para mim', 'do', 'test_mail', 'ti-mail', 'btn-outline-primary')
    . Ui::submit('Testar Teams', 'do', 'test_teams', 'ti-brand-teams', 'btn-outline-primary')
    . "<div style='min-width:200px'><label class='form-label small mb-0'>WhatsApp (vazio = meu celular)</label>" . Ui::input('test_phone', '', 'text', "placeholder='11 99999-9999'") . '</div>'
    . Ui::submit('Testar WhatsApp', 'do', 'test_whatsapp', 'ti-brand-whatsapp', 'btn-outline-primary')
    . Ui::submit('Executar um ciclo do motor agora', 'do', 'run', 'ti-player-play', 'btn-outline-secondary');
Ui::closeForm();
echo '</div></div></div>';
Menu::footer();
