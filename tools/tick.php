<?php
/**
 * Roda UM ciclo do motor de escalonamento (sobe níveis, confere SLO, reenvia falhas, observa chamados e itens com prazo).
 * Serve para quem não tem o cron do GLPI rodando a cada minuto: chama só o Escalations, sem disparar as outras ações
 * automáticas do GLPI. O motor compartilha a trava com as outras entradas.
 *
 *   * * * * * www-data /usr/bin/php /var/www/glpi/plugins/escalations/tools/tick.php >/dev/null 2>&1
 *
 * Variável GLPI_ROOT muda a raiz do GLPI (padrão: três pastas acima deste arquivo).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = getenv('GLPI_ROOT') ?: dirname(__DIR__, 3);
chdir($root);
require $root . '/vendor/autoload.php';

$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();

try {
    $n = GlpiPlugin\Escalations\Engine::tick();
    if (in_array('-v', $argv, true)) {
        echo date('Y-m-d H:i:s') . " ciclo concluído: $n ação(ões)\n";
    }
} catch (Throwable $e) {
    GlpiPlugin\Escalations\Log::file('tick.php: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
