<?php
declare(strict_types=1);

// Keep application and tool dependencies in their own Composer projects. Loading
// this autoloader does not bootstrap HTTP, load secrets, or connect to services.
require dirname(__DIR__) . '/vendor/autoload.php';
