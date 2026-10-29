<?php
defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('tyb-plugin-editor', [
        'label' => __('AI plugin folders', 'tyb-plugin-editor'),
        'description' => __('Create, read, edit and revert files and images inside this plugin\'s own "ai-*" plugin folders. Every other plugin is off limits.', 'tyb-plugin-editor'),
    ]);
});
