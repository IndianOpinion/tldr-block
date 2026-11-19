<?php

/**
 * Script dependencies for editor.js. There is no build step, so this file
 * is written by hand: WordPress reads it when it registers the block's
 * editor script, so the wp globals the script uses are loaded first.
 */

return [
    'dependencies' => ['wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n'],
    'version' => '0.1.0',
];
