<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\application\{AuthService,AvatarService,CatalogService,CommerceService,CredentialService,PaymentService,ProfileService,SessionService};
use Shop\domain\{ApiError,Ids};
use Shop\repositories\ShopRepository;

/** HTTP boundary. Routing/auth/DTO validation only; business mutations live in application services. */
final class ApiKernel
{
    private array $routes;
    private AuthService $auth;
    private AvatarService $avatar;
    private CatalogService $catalog;
    private CommerceService $commerce;
    private CredentialService $credentials;
    private PaymentService $payments;
    private ProfileService $profile;
    public function __construct(private Database $db)
    {
        $this->routes=require dirname(__DIR__).'/config/routes.php';$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);
        $this->auth=new AuthService($db,$repo,$sessions);$this->avatar=new AvatarService($db,$repo);$this->catalog=new CatalogService($db,$repo);
        $this->commerce=new CommerceService($db,$repo);$this->credentials=new CredentialService($db,$repo,$sessions);
        $this->payments=new PaymentService($db,$repo,$this->commerce);$this->profile=new ProfileService($db,$repo);
        foreach($this->routes as $r){if(!method_exists('Shop\\application\\'.$r[1].'Service',$r[2])){throw new \LogicException('Missing route operation '.$r[0]);}}
    }
    public function handle(string $method,string $uri,array $headers,string $body,string $remote): array
    {
        $headers=array_change_key_case($headers,CASE_LOWER);$trace=$headers['x-request-id']??'';
        if(!preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$trace)){$trace=Ids::uuid();}
        $outHeaders=['content-type'=>'application/json; charset=utf-8','x-request-id'=>$trace,'x-content-type-options'=>'nosniff','cache-control'=>'no-store'];
        try{
            $path=parse_url($uri,PHP_URL_PATH);$query=[];parse_str(parse_url($uri,PHP_URL_QUERY)??'',$query);
            foreach($query as $v){if(!is_string($v)){throw new ApiError('BAD_REQUEST','query parameters must be scalar strings');}}
            $origin=trim($headers['origin']??'');
            $allowedOrigins=array_filter(array_map('trim',explode(',',Env::get('CORS_ALLOWED_ORIGINS'))),fn($value)=>$value!=='' && $value!=='*');
            $originAllowed=in_array($origin,$allowedOrigins,true);
            if($origin!==''){$outHeaders['vary']='Origin';}
            if($originAllowed){
                $outHeaders+=['access-control-allow-origin'=>$origin,
                    'access-control-allow-headers'=>'Authorization, Content-Type, Idempotency-Key, X-Admin-Bootstrap-Token, X-Request-Id, X-Webhook-Timestamp, X-Webhook-Id, X-Webhook-Signature',
                    'access-control-allow-methods'=>'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                    'access-control-expose-headers'=>'Retry-After, X-Request-Id','access-control-max-age'=>'600'];
            }
            // Match Go: CORS controls browser access, not authorization of actual requests.
            if($method==='OPTIONS'){
                if($origin!=='' && !$originAllowed){throw new ApiError('CORS_ORIGIN_DENIED','origin is not allowed',[],403);}
                return $this->result(204,null,$outHeaders);
            }
            if($method==='GET' && in_array($path,['/health','/health/live','/health/ready'],true)){
                if($path==='/health/live'){return $this->result(200,['status'=>'ok','components'=>['process'=>'up']],$outHeaders);}
                return $this->ready($outHeaders);
            }
            if(preg_match('~^/uploads/users/([^/]+)/([^/]+)$~D',$path,$m) && $method==='GET'){
                $u=$this->authenticate($headers);$file=$this->avatar->download($u,rawurldecode($m[1]),rawurldecode($m[2]));$outHeaders['content-type']=$file['type'];return $this->result(200,$file['data'],$outHeaders,true);
            }
            if(preg_match('~^/demo/payments/([^/]+)$~D',$path,$m) && !Env::production() && Env::get('PAYMENT_GATEWAY_URL')===''){
                if(!in_array($method,['GET','POST'],true)){throw new ApiError('METHOD_NOT_ALLOWED','method not allowed');}
                $id=rawurldecode($m[1]);$token=$query['token']??'';
                if(!Ids::valid($id) || Env::get('PAYMENT_WEBHOOK_SECRET')==='' || !hash_equals($this->payments->demoToken($id),$token)){throw new ApiError('DEMO_PAYMENT_FORBIDDEN','invalid demo payment token');}
                if($method==='POST'){$this->payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'demo-'.Ids::uuid()],'demo-webhook-'.Ids::uuid());$html='<!doctype html><html><body><h1>Payment completed</h1><p><a href="shopkmp://payment/'.$id.'">Return to ShopKMP</a></p></body></html>';}
                else{$html='<!doctype html><html><body><h1>ShopKMP fake gateway</h1><p>Payment '.$id.' is ready.</p><form method="post"><button type="submit">Complete payment</button></form></body></html>';}
                $outHeaders['content-type']='text/html; charset=utf-8';$outHeaders['content-security-policy']="default-src 'none'; form-action 'self'; base-uri 'none'";return $this->result(200,$html,$outHeaders,true);
            }
            if($method==='GET' && (str_starts_with($path,'/images/products/') || str_starts_with($path,'/icons/'))){return $this->media($path,$outHeaders);}
            $relative=str_starts_with($path,'/api/v1/')?substr($path,7):null;
            if($relative===null){throw new ApiError('NOT_FOUND','route not found');}
            [$route,$params]=$this->match($method,$relative);$user=null;$authError=null;
            if($route[3]!=='public'){
                try{$user=$this->authenticate($headers);}catch(\Throwable $e){$authError=$e;}
            }
            // Like requireAuthentication in Go, account for failed auth at the unverified
            // network ceiling before returning its error. Only verified users gain a multiplier.
            $client=Network::client($remote,$headers);$this->rateLimit($method,$path,$client,$user);
            if($authError!==null){throw $authError;}
            if($route[3]==='admin' && !$user['is_admin']){throw new ApiError('ADMIN_REQUIRED','admin role required');}
            $limit=(int)Env::get($relative==='/payments/webhook'?'WEBHOOK_BODY_LIMIT_BYTES':($relative==='/users/me/avatar' && str_starts_with(strtolower($headers['content-type']??''),'multipart/form-data')?'AVATAR_BODY_LIMIT_BYTES':'JSON_BODY_LIMIT_BYTES'),$relative==='/users/me/avatar'?'5242880':'1048576');
            if(strlen($body)>$limit){throw new ApiError('REQUEST_TOO_LARGE','request body exceeds the configured limit');}
            if($relative==='/payments/webhook'){$this->verifyWebhook($headers,$body,$remote);}
            $input=[];
            if($route[4]!==null && !($relative==='/auth/logout' && $body==='')){$input=JsonInput::decode($body,$headers['content-type']??'',$route[4],$limit);}
            // IDs from routes and JSON are never interpolated into SQL. Invalid UUIDs fail at the boundary.
            foreach($params as $key=>$value){if($key!=='deviceID' && !Ids::valid($value)){throw new ApiError('BAD_REQUEST','invalid resource identifier');}}
            foreach(['productId','categoryId','deliveryAddressId','paymentId'] as $key){if(isset($input[$key]) && !Ids::valid($input[$key])){throw new ApiError('BAD_REQUEST','invalid resource identifier');}}
            if(isset($query['categoryId']) && $query['categoryId']!=='' && !Ids::valid($query['categoryId'])){throw new ApiError('BAD_REQUEST','invalid category identifier');}
            $value=$this->dispatch($route,$params,$query,$input,$headers,$body,$user);
            $status=$route[5];if($value===null && $status===200){$value=null;}
            // Public catalog ETags are computed from actual data; no personalized response is cached.
            if($method==='GET' && $route[1]==='Catalog' && !isset($headers['authorization'])){
                $etag='"'.hash('sha256',json_encode($value,JSON_THROW_ON_ERROR)).'"';$outHeaders['etag']=$etag;$outHeaders['cache-control']='public, max-age=0, must-revalidate';
                if(($headers['if-none-match']??'')===$etag){return $this->result(304,null,$outHeaders);}
            }
            return $this->result($status,$value,$outHeaders);
        }catch(\Throwable $e){
            if(!$e instanceof ApiError){error_log('Request '.$trace.' failed: '.get_class($e).' '.$e->getMessage());$e=$e instanceof \PDOException?new ApiError('DATABASE_UNAVAILABLE','database operation failed'):new ApiError('INTERNAL_ERROR','internal server error');}
            $error=['code'=>$e->errorCode,'message'=>$e->getMessage(),'traceId'=>$trace];if($e->details!==[]){$error['details']=$e->details;}
            if($e->status()===429){$outHeaders['retry-after']=(string)max(1,(int)ceil(Env::duration('RATE_LIMIT_WINDOW',60)));}
            return $this->result($e->status(),['error'=>$error],$outHeaders);
        }
    }
    private function result(int $status,mixed $data,array $headers,bool $raw=false): array {return compact('status','data','headers','raw');}
    private function match(string $method,string $path): array
    {
        $found=false;
        foreach($this->routes as $r){[$verb,$pattern]=explode(' ',$r[0],2);$names=[];
            $regex=preg_replace_callback('/\\\{([^}]+)\\\}/',function($m)use(&$names){$names[]=str_replace('\\','',$m[1]);return '([^/]+)';},preg_quote($pattern,'~'));
            if(preg_match('~^'.$regex.'$~D',$path,$matches)){
                $found=true;if($method!==$verb){continue;}array_shift($matches);return [$r,array_combine($names,array_map('rawurldecode',$matches))?:[]];
            }
        }
        throw new ApiError($found?'METHOD_NOT_ALLOWED':'NOT_FOUND',$found?'method not allowed':'route not found');
    }
    private function authenticate(array $headers): array
    {
        $raw=trim($headers['authorization']??'');if(!str_starts_with($raw,'Bearer ')){throw new ApiError('UNAUTHORIZED','Authorization Bearer token is required');}
        return $this->auth->authenticate(trim(substr($raw,7)));
    }
    private function dispatch(array $r,array $p,array $q,array $i,array $h,string $body,?array $actor): mixed
    {
        $user=$actor['id']??'';$id=reset($p)?:'';$key=$h['idempotency-key']??'';
        return match($r[1].'.'.$r[2]){
            'Auth.register'=>$this->auth->register($i,$h['x-admin-bootstrap-token']??''),
            'Auth.login'=>$this->auth->login($i),'Auth.refresh'=>$this->auth->refresh($i),
            'Auth.logout'=>$this->void(fn()=>$this->auth->logout($user,$i)),
            'Auth.changePassword'=>$this->void(fn()=>$this->auth->changePassword($user,$i)),
            'Credential.loginPin'=>$this->credentials->loginPin($i),'Credential.challenge'=>$this->credentials->challenge($i),'Credential.loginBiometric'=>$this->credentials->loginBiometric($i),
            'Credential.session'=>$this->credentials->session($user,$q['deviceId']??''),
            'Credential.setPin'=>$this->void(fn()=>$this->credentials->setPin($user,$i)),
            'Credential.changePin'=>$this->void(fn()=>$this->credentials->changePin($user,$i)),
            'Credential.deletePin'=>$this->void(fn()=>$this->credentials->deletePin($user,$p['deviceID']??$q['deviceId']??'')),
            'Credential.pinDevices'=>$this->credentials->pinDevices($user),
            'Credential.enroll'=>$this->credentials->enroll($user,$i),
            'Credential.deleteBiometric'=>$this->void(fn()=>$this->credentials->deleteBiometric($user,$p['deviceID']??$q['deviceId']??'')),
            'Credential.biometricDevices'=>$this->credentials->biometricDevices($user),
            'Catalog.categories'=>$this->catalog->categories(),'Catalog.category'=>$this->catalog->category($id),'Catalog.children'=>$this->catalog->children($id),
            'Catalog.products'=>$this->catalog->products($q,$r[0]==='GET /products/search'),'Catalog.popular'=>$this->catalog->popular($q),
            'Catalog.product'=>$this->catalog->product($id),'Catalog.reviews'=>$this->catalog->reviews($id,$q),
            'Catalog.createReview'=>$this->catalog->createReview($user,$id,$i),'Catalog.deleteReview'=>$this->void(fn()=>$this->catalog->deleteReview($actor,$id)),
            'Catalog.patchStock'=>$this->catalog->patchStock($id,$i),'Catalog.createProduct'=>$this->catalog->createProduct($i),'Catalog.updateProduct'=>$this->catalog->updateProduct($id,$i),
            'Catalog.deleteProduct'=>$this->void(fn()=>$this->catalog->deleteProduct($id)),
            'Profile.user'=>(new ShopRepository($this->db))->userDto($actor),'Profile.update'=>$this->profile->update($user,$i),
            'Profile.addresses'=>$this->profile->addresses($user),'Profile.createAddress'=>$this->profile->createAddress($user,$i),'Profile.updateAddress'=>$this->profile->updateAddress($user,$id,$i),
            'Profile.deleteAddress'=>$this->void(fn()=>$this->profile->deleteAddress($user,$id)),
            'Avatar.update'=>$this->avatar->update($user,$h['content-type']??'',$body),
            'Commerce.cart'=>$this->commerce->cart($user),'Commerce.addCart'=>$this->commerce->addCart($user,$i,$key),'Commerce.updateCart'=>$this->commerce->updateCart($user,$id,$i),'Commerce.removeCart'=>$this->commerce->removeCart($user,$id),
            'Commerce.clearCart'=>$this->void(fn()=>$this->commerce->clearCart($user)),
            'Commerce.createOrder'=>$this->commerce->createOrder($user,$i,$key),'Commerce.orders'=>$this->commerce->orders($user,$q),'Commerce.order'=>$this->commerce->order($user,$id),'Commerce.transition'=>$this->commerce->transition($id,$i),
            'Payment.payment'=>$this->payments->payment($user,$id),'Payment.confirm'=>$this->payments->confirm($user,$id),'Payment.cancel'=>$this->payments->cancel($user,$id),'Payment.refund'=>$this->payments->refund($id),'Payment.void'=>$this->payments->void($id),
            'Payment.webhook'=>$this->void(fn()=>$this->payments->webhook($i,$h['x-webhook-id']??'')),
            default=>throw new \LogicException('Unmapped operation '.$r[0]),
        };
    }
    private function void(\Closure $work): null {$work();return null;}
    private function verifyWebhook(array $h,string $body,string $client): void
    {
        $secret=Env::get('PAYMENT_WEBHOOK_SECRET');if($secret===''){throw new ApiError('WEBHOOK_NOT_CONFIGURED','payment webhook is not configured');}
        $allowed=Env::get('PAYMENT_WEBHOOK_ALLOWED_CIDRS');
        try{$sourceAllowed=Network::allowed($client,$allowed);}
        catch(\InvalidArgumentException){throw new ApiError('WEBHOOK_NOT_CONFIGURED','payment webhook source configuration is invalid');}
        if(($allowed!=='' || Env::production()) && !$sourceAllowed){throw new ApiError('WEBHOOK_SOURCE_DENIED','webhook source is not allowed');}
        $ts=$h['x-webhook-timestamp']??'';$id=$h['x-webhook-id']??'';$sig=$h['x-webhook-signature']??'';
        $now=microtime(true);
        if(!preg_match('/^[0-9]{1,12}$/D',$ts) || $id==='' || strlen($id)>255 || $now-(int)$ts>Env::duration('WEBHOOK_MAX_AGE',300) || (int)$ts-$now>60){throw new ApiError('INVALID_SIGNATURE','missing or stale webhook signature');}
        if(!hash_equals(hash_hmac('sha256',$ts.':'.$id.':'.$body,$secret),$sig)){throw new ApiError('INVALID_SIGNATURE','invalid webhook signature');}
    }
    private function rateLimit(string $method,string $path,string $client,?array $user): void
    {
        [$kind,$limit]=RatePolicy::limit($method,$path);if($limit===0){return;}
        $limiter=new RateLimiter($this->db);
        $networkLimit=$user===null?$limit:$limit*max(1,(int)Env::get('AUTHENTICATED_NETWORK_RATE_LIMIT_MULTIPLIER','4'));
        $limiter->check($kind.':network-'.Network::aggregate($client),$networkLimit);
        if($user!==null){$limiter->check($kind.':user-'.$user['id'],$limit);}
    }
    private function ready(array $headers): array
    {
        $components=[];
        try{
            Network::cidrs(Env::get('PAYMENT_WEBHOOK_ALLOWED_CIDRS'));
            $this->db->one('SELECT 1 FROM users LIMIT 1');$this->db->one('SELECT 1 FROM biometric_challenges LIMIT 1');
            // Old writers/schema are incompatible with durable effects and token
            // revocation. Check the complete 012-014 shape before serving traffic.
            $this->db->all('SELECT id,stock_restored_at FROM orders LIMIT 0');
            $this->db->all('SELECT id,cancel_requested_at,refund_requested_at FROM payments LIMIT 0');
            $this->db->all('SELECT id,payment_id,kind,operation_key,state,lease_token,lease_until,sent_at FROM payment_operations LIMIT 0');
            $this->db->all('SELECT operation_id,lease_token,observed_at,result FROM payment_operation_observations LIMIT 0');
            $this->db->all('SELECT transaction_id,payment_id FROM payment_transaction_links LIMIT 0');
            $this->db->all('SELECT operation_id,payment_id,transaction_id,status,resolved_at FROM payment_callback_inbox LIMIT 0');
            $this->db->all('SELECT session_security_version,sessions_blocked FROM users LIMIT 0');
            $this->db->all('SELECT id,user_id,security_version,credential_kind,credential_id,revoked_at FROM session_families LIMIT 0');
            foreach(['access_sessions','refresh_sessions'] as $table){$this->db->all('SELECT family_id FROM '.$table.' LIMIT 0');}
            $this->db->all('SELECT id,user_id,state,recovery_attempts,next_retry_at,recovery_claim_until,operator_evidence_ref,operator_confirmed_at FROM password_session_operations LIMIT 0');
            $legacy=$this->db->one('SELECT value FROM repository_metadata WHERE key=\'legacy_import_status\'');
            if(($legacy['value']??'')==='required'){throw new \RuntimeException('Explicit legacy import required');}$components['postgres']='up';
            (new Keycloak())->verifier()->ready();$components['keycloak']='up';
            if(Env::get('OPENSEARCH_URL')!==''){(new OpenSearch($this->db,new ShopRepository($this->db)))->ready();$components['opensearch']='up';}
            if(Env::production() && (Env::get('PAYMENT_GATEWAY_URL')==='' || Env::get('PAYMENT_GATEWAY_API_KEY')==='' || Env::get('PAYMENT_WEBHOOK_SECRET')==='' || Env::get('PAYMENT_WEBHOOK_ALLOWED_CIDRS')==='')){throw new \RuntimeException('Production payment configuration missing');}
            return $this->result(200,['status'=>'ok','components'=>$components],$headers);
        }catch(\Throwable $e){error_log('Readiness: '.$e->getMessage());$components['dependency']='down';return $this->result(503,['status'=>'unavailable','components'=>$components],$headers);}
    }
    private function media(string $path,array $headers): array
    {
        if(str_contains($path,'..') || str_contains($path,'\\')){throw new ApiError('NOT_FOUND','media not found');}
        $product=str_starts_with($path,'/images/products/');$row=$this->db->one($product?'SELECT id FROM product_images WHERE url=? LIMIT 1':'SELECT id FROM categories WHERE icon_url=? LIMIT 1',[$path]);
        if(!$row){throw new ApiError($product?'IMAGE_NOT_FOUND':'ICON_NOT_FOUND',$product?'product image not found':'category icon not found',[],404);}
        $seed=0;foreach(mb_str_split(basename($path)) as $char){$seed=($seed*33+mb_ord($char)) & 0xffffffff;}
        if($product){$image=imagecreatetruecolor(320,240);for($y=0;$y<240;$y++){for($x=0;$x<320;$x++){$shade=($x+$y)%48;$color=imagecolorallocate($image,96+$seed%96+intdiv($shade,3),96+intdiv($seed,7)%96+intdiv($shade,4),96+intdiv($seed,13)%96+intdiv($shade,5));imagesetpixel($image,$x,$y,$color);}}ob_start();imagepng($image);$data=ob_get_clean();imagedestroy($image);$headers['content-type']='image/png';}
        else{$color=sprintf('#%06x',0x404040+$seed%0xbfbfbf);$data='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img"><rect width="64" height="64" rx="14" fill="'.$color.'"/><path d="M18 32h28M32 18v28" stroke="#fff" stroke-width="6" stroke-linecap="round"/></svg>';$headers['content-type']='image/svg+xml; charset=utf-8';}
        $headers['cache-control']='public, max-age=86400';return $this->result(200,$data,$headers,true);
    }
}
