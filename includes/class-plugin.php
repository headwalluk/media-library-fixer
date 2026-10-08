<?php
/**
 * Main Plugin Class
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Main Plugin Class.
 *
 * @since 0.1.0
 */
class Plugin {

	/**
	 * Single instance of the class.
	 *
	 * @since 0.1.0
	 * @var ?Plugin
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @since 0.1.0
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Everything the plugin does happens in wp-admin, AJAX or WP-CLI.
	 *
	 * @since 0.1.0
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$settings = new Settings();
			$settings->run();

			$tool_page = new Tool_Page();
			$tool_page->run();

			add_filter( 'plugin_action_links_' . MEDIA_LIBRARY_FIXER_BASENAME, array( $this, 'add_action_links' ) );
		}
	}

	/**
	 * Load bundled translations.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'media-library-fixer', false, dirname( MEDIA_LIBRARY_FIXER_BASENAME ) . '/languages' );
	}

	/**
	 * Add Tool and Settings links to the plugin's row on the Plugins screen.
	 *
	 * @since 0.1.0
	 * @param mixed $links Existing links.
	 * @return mixed Links with ours prepended, or the input unchanged when it isn't an array.
	 */
	public function add_action_links( mixed $links ): mixed {
		if ( is_array( $links ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( get_tool_page_url() ), esc_html_x( 'Open tool', 'plugin action link', 'media-library-fixer' ) ),
				sprintf( '<a href="%s">%s</a>', esc_url( get_settings_page_url() ), esc_html_x( 'Settings', 'plugin action link', 'media-library-fixer' ) )
			);
		}

		return $links;
	}
}
