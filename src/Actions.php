<?php

namespace GlpiPlugin\Escalations;

use RuntimeException;

/**
 * Ações do usuário sobre um escalonamento (ficha, pop-up, WhatsApp): uma só porta de entrada, com a conferência de
 * permissão por ação (Perms::can: perfil, exceção do usuário, "somente notificado", escopo e papel no nível).
 * Lança RuntimeException com a mensagem para a tela.
 */
class Actions
{
    private const CAP = ['ack' => 'ack', 'resolve' => 'resolve', 'silence' => 'silence', 'resend' => 'silence', 'cancel' => 'cancel'];

    /** @return string mensagem de sucesso */
    public static function run(string $do, int $escId, int $uid, string $via = 'glpi', array $in = []): string
    {
        $e = Escalation::get($escId);
        if (!$e || !Escalation::canSee($e)) {
            throw new RuntimeException('Escalonamento indisponível.', 404);
        }
        $cap = self::CAP[$do] ?? null;
        if ($cap === null) {
            throw new RuntimeException('Ação desconhecida.', 422);
        }
        if (!Perms::can($cap, $e, $uid)) {
            throw new RuntimeException(Perms::notifyOnly($uid)
                ? 'Você está configurado como "somente notificado": recebe os avisos, mas não pode agir neste escalonamento.'
                : 'Você não tem permissão para "' . (Perms::caps()[$cap][0] ?? $cap) . '" neste escalonamento.', 403);
        }
        switch ($do) {
            case 'ack':
                Escalation::ack($escId, $uid, $via, (string) ($in['note'] ?? ''));
                return 'Escalonamento reconhecido. A escalada foi interrompida.';
            case 'resolve':
                Escalation::resolve($escId, 'manual', $uid, (string) ($in['note'] ?? ''));
                return 'Escalonamento resolvido.';
            case 'silence':
                Escalation::silence($escId, (int) ($in['minutes'] ?? 30), $uid);
                return 'Escalada pausada. O SLO continua contando.';
            case 'cancel':
                Escalation::cancel($escId, trim((string) ($in['note'] ?? '')) ?: 'cancelado manualmente', $uid);
                return 'Escalonamento cancelado.';
            case 'resend':
                Engine::resend($escId, $uid);
                return 'Avisos reenviados.';
        }
        throw new RuntimeException('Ação desconhecida.', 422);
    }
}
