<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Shop\infrastructure\{Database,DatabaseRole,Env};

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
