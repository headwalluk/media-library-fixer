<?php
/**
 * Plugin Functions
 *
 * Internal helpers: settings getters, the module registry and logging. Private to
 * the plugin — sites should use the hooks in docs/developers/hooks-and-filters.md.
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Block direct access.
defined( 'ABSPATH' ) || die();

// ============================================================================
// Settings
// ============================================================================

/**
 * Get default plugin settings, keyed by option name.
 *
 * @since 0.1.0
 * @return array<string,mixed> Default settings.
 */
function get_default_settings(): array {
	return array(
		OPTION_FILE_EXTENSIONS          => DEFAULT_FILE_EXTENSIONS,
		OPTION_SCAN_SCOPE               => DEFAULT_SCAN_SCOPE,
		OPTION_DATE_SOURCE              => DEFAULT_DATE_SOURCE,
		OPTION_TITLE_SOURCE             => DEFAULT_TITLE_SOURCE,
		OPTION_DESCRIBE_DOCUMENT        => DEFAULT_DESCRIBE_DOCUMENT,
		OPTION_GENERATE_PREVIEWS        => DEFAULT_GENERATE_PREVIEWS,
		OPTION_DELETE_DATA_ON_UNINSTALL => DEFAULT_DELETE_DATA_ON_UNINSTALL,
	);
}

/**
 * Read a boolean option, accepting every format WordPress stores booleans in.
 *
 * @since 0.1.0
 * @param string $option_name Option name.
 * @param bool   $fallback    Value when the option is absent.
 * @return bool
 */
