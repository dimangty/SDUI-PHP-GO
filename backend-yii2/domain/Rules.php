<?php
declare(strict_types=1);
namespace Shop\domain;

final class Rules
{
    public static function device(string $id): void
    {
        self::deviceAddress($id);
        if($id==='.' || $id==='..'){throw new ApiError('INVALID_DEVICE_ID','deviceId must not be a dot path segment');}
    }
    /** Existing identifiers may only be addressed through owner-scoped query revocation. */
    public static function deviceAddress(string $id): void
    { if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $id)) { throw new ApiError('INVALID_DEVICE_ID', 'deviceId must be URL-safe and contain 1 to 255 characters'); } }
    public static function deviceName(string $name): string
    {
        $name=trim($name);
        if ($name==='' || mb_strlen($name)>255) { throw new ApiError('DEVICE_NAME_REQUIRED','deviceName must contain 1 to 255 characters'); }
        return $name;
    }
    public static function password(string $password): void
    {
        if (mb_strlen($password)<8 || strlen($password)>128 || !preg_match('/\p{Ll}/u',$password) || !preg_match('/\p{Lu}/u',$password) || !preg_match('/\p{N}/u',$password)) {
            throw new ApiError('WEAK_PASSWORD','password must contain upper/lower-case letters and a digit and be at least 8 characters');
        }
    }
    public static function email(string $email): void
    {
        if (strlen($email)>254 || !preg_match('/^[A-Za-z0-9+_.-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D',$email) || str_contains($email,'..') || str_starts_with($email,'.') || str_contains($email,'.@')) { throw new ApiError('INVALID_EMAIL','invalid email'); }
        foreach(explode('.',explode('@',$email)[1]) as $label) { if ($label==='' || $label[0]==='-' || str_ends_with($label,'-')) { throw new ApiError('INVALID_EMAIL','invalid email'); } }
    }
    public static function profile(string $name, string $phone): array
    {
        $name=trim($name); $phone=trim($phone);
        if ($name==='' || mb_strlen($name)>255) { throw new ApiError('INVALID_FULL_NAME','fullName must contain 1 to 255 characters'); }
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)) { throw new ApiError('INVALID_PHONE','invalid phone'); }
        return [$name,$phone];
    }
    public static function pin(string $pin, ?string $birthday): void
    {
        if (!preg_match('/^[0-9]{4,6}$/D',$pin)) { throw new ApiError('INVALID_PIN_FORMAT','PIN must contain 4 to 6 digits'); }
        $weak=in_array($pin,['0852','1004','1212','1313','2000','2001','2580','4321','5683','6969','8068'],true);
        $asc=$desc=true;
        for($i=1;$i<strlen($pin);$i++) { $asc=$asc && (int)$pin[$i]===((int)$pin[$i-1]+1)%10; $desc=$desc && (int)$pin[$i]===((int)$pin[$i-1]+9)%10; }
        $weak=$weak || $asc || $desc;
        for($size=1;$size<=intdiv(strlen($pin),2);$size++) { if(strlen($pin)%$size===0 && str_repeat(substr($pin,0,$size),intdiv(strlen($pin),$size))===$pin){$weak=true;} }
        if(strlen($pin)%2===0){$paired=true;for($i=0;$i<strlen($pin);$i+=2){$paired=$paired && $pin[$i]===$pin[$i+1];}$weak=$weak||$paired;}
        if($birthday!==null){$d=new \DateTimeImmutable($birthday);foreach(['dm','md','Y','ym','my','dmy','mdy'] as $format){if(str_contains($pin,$d->format($format))){$weak=true;}}}
        if($weak){throw new ApiError('WEAK_PIN','PIN must not be common, follow a keypad pattern, repeat digits or blocks, use paired digits, or form an ascending or descending sequence');}
    }
    public static function plain(string $text): string { return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)); }
    public static function page(array $q): array
    {
        $page=self::queryInt($q,'page',0); $size=self::queryInt($q,'size',20);
        return [$page,min(100,$size<1?20:$size)];
    }
    public static function queryInt(array $q,string $key,int $default): int
    {
        $raw=trim((string)($q[$key]??'')); if($raw===''){return $default;}
        if(!preg_match('/^\+?[0-9]{1,15}$/D',$raw)){throw new ApiError('INVALID_PAGINATION',"$key must be a non-negative integer");}
        return (int)$raw;
    }
    public static function pageDto(array $items,int $page,int $size,int $total): array
    {return ['items'=>$items,'page'=>$page,'size'=>$size,'totalPages'=>intdiv($total+$size-1,$size),'totalItems'=>$total];}
    public static function key(string $key): string
    { $key=trim($key);if(strlen($key)>255){throw new ApiError('INVALID_IDEMPOTENCY_KEY','Idempotency-Key is too long');}return $key; }
}
