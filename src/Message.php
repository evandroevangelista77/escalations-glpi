<?php

namespace GlpiPlugin\Escalations;

/**
 * Monta as mensagens de cada canal a partir do escalonamento: o e-mail HTML completo (com o histórico das escaladas
 * anteriores, contexto, SLO e próximo passo), o cartão do Teams, o texto curto do WhatsApp e o resumo do pop-up.
 */
class Message
{
    private const DELIVERY_LABEL = ['sent' => 'enviado', 'queued' => 'na fila', 'failed' => 'falhou', 'shown' => 'exibido', 'acked' => 'encerrado'];

    /** Contexto comum às mensagens. */
    public static function context(array $e, ?array $level, string $kind = 'level'): array
    {
        $policy = Policy::get((int) $e['policies_id']) ?: ['name' => '—', 'area' => '', 'ack_slo_min' => 0, 'resolve_slo_min' => 0];
        $levels = Policy::levels((int) $e['policies_id']);
        $pos = (int) ($level['position'] ?? $e['level']);
        $next = $e['state'] === 'open' ? ($levels[$pos + 1] ?? null) : null;
        $t = Escalation::timing($e);
        $sev = Escalation::severity((int) $e['severity']);
        $facts = json_decode((string) $e['facts'], true) ?: [];
        [$base, $slo] = array_pad(explode(':', $kind, 2), 2, '');
        return [
            'e' => $e, 'policy' => $policy, 'level' => $level, 'pos' => $pos, 'next' => $next, 'timing' => $t, 'sev' => $sev, 'facts' => $facts, 'kind' => $base, 'slo' => $slo, 'role' => 'act',
            'url' => Ui::absUrl('/plugins/escalations/front/escalonamento.php?id=' . (int) $e['id'] . '&ack=1'),
            'url_plain' => Ui::absUrl('/plugins/escalations/front/escalonamento.php?id=' . (int) $e['id']),
            'history' => Escalation::levelHistory((int) $e['id']),
            'next_in_min' => $next && $e['next_action_at'] ? max(0, (int) ceil((strtotime($e['next_action_at']) - time()) / 60)) : null,
        ];
    }

    /** Troca {placeholders} do texto editável nas configurações. */
    public static function fill(string $tpl, array $c): string
    {
        return strtr($tpl, [
            '{title}' => (string) $c['e']['title'], '{severity}' => mb_strtoupper($c['sev'][0]), '{level}' => (string) ($c['level']['name'] ?? ('N' . $c['pos'])),
            '{trace}' => (string) $c['e']['trace_id'], '{policy}' => (string) $c['policy']['name'], '{area}' => (string) $c['policy']['area'],
        ]);
    }

    private static function heading(array $c): string
    {
        return match ($c['kind']) {
            'slo_warn'   => 'SLO de ' . ($c['slo'] === 'resolve' ? 'resolução' : 'ACK') . ' em risco',
            'slo_breach' => 'SLO de ' . ($c['slo'] === 'resolve' ? 'resolução' : 'ACK') . ' estourado',
            'exhausted'  => 'Cadeia esgotada sem ACK',
            default      => Ui::levelLabel((int) $c['pos'], (string) ($c['level']['name'] ?? '')),
        };
    }

    // ------------------------------------------------------------------ e-mail

