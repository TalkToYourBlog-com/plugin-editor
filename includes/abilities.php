<?php
// The abilities themselves. Every execute_callback is a thin wrapper over includes/file-access.php,
// includes/validate.php and includes/revisions.php -- the logic lives there, not here.
//
// Every ability requires the real WordPress capability 'edit_plugins' (the same one that gates
// WordPress's own built-in Plugin File Editor), checked server-side in permission_callback,
// independent of whatever scope an MCP connection was given: a connection cannot see or run a
// WordPress capability it doesn't have just because this plugin is active.
//
// None of these abilities can touch an existing plugin, including this one. Every path must
// live inside a folder whose name starts with TYB_PLUGIN_EDITOR_FOLDER_PREFIX
// ("ai-" by default), created through create-plugin -- see includes/file-access.php for the
// boundary check itself. Creating files here never installs or runs anything: WordPress only
// lists a folder as a plugin once it has a PHP file with a valid header, and only runs it once
// a site admin activates it from the Plugins screen -- this plugin never activates one itself.

defined('ABSPATH') || exit;

function tyb_plugin_editor_can_edit_plugins() {
    return current_user_can('edit_plugins');
}

add_action('wp_abilities_api_init', function () {
    wp_register_ability('tyb-plugin-editor/create-plugin', [
        'label' => __('Create a plugin folder', 'tyb-plugin-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Creates a new plugin under wp-content/plugins -- its own folder seeded with a minimal but complete plugin skeleton (a main file with a valid plugin header, includes/hooks.php to build on, and uninstall.php) so there is real, working code to start from instead of an empty folder (or confirms one with that name already exists). The name must start with "%1$s" (e.g. "%1$smy-widget") -- call this once before writing the first file into a new plugin. Every other plugin folder, including this one, stays off limits. This only creates files; it never activates the plugin -- a site admin does that from the Plugins screen once it is ready.', 'tyb-plugin-editor'),
            TYB_PLUGIN_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'description' => 'Folder name, e.g. "ai-my-widget". Must start with "ai-" and contain only lowercase letters, digits and hyphens.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'created' => ['type' => 'boolean', 'description' => 'False when the folder already existed.'],
                'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The starter files written (empty if the plugin already existed).'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('create-plugin', function ($input) {
            return tyb_plugin_editor_create_plugin_folder($input['name']);
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/list-plugins', [
        'label' => __('List AI plugin folders', 'tyb-plugin-editor'),
        'description' => __('Lists the plugin folders this plugin has created so far.', 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'plugins' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('list-plugins', function () {
            $folders = tyb_plugin_editor_list_plugin_folders();
            return is_wp_error($folders) ? $folders : ['plugins' => $folders];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/list-files', [
        'label' => __('List files', 'tyb-plugin-editor'),
        'description' => __('Lists every PHP, CSS, JS and image file inside this plugin\'s own "ai-*" plugin folders, with size and last-modified time.', 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                            'modified_at' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('list-files', function () {
            $files = tyb_plugin_editor_list_files();
            return is_wp_error($files) ? $files : ['files' => $files];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/get-file', [
        'label' => __('Read a file', 'tyb-plugin-editor'),
        'description' => __('Returns the text content of one PHP, CSS or JS file from one of this plugin\'s own "ai-*" plugin folders, plus its sha256 hash (pass that hash back as expected_hash to update-file to catch a change made elsewhere in between). Images are not readable this way -- use list-files for their URL.', 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the plugins folder, e.g. "ai-my-widget/ai-my-widget.php".'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'hash' => ['type' => 'string'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('get-file', function ($input) {
            $resolved = tyb_plugin_editor_resolve_path($input['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $content = file_get_contents($resolved);
            if ($content === false) {
                return new WP_Error('tyb_plugin_editor_read_failed', __('The file could not be read.', 'tyb-plugin-editor'));
            }
            return [
                'path' => tyb_plugin_editor_relative_path($resolved),
                'content' => $content,
                'hash' => hash('sha256', $content),
            ];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/update-file', [
        'label' => __('Create or edit a file', 'tyb-plugin-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Writes a PHP, CSS or JS file inside one of this plugin\'s own "%1$s*" plugin folders -- creates it if it does not exist yet, or replaces it if it does (snapshotting the previous content first; see list-revisions / restore-revision). Refuses to save PHP/CSS/JS that fails a syntax check. Call create-plugin first if the folder does not exist yet.', 'tyb-plugin-editor'),
            TYB_PLUGIN_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1],
                'content' => ['type' => 'string'],
                'expected_hash' => ['type' => 'string', 'description' => 'sha256 from a prior get-file call. If given and the file no longer matches it, the edit is refused instead of silently overwriting a change made elsewhere. Ignored when the file does not exist yet.'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'created' => ['type' => 'boolean', 'description' => 'True when this call created a new file rather than editing one that already existed.'],
                'hash' => ['type' => 'string'],
                'revision_id' => ['type' => ['integer', 'null'], 'description' => 'The id of the revision that now holds the content this edit replaced, or null for a newly created file (nothing to snapshot).'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('update-file', function ($input) {
            $resolved = tyb_plugin_editor_resolve_path($input['path'], false);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $file_existed = file_exists($resolved);
            $previous_content = '';

            if ($file_existed) {
                $previous_content = file_get_contents($resolved);
                if ($previous_content === false) {
                    return new WP_Error('tyb_plugin_editor_read_failed', __('The file could not be read.', 'tyb-plugin-editor'));
                }
                if (!empty($input['expected_hash']) && hash('sha256', $previous_content) !== $input['expected_hash']) {
                    return new WP_Error('tyb_plugin_editor_conflict', __('The file changed since it was last read; re-read it before editing.', 'tyb-plugin-editor'));
                }
            }

            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $valid = tyb_plugin_editor_validate_content($extension, $input['content']);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $relative_path = tyb_plugin_editor_relative_path($resolved);
            $revision_id = null;
            if ($file_existed) {
                $revision_id = tyb_plugin_editor_save_revision($relative_path, $previous_content);
                if (is_wp_error($revision_id)) {
                    return $revision_id;
                }
            }

            if (file_put_contents($resolved, $input['content']) === false) {
                return new WP_Error('tyb_plugin_editor_write_failed', __('The file could not be written (check folder permissions).', 'tyb-plugin-editor'));
            }

            return [
                'success' => true,
                'created' => !$file_existed,
                'hash' => hash('sha256', $input['content']),
                'revision_id' => $revision_id,
            ];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/upload-image', [
        'label' => __('Upload an image', 'tyb-plugin-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Writes a JPG, PNG, GIF or WebP image inside one of this plugin\'s own "%1$s*" plugin folders, from base64-encoded content -- creates it if it does not exist yet, or overwrites it if it does. SVG is not accepted (it is markup, not a picture, and could carry a script). The actual bytes are checked against the extension, and there is a 5 MB limit. Call create-plugin first if the folder does not exist yet.', 'tyb-plugin-editor'),
            TYB_PLUGIN_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the plugins folder, e.g. "ai-my-widget/assets/img/icon.png".'],
                'content_base64' => ['type' => 'string', 'minLength' => 1, 'description' => 'The image file, base64-encoded.'],
            ],
            'required' => ['path', 'content_base64'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'size_bytes' => ['type' => 'integer'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('upload-image', function ($input) {
            return tyb_plugin_editor_save_image($input['path'], $input['content_base64']);
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/delete-file', [
        'label' => __('Delete a file', 'tyb-plugin-editor'),
        'description' => __('Deletes a PHP, CSS, JS or image file inside one of this plugin\'s own "ai-*" plugin folders. The content is snapshotted first (for text files, via list-revisions / restore-revision), so a deletion can be undone by restoring that revision to the same path.', 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the plugins folder, e.g. "ai-my-widget/unused.php".'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'revision_id' => ['type' => ['integer', 'null'], 'description' => 'The id of the revision holding the content this call deleted, or null for an image (images are not revisioned).'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('delete-file', function ($input) {
            $extension = strtolower(pathinfo($input['path'], PATHINFO_EXTENSION));
            if (in_array($extension, TYB_PLUGIN_EDITOR_ALLOWED_IMAGE_EXTENSIONS, true)) {
                $resolved = tyb_plugin_editor_resolve_path($input['path'], true, TYB_PLUGIN_EDITOR_ALLOWED_IMAGE_EXTENSIONS);
                if (is_wp_error($resolved)) {
                    return $resolved;
                }
                if (!unlink($resolved)) {
                    return new WP_Error('tyb_plugin_editor_delete_failed', __('The file could not be deleted (check folder permissions).', 'tyb-plugin-editor'));
                }
                return ['success' => true, 'revision_id' => null];
            }
            return tyb_plugin_editor_delete_file($input['path']);
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/list-revisions', [
        'label' => __('List a file\'s history', 'tyb-plugin-editor'),
        'description' => __('Lists previous versions of one PHP, CSS or JS file, newest first, each with the id needed to restore it.', 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'revisions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'created_at' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'hash' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('list-revisions', function ($input) {
            $resolved = tyb_plugin_editor_resolve_path($input['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            return ['revisions' => tyb_plugin_editor_list_revisions(tyb_plugin_editor_relative_path($resolved))];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('tyb-plugin-editor/restore-revision', [
        'label' => __('Restore a file to a previous version', 'tyb-plugin-editor'),
        'description' => __("Writes a previous revision's content back to its file. The file's current content is itself snapshotted first, so a restore can always be undone the same way.", 'tyb-plugin-editor'),
        'category' => 'tyb-plugin-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'revision_id' => ['type' => 'integer', 'description' => 'An id returned by list-revisions or by a prior update-file call.'],
            ],
            'required' => ['revision_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'path' => ['type' => 'string'],
                'hash' => ['type' => 'string'],
                'backup_revision_id' => ['type' => 'integer', 'description' => 'The id of the new revision created from the content this restore just replaced.'],
            ],
        ],
        'execute_callback' => tyb_plugin_editor_logged_ability('restore-revision', function ($input) {
            $revision = tyb_plugin_editor_get_revision($input['revision_id']);
            if (is_wp_error($revision)) {
                return $revision;
            }

            $resolved = tyb_plugin_editor_resolve_path($revision['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $valid = tyb_plugin_editor_validate_content($extension, $revision['content']);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $relative_path = tyb_plugin_editor_relative_path($resolved);
            $current_content = file_get_contents($resolved);
            if ($current_content === false) {
                return new WP_Error('tyb_plugin_editor_read_failed', __('The file could not be read.', 'tyb-plugin-editor'));
            }

            $backup_revision_id = tyb_plugin_editor_save_revision($relative_path, $current_content);
            if (is_wp_error($backup_revision_id)) {
                return $backup_revision_id;
            }

            if (file_put_contents($resolved, $revision['content']) === false) {
                return new WP_Error('tyb_plugin_editor_write_failed', __('The file could not be written (check folder permissions).', 'tyb-plugin-editor'));
            }

            return [
                'success' => true,
                'path' => $relative_path,
                'hash' => hash('sha256', $revision['content']),
                'backup_revision_id' => $backup_revision_id,
            ];
        }),
        'permission_callback' => 'tyb_plugin_editor_can_edit_plugins',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);
});
