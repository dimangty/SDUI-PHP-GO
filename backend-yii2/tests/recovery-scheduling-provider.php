<?php
// The scheduler exercises the ordinary service-account path, without an admin-token override.
declare(strict_types=1);
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/realms/worker/protocol/openid-connect/token'){
    parse_str(file_get_contents('php://input'),$form);
    if($_SERVER['REQUEST_METHOD']!=='POST' || ($form['grant_type']??'')!=='client_credentials'
        || ($form['client_id']??'')!=='shopkmp-backend' || ($form['client_secret']??'')!=='fixture-secret'){
        http_response_code(401);return;
    }
    header('Content-Type: application/json');print '{"access_token":"fixture-admin","expires_in":60}';return;
}
require __DIR__.'/recovery-provider.php';
