<?php
declare(strict_types=1);
return [
    'id'=>'shopkmp-business-api',
    'basePath'=>dirname(__DIR__),
    'controllerNamespace'=>'Shop\\controllers',
    'catchAll'=>['api/dispatch'],
    'components'=>[
        'request'=>['enableCookieValidation'=>false,'enableCsrfValidation'=>false,'scriptUrl'=>'/index.php'],
        'response'=>['format'=>\yii\web\Response::FORMAT_RAW,'charset'=>'UTF-8'],
        'user'=>['identityClass'=>null,'enableSession'=>false,'enableAutoLogin'=>false,'loginUrl'=>null],
        'errorHandler'=>['class'=>\Shop\infrastructure\ErrorHandler::class],
        'log'=>['targets'=>[['class'=>\yii\log\FileTarget::class,'logFile'=>'php://stderr','levels'=>['error','warning'],'logVars'=>[],'enableRotation'=>false]]],
    ],
];
