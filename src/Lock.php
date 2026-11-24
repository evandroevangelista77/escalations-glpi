<?php

namespace GlpiPlugin\Escalations;

use Closure;
use RuntimeException;

/** Travas de sessão do MySQL/MariaDB, compartilhadas por cron, CLI e requisições web. */
final class Lock
{
    /**
     * @template T
     * @param Closure(): T $action
     * @return T|null Null quando a tentativa sem espera encontra a trava ocupada.
     */
    public static function run(string $resource, Closure $action, int $wait = 30): mixed
    {
        global $DB;
        // O digest inclui o banco e cabe no limite de 64 caracteres do GET_LOCK.
        $name = $DB->quote(hash('sha256', $DB->dbdefault . ':escalations:' . $resource));
        [$acquired] = $DB->fetchRow($DB->doQuery("SELECT GET_LOCK($name, $wait)"));
        if ((int) $acquired !== 1) {
            if ($acquired !== null && $wait === 0) {
                return null;
            }
            throw new RuntimeException('Não foi possível obter a trava do Escalations.', 503);
        }
        try {
            return $action();
        } finally {
            $DB->doQuery("SELECT RELEASE_LOCK($name)");
        }
    }
}
