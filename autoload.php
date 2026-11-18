<?php

/**
 * Loads the plugin without Composer: a PSR-4 autoloader for src/, the
 * public functions, and llm-json-php from vendor/ or lib/ unless another
 * plugin already loaded it.
 */

declare(strict_types=1);

if (!defined('TLDR_BLOCK_AUTOLOADED')) {
    define('TLDR_BLOCK_AUTOLOADED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\TldrBlock\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require_once __DIR__ . '/src/functions.php';

    if (!class_exists(\IndianOpinion\LlmJson\Client::class)) {
        if (is_file(__DIR__ . '/vendor/indianopinion/llm-json-php/bootstrap.php')) {
            require_once __DIR__ . '/vendor/indianopinion/llm-json-php/bootstrap.php';
        } elseif (is_file(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
            require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
        }
    }
}
