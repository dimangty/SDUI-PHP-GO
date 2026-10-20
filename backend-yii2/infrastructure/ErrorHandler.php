<?php
declare(strict_types=1);
namespace Shop\infrastructure;

final class ErrorHandler extends \yii\web\ErrorHandler
{
    protected function renderException($exception): void
    {
        error_log('Yii boundary failure: '.get_class($exception).' '.$exception->getMessage());
        $response=\Yii::$app->response;$response->format=\yii\web\Response::FORMAT_JSON;$response->statusCode=500;
        $response->data=['error'=>['code'=>'INTERNAL_ERROR','message'=>'internal server error','traceId'=>\Shop\domain\Ids::uuid()]];
        $response->headers->set('Cache-Control','no-store');$response->send();
    }
}
