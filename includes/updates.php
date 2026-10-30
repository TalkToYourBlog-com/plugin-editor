<?php

defined('ABSPATH') || exit;

// Not in the WordPress.org directory, so WordPress never checks for updates on its own.
// This points it at the plugin's GitHub Releases instead, using the built zip attached to
// each release (not the raw repo, which still has .git/.wordpress-org/dist in it).
require_once TYB_PLUGIN_EDITOR_INCLUDES_DIR . '/plugin-update-checker/plugin-update-checker.php';

$tyb_plugin_editor_update_checker = YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
    'https://github.com/TalkToYourBlog-com/plugin-editor/',
    TYB_PLUGIN_EDITOR_PLUGIN_DIR . 'tyb-plugin-editor.php',
    'tyb-plugin-editor'
);

$tyb_plugin_editor_update_checker->getVcsApi()->enableReleaseAssets();
