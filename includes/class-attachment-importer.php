<?php
/**
 * Attachment Importer
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Creates an attachment record for a file already in the uploads directory, without moving or copying it.
 *
 * @since 0.1.0
 */
class Attachment_Importer {

	/**
	 * Register a file as an attachment.
	 *
	 * @since 0.1.0
	 * @param string $relative_path Path relative to the uploads directory.
	 * @return array{attachment_id:int,title:string,date:int,date_source:string,warning:string}|\WP_Error
	 */
	public function import( string $relative_path ): array|\WP_Error {
		$result    = null;
		$base_path = get_uploads_base_path();
		$full_path = $base_path . '/' . $relative_path;
		$real_path = realpath( $full_path );
		$file_type = wp_check_filetype( wp_basename( $relative_path ) );

		if ( '' === $base_path ) {
			$result = new \WP_Error( 'mlfixer_no_uploads', __( 'The uploads directory is unavailable.', 'media-library-fixer' ) );
		} elseif ( false === $real_path || ! str_starts_with( $real_path, (string) realpath( $base_path ) . DIRECTORY_SEPARATOR ) || ! is_file( $real_path ) ) {
			$result = new \WP_Error( 'mlfixer_not_found', __( 'The file no longer exists in the uploads directory.', 'media-library-fixer' ) );
		} elseif ( ! is_string( $file_type['type'] ) || '' === $file_type['type'] ) {
			$result = new \WP_Error( 'mlfixer_type_not_allowed', __( 'WordPress does not allow this file type.', 'media-library-fixer' ) );
		} elseif ( $this->is_registered( $relative_path ) ) {
			$result = new \WP_Error( 'mlfixer_already_registered', __( 'The file is already in the Media Library.', 'media-library-fixer' ) );
		} else {
			$result = $this->create_attachment( $relative_path, $full_path, $file_type['type'] );
		}

		return $result;
	}

	/**
	 * Delete an attachment this plugin imported, keeping its file.
	 *
	 * Generated previews and sub-sizes are deleted with the record. The original is shielded
	 * through wp_delete_file, which core's attachment deletion passes every path through.
	 *
	 * @since 0.1.0
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error Path of the kept file, relative to uploads, or why nothing was deleted.
	 */
	public function remove_import( int $attachment_id ): string|\WP_Error {
		$attachment    = get_post( $attachment_id );
		$relative_path = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original_path = realpath( (string) get_attached_file( $attachment_id, true ) );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			$result = new \WP_Error( 'mlfixer_not_attachment', __( 'No such attachment.', 'media-library-fixer' ) );
		} elseif ( '' === (string) get_post_meta( $attachment_id, META_IMPORTED, true ) ) {
			$result = new \WP_Error( 'mlfixer_not_imported', __( 'This attachment was not created by Media Library Fixer, so it was left alone.', 'media-library-fixer' ) );
		} else {
			$shield = static fn( mixed $file ): mixed => ( false !== $original_path && is_string( $file ) && realpath( $file ) === $original_path ) ? '' : $file;

			// Last, so no later callback can hand the original back for deletion.
			add_filter( 'wp_delete_file', $shield, PHP_INT_MAX );
			$deleted = wp_delete_attachment( $attachment_id, true );
			remove_filter( 'wp_delete_file', $shield, PHP_INT_MAX );

			if ( false !== $original_path && ! file_exists( $original_path ) ) {
				log_error( sprintf( 'Removing imported attachment %d deleted its file %s.', $attachment_id, $original_path ) );
				$result = new \WP_Error( 'mlfixer_file_lost', __( 'The file was deleted along with the record. Restore it from a backup.', 'media-library-fixer' ) );
			} elseif ( ! $deleted instanceof \WP_Post ) {
				$result = new \WP_Error( 'mlfixer_delete_failed', __( 'WordPress could not delete the attachment.', 'media-library-fixer' ) );
			} else {
				$result = $relative_path;
			}
		}

