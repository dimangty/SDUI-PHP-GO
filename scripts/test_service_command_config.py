#!/usr/bin/env python3
"""Role startup requirements; first build with `make test-php`, or set PHP_TEST_IMAGE."""
from pathlib import Path
import subprocess
import unittest
import uuid
from test_service_support import php_test_image


class CommandConfiguration(unittest.TestCase):
    def test_preflight_distinguishes_external_config_and_database_failures(self):
        root = Path(__file__).resolve().parents[1]
        for role, expected in (
                ('runtime', 'SERVICE_CONFIG_PREFLIGHT_FAILED: verify required external configuration for this role\n'),
                ('owner', 'DATABASE_ROLE_PREFLIGHT_FAILED: verify service credentials and SQL grants\n')):
            with self.subTest(role=role):
                result = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '--memory', '128m',
                    '--name', 'shopkmp-round7-config-' + uuid.uuid4().hex[:8] + '-e2e', '--entrypoint', 'php',
                    '-e', 'APP_ENV=production', '-e', 'DATABASE_ROLE=' + role,
                    '-v', str(root / 'backend-yii2/infrastructure') + ':/app/infrastructure:ro',
                    '-v', str(root / 'backend-yii2/bin') + ':/app/bin:ro',
                    php_test_image(),
                    '/app/bin/database-preflight.php'], text=True, capture_output=True)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, '')
                self.assertEqual(result.stderr, expected)

    def test_unused_external_secrets_not_required(self):
        root = Path(__file__).resolve().parents[1]
        code = r'''<?php
require '/opt/Env.php';
use Shop\infrastructure\Env;
putenv('APP_ENV=production');
$keys=['OPENSEARCH_URL','PAYMENT_GATEWAY_URL','PAYMENT_GATEWAY_API_KEY','PAYMENT_WEBHOOK_SECRET','PAYMENT_WEBHOOK_ALLOWED_CIDRS'];
foreach(['owner'=>[], 'operator'=>[], 'maintenance'=>[],
    'search'=>['OPENSEARCH_URL'], 'indexer'=>['OPENSEARCH_URL'],
    'recovery'=>['PAYMENT_GATEWAY_URL','PAYMENT_GATEWAY_API_KEY'], 'runtime'=>array_values(array_diff($keys,['OPENSEARCH_URL']))] as $role=>$required){
    foreach($keys as $key){putenv($key);}
    foreach($required as $key){putenv($key.'=synthetic-config');}
    Env::requireRoleConfig($role);
    foreach($required as $missing){
        putenv($missing);$denied=false;
        try{Env::requireRoleConfig($role);}catch(RuntimeException){$denied=true;}
        if(!$denied){throw new RuntimeException($role.': missing required key accepted');}
        putenv($missing.'=synthetic-config');
    }
    print $role.': role-specific config checked'."\n";
}
$denied=false;try{Env::requireRoleConfig('unknown');}catch(RuntimeException){$denied=true;}
if(!$denied){throw new RuntimeException('unknown role accepted');}
'''
        result = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '--memory', '128m',
                                 '--name', 'shopkmp-round7-config-' + uuid.uuid4().hex[:8] + '-e2e',
                                 '--entrypoint', 'php', '-i', '-v',
                                 str(root / 'backend-yii2/infrastructure/Env.php') + ':/opt/Env.php:ro',
                                 php_test_image()],
                                input=code, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
