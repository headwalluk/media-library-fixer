<?php
/**
 * Settings Page Class
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Settings → Media Library Fixer.
 *
 * @since 0.1.0
 */
class Settings {

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public function run(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @since 0.1.0
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Media Library Fixer Settings', 'media-library-fixer' ),
			__( 'Media Library Fixer', 'media-library-fixer' ),
			REQUIRED_CAPABILITY,
			SETTINGS_PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @since 0.1.0
	 */
	public function register_settings(): void {
		register_setting(
			SETTINGS_GROUP,
			OPTION_FILE_EXTENSIONS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_file_extensions' ),
				'default'           => DEFAULT_FILE_EXTENSIONS,
			)
		);

		register_setting(
			SETTINGS_GROUP,
			OPTION_SCAN_SCOPE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_scan_scope' ),
				'default'           => DEFAULT_SCAN_SCOPE,
			)
		);

		register_setting(
			SETTINGS_GROUP,
			OPTION_DATE_SOURCE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_date_source' ),
				'default'           => DEFAULT_DATE_SOURCE,
			)
		);

		register_setting(
			SETTINGS_GROUP,
			OPTION_TITLE_SOURCE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_title_source' ),
				'default'           => DEFAULT_TITLE_SOURCE,
			)
		);

		$boolean_settings = array(
			OPTION_DESCRIBE_DOCUMENT        => DEFAULT_DESCRIBE_DOCUMENT,
			OPTION_GENERATE_PREVIEWS        => DEFAULT_GENERATE_PREVIEWS,
			OPTION_DELETE_DATA_ON_UNINSTALL => DEFAULT_DELETE_DATA_ON_UNINSTALL,
		);

		foreach ( $boolean_settings as $option_name => $default_value ) {
			register_setting(
				SETTINGS_GROUP,
				$option_name,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => $default_value,
				)
			);
		}
	}

	/**
	 * Keep only extensions WordPress allows, reporting any that were dropped.
	 *
	 * @since 0.1.0
	 * @param mixed $value Submitted value.
	 * @return string Comma-separated extensions.
	 */
	public function sanitize_file_extensions( mixed $value ): string {
		$allowed  = array();
		$rejected = array();

		foreach ( parse_extension_list( $value ) as $extension ) {
			$file_type = wp_check_filetype( 'file.' . $extension );

			if ( is_string( $file_type['type'] ) && '' !== $file_type['type'] ) {
				$allowed[] = $extension;
			} else {
				$rejected[] = $extension;
			}
		}

		if ( array() !== $rejected ) {
			add_settings_error(
				OPTION_FILE_EXTENSIONS,
				'mlfixer_rejected_extensions',
				sprintf(
					/* translators: %s: comma-separated list of file extensions */
					__( 'WordPress does not allow these file types, so they were removed: %s', 'media-library-fixer' ),
					implode( ', ', $rejected )
				)
			);
		}

		if ( array() === $allowed ) {
			$allowed = parse_extension_list( DEFAULT_FILE_EXTENSIONS );
		}

		return implode( ', ', $allowed );
	}

	/**
	 * Sanitize the scan scope.
	 *
	 * @since 0.1.0
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_scan_scope( mixed $value ): string {
		return in_array( $value, array( SCAN_SCOPE_DATED, SCAN_SCOPE_ALL ), true ) ? $value : DEFAULT_SCAN_SCOPE;
	}

	/**
	 * Sanitize the date source.
	 *
	 * @since 0.1.0
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_date_source( mixed $value ): string {
		return in_array( $value, array( DATE_SOURCE_DOCUMENT, DATE_SOURCE_FOLDER, DATE_SOURCE_MODIFIED ), true ) ? $value : DEFAULT_DATE_SOURCE;
	}

	/**
	 * Sanitize the title source.
	 *
	 * @since 0.1.0
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_title_source( mixed $value ): string {
		return in_array( $value, array( TITLE_SOURCE_FILENAME, TITLE_SOURCE_DOCUMENT ), true ) ? $value : DEFAULT_TITLE_SOURCE;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 0.1.0
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( REQUIRED_CAPABILITY ) ) {
			return;
		}

		$settings = array(
			'file_extensions'          => (string) get_option( OPTION_FILE_EXTENSIONS, DEFAULT_FILE_EXTENSIONS ),
			'scan_scope'               => get_scan_scope(),
			'date_source'              => get_date_source(),
			'title_source'             => get_title_source(),
			'describe_document'        => get_bool_option( OPTION_DESCRIBE_DOCUMENT, DEFAULT_DESCRIBE_DOCUMENT ),
			'generate_previews'        => get_bool_option( OPTION_GENERATE_PREVIEWS, DEFAULT_GENERATE_PREVIEWS ),
			'delete_data_on_uninstall' => get_bool_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEFAULT_DELETE_DATA_ON_UNINSTALL ),
		);

		$can_generate_pdf_previews = can_generate_pdf_previews();

		// Option names and choice values passed to the template, which runs outside the namespace.
		$option_names = array(
			'file_extensions'          => OPTION_FILE_EXTENSIONS,
			'scan_scope'               => OPTION_SCAN_SCOPE,
			'date_source'              => OPTION_DATE_SOURCE,
			'title_source'             => OPTION_TITLE_SOURCE,
			'describe_document'        => OPTION_DESCRIBE_DOCUMENT,
			'generate_previews'        => OPTION_GENERATE_PREVIEWS,
			'delete_data_on_uninstall' => OPTION_DELETE_DATA_ON_UNINSTALL,
		);

		$scan_scope_choices = array(
			SCAN_SCOPE_DATED => __( 'Year/month folders only (e.g. 2021/10)', 'media-library-fixer' ),
			SCAN_SCOPE_ALL   => __( 'The whole uploads folder, skipping folders other plugins use for caches, logs and form uploads', 'media-library-fixer' ),
		);

		$date_source_choices = array(
			DATE_SOURCE_DOCUMENT => __( 'Document creation date where the file has one (PDF only), otherwise the upload folder', 'media-library-fixer' ),
			DATE_SOURCE_FOLDER   => __( 'The upload folder: the first of the month the file was filed under', 'media-library-fixer' ),
			DATE_SOURCE_MODIFIED => __( 'The file\'s modified time on disk', 'media-library-fixer' ),
		);

		$title_source_choices = array(
			TITLE_SOURCE_FILENAME => __( 'The file name, with underscores turned into spaces', 'media-library-fixer' ),
			TITLE_SOURCE_DOCUMENT => __( 'The document\'s own title where it looks genuine, otherwise the file name', 'media-library-fixer' ),
		);

		$settings_group = SETTINGS_GROUP;
		$tool_page_url  = get_tool_page_url();

		require MEDIA_LIBRARY_FIXER_PATH . 'views/settings-page.php';
	}
}