		return $result;
	}

	/**
	 * Whether any attachment already points at this file.
	 *
	 * @since 0.1.0
	 * @param string $relative_path Path relative to the uploads directory.
	 * @return bool
	 */
	public function is_registered( string $relative_path ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see rows written moments ago; no API looks up by attached file.
		$attached_files = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $relative_path ) );

		// The column's collation usually ignores case, but report.pdf and Report.pdf are different files.
		return in_array( $relative_path, $attached_files, true );
	}

	/**
	 * Insert the attachment post and its metadata.
	 *
	 * @since 0.1.0
	 * @param string $relative_path Path relative to the uploads directory.
	 * @param string $full_path     Absolute path.
	 * @param string $mime_type     MIME type.
	 * @return array|\WP_Error
	 */
	private function create_attachment( string $relative_path, string $full_path, string $mime_type ): array|\WP_Error {
		$result            = null;
		$document_metadata = array();

		if ( 'application/pdf' === $mime_type ) {
			$reader            = new Pdf_Metadata_Reader();
			$document_metadata = $reader->read( $full_path );
		}

		/**
		 * Filter the document properties read from a file before they are used for the title, date and description.
		 *
		 * Hook this to read properties from file types the plugin doesn't parse. Keys:
		 * title, author, subject, keywords, creator, producer (strings) and created,
		 * modified (Unix timestamps or null).
		 *
		 * @since 0.1.0
		 *
		 * @param array  $document_metadata Properties read so far; empty for non-PDF files.
		 * @param string $full_path         Absolute path to the file.
		 * @param string $mime_type         MIME type.
		 */
		$filtered_metadata = apply_filters( 'mlfixer_document_metadata', $document_metadata, $full_path, $mime_type );
		$document_metadata = is_array( $filtered_metadata ) ? $filtered_metadata : $document_metadata;

		[ $timestamp, $date_source ] = $this->resolve_date( $relative_path, $full_path, $document_metadata );

		$title           = $this->resolve_title( $relative_path, $document_metadata );
		$attachment_data = array(
			'post_mime_type' => $mime_type,
			'guid'           => wp_get_upload_dir()['baseurl'] . '/' . $relative_path,
			'post_title'     => $title,
			'post_content'   => get_bool_option( OPTION_DESCRIBE_DOCUMENT, DEFAULT_DESCRIBE_DOCUMENT ) ? $this->build_description( $title, $document_metadata ) : '',
			'post_status'    => 'inherit',
			'post_date'      => wp_date( 'Y-m-d H:i:s', $timestamp ),
			'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
		);

		/**
		 * Filter the post data for an attachment about to be created.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $attachment_data   Arguments for wp_insert_attachment().
		 * @param string $relative_path     Path relative to the uploads directory.
		 * @param array  $document_metadata Document properties; see mlfixer_document_metadata.
		 */
		$filtered_data   = apply_filters( 'mlfixer_import_attachment_data', $attachment_data, $relative_path, $document_metadata );
		$attachment_data = is_array( $filtered_data ) ? $filtered_data : $attachment_data;

		$attachment_id = wp_insert_attachment( $attachment_data, $full_path, 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			$result = $attachment_id;
		} else {
			$warning = $this->generate_metadata( $attachment_id, $full_path, $mime_type );
			update_post_meta( $attachment_id, META_IMPORTED, time() );

			/**
			 * Fires after a file has been registered as an attachment.
			 *
			 * @since 0.1.0
			 *
			 * @param int    $attachment_id     New attachment ID.
			 * @param string $relative_path     Path relative to the uploads directory.
			 * @param array  $document_metadata Document properties; see mlfixer_document_metadata.
			 */
			do_action( 'mlfixer_attachment_imported', $attachment_id, $relative_path, $document_metadata );

			$result = array(
				'attachment_id' => $attachment_id,
				'title'         => (string) get_the_title( $attachment_id ),
				'date'          => $timestamp,
				'date_source'   => $date_source,
				'warning'       => $warning,
			);
		}

		return $result;
	}

	/**
	 * Generate and store attachment metadata, including PDF previews and image sub-sizes when enabled.
	 *
	 * @since 0.1.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $full_path     Absolute path.
	 * @param string $mime_type     MIME type.
	 * @return string Why previews or sub-sizes are missing, or '' when generation succeeded or was skipped.
	 */
	private function generate_metadata( int $attachment_id, string $full_path, string $mime_type ): string {
		$warning  = '';
		$metadata = array( 'filesize' => (int) filesize( $full_path ) );

		if ( 'application/pdf' !== $mime_type || get_bool_option( OPTION_GENERATE_PREVIEWS, DEFAULT_GENERATE_PREVIEWS ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			try {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $full_path );
			} catch ( \Throwable $throwable ) {
				// The attachment is still usable without previews, so the import stands and reports the failure.
				$warning = $throwable->getMessage();
				log_error( sprintf( 'Generating metadata for attachment %d (%s) failed: %s', $attachment_id, $full_path, $warning ) );
			}

			// Core skips PDF previews without a word when the image editor can't read PDFs.
			if ( '' === $warning && 'application/pdf' === $mime_type && empty( $metadata['sizes'] ) ) {
				$warning = can_generate_pdf_previews()
					? __( 'the image editor could not read this PDF.', 'media-library-fixer' )
					: __( 'this server cannot make PDF previews from here. See the PDF previews setting.', 'media-library-fixer' );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $warning;
	}

	/**
	 * Choose the attachment's date, following the configured source and falling back in order.
	 *
	 * @since 0.1.0
	 * @param string $relative_path     Path relative to the uploads directory.
	 * @param string $full_path         Absolute path.
	 * @param array  $document_metadata Document properties.
	 * @return array{0:int,1:string} Unix timestamp and the DATE_SOURCE_* constant it came from.
	 */
	public function resolve_date( string $relative_path, string $full_path, array $document_metadata ): array {
		$date_source   = get_date_source();
		$document_date = is_int( $document_metadata['created'] ?? null ) ? $document_metadata['created'] : null;
		$folder_date   = $this->get_folder_date( $relative_path );

		if ( DATE_SOURCE_DOCUMENT === $date_source && null !== $document_date ) {
			$resolved = array( $document_date, DATE_SOURCE_DOCUMENT );
		} elseif ( DATE_SOURCE_MODIFIED !== $date_source && null !== $folder_date ) {
			$resolved = array( $folder_date, DATE_SOURCE_FOLDER );
		} else {
			$resolved = array( (int) filemtime( $full_path ), DATE_SOURCE_MODIFIED );
		}

		return $resolved;
	}

	/**
	 * Date from a YYYY/MM upload folder: noon on the first of the month, site time.
	 *
	 * @since 0.1.0
	 * @param string $relative_path Path relative to the uploads directory.
	 * @return int|null Null when the file isn't in a dated folder.
	 */
	public function get_folder_date( string $relative_path ): ?int {
		$timestamp = null;
		$parts     = array();

		if ( 1 === preg_match( '#^(\d{4})/(\d{2})/#', $relative_path, $parts ) && checkdate( (int) $parts[2], 1, (int) $parts[1] ) ) {
			// Noon keeps the date on the first whatever the site's offset from UTC.
			$date      = date_create_immutable( sprintf( '%s-%s-01 12:00:00', $parts[1], $parts[2] ), wp_timezone() );
			$timestamp = false === $date ? null : $date->getTimestamp();
		}

		return $timestamp;
	}

	/**
	 * Choose the attachment's title.
	 *
	 * @since 0.1.0
	 * @param string $relative_path     Path relative to the uploads directory.
	 * @param array  $document_metadata Document properties.
	 * @return string
	 */
	public function resolve_title( string $relative_path, array $document_metadata ): string {
		$document_title = is_string( $document_metadata['title'] ?? null ) ? sanitize_text_field( $document_metadata['title'] ) : '';

		if ( TITLE_SOURCE_DOCUMENT === get_title_source() && $this->is_usable_document_title( $document_title ) ) {
			$title = $document_title;
		} else {
			// Underscores become spaces; hyphens stay, as in "COVID-19".
			$title = sanitize_text_field( str_replace( '_', ' ', pathinfo( $relative_path, PATHINFO_FILENAME ) ) );
		}

		return $title;
	}

	/**
	 * Whether an embedded title is worth using: not empty, a single word or an authoring tool's placeholder.
	 *
	 * @since 0.1.0
	 * @param string $title Embedded title.
	 * @return bool
	 */
	public function is_usable_document_title( string $title ): bool {
		$is_placeholder = 1 === preg_match( '/^(microsoft (word|powerpoint|excel)\b|untitled|layout \d|document\d*$|presentation\d*$|slide \d)/i', $title )
			|| 1 === preg_match( '/\.(docx?|pptx?|xlsx?|pdf|indd|qxp|rtf|txt|pub)$/i', $title );

		return ! $is_placeholder && str_word_count( $title ) >= 2;
	}

	/**
	 * Build a plain-text description from the document properties, for Media Library search.
	 *
	 * @since 0.1.0
	 * @param string $title             The attachment title already chosen.
	 * @param array  $document_metadata Document properties.
	 * @return string
	 */
	private function build_description( string $title, array $document_metadata ): string {
		$labels = array(
			'title'    => __( 'Document title', 'media-library-fixer' ),
			'author'   => __( 'Author', 'media-library-fixer' ),
			'subject'  => __( 'Subject', 'media-library-fixer' ),
			'keywords' => __( 'Keywords', 'media-library-fixer' ),
		);
		$lines  = array();

		foreach ( $labels as $key => $label ) {
			$value = is_string( $document_metadata[ $key ] ?? null ) ? sanitize_text_field( $document_metadata[ $key ] ) : '';

			if ( 'title' === $key && ( $value === $title || ! $this->is_usable_document_title( $value ) ) ) {
				// Already the attachment title, or a placeholder.
			} elseif ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		return implode( "\n", $lines );
	}
}
