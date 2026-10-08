<?php
/**
 * Plugin Name: Media Library Fixer
 * Plugin URI: https://github.com/headwalluk/media-library-fixer
 * Description: Resync the Media Library with the uploads folder: register files WordPress doesn't know about, and find attachments whose files have gone.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Author: Paul Faulkner
 * Author URI: https://headwall-hosting.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-library-fixer
 * Domain Path: /languages
 *
 * @package Media_Library_Fixer
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

define( 'MEDIA_LIBRARY_FIXER_VERSION', '0.1.0' );
define( 'MEDIA_LIBRARY_FIXER_FILE', __FILE__ );
define( 'MEDIA_LIBRARY_FIXER_PATH', plugin_dir_path( __FILE__ ) );
define( 'MEDIA_LIBRARY_FIXER_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIA_LIBRARY_FIXER_BASENAME', plugin_basename( __FILE__ ) );

require_once MEDIA_LIBRARY_FIXER_PATH . 'constants.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'functions-private.php';

require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-module.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-pdf-metadata-reader.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-attachment-importer.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/modules/class-unregistered-files-module.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/modules/class-missing-files-module.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-job-runner.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-settings.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-tool-page.php';
require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MEDIA_LIBRARY_FIXER_PATH . 'includes/class-cli-commands.php';
	WP_CLI::add_command( 'media-library-fixer', 'Media_Library_Fixer\CLI_Commands' );
}

/**
 * Add default options on activation.
 *
 * @since 0.1.0
 */
function media_library_fixer_activate(): void {
	foreach ( Media_Library_Fixer\get_default_settings() as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
}
register_activation_hook( __FILE__, 'media_library_fixer_activate' );

/**
 * Initialise the plugin.
 *
 * @since 0.1.0
 */
function media_library_fixer_run(): void {
	$plugin = Media_Library_Fixer\Plugin::instance();
	$plugin->run();
}
media_library_fixer_run();
