<?php

namespace App\Enums;

/** Права ключа API (abilities токена Sanctum). */
enum ApiAbility: string
{
    case CommentsRead = 'comments:read';
    case CommentsWrite = 'comments:write';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
