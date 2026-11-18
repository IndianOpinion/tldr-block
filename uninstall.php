<?php

/**
 * Uninstall: removes options, post meta, term meta, events and transients
 * on every site. Never calls a provider.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\TldrBlock\Uninstaller::run();
