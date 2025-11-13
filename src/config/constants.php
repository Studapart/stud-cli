<?php

$config = require __DIR__ . '/app.php';

define('APP_REPO_SLUG', $config['repo_slug']);
define('APP_NAME', $config['description']);
define('APP_VERSION', $config['version']);
define('APP_LOGO_PATH', __DIR__ . '/../repack/logo.php');