    /** @return array{0: string, 1: string, 2: string} assunto, HTML, texto */
    public static function email(array $c): array
    {
        $e = $c['e'];
        $col = $c['sev'][1];
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $t = $c['timing'];
        $subject = self::fill(Settings::get('mail_subject'), $c);
        if (($c['role'] ?? 'act') === 'notify' && $c['kind'] === 'level') {
            $subject = '[Informativo] ' . $subject;
        }
        if ($c['kind'] === 'slo_warn') {
            $subject = '[SLO em risco] ' . $subject;
        } elseif ($c['kind'] === 'slo_breach') {
            $subject = '[SLO ESTOURADO] ' . $subject;
        }

        // histórico das escaladas anteriores
        $hist = '';
        foreach ($c['history'] as $pos => $h) {
            if ($pos === $c['pos'] && $c['kind'] === 'level' && count($c['history']) === 1) {
                continue; // primeiro acionamento: ainda não há "anteriores"
            }
            $who = [];
            foreach ($h['recipients'] as $name => $chs) {
                $parts = [];
                foreach ($chs as $ch => $st) {
                    $parts[] = (Policy::CHANNELS[$ch][0] ?? $ch) . ' (' . (self::DELIVERY_LABEL[$st] ?? $st) . ')';
                }
                $who[] = '<b>' . $x($name) . '</b><br><span style="color:#64748b;font-size:12px">' . $x(implode(', ', $parts)) . '</span>';
            }
            $hist .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;white-space:nowrap"><b>' . $x(Ui::levelLabel((int) $pos, (string) $h['name'])) . '</b></td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top">' . implode('<br>', $who) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;white-space:nowrap">' . $x(Ui::dt($h['started'])) . '</td></tr>';
        }
        $histBlock = $hist !== ''
            ? '<h3 style="margin:22px 0 8px;font-size:15px;color:#0f172a">Histórico das escaladas</h3><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a">'
                . '<tr style="background:#f1f5f9"><th align="left" style="padding:8px 10px">Nível</th><th align="left" style="padding:8px 10px">Quem foi acionado</th><th align="left" style="padding:8px 10px">Quando</th></tr>' . $hist . '</table>'
            : '';

        // contexto
        $facts = '';
        foreach (array_merge(['Origem' => (Escalation::SOURCES[$e['source']] ?? $e['source']) . ($e['source_ref'] ? ' · ' . $e['source_ref'] : ''), 'Hierarquia' => $c['policy']['name'] . ($c['policy']['area'] ? ' (' . $c['policy']['area'] . ')' : '')], $c['facts']) as $k => $v) {
            $facts .= '<tr><td style="padding:5px 10px;color:#64748b;width:34%">' . $x($k) . '</td><td style="padding:5px 10px;color:#0f172a">' . $x(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . '</td></tr>';
        }
        if ((int) $e['occurrences'] > 1) {
            $facts .= '<tr><td style="padding:5px 10px;color:#64748b">Ocorrências</td><td style="padding:5px 10px">' . (int) $e['occurrences'] . ' (agrupadas)</td></tr>';
        }

        // SLO
        $ackTxt = $e['acked_at'] ? 'Reconhecido' : ($t['ack_left'] !== null && $t['ack_left'] < 0 ? 'ESTOURADO há ' . Ui::dur(-$t['ack_left']) : 'restam ' . Ui::dur($t['ack_left']));
        $resTxt = $t['resolve_left'] !== null && $t['resolve_left'] < 0 ? 'ESTOURADO há ' . Ui::dur(-$t['resolve_left']) : 'restam ' . Ui::dur($t['resolve_left']);

        // próximo passo
        $nextTxt = $c['next']
            ? 'Sem ACK em <b>' . (int) ($c['next_in_min'] ?? $c['level']['wait_min'] ?? 0) . ' min</b>, o escalonamento sobe para <b>' . $x(Ui::levelLabel((int) $c['pos'] + 1, (string) $c['next']['name'])) . '</b> ('
                . $x(implode(', ', Policy::targetNames($c['next']))) . ').'
            : 'Este é o <b>último nível</b> da hierarquia. Sem ACK, o nível será repetido e o responsável pela hierarquia será avisado.';

        $btn = fn (string $label, string $url, string $bg, string $fg = '#fff') => '<a href="' . $x($url) . '" style="display:inline-block;background:' . $bg . ';color:' . $fg
            . ';text-decoration:none;font-weight:700;padding:11px 20px;border-radius:8px;margin-right:8px;font-size:14px">' . $x($label) . '</a>';
        $notify = ($c['role'] ?? 'act') === 'notify' && $c['kind'] === 'level';
        $ask = self::asksForAck($c);
        $intro = $x($notify || $ask ? Settings::get($notify ? 'mail_intro_notify' : 'mail_intro') : 'Aviso informativo sobre o escalonamento.');
        $footer = $x(Settings::get('mail_footer'));

        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $x($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
            . '<tr><td style="background:' . $col . ';padding:18px 24px;color:#fff"><div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;opacity:.9">Escalations · ' . $x(self::heading($c)) . '</div>'
            . '<div style="font-size:21px;font-weight:700;margin-top:4px">' . $x($e['title']) . '</div>'
            . '<div style="font-size:13px;margin-top:6px;opacity:.95">Severidade <b>' . $x($c['sev'][0]) . '</b> · aberto há <b>' . $x(Ui::dur($t['age'])) . '</b> · rastreio <b>' . $x($e['trace_id']) . '</b></div></td></tr>'
            . '<tr><td style="padding:22px 24px;color:#0f172a;font-size:14px;line-height:1.5">'
            . '<p style="margin:0 0 14px">' . $intro . '</p>'
            . ($e['summary'] !== '' ? '<div style="background:#f8fafc;border-left:4px solid ' . $col . ';padding:10px 14px;border-radius:4px;margin-bottom:14px;white-space:pre-line">' . $x(mb_substr((string) $e['summary'], 0, 1200)) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border:1px solid #e2e8f0;border-radius:8px">' . $facts . '</table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px"><tr>'
            . '<td width="50%" style="padding:0 6px 0 0"><div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px"><div style="font-size:11px;color:#64748b;text-transform:uppercase">Prazo de ACK (' . (int) $c['policy']['ack_slo_min'] . ' min)</div><div style="font-weight:700;color:' . (($t['ack_left'] ?? 1) < 0 && !$e['acked_at'] ? '#dc2626' : '#0f172a') . '">' . $x($ackTxt) . '</div></div></td>'
            . '<td width="50%" style="padding:0 0 0 6px"><div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px"><div style="font-size:11px;color:#64748b;text-transform:uppercase">Prazo de resolução (' . (int) $c['policy']['resolve_slo_min'] . ' min)</div><div style="font-weight:700;color:' . (($t['resolve_left'] ?? 1) < 0 ? '#dc2626' : '#0f172a') . '">' . $x($resTxt) . '</div></div></td></tr></table>'
            . $histBlock
            . ($e['state'] === 'open' ? '<div style="margin-top:20px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;font-size:13px"><b>Próximo passo.</b> ' . $nextTxt . '</div>' : '')
            . '<div style="margin-top:22px">' . ($ask ? $btn('Assumir (ACK)', $c['url'], $col) : '') . $btn('Abrir no GLPI', $c['url_plain'], $ask ? '#e2e8f0' : $col, $ask ? '#0f172a' : '#fff') . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#64748b">' . $footer . ' · ' . $x($e['trace_id']) . ' · ' . $x(date('d/m/Y H:i')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        $text = self::plain($c);
        return [$subject, $html, $text];
    }

    // ------------------------------------------------------------------ texto simples (e-mail texto, WhatsApp)

    public static function plain(array $c): string
    {
        $e = $c['e'];
        $t = $c['timing'];
        $lines = [
            '[' . mb_strtoupper($c['sev'][0]) . '] ' . $e['title'],
            self::heading($c) . ' · rastreio ' . $e['trace_id'],
            'Aberto há ' . Ui::dur($t['age']) . ' · hierarquia ' . $c['policy']['name'],
        ];
        if ($e['summary'] !== '') {
            $lines[] = mb_substr((string) $e['summary'], 0, 300);
        }
        $lines[] = !$e['acked_at'] && $t['ack_left'] !== null ? ($t['ack_left'] < 0 ? 'Prazo de ACK ESTOURADO há ' . Ui::dur(-$t['ack_left']) : 'Prazo de ACK: restam ' . Ui::dur($t['ack_left'])) : 'ACK registrado.';
        if ($c['next']) {
            $lines[] = 'Sem ACK, sobe para ' . Ui::levelLabel((int) $c['pos'] + 1, (string) $c['next']['name']) . '.';
        }
        $lines[] = self::asksForAck($c) ? 'Assumir: ' . $c['url'] : 'Detalhes (apenas informativo): ' . $c['url_plain'];
        return implode("\n", $lines);
    }

    public static function whatsapp(array $c): string
    {
        $e = $c['e'];
        $head = ($c['kind'] === 'slo_breach' ? '⛔ *SLO ESTOURADO*' : ($c['kind'] === 'slo_warn' ? '⚠️ *SLO EM RISCO*' : '🚨 *ESCALONAMENTO*')) . ' · *' . mb_strtoupper($c['sev'][0]) . "*\n";
        $body = '*' . $e['title'] . "*\n" . self::heading($c) . ' · ' . $e['trace_id'] . "\n"
            . 'Aberto há ' . Ui::dur($c['timing']['age']) . '.';
        if (!$e['acked_at'] && $c['timing']['ack_left'] !== null) {
            $body .= "\n" . ($c['timing']['ack_left'] < 0 ? 'Prazo de ACK estourado há ' . Ui::dur(-$c['timing']['ack_left']) . '.' : 'Prazo de ACK: restam ' . Ui::dur($c['timing']['ack_left']) . '.');
        }
        $reply = !self::asksForAck($c) ? "\n\nApenas informativo: nenhuma ação sua é necessária." : (Settings::bool('wa_ack_reply') ? "\n\nResponda *ACK " . (int) $e['id'] . '* para assumir.' : '');
        return $head . $body . $reply . "\nFicha: " . $c['url_plain'];
    }

    private static function asksForAck(array $c): bool
    {
        return $c['e']['state'] === 'open' && ($c['role'] ?? 'act') !== 'notify';
    }

    // ------------------------------------------------------------------ Teams (cartão adaptável)

    public static function teamsCard(array $c): array
    {
        $e = $c['e'];
        $facts = [
            ['title' => 'Rastreio', 'value' => (string) $e['trace_id']],
            ['title' => 'Hierarquia', 'value' => (string) $c['policy']['name']],
            ['title' => 'Nível', 'value' => Ui::levelLabel((int) $c['pos'], (string) ($c['level']['name'] ?? ''))],
            ['title' => 'Aberto há', 'value' => Ui::dur($c['timing']['age'])],
            ['title' => 'Prazo de ACK', 'value' => $e['acked_at'] ? 'Reconhecido' : ($c['timing']['ack_left'] < 0 ? 'ESTOURADO há ' . Ui::dur(-$c['timing']['ack_left']) : 'restam ' . Ui::dur($c['timing']['ack_left']))],
        ];
        foreach ($c['facts'] as $k => $v) {
            $facts[] = ['title' => (string) $k, 'value' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v];
        }
        $color = match (true) {
            $c['sev'][0] === 'Crítica' => 'Attention', $c['sev'][0] === 'Alta' => 'Warning', default => 'Accent',
        };
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4',
                    'body' => array_values(array_filter([
                        ['type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'color' => $color, 'wrap' => true, 'text' => '[' . mb_strtoupper($c['sev'][0]) . '] ' . $e['title']],
                        ['type' => 'TextBlock', 'isSubtle' => true, 'wrap' => true, 'spacing' => 'None', 'text' => self::heading($c)],
                        $e['summary'] !== '' ? ['type' => 'TextBlock', 'wrap' => true, 'text' => mb_substr((string) $e['summary'], 0, 500)] : null,
                        ['type' => 'FactSet', 'facts' => $facts],
                        $c['next'] ? ['type' => 'TextBlock', 'wrap' => true, 'isSubtle' => true, 'text' => 'Sem ACK, sobe para ' . Ui::levelLabel((int) $c['pos'] + 1, (string) $c['next']['name']) . '.'] : null,
                    ])),
                    'actions' => array_values(array_filter([
                        self::asksForAck($c) ? ['type' => 'Action.OpenUrl', 'title' => 'Assumir (ACK)', 'url' => $c['url']] : null,
                        ['type' => 'Action.OpenUrl', 'title' => 'Abrir no GLPI', 'url' => $c['url_plain']],
                    ])),
                ],
            ]],
        ];
    }

