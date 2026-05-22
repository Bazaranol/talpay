<?php

namespace App\Support;

final class EmailMask
{
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
