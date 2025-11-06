<?php

$config = require __DIR__ . '/app.php';

define('APP_NAME', $config['name']);
define('APP_VERSION', $config['version']);
define('APP_LOGO_PATH', realpath(__DIR__ . '/../repack/logo.php'));
