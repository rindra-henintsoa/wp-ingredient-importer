<?php

if (!defined('ABSPATH')) exit;

class Ingredient_CPT {

    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;

        register_activation_hook(
            dirname(__DIR__) . '/ingredient-importer.php',
            [$this, 'activate']
        );
    }

    public function activate() {
        $this->create_tables();

        if (!wp_next_scheduled('ingredient_importer_cron_event')) {
            wp_schedule_event(time(), 'hourly', 'ingredient_importer_cron_event');
        }
    }

    public function create_tables() {
        global $wpdb;
        $table = $this->plugin->log_table;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name VARCHAR(255),
            file_path VARCHAR(255),
            rows_total INT DEFAULT 0,
            rows_processed INT DEFAULT 0,
            inserted INT DEFAULT 0,
            updated INT DEFAULT 0,
            ignored INT DEFAULT 0,
            errors TEXT,
            status ENUM('pending','running','done','failed') DEFAULT 'pending',
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            user_id BIGINT NULL,
            PRIMARY KEY (id)
        ) $charset";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function register_cpt() {
        if (post_type_exists('ingredient_fiche')) return;

        $labels = [
            'name' => 'Fiches Ingrédients',
            'singular_name' => 'Fiche Ingrédient',
            'menu_name' => 'Fiches Ingrédients',
            'name_admin_bar' => 'Fiche Ingrédient',
            'add_new_item'   => 'Ajouter un ingrédient',
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true, // custom menu
            'supports' => [ 'title', 'editor' ],
            'capability_type' => 'post',
            'has_archive' => false,
            'menu_position' => 58,
            'menu_icon' => 'dashicons-clipboard'
        ];

        register_post_type( 'ingredient_fiche', $args );
    }
}
