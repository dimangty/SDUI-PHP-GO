<?php
declare(strict_types=1);

// Targeted existing-index upgrade/rotation; no full securityadmin -cd on upgrades.
// Never print response bodies (internal-user replies may contain password hashes).
function searchRequest(string $method, string $path, ?array $body = null, ?array $basic = null): array
{
    $curl = curl_init('https://opensearch:9200'.$path);
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_CAINFO => '/run/tls/ca.crt',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
    if ($method === 'HEAD') { $options[CURLOPT_NOBODY] = true; }
    if ($basic === null) {
        $options += [CURLOPT_SSLCERT => '/run/tls/search-admin.crt', CURLOPT_SSLKEY => '/run/tls/search-admin.key'];
    } else {
        $options[CURLOPT_USERPWD] = implode(':', $basic);
    }
    if ($body !== null) { $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR); }
    curl_setopt_array($curl, $options);
    $reply = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($reply === false) { throw new RuntimeException('Search TLS/transport failed'); }
    // Basic-auth denial is non-JSON on OpenSearch 2.19. It is never an
    // acknowledgement; callers handle 401 explicitly before any credential write.
    if ($method === 'HEAD' || $status === 401) { return [$status, null]; }
    try { $decoded = json_decode($reply, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('Search returned malformed JSON (HTTP '.$status.')'); }
    if (!is_array($decoded) || !str_starts_with(ltrim($reply), '{')) {
        throw new RuntimeException('Search returned a non-object JSON response');
    }
    return [$status, $decoded];
}

function searchProvision(string $mode): void
{
    if (!in_array($mode, ['check', 'upgrade'], true)) { throw new RuntimeException('Use check or explicit upgrade'); }
    $roles = require __DIR__.'/search-roles.php';
    $secrets = [];
    foreach ($roles as $name => $_) {
        $secret = getenv('OPENSEARCH_'.strtoupper($name).'_PASSWORD') ?: '';
        if (!preg_match('/^[A-Za-z0-9_-]{32,}$/D', $secret)) { throw new RuntimeException('Distinct URL-safe search passwords required'); }
        $secrets[$name] = $secret;
    }
    if (count(array_unique($secrets)) !== count($secrets)) { throw new RuntimeException('Distinct search passwords required'); }
    foreach ($roles as $name => $spec) {
        $user = 'shopkmp_'.$name;
        $role = 'shopkmp_catalog_'.$name;
        $mapping = ['backend_roles' => [], 'hosts' => [], 'users' => [$user]];
        foreach (['roles' => $spec, 'rolesmapping' => $mapping] as $kind => $desired) {
            $path = '/_plugins/_security/api/'.$kind.'/'.$role;
            [$status, $existing] = searchRequest('GET', $path);
            if (!in_array($status, [200, 404], true)) { throw new RuntimeException($name.': policy read HTTP '.$status); }
            // Security API adds harmless metadata/default empty fields.
            $actual = $existing[$role] ?? [];
            $matches = $status === 200;
            foreach ($desired as $key => $value) { $matches = $matches && ($actual[$key] ?? null) == $value; }
            if (!$matches) {
                if ($mode === 'check') { throw new RuntimeException($name.': managed policy differs'); }
                [$status] = searchRequest('PUT', $path, $desired);
                if (!in_array($status, [200, 201], true)) { throw new RuntimeException($name.': policy update HTTP '.$status); }
            }
        }
        [$status, $info] = searchRequest('GET', '/_plugins/_security/authinfo', null, [$user, $secrets[$name]]);
        if (!in_array($status, [200, 401], true)) { throw new RuntimeException($name.': authentication check HTTP '.$status); }
        if ($status !== 200 || ($info['backend_roles'] ?? null) !== []) {
            if ($mode === 'check') { throw new RuntimeException($name.': credentials/backend roles differ'); }
            [$status] = searchRequest('PUT', '/_plugins/_security/api/internalusers/'.$user,
                ['password' => $secrets[$name], 'backend_roles' => [], 'attributes' => new stdClass()]);
            if (!in_array($status, [200, 201], true)) { throw new RuntimeException($name.': user update rejected'); }
        }
        [$status, $info] = searchRequest('GET', '/_plugins/_security/authinfo', null, [$user, $secrets[$name]]);
        $effective = $info['roles'] ?? [];
        sort($effective);
        if ($status !== 200 || $effective !== [$role] || ($info['backend_roles'] ?? null) !== []) {
            throw new RuntimeException($name.': unexpected effective roles; operator must review other mappings');
        }
        print $name.': policy and authentication checked' . "\n";
    }
}