function get_bool_option( string $option_name, bool $fallback ): bool {
	return (bool) filter_var( get_option( $option_name, $fallback ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Parse a comma- or space-separated extension list into unique lower-case extensions.
 *
 * @since 0.1.0
 * @param mixed $value Raw list.
 * @return string[] Extensions without dots.
 */
function parse_extension_list( mixed $value ): array {
	$extensions = array();

	if ( is_string( $value ) ) {
		foreach ( preg_split( '/[\s,;]+/', strtolower( $value ) ) as $extension ) {
			$extension = ltrim( trim( $extension ), '.' );

			if ( '' !== $extension && 1 === preg_match( '/^[a-z0-9]+$/', $extension ) ) {
				$extensions[ $extension ] = $extension;
			}
		}
	}

	return array_values( $extensions );
}

/**
 * Extensions the unregistered-files scan looks for, limited to types WordPress allows.
 *
 * @since 0.1.0
 * @return string[] Extensions without dots.
 */
function get_scan_extensions(): array {
	$extensions = array();

	foreach ( parse_extension_list( get_option( OPTION_FILE_EXTENSIONS, DEFAULT_FILE_EXTENSIONS ) ) as $extension ) {
		$file_type = wp_check_filetype( 'file.' . $extension );

		if ( is_string( $file_type['type'] ) && '' !== $file_type['type'] ) {
			$extensions[] = $extension;
		}
	}

	return $extensions;
}

/**
 * Read a string option, falling back to its default when the stored value isn't one of the allowed values.
 *
 * @since 0.1.0
 * @param string   $option_name    Option name.
 * @param string[] $allowed_values Accepted values.
 * @param string   $fallback       Value when the stored one is not allowed.
 * @return string
 */
function get_choice_option( string $option_name, array $allowed_values, string $fallback ): string {
	$value = get_option( $option_name, $fallback );

	return in_array( $value, $allowed_values, true ) ? $value : $fallback;
}

/**
 * Get the configured scan scope.
 *
 * @since 0.1.0
 * @return string SCAN_SCOPE_DATED or SCAN_SCOPE_ALL.
 */
function get_scan_scope(): string {
	return get_choice_option( OPTION_SCAN_SCOPE, array( SCAN_SCOPE_DATED, SCAN_SCOPE_ALL ), DEFAULT_SCAN_SCOPE );
}

/**
 * Get the configured date source for imported attachments.
 *
 * @since 0.1.0
 * @return string One of the DATE_SOURCE_* constants.
 */
function get_date_source(): string {
	return get_choice_option( OPTION_DATE_SOURCE, array( DATE_SOURCE_DOCUMENT, DATE_SOURCE_FOLDER, DATE_SOURCE_MODIFIED ), DEFAULT_DATE_SOURCE );
}

/**
 * Get the configured title source for imported attachments.
 *
 * @since 0.1.0
 * @return string One of the TITLE_SOURCE_* constants.
 */
function get_title_source(): string {
	return get_choice_option( OPTION_TITLE_SOURCE, array( TITLE_SOURCE_FILENAME, TITLE_SOURCE_DOCUMENT ), DEFAULT_TITLE_SOURCE );
}

/**
 * Seconds of work allowed per batch request.
 *
 * @since 0.1.0
 * @return int At least 1.
 */
function get_batch_time_limit(): int {
	/**
	 * Filter how many seconds of work each batch request does before returning.
	 *
	 * @since 0.1.0
	 *
	 * @param int $seconds Default DEFAULT_BATCH_TIME_LIMIT.
	 */
	$seconds = apply_filters( 'mlfixer_batch_time_limit', DEFAULT_BATCH_TIME_LIMIT );

	return is_numeric( $seconds ) ? max( 1, (int) $seconds ) : DEFAULT_BATCH_TIME_LIMIT;
}

// ============================================================================
// Module registry
// ============================================================================

/**
 * Get the registered modules, keyed by module ID, in display order.
 *
 * @since 0.1.0
 * @return array<string,Module>
 */
function get_modules(): array {
	static $modules = null;

	if ( null === $modules ) {
		$default_modules = array( new Unregistered_Files_Module(), new Missing_Files_Module() );

		/**
		 * Filter the hygiene modules shown on Tools → Fix Media Library.
		 *
		 * Append an instance of a Media_Library_Fixer\Module subclass to add a
		 * module; remove an entry to hide one.
		 *
		 * @since 0.1.0
		 *
		 * @param Module[] $default_modules Built-in modules.
		 */
		$filtered_modules = apply_filters( 'mlfixer_modules', $default_modules );

		if ( ! is_array( $filtered_modules ) ) {
			log_error( 'mlfixer_modules returned a ' . gettype( $filtered_modules ) . '; using the built-in modules.' );
			$filtered_modules = $default_modules;
		}

		$modules = array();

		foreach ( $filtered_modules as $module ) {
			if ( ! $module instanceof Module ) {
				log_error( 'mlfixer_modules entry is not a Module subclass; skipped.' );
			} elseif ( 1 !== preg_match( '/^[a-z0-9-]+$/', $module->get_id() ) ) {
				log_error( sprintf( 'Module ID "%s" must be lower-case letters, digits and hyphens; skipped.', $module->get_id() ) );
			} elseif ( isset( $modules[ $module->get_id() ] ) ) {
				log_error( sprintf( 'Module ID "%s" is registered twice; the second was skipped.', $module->get_id() ) );
			} else {
				$modules[ $module->get_id() ] = $module;
			}
		}
	}

	return $modules;
}

/**
 * Look up one registered module.
 *
 * @since 0.1.0
 * @param string $module_id Module ID.
 * @return Module|null Null when no module has that ID.
 */
function get_module( string $module_id ): ?Module {
	return get_modules()[ $module_id ] ?? null;
}

// ============================================================================
// Admin URLs
// ============================================================================

/**
 * URL of Tools → Fix Media Library.
 *
 * @since 0.1.0
 * @return string
 */
function get_tool_page_url(): string {
	return admin_url( 'tools.php?page=' . TOOL_PAGE_SLUG );
}

/**
 * URL of Settings → Media Library Fixer.
 *
 * @since 0.1.0
 * @return string
 */
function get_settings_page_url(): string {
	return admin_url( 'options-general.php?page=' . SETTINGS_PAGE_SLUG );
}

// ============================================================================
// Uploads helpers
// ============================================================================

/**
 * Absolute path of the uploads base directory, without a trailing slash.
 *
 * @since 0.1.0
 * @return string Empty string when the uploads directory is unavailable.
 */
function get_uploads_base_path(): string {
	$upload_dir = wp_get_upload_dir();

	return empty( $upload_dir['error'] ) ? untrailingslashit( $upload_dir['basedir'] ) : '';
}

/**
 * Whether WordPress can make preview images of PDFs in this PHP process, tested by loading a one-page PDF.
 *
 * wp_image_editor_supports() isn't enough: ImageMagick lists PDF as a format even when
 * its security policy refuses to read one. Web and command-line PHP can differ.
 *
 * @since 0.1.0
 * @return bool
 */
function can_generate_pdf_previews(): bool {
	static $can_generate = null;

	if ( null === $can_generate ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$can_generate = false;

		if ( wp_image_editor_supports( array( 'mime_type' => 'application/pdf' ) ) ) {
			$probe_path = wp_tempnam( 'mlfixer-probe.pdf' );
			$probe_pdf  = "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

			if ( false === file_put_contents( $probe_path, $probe_pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary local file.
				log_error( 'Could not write a probe PDF to ' . $probe_path . '; assuming PDF previews are unavailable.' );
			} else {
				$editor       = wp_get_image_editor( $probe_path, array( 'mime_type' => 'application/pdf' ) );
				$can_generate = ! is_wp_error( $editor );

				if ( ! $can_generate ) {
					log( 'PDF preview probe failed: ' . $editor->get_error_message() );
				}
			}

			wp_delete_file( $probe_path );
		}
	}

	return $can_generate;
}

// ============================================================================
// Logging
// ============================================================================

/**
 * Log routine flow tracing, only when WP_DEBUG is on.
 *
 * @since 0.1.0
 * @param string $message Message.
 */
function log( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'Media_Library_Fixer: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
	}
}

/**
 * Log a genuine failure unconditionally.
 *
 * @since 0.1.0
 * @param string $message Message.
 */
function log_error( string $message ): void {
	error_log( 'Media_Library_Fixer [error]: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
}
