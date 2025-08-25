<?php
/**
 * Plugin Name: HTML → WP Importer (Admin UI)
 * Description: Import a folder/zip of static HTML (.html/.htm) into posts/pages with options. Tools → HTML → WP Importer.
 * Version:     1.0.1
 * Author:      Knol Aust
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'H2WPI_PATH', plugin_dir_path( __FILE__ ) );
define( 'H2WPI_URL',  plugin_dir_url( __FILE__ ) );
define( 'H2WPI_VER',  '1.0.1' );

require_once H2WPI_PATH . 'includes/Admin.php';
require_once H2WPI_PATH . 'includes/Importer.php';

add_action('plugins_loaded', function() {
	\H2WPI\Admin::init();
	\H2WPI\Importer::init();
});