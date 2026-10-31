<?php
declare(strict_types=1);
namespace Shop\controllers;

use Shop\infrastructure\{Database,Env,OpenSearch};
use Shop\repositories\ShopRepository;
use Shop\application\{MaintenanceService,CommerceService,PaymentOperations,RecoveryService,CredentialService,SessionService};

final class MaintenanceController extends \yii\console\Controller
{
    private function credentials(): CredentialService
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);return new CredentialService($db,$repo,new SessionService($db,$repo));
    }
    public function actionLegacyDevices(): int
    {
        $this->stdout(json_encode($this->credentials()->legacyDeviceCounts(),JSON_THROW_ON_ERROR)."\n");return 0;
    }
    public function actionLegacyDeviceApply(string $user,string $kind,string $device,string $evidence): int
    {
        $this->credentials()->remediateLegacyDevice($user,$kind,$device,$evidence);
        $this->stdout("Legacy credential revoked; operator ticket recorded.\n");return 0;
    }
    public function actionRun(): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
        $count=(new MaintenanceService($db,$repo,new CommerceService($db,$repo)))->run();
        $this->stdout("Expired orders cancelled: $count\n");return 0;
    }
    public function actionSearchBootstrap(int $limit=100000,int|float $seconds=900): int
    {
        $db=new Database(Env::get('DATABASE_URL'));
        $this->stdout(json_encode((new OpenSearch($db,new ShopRepository($db)))->bootstrap($limit,$seconds),JSON_THROW_ON_ERROR)."\n");return 0;
    }
    public function actionReindex(int $limit=100000,int|float $seconds=900): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$count=(new OpenSearch($db,new ShopRepository($db)))->reindex($limit,$seconds);
        $this->stdout("SQL products reconciled: $count\n");return 0;
    }
    public function actionSearchInventory(int $limit=100000,int|float $seconds=120): int
    {
        $db=new Database(Env::get('DATABASE_URL'));
        $this->stdout(json_encode((new OpenSearch($db,new ShopRepository($db)))->inventory($limit,$seconds),JSON_THROW_ON_ERROR)."\n");return 0;
    }
    public function actionSearchStage(string $target,int $limit=100000,int|float $seconds=900): int
    {
        $db=new Database(Env::get('DATABASE_URL'));
        $this->stdout(json_encode((new OpenSearch($db,new ShopRepository($db)))->stage($target,$limit,$seconds),JSON_THROW_ON_ERROR)."\n");return 0;
    }
    public function actionPayments(): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
        $report=(new PaymentOperations($db,$repo,new CommerceService($db,$repo)))->recover();
        $this->stdout(json_encode(['dispatched'=>$report['dispatched'],'manual'=>count($report['review'])],JSON_THROW_ON_ERROR)."\n");return $report['review']===[]?0:2;
    }
    public function actionRecover(): int
    {
        $report=(new RecoveryService(new Database(Env::get('DATABASE_URL'))))->run();
        $this->stdout(json_encode($report,JSON_THROW_ON_ERROR)."\n");return 0;
    }
    public function actionSessionRecover(string $operation,string $confirmation,string $evidence): int
    {
        (new RecoveryService(new Database(Env::get('DATABASE_URL'))))->recoverSession($operation,$confirmation==='independently-confirmed',$evidence);
        return 0;
    }
    public function actionPaymentReconcile(string $operation,string $outcome,string $transaction,string $evidence): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
        (new PaymentOperations($db,$repo,new CommerceService($db,$repo)))->reconcile($operation,$outcome,$transaction,$evidence);
        $this->stdout("Verified provider outcome recorded; run maintenance/payments to dispatch any compensation.\n");return 0;
    }
    public function actionRoutes(): int
    {
        foreach(require dirname(__DIR__).'/config/routes.php' as $r){$this->stdout($r[0].' '.$r[3].' '.$r[1].'.'.$r[2]."\n");}return 0;
    }
}
