<?php
declare(strict_types=1);
defined('YII_DEBUG') || define('YII_DEBUG',false);
defined('YII_ENV') || define('YII_ENV',getenv('APP_ENV')==='production'?'prod':'dev');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/vendor/yiisoft/yii2/Yii.php';
(new yii\web\Application(require dirname(__DIR__).'/config/web.php'))->run();
