<?php

/*
 * Plugin Name:       TalkToYourBlog Plugin Editor
 * Plugin URI:        https://github.com/TalkToYourBlog-com/plugin-editor
 * Description:       Optional companion for TalkToYourBlog. Lets an AI app create and edit its own WordPress plugins through the WordPress Abilities API -- never an existing plugin. Not required for TalkToYourBlog to work.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Michal Futera
 * Author URI:        https://michalfutera.pro/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       talktoyourblog-plugin-editor
 */

defined('ABSPATH') || exit;

define('TALKTOYOURBLOG_PLUGIN_EDITOR_VERSION', '0.2.0');
define('TALKTOYOURBLOG_PLUGIN_EDITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TALKTOYOURBLOG_PLUGIN_EDITOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR', TALKTOYOURBLOG_PLUGIN_EDITOR_PLUGIN_DIR . 'includes');

// The debug log lives in the uploads directory, not the plugin folder, which WordPress replaces
// on every update.
$talktoyourblog_plugin_editor_uploads = wp_upload_dir(null, false);
define('TALKTOYOURBLOG_PLUGIN_EDITOR_VAR_DIR', trailingslashit($talktoyourblog_plugin_editor_uploads['basedir']) . 'talktoyourblog-plugin-editor');
unset($talktoyourblog_plugin_editor_uploads);

// The Abilities API (wp_register_ability, wp_abilities_api_init, ...) ships in WordPress core
// from 6.9 onward. On an older site this plugin simply has nothing to hook into -- fail quietly
// rather than fatal, so activating it on an old install is a no-op, not a broken admin screen.
if (!function_exists('wp_register_ability')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('TalkToYourBlog Plugin Editor needs WordPress 6.9 or later (Abilities API). It is installed but inactive until you update WordPress.', 'talktoyourblog-plugin-editor') .
            '</p></div>';
    });
    return;
}

require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/logging.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/file-access.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/validate.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/revisions.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/categories.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/abilities.php';
require_once TALKTOYOURBLOG_PLUGIN_EDITOR_INCLUDES_DIR . '/admin-log.php';

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $links[] = '<a href="https://issues.michalfutera.com/?project=talktoyourblog-plugin-editor" target="_blank" rel="noopener">' .
        esc_html__('Report a bug or idea', 'talktoyourblog-plugin-editor') .
        '</a>';
    return $links;
});
