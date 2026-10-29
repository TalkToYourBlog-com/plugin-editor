<?php
defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('tyb-plugin-editor', [
        'label' => __('AI plugins', 'tyb-plugin-editor'),
        'description' => __('Create, read, edit, delete and revert files and images inside this plugin\'s own "ai-*" plugins. Every other plugin, including this one, is off limits.', 'tyb-plugin-editor'),
    ]);
});
