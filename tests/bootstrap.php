<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/WpStub.php';
require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/functions.php';

$GLOBALS['wpdb'] = new wpdb();
