<?php
declare(strict_types=1);
require __DIR__.'/search-security-provision.php';

// Disposable-cluster test only: this script is not mounted in ordinary services.
function expectSearch(string $name, string $method, string $path, ?array $body, array $expected, ?array $auth = null): array
{
    $start = hrtime(true);
    [$status, $reply] = searchRequest($method, $path, $body, $auth);
    $actions = [];
    preg_match_all('/no permissions for \[(.+?)\] and/', json_encode($reply, JSON_UNESCAPED_SLASHES), $actions);
    print $name.' '.$method.' '.$path.' => '.$status.' '.round((hrtime(true)-$start)/1e6, 2).'ms';
    if ($actions[1]) { print ' denied_actions='.implode(',', array_unique($actions[1])); }
    print "\n";
    if (!in_array($status, $expected, true)) { throw new RuntimeException($name.': unexpected HTTP status'); }
    return $reply ?? [];
}

try {
    if (getenv('SEARCH_PRIVILEGES_DISPOSABLE') !== '1') { throw new RuntimeException('Disposable test opt-in required'); }
    // Simulate an existing security index with independent operator entries and
    // an unsafe old managed policy. Upgrade must narrow only its own objects.
    $legacyPassword = bin2hex(random_bytes(32));
    $legacy = [
        'roles/round7_independent_operator'=>['cluster_permissions'=>['cluster:monitor/health'],'index_permissions'=>[],'tenant_permissions'=>[]],
        'rolesmapping/round7_independent_operator'=>['users'=>['round7_independent_operator'],'backend_roles'=>[],'hosts'=>[]],
        'internalusers/round7_independent_operator'=>['password'=>$legacyPassword,'backend_roles'=>[],'attributes'=>new stdClass()],
    ];
    $preserved = [];
    foreach ($legacy as $path => $body) {
        [$status] = searchRequest('PUT', '/_plugins/_security/api/'.$path, $body);
        if (!in_array($status,[200,201],true)) { throw new RuntimeException('Cannot install independent upgrade fixture'); }
        [$status, $preserved[$path]] = searchRequest('GET', '/_plugins/_security/api/'.$path);
        if ($status !== 200) { throw new RuntimeException('Cannot inventory independent upgrade fixture'); }
    }
    [$status] = searchRequest('PUT', '/_plugins/_security/api/roles/shopkmp_catalog_runtime', [
        'cluster_permissions'=>['cluster:monitor/health'],
        'index_permissions'=>[['index_patterns'=>['*'],'allowed_actions'=>['indices_all']]], 'tenant_permissions'=>[]]);
    if ($status !== 200) { throw new RuntimeException('Cannot install unsafe old managed policy fixture'); }
    expectSearch('legacy-unsafe-runtime-before-upgrade', 'GET', '/_cluster/health', null, [200],
        ['shopkmp_runtime',getenv('OPENSEARCH_RUNTIME_PASSWORD')]);
    searchProvision('upgrade');
    foreach ($preserved as $path => $expected) {
        [$status, $actual] = searchRequest('GET', '/_plugins/_security/api/'.$path);
        if ($status !== 200 || $actual !== $expected) { throw new RuntimeException('Upgrade changed independent operator object'); }
    }
    expectSearch('independent-existing-operator-after-upgrade', 'GET', '/_cluster/health', null, [200],
        ['round7_independent_operator',$legacyPassword]);
    print "Existing-security-index upgrade narrowed managed policy and preserved unrelated operator objects\n";
    // Model the shipped legacy own_index wildcard, not merely an extra role name.
    [$status,$defaultRole]=searchRequest('GET','/_plugins/_security/api/roles/own_index');
    if($status!==200 || ($defaultRole['own_index']['index_permissions'][0]['index_patterns']??[])!==['${user_name}']){
        throw new RuntimeException('Pinned default own_index role differs');
    }
    foreach ([
        'rolesmapping/own_index'=>['users'=>['*'],'backend_roles'=>[],'hosts'=>[]],
    ] as $path=>$body) {
        [$status]=searchRequest('PUT','/_plugins/_security/api/'.$path,$body);
        if(!in_array($status,[200,201],true)){throw new RuntimeException('Cannot install legacy wildcard fixture');}
    }
    $runtimeAuth=['shopkmp_runtime',getenv('OPENSEARCH_RUNTIME_PASSWORD')];
    expectSearch('legacy-wildcard-grants-own-index-create','PUT','/shopkmp_runtime',
        ['settings'=>['number_of_shards'=>1,'number_of_replicas'=>0]],[200],$runtimeAuth);
    foreach(['check','upgrade'] as $mode){
        $denied=false; ob_start();
        try{searchProvision($mode);}catch(RuntimeException $error){$denied=str_contains($error->getMessage(),'unexpected effective roles');}
        ob_end_clean();
        if(!$denied){throw new RuntimeException('Unexpected wildcard privilege was accepted');}
        print 'legacy wildcard '.$mode.": rejected unexpected effective privileges\n";
    }
    expectSearch('operator-controlled-wildcard-narrowing','PUT','/_plugins/_security/api/rolesmapping/own_index',
        ['users'=>['round7_independent_operator'],'backend_roles'=>[],'hosts'=>[]],[200]);
    searchProvision('check');
    expectSearch('runtime-after-wildcard-narrowing','GET','/shopkmp_runtime',null,[403],$runtimeAuth);
    expectSearch('independent-operator-after-wildcard-narrowing','GET','/_cluster/health',null,[200],
        ['round7_independent_operator',$legacyPassword]);
    $versions = [];
    foreach (['roles','rolesmapping','internalusers'] as $kind) {
        [$status, $doc] = searchRequest('GET', '/.opendistro_security/_doc/'.$kind);
        if ($status !== 200 || !is_int($doc['_seq_no'] ?? null)) { throw new RuntimeException('Cannot measure managed security versions'); }
        $versions[$kind] = $doc['_seq_no'];
    }
    searchProvision('upgrade');
    foreach ($versions as $kind => $version) {
        [$status, $doc] = searchRequest('GET', '/.opendistro_security/_doc/'.$kind);
        if ($status !== 200 || $doc['_seq_no'] !== $version) { throw new RuntimeException('Repeat upgrade rewrote '.$kind); }
    }
    print "Idempotent upgrade: security document sequence numbers unchanged\n";
    $mapping = ['settings'=>['number_of_shards'=>1,'number_of_replicas'=>0],
        'mappings'=>['_meta'=>['shopkmp_products'=>['version'=>1,'state'=>'building']],
        'properties'=>['id'=>['type'=>'text','fields'=>['keyword'=>['type'=>'keyword']]],
            'name'=>['type'=>'text'],'salePriceCents'=>['type'=>'long'],'isActive'=>['type'=>'boolean']]]];
    [$status] = searchRequest('HEAD', '/shopkmp-products');
    if ($status === 404) { expectSearch('operator', 'PUT', '/shopkmp-products', $mapping, [200]); }
    [$status] = searchRequest('HEAD', '/foreign-catalog');
    if ($status === 404) { expectSearch('operator', 'PUT', '/foreign-catalog', ['settings'=>['number_of_shards'=>1,'number_of_replicas'=>0]], [200]); }
    $document = ['id'=>'00000000-0000-4000-8000-000000000001','name'=>'Actual TLS catalog write',
        'description'=>'Catalog DTO with dynamically mapped fields','categoryId'=>'00000000-0000-4000-8000-000000000002',
        'price'=>'50.01','discountPrice'=>null,'stock'=>5,'rating'=>0,'reviewsCount'=>0,'images'=>['/test.png'],
        'isActive'=>true,'salePriceCents'=>5001,'categoryPath'=>[],'createdAt'=>'2026-11-01T00:00:00Z'];
    foreach (['runtime', 'reconcile', 'indexer'] as $role) {
        $auth = ['shopkmp_'.$role, getenv('OPENSEARCH_'.strtoupper($role).'_PASSWORD')];
        expectSearch($role, 'GET', '/shopkmp-products', null, [200], $auth);
        expectSearch($role, 'PUT', '/shopkmp-products/_doc/'.$document['id'].'?refresh=wait_for', $document, [200,201], $auth);
        $result = expectSearch($role, 'POST', '/shopkmp-products/_search?allow_partial_search_results=false', ['size'=>1], [200], $auth);
        if (($result['hits']['total']['value'] ?? 0) !== 1) { throw new RuntimeException('Actual catalog document not searchable'); }
        expectSearch($role, 'DELETE', '/shopkmp-products/_doc/'.$document['id'].'?refresh=wait_for', null, [200], $auth);
        expectSearch($role, 'GET', '/_plugins/_security/api/roles', null, [403], $auth);
        expectSearch($role, 'GET', '/_cluster/health', null, [403], $auth);
        expectSearch($role, 'PUT', '/_cluster/settings', ['persistent'=>['cluster.max_shards_per_node'=>1000]], [403], $auth);
        expectSearch($role, 'GET', '/foreign-catalog', null, [403], $auth);
        expectSearch($role, 'POST', '/foreign-catalog/_search', ['size'=>0], [403], $auth);
        expectSearch($role, 'PUT', '/foreign-catalog/_doc/denied', $document, [403], $auth);
        expectSearch($role, 'PUT', '/outside-products-scope', $mapping, [403], $auth);
        expectSearch($role, 'PUT', '/shopkmp-products/_settings', ['index'=>['refresh_interval'=>'1s']], [403], $auth);
        if ($role !== 'indexer') {
            expectSearch($role, 'PUT', '/shopkmp-products-'.$role.'-denied', $mapping, [403], $auth);
            expectSearch($role, 'PUT', '/shopkmp-products/_mapping', ['_meta'=>['denied'=>true]], [403], $auth);
        }
        if ($role === 'runtime') {
            expectSearch($role, 'POST', '/shopkmp-products/_refresh', null, [403], $auth);
        } else {
            expectSearch($role, 'POST', '/shopkmp-products/_refresh', null, [200], $auth);
        }
        if ($role === 'indexer') {
            expectSearch($role, 'HEAD', '/shopkmp-products', null, [200], $auth);
            [$status] = searchRequest('HEAD', '/shopkmp-products-stage-proof', null, $auth);
            if ($status === 404) { expectSearch($role, 'PUT', '/shopkmp-products-stage-proof', $mapping, [200], $auth); }
            expectSearch($role, 'PUT', '/shopkmp-products-stage-proof/_mapping', ['_meta'=>['shopkmp_products'=>['state'=>'ready']]], [200], $auth);
            $scroll = expectSearch($role, 'POST', '/shopkmp-products/_search?scroll=1m', ['size'=>1], [200], $auth);
            expectSearch($role, 'POST', '/_search/scroll', ['scroll'=>'1m','scroll_id'=>$scroll['_scroll_id']], [200], $auth);
            expectSearch($role, 'DELETE', '/_search/scroll', ['scroll_id'=>[$scroll['_scroll_id']]], [200], $auth);
        }
    }
    foreach (['runtime','reconcile'] as $role) {
        expectSearch($role.'-cannot-use-staged-index', 'GET', '/shopkmp-products-stage-proof', null, [403],
            ['shopkmp_'.$role,getenv('OPENSEARCH_'.strtoupper($role).'_PASSWORD')]);
    }
    expectSearch('operator-independent-mTLS', 'GET', '/_plugins/_security/api/internalusers', null, [200]);
    // Controlled config rollback restores a previously recorded least-privileged
    // policy, never the deliberately unsafe legacy policy above.
    [$status,$saved]=searchRequest('GET','/_plugins/_security/api/roles/shopkmp_catalog_runtime');
    if($status!==200){throw new RuntimeException('Cannot back up managed policy');}
    $backup=array_intersect_key($saved['shopkmp_catalog_runtime'],array_flip(['cluster_permissions','index_permissions','tenant_permissions']));
    $restricted=$backup;
    $restricted['index_permissions'][0]['allowed_actions']=array_values(array_diff($restricted['index_permissions'][0]['allowed_actions'],['indices:data/write/delete']));
    expectSearch('operator-controlled-policy-change','PUT','/_plugins/_security/api/roles/shopkmp_catalog_runtime',$restricted,[200]);
    expectSearch('operator-policy-rollback-document','PUT','/shopkmp-products/_doc/rollback-proof',['id'=>'rollback-proof'],[201]);
    expectSearch('restricted-policy-denies-delete','DELETE','/shopkmp-products/_doc/rollback-proof',null,[403],$runtimeAuth);
    expectSearch('operator-restores-managed-policy-backup','PUT','/_plugins/_security/api/roles/shopkmp_catalog_runtime',$backup,[200]);
    expectSearch('restored-policy-allows-delete','DELETE','/shopkmp-products/_doc/rollback-proof',null,[200],$runtimeAuth);
    [$status,$restored]=searchRequest('GET','/_plugins/_security/api/roles/shopkmp_catalog_runtime');
    if($status!==200 || array_intersect_key($restored['shopkmp_catalog_runtime'],$backup)!==$backup){
        throw new RuntimeException('Managed policy rollback readback differs');
    }
    foreach($preserved as $path=>$expected){
        [$status,$actual]=searchRequest('GET','/_plugins/_security/api/'.$path);
        if($status!==200 || $actual!==$expected){throw new RuntimeException('Policy rollback changed independent operator');}
    }
    searchProvision('check');
    print "Managed-policy rollback restored exact permissions and preserved independent operator objects\n";
    $original = getenv('OPENSEARCH_RUNTIME_PASSWORD');
    $rotated = bin2hex(random_bytes(32));
    expectSearch('operator-rotate-runtime', 'PUT', '/_plugins/_security/api/internalusers/shopkmp_runtime',
        ['password'=>$rotated,'backend_roles'=>[],'attributes'=>new stdClass()], [200]);
    expectSearch('revoked-original-runtime', 'GET', '/_plugins/_security/authinfo', null, [401], ['shopkmp_runtime',$original]);
    expectSearch('rotated-runtime', 'GET', '/_plugins/_security/authinfo', null, [200], ['shopkmp_runtime',$rotated]);
    expectSearch('operator-recovery-after-rotation', 'GET', '/_plugins/_security/api/roles', null, [200]);
    // Roll back only this disposable credential using the same explicit operator
    // upgrade command. Real incidents require fresh credentials, not resurrection.
    searchProvision('upgrade');
    expectSearch('rollback-revokes-rotated-runtime', 'GET', '/_plugins/_security/authinfo', null, [401], ['shopkmp_runtime',$rotated]);
    expectSearch('rollback-restores-expected-runtime', 'GET', '/_plugins/_security/authinfo', null, [200], ['shopkmp_runtime',$original]);
    print "Actual TLS role actions passed; no index deletions performed\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'SEARCH_PRIVILEGES_TEST_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
