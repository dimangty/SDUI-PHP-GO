"""Shared image selection for F2 tests; `make test-php` builds the CI default."""
import os


def php_test_image():
    return (os.getenv('SERVICE_PRIVILEGES_PHP_IMAGE') or os.getenv('PHP_TEST_IMAGE')
            or 'shopkmp-php-tests')
