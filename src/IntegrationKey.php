<?php

namespace GlpiPlugin\Escalations;

/** Representações estáveis que cabem nas colunas de identidade existentes. */
final class IntegrationKey
{
    private const PREFIX = '~1:';
    public const ESCALATION_LIMIT = 190;
    public const REFERENCE_LIMIT = 120;

    public static function digest(string $kind, string ...$parts): string
    {
        return self::PREFIX . $kind . ':' . hash('sha256', serialize($parts));
    }

    public static function bounded(string $kind, string $key, int $limit): string
    {
        return mb_strlen($key) > $limit || str_starts_with($key, self::PREFIX)
            ? self::digest($kind, $key) : $key;
    }

    public static function webhook(string $explicit, string $system, string $trigger, string $host): string
    {
        if ($explicit !== '') {
            return self::bounded('event', $explicit, self::ESCALATION_LIMIT);
        }
        if (mb_strlen($system) <= 40 && !str_contains($system . $trigger . $host, '|')) {
            return 'evt:' . sha1($system . '|' . $trigger . '|' . $host);
        }
        return self::digest('trigger', $system, $trigger, $host);
    }
}
