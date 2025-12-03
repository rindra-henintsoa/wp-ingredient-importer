<?php

if (!defined('ABSPATH')) exit;

class Ingredient_Importer {

    public static $instance;
    public $version = '1.0.0';
    public $upload_subdir = 'ingredient-imports';
    public $option_key = 'ingredient_importer_options';
    public $log_table;

    public $cpt;
    public $admin;
    public $import;
    public $rest;
    public $shortcode;
    public $export;

    public static function instance() {
        return self::$instance ?? (self::$instance = new self());
    }

    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'ingredient_import_logs';

        // Load modules
        $this->cpt          = new Ingredient_CPT($this);
        $this->admin        = new Ingredient_Admin($this);
        $this->import       = new Ingredient_Import($this);
        $this->rest         = new Ingredient_REST($this);
        $this->shortcode    = new Ingredient_Shortcodes($this);
        $this->export       = new Ingredient_Exporter_XLSX($this);

        add_action('init',      [$this->cpt,   'register_cpt']);
        add_action('admin_menu',[$this->admin, 'admin_menu']);
        add_action('admin_post_ingredient_import', [$this->admin, 'handle_upload_post']);

        add_action('ingredient_importer_cron_event', [$this->import, 'cron_process_pending_imports']);
        add_action('rest_api_init', [$this->rest, 'register_rest_routes']);
    }
}
