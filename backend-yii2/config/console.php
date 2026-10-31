<?php
declare(strict_types=1);
$url=parse_url(\Shop\infrastructure\Env::get('DATABASE_URL'));$q=[];parse_str($url['query']??'',$q);
return [
    'id'=>'shopkmp-cli','basePath'=>dirname(__DIR__),'controllerNamespace'=>'Shop\\controllers',
    'controllerMap'=>[
        'migrate'=>['class'=>\yii\console\controllers\MigrateController::class,'migrationPath'=>'@app/migrations','migrationTable'=>'yii2_migration_history'],
        'maintenance'=>\Shop\controllers\MaintenanceController::class,
        'development-seed'=>\Shop\controllers\DevelopmentSeedController::class,
        'auth'=>\Shop\controllers\AuthController::class,
    ],
    'components'=>['db'=>[
        'class'=>\yii\db\Connection::class,
        'dsn'=>'pgsql:host='.($url['host']??'localhost').';port='.($url['port']??5432).';dbname='.rawurldecode(ltrim($url['path']??'','/')).';sslmode='.($q['sslmode']??'prefer'),
        'username'=>rawurldecode($url['user']??''),'password'=>rawurldecode($url['pass']??''),'charset'=>'utf8',
    ]],
];
