<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Shop\infrastructure\{Database,DatabaseRole,Env};

try {
    if (Env::production()) {
        Env::requireRoleConfig(Env::get('DATABASE_ROLE'));
    }
} catch (Throwable) {
    fwrite(STDERR, "SERVICE_CONFIG_PREFLIGHT_FAILED: verify required external configuration for this role\n");
    exit(1);
}
try {
    if (Env::production()) {
        DatabaseRole::preflight(new Database(Env::get('DATABASE_URL')), Env::get('DATABASE_ROLE'));
    }
    print "Database role preflight passed\n";
} catch (Throwable) {
    // Never print driver/DSN details or exception arguments.
    fwrite(STDERR, "DATABASE_ROLE_PREFLIGHT_FAILED: verify service credentials and SQL grants\n");
    exit(1);
}
