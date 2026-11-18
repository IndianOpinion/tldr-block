<?php

/**
 * Plugin Name:       tldr-block
 * Plugin URI:        https://github.com/IndianOpinion/tldr-block
 * Description:       A short, editor-approved summary at the top of long posts. Regenerated when the post changes, stored in post meta, with a shortcode, a block and per-category switches.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       tldr-block
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\TldrBlock\Plugin::boot(__FILE__);
