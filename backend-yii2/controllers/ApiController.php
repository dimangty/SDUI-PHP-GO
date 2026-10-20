<?php
declare(strict_types=1);
namespace Shop\controllers;

use Shop\infrastructure\{ApiKernel,Database,Env};

final class ApiController extends \yii\web\Controller
{
    public $enableCsrfValidation=false;
    public function actionDispatch(): string
    {
        $request=\Yii::$app->request;$headers=[];
        foreach($request->headers as $name=>$values){$headers[$name]=implode(',',$values);}
        $type=$headers['content-type']??'';
        $limit=(int)Env::get(str_starts_with(strtolower($type),'multipart/form-data')?'AVATAR_BODY_LIMIT_BYTES':(str_contains($request->url,'/payments/webhook')?'WEBHOOK_BODY_LIMIT_BYTES':'JSON_BODY_LIMIT_BYTES'),str_starts_with(strtolower($type),'multipart/form-data')?'5242880':'1048576');
        $stream=fopen('php://input','rb');$body=stream_get_contents($stream,$limit+1);fclose($stream);
        $result=(new ApiKernel(new Database(Env::get('DATABASE_URL'))))->handle($request->method,$request->url,$headers,$body,$_SERVER['REMOTE_ADDR']??'127.0.0.1');
        $response=\Yii::$app->response;$response->statusCode=$result['status'];
        foreach($result['headers'] as $name=>$value){$response->headers->set($name,$value);}
        if(in_array($result['status'],[204,304],true) || $result['data']===null){return '';}
        return $result['raw']?$result['data']:json_encode($result['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
