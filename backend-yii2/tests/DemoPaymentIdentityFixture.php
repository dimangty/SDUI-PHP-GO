<?php
declare(strict_types=1);

use Shop\application\{CommerceService,PaymentService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient};
use Shop\repositories\ShopRepository;

/** Committed fixtures: the separate real Yii HTTP process must see every mutation. */
final class DemoPaymentIdentityFixture
{
    public readonly Database $db;
    public readonly ShopRepository $repo;
    public readonly PaymentService $payments;
    public const SECRET='demo-identity-test-secret';

    public function __construct()
    {
        if(!preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',Env::get('DEMO_PAYMENT_TEST_DISPOSABLE'))){
            throw new RuntimeException('DEMO_PAYMENT_TEST_DISPOSABLE must identify an owned disposable database');
        }
        if(Env::production() || Env::get('PAYMENT_GATEWAY_URL')!==''){
            throw new RuntimeException('Run demo identity tests only in an isolated development backend without a provider');
        }
        putenv('PAYMENT_WEBHOOK_SECRET='.self::SECRET);
        $this->db=new Database(Env::get('DATABASE_URL'));
        $this->repo=new ShopRepository($this->db);
        $this->payments=new PaymentService($this->db,$this->repo,new CommerceService($this->db,$this->repo));
    }

    public function payment(string $method='CARD'): array
    {
        $user=Ids::uuid();$address=Ids::uuid();$order=Ids::uuid();
        // Fixed alphabetic prefix makes every casing variant meaningful, without random-case flakes.
        $payment='ab12cd34-56ef-4a78-9b01-'.substr(str_replace('-','',Ids::uuid()),0,12);
        $this->db->transaction(function()use($user,$address,$order,$payment,$method){
            $this->db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,local_identity_ready) VALUES(?,?,'','Demo identity buyer','+79991234567',true)",[$user,$user.'@demo-identity.test']);
            $this->db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')",[$address,$user]);
            $this->db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method) VALUES(?,?,'CREATED','20.46',?,'X, Y, 1',?)",[$order,$user,$address,$method]);
            $this->db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,?,'PENDING','20.46')",[$payment,$order,$user,$method]);
            $this->db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$payment,$order]);
        });
        $token=(new SessionService($this->db,$this->repo))->issue($user)['accessToken'];
        return compact('user','order','payment','token');
    }

    public static function casing(string $id,string $case): string
    {
        return match($case){'upper'=>strtoupper($id),'mixed'=>strtoupper(substr($id,0,18)).substr($id,18),default=>$id};
    }

    public function operations(string $payment): array
    {
        return $this->db->all('SELECT id,kind,operation_key,state,sent_at,result FROM payment_operations WHERE payment_id=? ORDER BY created_at,id',[$payment]);
    }

    public function state(array $fixture): array
    {
        return $this->db->required('SELECT p.status,p.gateway_url,p.transaction_id,o.status AS order_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=?',[$fixture['payment']],'PAYMENT_NOT_FOUND','fixture not found');
    }
}

/** Native HTTP through web/index.php, Composer and Yii; no replacement kernel/router. */
final class DemoPaymentIdentityHttp
{
    private $process;
    private string $log;
    public readonly string $origin;

    public function __construct(array $environment=[])
    {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        if(!$listener){throw new RuntimeException($message);}
        $address=stream_socket_get_name($listener,false);fclose($listener);$this->origin='http://'.$address;
        $this->log=tempnam(sys_get_temp_dir(),'demo-payment-http-');
        $environment=array_replace(getenv(),[
            'APP_ENV'=>'development','PAYMENT_GATEWAY_URL'=>'','PAYMENT_WEBHOOK_SECRET'=>DemoPaymentIdentityFixture::SECRET,
            'READ_RATE_LIMIT'=>'10000','MUTATION_RATE_LIMIT'=>'10000','SENSITIVE_RATE_LIMIT'=>'10000',
        ],$environment);
        $this->process=proc_open([PHP_BINARY,'-S',$address,'-t',dirname(__DIR__).'/web',dirname(__DIR__).'/web/index.php'],[
            0=>['file','/dev/null','r'],1=>['file',$this->log,'a'],2=>['file',$this->log,'a'],
        ],$pipes,dirname(__DIR__),$environment);
        for($i=0;$i<100;$i++){
            $probe=@stream_socket_client('tcp://'.$address,$errno,$message,.05);
            if($probe){fclose($probe);return;}
            if(!proc_get_status($this->process)['running']){break;}
            usleep(10000);
        }
        $log=file_get_contents($this->log);$this->close();throw new RuntimeException('Yii HTTP fixture failed to start: '.$log);
    }

    public function request(string $method,string $path,?string $token=null): array
    {
        return (new HttpClient(3))->request($method,$this->origin.$path,$token===null?[]:['Authorization'=>'Bearer '.$token]);
    }

    public function confirm(array $fixture,string $id): array
    {
        $response=$this->request('POST','/api/v1/payments/'.$id.'/confirm',$fixture['token']);
        same(200,$response['status']);
        return json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);
    }

    public function close(): void
    {
        if(is_resource($this->process)){proc_terminate($this->process);proc_close($this->process);}
        if(is_file($this->log)){unlink($this->log);}
    }
}
