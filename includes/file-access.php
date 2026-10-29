<?php
// Path resolution and raw read/write, scoped to plugin folders this plugin itself created --
// never an existing plugin. Every public function here either returns a safe, already-validated
// absolute path or a WP_Error -- nothing upstream should ever touch WP_PLUGIN_DIR or build a
// path by hand.

defined('ABSPATH') || exit;

const TYB_PLUGIN_EDITOR_ALLOWED_EXTENSIONS = ['php', 'css', 'js'];
const TYB_PLUGIN_EDITOR_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const TYB_PLUGIN_EDITOR_FOLDER_PREFIX = 'ai-';
const TYB_PLUGIN_EDITOR_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/** The plugins directory every WordPress site already has (wp-content/plugins). */
function tyb_plugin_editor_plugins_dir() {
    return wp_normalize_path(realpath(WP_PLUGIN_DIR));
}

/** Public URL matching tyb_plugin_editor_plugins_dir(). */
function tyb_plugin_editor_plugins_dir_url() {
    return untrailingslashit(plugins_url());
}

/**
 * Whether $name is a folder name this plugin is allowed to create or write inside -- every
 * other plugin folder (this one included) stays off limits. Lowercase letters, digits and
 * hyphens only, so a folder name is always a safe single path segment.
 */
function tyb_plugin_editor_is_allowed_folder_name($name) {
    $pattern = '/^' . preg_quote(TYB_PLUGIN_EDITOR_FOLDER_PREFIX, '/') . '[a-z0-9-]+$/';
    return is_string($name) && preg_match($pattern, $name) === 1;
}

/**
 * Resolves a relative path against wp-content/plugins and makes sure the result cannot escape
 * it, stays inside an allowed "ai-*" plugin folder (no "../", no symlink pointing outside, no
 * absolute path), and uses an allowed extension. Returns the absolute path on success, WP_Error
 * otherwise.
 *
 * @param string     $relative_path       As given by the caller, e.g. "ai-my-widget/ai-my-widget.php".
 * @param bool       $must_exist          Reject a path that does not resolve to an existing file.
 * @param array|null $allowed_extensions  Defaults to the text extensions; pass the image list
 *                                        for image uploads.
 */
function tyb_plugin_editor_resolve_path($relative_path, $must_exist = true, $allowed_extensions = null) {
    $allowed_extensions = $allowed_extensions ?: TYB_PLUGIN_EDITOR_ALLOWED_EXTENSIONS;

    if (!is_string($relative_path) || $relative_path === '') {
        return new WP_Error('tyb_plugin_editor_invalid_path', __('A file path is required.', 'tyb-plugin-editor'));
    }

    $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions, true)) {
        return new WP_Error(
            'tyb_plugin_editor_extension_not_allowed',
            sprintf(
                /* translators: %s: comma separated list of allowed file extensions */
                __('Only these file types can be used here: %s.', 'tyb-plugin-editor'),
                implode(', ', $allowed_extensions)
            )
        );
    }

    $plugins_dir = tyb_plugin_editor_plugins_dir();
    if (!$plugins_dir) {
        return new WP_Error('tyb_plugin_editor_no_plugins_dir', __('The plugins directory could not be found.', 'tyb-plugin-editor'));
    }

    $normalized = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return new WP_Error('tyb_plugin_editor_invalid_path', __('Invalid path.', 'tyb-plugin-editor'));
    }

    $top = explode('/', $normalized)[0];
    if (!tyb_plugin_editor_is_allowed_folder_name($top)) {
        return new WP_Error(
            'tyb_plugin_editor_outside_scope',
            sprintf(
                /* translators: 1: required folder prefix, e.g. "ai-" */
                __('This plugin can only touch plugin folders starting with "%1$s" (e.g. "%1$smy-widget/%1$smy-widget.php"). Call create-plugin first.', 'tyb-plugin-editor'),
                TYB_PLUGIN_EDITOR_FOLDER_PREFIX
            )
        );
    }

    $candidate = $plugins_dir . '/' . $normalized;

    if ($must_exist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return new WP_Error('tyb_plugin_editor_not_found', __('That file does not exist.', 'tyb-plugin-editor'));
        }
        $resolved = wp_normalize_path($resolved);
    } else {
        // A new (or overwritten) file: create its containing folder first, which is itself
        // already confirmed to sit inside an allowed "ai-*" plugin folder above.
        $parent_dir = dirname($candidate);
        if (!wp_mkdir_p($parent_dir)) {
            return new WP_Error('tyb_plugin_editor_mkdir_failed', __('The containing folder could not be created.', 'tyb-plugin-editor'));
        }
        $resolved_parent = realpath($parent_dir);
        if ($resolved_parent === false) {
            return new WP_Error('tyb_plugin_editor_not_found', __('The containing folder could not be found.', 'tyb-plugin-editor'));
        }
        $resolved = wp_normalize_path($resolved_parent) . '/' . basename($candidate);
    }

    // Defense in depth: re-check the fully resolved (symlink-free) path is still inside the
    // plugins dir and inside an allowed top-level folder, even though both were already checked
    // on the unresolved path above -- a symlink could otherwise smuggle the real location out.
    if (strpos($resolved, $plugins_dir . '/') !== 0) {
        return new WP_Error('tyb_plugin_editor_outside_scope', __('That path is outside the plugins directory.', 'tyb-plugin-editor'));
    }
    $resolved_top = explode('/', substr($resolved, strlen($plugins_dir) + 1))[0];
    if (!tyb_plugin_editor_is_allowed_folder_name($resolved_top)) {
        return new WP_Error('tyb_plugin_editor_outside_scope', __('That path resolves outside the AI-managed plugin folders.', 'tyb-plugin-editor'));
    }

    return $resolved;
}

