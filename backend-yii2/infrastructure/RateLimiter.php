<?php
declare(strict_types=1);
namespace Shop\infrastructure;
use Shop\domain\ApiError;

final class RateLimiter
{
    public function __construct(private Database $db) {}
    public function check(string $bucket,int $limit): void
    {
        if($limit<1){return;}$window=Env::duration('RATE_LIMIT_WINDOW',60);
        $limited=$this->db->transaction(function()use($bucket,$limit,$window){
            $this->db->advisory($bucket);
            $this->db->execute('DELETE FROM rate_limit_hits WHERE bucket_key=? AND occurred_at<=now()-?*interval \'1 second\'',[$bucket,$window]);
            $n=$this->db->one('SELECT count(*) AS n FROM rate_limit_hits WHERE bucket_key=?',[$bucket])['n'];
            if($n>=$limit){return true;}
            $this->db->execute('INSERT INTO rate_limit_hits(bucket_key,occurred_at) VALUES(?,now())',[$bucket]);return false;
        });if($limited){throw new ApiError('RATE_LIMITED','too many requests');}
    }
}
