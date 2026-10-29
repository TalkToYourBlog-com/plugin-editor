=== TalkToYourBlog Plugin Editor ===
Contributors: mjfutera
Tags: abilities api, mcp, ai, plugin editor, developer tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional companion for TalkToYourBlog: lets an AI app build its own WordPress plugins -- PHP, CSS, JS and images -- never an existing plugin.

== Description ==

TalkToYourBlog connects an AI app (Claude, ChatGPT, Cursor and others) to a WordPress site with no plugin required, using WordPress's own REST API. That stays true with or without this plugin.

What TalkToYourBlog's REST-API approach cannot reach is plugin code -- WordPress doesn't expose PHP/CSS/JS files for editing over its core REST API. This plugin adds exactly that, but on purpose does not hand the AI the whole `wp-content/plugins` folder: it can only create and work inside plugin folders of its own, each named with the `ai-` prefix (e.g. `ai-my-widget/`). Every other plugin -- including this one -- is never touched. This is eight WordPress Abilities (the `wp-abilities/v1` mechanism built into WordPress core since 6.9 -- the same one plugins like Rank Math or AIOSEO use to expose their own features to AI apps):

* **Create a plugin folder** -- a new `ai-*` folder under `wp-content/plugins`, the only kind this plugin will ever write into.
* **List plugin folders** -- every `ai-*` folder created so far.
* **List files** -- every PHP/CSS/JS/image file inside those folders, with size and last-modified time.
* **Read a file** -- its content plus a hash, so a later edit can detect if the file changed in between (PHP/CSS/JS only).
* **Create or edit a file** -- writes a PHP, CSS or JS file, creating it if it is new or replacing it if it exists. Refused if the new content fails a syntax check, or if an existing file changed since it was last read.
* **Upload an image** -- writes a JPG, PNG, GIF or WebP file from base64 content, up to 5 MB; the actual bytes are checked against the extension. SVG is refused, since it is markup that could carry a script, not a picture.
* **List a file's history** -- every earlier version of one PHP/CSS/JS file.
* **Restore a file to a previous version** -- and the version it replaces is itself saved first, so a restore can always be undone the same way.

Every edit to an existing file snapshots what it replaces before replacing it (the same idea as WordPress's own post revisions, stored the same way) -- there is no separate "draft" or "preview" step. Image uploads are not snapshotted; re-uploading one simply overwrites it.

**This never activates anything.** Creating or editing files in an `ai-*` folder does not install or run that code -- WordPress only lists a folder as a plugin once it has a PHP file with a valid plugin header, and only runs it once a site admin activates it from the Plugins screen, exactly like a plugin uploaded by hand. This plugin has no activate-plugin ability.

**Who should install this:** only a site owner who specifically wants an AI app to build whole plugins, and understands that means the AI app can write PHP that, once activated by a human, runs on the site. Nobody else needs to -- TalkToYourBlog's own content, media, menu and settings tools, and most of what the WordPress Abilities API already exposes through other plugins, all work the same without it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
2. That's it -- there is no settings screen. The abilities register themselves and become callable by anything with WordPress credentials that has the `edit_plugins` capability (by default: administrators).

== Frequently Asked Questions ==

= Does TalkToYourBlog need this plugin? =

No. TalkToYourBlog works fully without it. This only adds plugin-file creation and editing, which isn't reachable through WordPress's core REST API at all.

= Can only TalkToYourBlog use these abilities? =

No -- once active, these abilities are ordinary WordPress Abilities API entries, reachable by anything authenticated to the site with the `edit_plugins` capability, the same as any other plugin's abilities (Rank Math's, AIOSEO's, etc). This plugin does not check which app is calling.

= What stops it from writing to an existing plugin, or to files like wp-config.php? =

Every path is resolved and checked against `wp-content/plugins` first, and then against the `ai-*` folder it must start inside -- a path with "..", an absolute path, a symlink pointing elsewhere, or a folder name that doesn't start with `ai-` is refused before anything is read or written. Files must also be an allowed type: .php, .css or .js for code, or .jpg/.jpeg/.png/.gif/.webp for images.

= Can the AI edit this plugin, or TalkToYourBlog Event Calendar, or any plugin I already have installed? =

No. Every ability refuses any path outside an `ai-*` folder, and none of this site's existing plugin folders start with `ai-`. The AI can build out its own new plugin folders freely, but it cannot touch an existing one.

= Does this activate the plugins it creates? =

No, never. A new `ai-*` folder is just files on disk until a site admin activates it from the Plugins screen, the same as any plugin uploaded by hand. This plugin has no ability to activate, deactivate or delete a plugin.

= What if the AI writes broken PHP? =

The edit is refused before it touches the file if the new content fails a syntax check (a real `php -l` where the server allows it, otherwise a tokenizer-based check that still catches most mistakes). Since nothing is activated automatically, broken code sitting in an inactive `ai-*` folder cannot break the site either way; if it is later activated and something still gets through, WordPress's own fatal-error protection (built into core since 5.2) puts the site into recovery mode rather than staying broken, and the previous version of the file is one `restore-revision` call away.

= Where is the history kept, and does it ever get deleted automatically? =

As a private WordPress post type (`talktoyourblog_plugin_revision`), the same mechanism core uses for post revisions -- not a separate file store. Nothing is pruned automatically in this version. Deleting this plugin (not just deactivating it) removes all of it; it does not remove the `ai-*` plugins themselves.

== External services ==

This plugin does not connect to any external service. Everything it does happens on the WordPress site itself: reading and writing files in its own `ai-*` plugin folders, and storing revisions in the site's own database.

== Privacy ==

No data about the site or its users is sent anywhere by this plugin. Revisions store the file content, the editing user's ID and a timestamp, in the site's own database, for as long as the plugin stays installed.

== Source code ==

The full, human-readable source of this plugin is public: https://github.com/TalkToYourBlog-com/plugin-editor.

== Changelog ==

= 0.1.0 =
* First version: create-plugin/list-plugins/list-files/get-file/create-or-edit-file/upload-image/list-revisions/restore-revision, all scoped to this plugin's own "ai-*" plugin folders.