    // ------------------------------------------------------------------ pop-up

    /** Dados do pop-up (o JavaScript monta o modal). */
    public static function popup(array $e, array $delivery): array
    {
        $levels = Policy::levels((int) $e['policies_id']);
        $pos = (int) $delivery['level'] ?: (int) $e['level'];
        $t = Escalation::timing($e);
        $sev = Escalation::severity((int) $e['severity']);
        $canAck = $e['state'] === 'open' && $delivery['kind'] === 'level' && (($delivery['role'] ?? 'act') === 'act') && Perms::can('ack', $e, (int) $delivery['users_id']);
        $hist = [];
        foreach (Escalation::levelHistory((int) $e['id']) as $p => $h) {
            $hist[] = ['level' => $p, 'name' => $h['name'], 'label' => Ui::levelLabel((int) $p, (string) $h['name']), 'who' => implode(', ', array_keys($h['recipients'])), 'at' => Ui::dt($h['started'])];
        }
        return [
            'id'        => (int) $delivery['id'],
            'esc_id'    => (int) $e['id'],
            'kind'      => (string) $delivery['kind'],
            'trace'     => (string) $e['trace_id'],
            'title'     => (string) $e['title'],
            'summary'   => mb_substr((string) $e['summary'], 0, 400),
            'severity'  => $sev[0],
            'color'     => $sev[1],
            'level'     => Ui::levelLabel($pos, (string) ($levels[$pos]['name'] ?? '')),
            'age'       => Ui::dur($t['age']),
            'ack_left'  => $e['acked_at'] ? null : $t['ack_left'],
            'can_ack'   => $canAck,
            'notify_only' => $delivery['kind'] === 'level' && !$canAck,
            'history'   => $hist,
            'url'       => Escalation::url((int) $e['id']),
        ];
    }
}
