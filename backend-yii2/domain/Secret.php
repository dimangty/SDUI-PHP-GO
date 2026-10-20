<?php
declare(strict_types=1);
namespace Shop\domain;

final class Secret
{
    public static function hash(string $value): string
    {
        $native = password_hash($value, PASSWORD_ARGON2ID, ['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]);
        $p = explode('$', $native);
        return 'argon2id$v=19$'.$p[3].'$'.bin2hex(base64_decode($p[4], true)).'$'.bin2hex(base64_decode($p[5], true));
    }
    public static function verify(string $value, string $encoded): bool
    {
        if (!preg_match('/^argon2id\$v=19\$m=(\d+),t=(\d+),p=(\d+)\$([a-fA-F0-9]{32})\$([a-fA-F0-9]{64})$/D', $encoded, $m)
            || (int)$m[1] < 8192 || (int)$m[1] > 65536 || (int)$m[2] < 1 || (int)$m[2] > 4 || (int)$m[3] < 1 || (int)$m[3] > 4) { return false; }
        return password_verify($value, '$argon2id$v=19$m='.$m[1].',t='.$m[2].',p='.$m[3].'$'.rtrim(base64_encode(hex2bin($m[4])), '=').'$'.rtrim(base64_encode(hex2bin($m[5])), '='));
    }
}
