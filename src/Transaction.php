<?php

namespace GlpiPlugin\Escalations;

use Closure;
use DBmysql;
use ReflectionMethod;
use Throwable;

final class Transaction
{
    /** O GLPI 11 não expõe publicamente se a conexão já está em uma transação. */
    public static function isActive(): bool
    {
        global $DB;
        return (new ReflectionMethod(DBmysql::class, 'isInTransaction'))->invoke($DB);
    }

    /**
     * @template T
     * @param Closure(): T $action
     * @return T
     */
    public static function run(Closure $action): mixed
    {
        global $DB;
        $DB->beginTransaction();
        try {
            $result = $action();
            $DB->commit();
            return $result;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
}
