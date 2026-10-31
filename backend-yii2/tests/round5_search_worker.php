<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/SearchNativeFixture.php';
SearchNativeFixture::configure();

// Real statements and fresh connections; a child never inherits the parent's PDO.
final class SearchBarrierStatement extends PDOStatement
{
    private static int $products=0;
    protected function __construct() {}
    public function execute(?array $params=null): bool
    {
        if(getenv('ROUND5_SEARCH_BARRIER')==='build' && str_starts_with($this->queryString,'SELECT p.*') && ++self::$products===2){
            fwrite(STDOUT,"BUILD_PAUSED\n");fflush(STDOUT);$end=hrtime(true)/1e9+30;
            while(hrtime(true)/1e9<$end){usleep(10000);}throw new RuntimeException('Native interruption barrier was not released');
        }
        $result=parent::execute($params);
        if(str_contains($this->queryString,'pg_try_advisory_')){fwrite(STDOUT,"LOCK_ATTEMPT\n");fflush(STDOUT);}
        return $result;
    }
}
$db=SearchNativeFixture::db();
if(in_array(getenv('ROUND5_SEARCH_BARRIER'),['1','build'],true)){$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SearchBarrierStatement::class]);}
$search=SearchNativeFixture::search($db,$argv[2]);
match($argv[1]){'bootstrap'=>$search->bootstrap(100000,60),'index'=>$search->index($argv[3]),default=>throw new InvalidArgumentException('Unknown search worker action')};
fwrite(STDOUT,"DONE\n");
