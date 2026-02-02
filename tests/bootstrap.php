<?php

/**
 * Test bootstrap file for stud-cli
 *
 * This file is loaded before tests run and sets up the test environment.
 * It defines constants and environment variables needed for reliable test detection.
 */

// Load Composer autoloader first
require __DIR__ . '/../vendor/autoload.php';

// Define test mode constant for reliable detection in UpdateHandler::isTestEnvironment()
if (! defined('STUD_CLI_TEST_MODE')) {
    define('STUD_CLI_TEST_MODE', true);
}

// Ensure APP_ENV is set to 'test' if not already set
if (! isset($_ENV['APP_ENV'])) {
    $_ENV['APP_ENV'] = 'test';
}
if (! isset($_SERVER['APP_ENV'])) {
    $_SERVER['APP_ENV'] = 'test';
}

// Capture deprecations for debugging (only in test mode)
if (getenv('DISPLAY_DEPRECATIONS') === '1') {
    set_error_handler(function ($severity, $message, $file, $line) {
        if ($severity === E_DEPRECATED || strpos(strtolower($message), 'deprecat') !== false) {
            fwrite(STDERR, "DEPRECATION: {$message} in {$file}:{$line}\n");
        }

        return false;
    }, E_ALL);
}
