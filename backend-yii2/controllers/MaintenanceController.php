<?php
declare(strict_types=1);
namespace Shop\controllers;

use Shop\infrastructure\{Database,Env,OpenSearch};
use Shop\repositories\ShopRepository;
use Shop\application\{MaintenanceService,CommerceService};

final class MaintenanceController extends \yii\console\Controller
{
    public function actionRun(): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
        $count=(new MaintenanceService($db,$repo,new CommerceService($db,$repo)))->run();
        $this->stdout("Expired orders cancelled: $count\n");return 0;
    }
    public function actionReindex(): int
    {
        $db=new Database(Env::get('DATABASE_URL'));$count=(new OpenSearch($db,new ShopRepository($db)))->reindex();
        $this->stdout("SQL products reconciled: $count\n");return 0;
    }
    public function actionRoutes(): int
    {
        foreach(require dirname(__DIR__).'/config/routes.php' as $r){$this->stdout($r[0].' '.$r[3].' '.$r[1].'.'.$r[2]."\n");}return 0;
    }
}