function searchCatalogSchema(): void
{
    // The application bootstrap declares every searchable field explicitly. Stop
    // incidental DTO fields from triggering mapping/put under ordinary writers.
    // _source is preserved. Existing field types/metadata are never rewritten.
    $index = getenv('OPENSEARCH_PRODUCTS_INDEX') ?: 'shopkmp-products';
    require __DIR__.'/search-roles.php'; // same strict index-name validation
    $template = '/_index_template/shopkmp-catalog-writer-policy';
    $spec = [
        'index_patterns' => ['shopkmp-products', 'shopkmp-products-*'], 'priority' => 100,
        'template' => ['mappings' => ['dynamic' => false]],
        '_meta' => ['owner' => 'shopkmp', 'purpose' => 'explicit searchable fields; unprivileged document writers'],
    ];
    searchSchemaAcknowledged(searchRequest('PUT', $template, $spec), 'Catalog writer template');
    [$status, $reply] = searchRequest('GET', $template);
    $templates = $reply['index_templates'] ?? [];
    $actual = $templates[0]['index_template'] ?? [];
    if ($status !== 200 || count($templates) !== 1
        || ($templates[0]['name'] ?? null) !== 'shopkmp-catalog-writer-policy'
        || ($actual['index_patterns'] ?? null) !== $spec['index_patterns']
        || ($actual['priority'] ?? null) !== $spec['priority']
        || !in_array($actual['template']['mappings']['dynamic'] ?? null, [false, 'false'], true)) {
        throw new RuntimeException('Catalog writer template readback differs; inspect outcome before another write');
    }
    [$status] = searchRequest('HEAD', '/'.$index);
    if ($status === 200) {
        $before = searchSchemaIndex($index);
        searchSchemaAcknowledged(searchRequest('PUT', '/'.$index.'/_mapping', ['dynamic' => false]), 'Existing catalog writer mapping');
        $after = searchSchemaIndex($index);
        if (!in_array($after['mappings']['dynamic'] ?? null, [false, 'false'], true)
            || $after['settings']['index']['uuid'] !== $before['settings']['index']['uuid']) {
            throw new RuntimeException('Catalog mapping readback differs; inspect outcome before another write');
        }
        unset($before['mappings']['dynamic'], $after['mappings']['dynamic']);
        if ($after['mappings'] !== $before['mappings']) {
            throw new RuntimeException('Catalog fields/metadata changed; inspect outcome before another write');
        }
    } elseif ($status !== 404) { throw new RuntimeException('Cannot inspect existing catalog mapping policy'); }
    print "Catalog writer policy acknowledged and read back; source/field types/metadata preserved\n";
}

function searchSchemaAcknowledged(array $response, string $operation): void
{
    [$status, $body] = $response;
    if ($status !== 200 || ($body['acknowledged'] ?? null) !== true || array_key_exists('error', $body)) {
        throw new RuntimeException($operation.' not acknowledged; inspect outcome before another write');
    }
}

function searchSchemaIndex(string $index): array
{
    [$status, $body] = searchRequest('GET', '/'.$index);
    $actual = $body[$index] ?? [];
    $uuid = $actual['settings']['index']['uuid'] ?? null;
    if ($status !== 200 || !is_string($uuid) || $uuid === '' || !is_array($actual['mappings'] ?? null)) {
        throw new RuntimeException('Cannot read back catalog mapping identity');
    }
    return $actual;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'catalog-schema') { searchCatalogSchema(); }
        else { searchProvision($argv[1] ?? 'check'); }
    }
    catch (Throwable $error) {
        // Only our fixed diagnostics; no exception args, responses, URLs or secrets.
        fwrite(STDERR, 'SEARCH_SECURITY_PROVISION_FAILED: '.$error->getMessage()."\n");
        exit(1);
    }
}