/** Relative path (as the API speaks of it) from an already-resolved absolute path. */
function tyb_plugin_editor_relative_path($absolute_path) {
    $plugins_dir = tyb_plugin_editor_plugins_dir();
    return ltrim(substr(wp_normalize_path($absolute_path), strlen($plugins_dir)), '/');
}

/** sha256 of a file's current content, used so a caller can detect it is editing stale content. */
function tyb_plugin_editor_file_hash($absolute_path) {
    $contents = file_get_contents($absolute_path);
    return $contents === false ? null : hash('sha256', $contents);
}

/**
 * The seed files for a brand new plugin: real, minimal, working code -- not just an empty
 * folder. $slug is the folder/plugin name (e.g. "ai-my-widget"); $fn_prefix is the same string
 * with hyphens turned to underscores, safe to use in PHP identifiers.
 *
 * @return array<string, string> relative path (inside the plugin) => file content
 */
function tyb_plugin_editor_starter_plugin_files($slug, $fn_prefix) {
    return [
        "{$slug}.php" => <<<PHP
<?php
/*
Plugin Name: {$slug}
Version: 1.0.0
*/

defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . 'includes/hooks.php';

PHP,
        'includes/hooks.php' => <<<PHP
<?php
defined('ABSPATH') || exit;

// Add this plugin's hooks here, e.g.:
// add_action('init', '{$fn_prefix}_init');

PHP,
        'uninstall.php' => <<<PHP
<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove any options or data this plugin created, if any.

PHP,
    ];
}

/**
 * Creates a new "ai-*" plugin (or confirms one with that name already exists): its own folder
 * at the plugins root, seeded with a minimal but complete plugin skeleton -- a main file with a
 * valid plugin header, an includes/hooks.php to build on, and an uninstall.php stub. This is the
 * only way this plugin ever creates a top-level folder -- update-file and upload-image only
 * ever write inside one that already exists. Creating it does not install or activate anything;
 * WordPress only runs it once a site admin activates it from the Plugins screen.
 *
 * @return array{path:string, url:string, created:bool, files:string[]}|WP_Error
 */
function tyb_plugin_editor_create_plugin_folder($name) {
    if (!tyb_plugin_editor_is_allowed_folder_name($name)) {
        return new WP_Error(
            'tyb_plugin_editor_invalid_folder_name',
            sprintf(
                /* translators: 1: required folder prefix, e.g. "ai-" */
                __('The folder name must start with "%1$s" and contain only lowercase letters, digits and hyphens, e.g. "%1$smy-widget".', 'tyb-plugin-editor'),
                TYB_PLUGIN_EDITOR_FOLDER_PREFIX
            )
        );
    }

    $plugins_dir = tyb_plugin_editor_plugins_dir();
    if (!$plugins_dir) {
        return new WP_Error('tyb_plugin_editor_no_plugins_dir', __('The plugins directory could not be found.', 'tyb-plugin-editor'));
    }

    $path = $plugins_dir . '/' . $name;
    $already_existed = is_dir($path);

    if (!wp_mkdir_p($path)) {
        return new WP_Error('tyb_plugin_editor_mkdir_failed', __('The folder could not be created.', 'tyb-plugin-editor'));
    }

    $fn_prefix = str_replace('-', '_', $name);
    $created_files = [];
    foreach (tyb_plugin_editor_starter_plugin_files($name, $fn_prefix) as $relative => $content) {
        $file_path = $path . '/' . $relative;
        if (!file_exists($file_path)) {
            wp_mkdir_p(dirname($file_path));
            file_put_contents($file_path, $content);
            $created_files[] = $relative;
        }
    }

    return [
        'path' => $name,
        'url' => tyb_plugin_editor_plugins_dir_url() . '/' . $name,
        'created' => !$already_existed,
        'files' => $created_files,
    ];
}

/**
 * Deletes a file inside an "ai-*" plugin folder, after snapshotting its current content first --
 * writing that snapshot back to the same path with restore-revision re-creates the file, so a
 * deletion is never permanent by accident.
 *
 * @return array{success:bool, revision_id:int}|WP_Error
 */
