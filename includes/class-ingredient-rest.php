<?php

if (!defined('ABSPATH')) exit;

class Ingredient_REST {

    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    public function register_rest_routes() {
        register_rest_route('ingr/v1', '/imports', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_imports'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    public function rest_get_imports() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->plugin->log_table} ORDER BY id DESC LIMIT 100"
        );
        return rest_ensure_response($rows);
    }
}
