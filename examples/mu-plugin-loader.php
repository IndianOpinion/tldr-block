<?php

/**
 * Plugin Name: tldr-block loader
 * Description: Loads tldr-block as a must-use plugin. Copy this file to
 * wp-content/mu-plugins/ and put the plugin folder at
 * wp-content/mu-plugins/tldr-block/.
 */

declare(strict_types=1);

if (is_file(__DIR__ . '/tldr-block/tldr-block.php')) {
    require_once __DIR__ . '/tldr-block/tldr-block.php';
}
