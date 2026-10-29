<?php
declare(strict_types=1);

// Invoked through `docker exec -i ... php /dev/stdin ACTION RUN [VARIANT]`.
// stdout on seed contains short-lived test tokens: the caller MUST keep it private.
require '/app/vendor/autoload.php';

use Shop\application\SessionService;
use Shop\domain\{Ids,Secret};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

if (PHP_SAPI !== 'cli' || Env::production() || !preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',getenv('ROUND3_DISPOSABLE')?:'')) {
    fwrite(STDERR, "Explicit disposable shopkmp-*-e2e development project is required\n"); exit(2);
}
[$script,$action,$run] = array_pad($argv,3,'');
if (!Ids::valid($run) || !in_array($action,['seed','cleanup','description','long-image'],true)) {
    fwrite(STDERR,"Expected seed|cleanup|description|long-image and a fresh run UUID\n"); exit(2);
}
$url=Env::get('DATABASE_URL');$parts=parse_url($url);
if (($parts['host']??'')!=='postgres' || ($parts['path']??'')!=='/shopkmp') {
    fwrite(STDERR,"Edge fixture is restricted to the disposable project's postgres/shopkmp\n");exit(2);
}
function edgeId(string $run,string $label): string {
    $h=hash('sha256',$run.'/'.$label);
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
$db=new Database($url);$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);
$owner=edgeId($run,'owner');$foreign=edgeId($run,'foreign');$category=$run;
$name='Round3 edge '.$run;$email='round3-edge-'.$run.'@example.invalid';
$ids=array_map(fn($i)=>edgeId($run,'product-'.$i),range(0,99));
sort($ids,SORT_STRING);
try {
    $result=$db->transaction(function() use($db,$sessions,$action,$run,$owner,$foreign,$category,$name,$email,$ids,$argv) {
        if ($action==='seed') {
            // An existing marker must never be adopted or overwritten.
            if ($db->one('SELECT id FROM categories WHERE id=?',[$category])) {throw new RuntimeException('Run UUID already exists');}
            foreach([[$owner,$email],[$foreign,'round3-edge-foreign-'.$run.'@example.invalid']] as [$u,$e]) {
                $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Round3 synthetic buyer','+79990000000')",[$u,$e]);
            }
            $db->execute('INSERT INTO categories(id,name) VALUES(?,?)',[$category,$name]);
            foreach($ids as $i=>$id) {
                $db->execute("INSERT INTO products(id,category_id,name,description,price,stock,created_at) VALUES(?,?,?,?,'50.01',7,now()-?*interval '1 second')",[$id,$category,'Round3 product '.sprintf('%03d',$i),str_repeat('😀',10000),$i]);
                $db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,0)',[$id,'/images/products/round3.png']);
            }
            $linked=[];
            foreach(['pin','biometric'] as $kind) {
                foreach(['ordinary.Phone-'.$run,'.','..'] as $device) {
                    // Dot records deliberately bypass enrollment to represent legacy data.
                    // Refuse to interfere if another run already owns a globally unique dot ID.
                    $id=edgeId($run,$kind.'/'.$device);$label='Round3 '.$kind.' '.$device;
                    if($kind==='pin') {$db->execute('INSERT INTO device_pins(id,user_id,device_id,device_name,pin_hash,last_used_at) VALUES(?,?,?,?,?,now())',[$id,$owner,$device,$label,Secret::hash('7395')]);}
                    else {$key=hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());$db->execute("INSERT INTO device_biometrics(id,user_id,device_id,device_name,public_key,key_algorithm,last_used_at) VALUES(?,?,?,?,decode(?,'hex'),'EdDSA',now())",[$id,$owner,$device,$label,bin2hex($key)]);}
                    if($device==='.' || $device==='..') {
                        $linked[]=['kind'=>$kind,'device'=>$device,'credentialId'=>$id,'families'=>[$sessions->issue($owner,$kind,$id),$sessions->issue($owner,$kind,$id)]];
                    }
                }
            }
            // Stable authenticated reads avoid anonymous-IP quota churn; no limits are changed.
            $ordinary=$sessions->issue($owner,'pin',edgeId($run,'pin/ordinary.Phone-'.$run));
            return ['run'=>$run,'owner'=>$owner,'foreign'=>$foreign,'category'=>$category,'products'=>$ids,'ordinaryDevice'=>'ordinary.Phone-'.$run,'ownerSession'=>$ordinary,'foreignSession'=>$sessions->issue($foreign),'linked'=>$linked];
        }
        $marker=$db->one('SELECT name FROM categories WHERE id=?',[$category]);
        if (!$marker && $action==='cleanup') {return ['cleaned'=>true,'alreadyAbsent'=>true];}
        if (($marker['name']??null)!==$name || ($db->one('SELECT email FROM users WHERE id=?',[$owner])['email']??null)!==$email) {throw new RuntimeException('Run ownership marker mismatch');}
        if($action==='description') {
            $description=match($argv[3]??'') {'ascii256'=>str_repeat('a',256),'ascii257'=>str_repeat('a',257),'cyrillic257'=>str_repeat('я',257),'emoji257'=>str_repeat('😀',257),'emoji10000'=>str_repeat('😀',10000),default=>throw new RuntimeException('Unknown description variant')};
            $db->execute('UPDATE products SET description=? WHERE id=? AND category_id=?',[$description,$ids[0],$category]);
            return ['updated'=>true,'codePoints'=>mb_strlen($description,'UTF-8')];
        }
        if($action==='long-image') {
            $prefix='/images/products/';$url=$prefix.str_repeat('a',2048-strlen($prefix)-4).'.png';
            $db->execute('UPDATE product_images SET url=? WHERE product_id IN (SELECT id FROM products WHERE category_id=?)',[$url,$category]);
            return ['updated'=>true,'urlBytes'=>strlen($url)];
        }
        // Exact IDs AND ownership markers. Never delete rows by a broad prefix.
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $db->execute('DELETE FROM products WHERE category_id=? AND id IN ('.$marks.')',[$category,...$ids]);
        $db->execute('DELETE FROM categories WHERE id=? AND name=?',[$category,$name]);
        foreach([[$owner,$email],[$foreign,'round3-edge-foreign-'.$run.'@example.invalid']] as [$u,$e]) {
            // rate_limits are request-accounting rows, intentionally retained until normal expiry.
            $db->execute('DELETE FROM users WHERE id=? AND email=?',[$u,$e]);
        }
        $left=$db->one('SELECT (SELECT count(*) FROM products WHERE category_id=?)+(SELECT count(*) FROM users WHERE id IN (?,?))+(SELECT count(*) FROM categories WHERE id=?) AS n',[$category,$owner,$foreign,$category])['n'];
        if($left!==0){throw new RuntimeException('Addressed cleanup left fixture rows');}
        return ['cleaned'=>true,'remainingFixtureRows'=>$left];
    });
    print json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} catch(Throwable $e) {
    // Never dump token-bearing variables, environment, SQL arguments or PDO messages.
    fwrite(STDERR,'Round3 fixture failed: '.get_class($e)."\n");exit(1);
}
