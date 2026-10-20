<?php
declare(strict_types=1);
namespace Shop\domain;

final class Money
{
    public const MAX = 999999999999;
    public static function parse(string $raw): int
    {
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{0,2}))?$/D', $raw, $m)) {
            throw new ApiError('BAD_REQUEST', 'invalid money value');
        }
        $whole = ltrim($m[2], '0') ?: '0';
        $fraction = str_pad($m[3] ?? '', 2, '0');
        $digits = ltrim($whole . $fraction, '0') ?: '0';
        if (strlen($digits) > 18 || (strlen($digits) === 18 && strcmp($digits, '999999999999999999') > 0)) {
            throw new ApiError('BAD_REQUEST', 'money value is out of range');
        }
        return ($m[1] === '-' ? -1 : 1) * (int)$digits;
    }
    public static function format(int $cents): string
    { return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT); }
    public static function line(int $price, int $quantity): int
    {
        if ($price < 0 || $quantity < 0 || ($quantity > 0 && $price > intdiv(self::MAX, $quantity))) {
            throw new ApiError('AMOUNT_TOO_LARGE', 'order total exceeds the supported range');
        }
        return $price * $quantity;
    }
    public static function add(int $a, int $b): int
    {
        if ($b < 0 || $a < 0 || $a > self::MAX - $b) { throw new ApiError('AMOUNT_TOO_LARGE', 'order total exceeds the supported range'); }
        return $a + $b;
    }
}
