<?php
/**
 * Plugin Name: Ingredient Importer
 * Description: Import CSV/XLSX → CPT ingredient_fiche (admin UI, cron, logs, REST)
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-ingredient-exporter.php';
require_once __DIR__ . '/includes/class-ingredient-shortcodes.php';
require_once __DIR__ . '/includes/class-ingredient-cpt.php';
require_once __DIR__ . '/includes/class-ingredient-admin.php';
require_once __DIR__ . '/includes/class-ingredient-import.php';
require_once __DIR__ . '/includes/class-ingredient-rest.php';
require_once __DIR__ . '/includes/class-ingredient-importer.php';

add_action('plugins_loaded', function () {
    Ingredient_Importer::instance();
});