function tyb_plugin_editor_delete_file($relative_path) {
    $resolved = tyb_plugin_editor_resolve_path($relative_path, true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $content = file_get_contents($resolved);
    if ($content === false) {
        return new WP_Error('tyb_plugin_editor_read_failed', __('The file could not be read.', 'tyb-plugin-editor'));
    }

    $relative = tyb_plugin_editor_relative_path($resolved);
    $revision_id = tyb_plugin_editor_save_revision($relative, $content);
    if (is_wp_error($revision_id)) {
        return $revision_id;
    }

    if (!unlink($resolved)) {
        return new WP_Error('tyb_plugin_editor_delete_failed', __('The file could not be deleted (check folder permissions).', 'tyb-plugin-editor'));
    }

    return [
        'success' => true,
        'revision_id' => $revision_id,
    ];
}

/** Lists every "ai-*" plugin folder at the plugins root. */
function tyb_plugin_editor_list_plugin_folders() {
    $plugins_dir = tyb_plugin_editor_plugins_dir();
    if (!$plugins_dir) {
        return new WP_Error('tyb_plugin_editor_no_plugins_dir', __('The plugins directory could not be found.', 'tyb-plugin-editor'));
    }

    $folders = [];
    foreach (glob($plugins_dir . '/' . TYB_PLUGIN_EDITOR_FOLDER_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $folder) {
        $name = basename($folder);
        if (tyb_plugin_editor_is_allowed_folder_name($name)) {
            $folders[] = $name;
        }
    }
    sort($folders);

    return $folders;
}

/** Lists every allowed-extension file (text or image) inside this plugin's own "ai-*" folders. */
function tyb_plugin_editor_list_files() {
    $plugins_dir = tyb_plugin_editor_plugins_dir();
    if (!$plugins_dir) {
        return new WP_Error('tyb_plugin_editor_no_plugins_dir', __('The plugins directory could not be found.', 'tyb-plugin-editor'));
    }

    $all_extensions = array_merge(TYB_PLUGIN_EDITOR_ALLOWED_EXTENSIONS, TYB_PLUGIN_EDITOR_ALLOWED_IMAGE_EXTENSIONS);
    $files = [];

    foreach (glob($plugins_dir . '/' . TYB_PLUGIN_EDITOR_FOLDER_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $folder) {
        if (!tyb_plugin_editor_is_allowed_folder_name(basename($folder))) {
            continue; // Defensive; the glob pattern already enforces the prefix.
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $all_extensions, true)) {
                continue;
            }
            $files[] = [
                'path' => tyb_plugin_editor_relative_path($file->getPathname()),
                'size_bytes' => $file->getSize(),
                'modified_at' => gmdate('c', $file->getMTime()),
            ];
        }
    }

    usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

    return $files;
}

/**
 * Decodes, validates and writes a base64-encoded image to a path inside an "ai-*" plugin
 * folder. The actual bytes are checked against the extension (not just the file name), and SVG
 * is refused -- it is markup, not a picture, and could carry a script.
 *
 * @return array{path:string, url:string, size_bytes:int}|WP_Error
 */
function tyb_plugin_editor_save_image($relative_path, $content_base64) {
    $resolved = tyb_plugin_editor_resolve_path($relative_path, false, TYB_PLUGIN_EDITOR_ALLOWED_IMAGE_EXTENSIONS);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_string($content_base64) || $content_base64 === '') {
        return new WP_Error('tyb_plugin_editor_invalid_image', __('Image content is required.', 'tyb-plugin-editor'));
    }

    $binary = base64_decode($content_base64, true);
    if ($binary === false) {
        return new WP_Error('tyb_plugin_editor_invalid_image', __('Image content is not valid base64.', 'tyb-plugin-editor'));
    }

    if (strlen($binary) > TYB_PLUGIN_EDITOR_MAX_IMAGE_BYTES) {
        return new WP_Error(
            'tyb_plugin_editor_image_too_large',
            sprintf(
                /* translators: %d: maximum size in megabytes */
                __('Images must be %d MB or smaller.', 'tyb-plugin-editor'),
                TYB_PLUGIN_EDITOR_MAX_IMAGE_BYTES / 1024 / 1024
            )
        );
    }

    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        return new WP_Error('tyb_plugin_editor_invalid_image', __('That does not look like a real image file.', 'tyb-plugin-editor'));
    }

    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $expected_mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    if (($expected_mimes[$extension] ?? null) !== $info['mime']) {
        return new WP_Error(
            'tyb_plugin_editor_image_mismatch',
            __("The file's actual content does not match its extension.", 'tyb-plugin-editor')
        );
    }

    if (file_put_contents($resolved, $binary) === false) {
        return new WP_Error('tyb_plugin_editor_write_failed', __('The image could not be written (check folder permissions).', 'tyb-plugin-editor'));
    }

    $relative = tyb_plugin_editor_relative_path($resolved);

    return [
        'path' => $relative,
        'url' => tyb_plugin_editor_plugins_dir_url() . '/' . $relative,
        'size_bytes' => strlen($binary),
    ];
}
