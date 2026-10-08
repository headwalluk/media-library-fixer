<?php
/**
 * Unregistered Files Module
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Finds files in the uploads directory that no attachment points at, and registers them.
 *
 * @since 0.1.0
 */
class Unregistered_Files_Module extends Module {

	/**
	 * Uploads subdirectories skipped when the whole uploads directory is scanned.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private const DEFAULT_EXCLUDED_DIRECTORIES = array( 'cache', 'elementor', 'wc-logs', 'woocommerce_uploads', 'wpcf7_uploads', 'gravity_forms', 'wpforms', 'ao_ccss', 'backups', 'backwpup', 'updraft', 'ai1wm-backups', 'et-cache', 'fonts' );

	/**
	 * Unique ID.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_id(): string {
		return 'unregistered-files';
	}

	/**
	 * Heading shown on the tool page.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Files missing from the Media Library', 'media-library-fixer' );
	}

	/**
	 * What the scan looks for and what the fix does.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_description(): string {
		$extensions = get_scan_extensions();

		return sprintf(
			/* translators: %s: comma-separated list of file extensions, e.g. "pdf, docx" */
			__( 'Looks in the uploads folder for files of these types that WordPress has no attachment record for: %s. Importing creates the record in place; files are not moved or copied. Resized copies of images are ignored.', 'media-library-fixer' ),
			array() === $extensions ? _x( 'none configured', 'file extension list', 'media-library-fixer' ) : implode( ', ', $extensions )
		);
	}

	/**
	 * Fix button label.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_fix_label(): string {
		return __( 'Import selected files', 'media-library-fixer' );
	}

	/**
	 * Column headings.
	 *
	 * @since 0.1.0
	 * @return array{label:string,detail:string}
	 */
	public function get_columns(): array {
		return array(
			'label'  => _x( 'File', 'findings table column heading', 'media-library-fixer' ),
			'detail' => _x( 'Size and modified date', 'findings table column heading', 'media-library-fixer' ),
		);
	}

	/**
	 * One unit per directory, as a path relative to the uploads directory ('' for the top level).
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	public function get_scan_units(): array {
		$base_path   = get_uploads_base_path();
		$directories = array();

		if ( '' === $base_path || array() === get_scan_extensions() ) {
			// Nothing to scan.
		} elseif ( SCAN_SCOPE_DATED === get_scan_scope() ) {
			foreach ( (array) glob( $base_path . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]', GLOB_ONLYDIR ) as $directory ) {
				$directories[] = substr( (string) $directory, strlen( $base_path ) + 1 );
			}
		} else {
			$directories = $this->list_all_directories( $base_path );
		}

		sort( $directories );

		return $directories;
	}

	/**
	 * Every directory under the uploads directory, less the excluded ones and anything beneath them.
	 *
	 * @since 0.1.0
	 * @param string $base_path Uploads base path.
	 * @return string[] Relative paths, including '' for the top level.
	 */
	private function list_all_directories( string $base_path ): array {
		/**
		 * Filter the uploads subdirectories skipped when the whole uploads directory is scanned.
		 *
		 * Entries are matched against each directory name at any depth.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $excluded_directories Directory names.
		 */
		$excluded = apply_filters( 'mlfixer_excluded_directories', self::DEFAULT_EXCLUDED_DIRECTORIES );
		$excluded = is_array( $excluded ) ? $excluded : self::DEFAULT_EXCLUDED_DIRECTORIES;

		$directory_iterator = new \RecursiveDirectoryIterator( $base_path, \FilesystemIterator::SKIP_DOTS );
		$filter_iterator    = new \RecursiveCallbackFilterIterator(
			$directory_iterator,
			static fn( \SplFileInfo $entry ): bool => $entry->isDir() && ! $entry->isLink() && ! in_array( $entry->getFilename(), $excluded, true )
		);
		$directories        = array( '' );

		foreach ( new \RecursiveIteratorIterator( $filter_iterator, \RecursiveIteratorIterator::SELF_FIRST ) as $entry ) {
			$directories[] = substr( $entry->getPathname(), strlen( $base_path ) + 1 );
		}

		return $directories;
	}

	/**
	 * Find unregistered files in one directory.
	 *
	 * @since 0.1.0
	 * @param mixed $unit Directory relative to the uploads directory.
	 * @return array[]
	 */
	public function scan_unit( mixed $unit ): array {
		$findings  = array();
		$base_path = get_uploads_base_path();
		$directory = is_string( $unit ) ? trim( $unit, '/' ) : '';
		$full_dir  = '' === $directory ? $base_path : $base_path . '/' . $directory;

		if ( '' !== $base_path && is_dir( $full_dir ) && ! str_contains( $directory, '..' ) ) {
			$file_names = array();

			foreach ( new \DirectoryIterator( $full_dir ) as $entry ) {
				if ( $entry->isFile() ) {
					$file_names[ $entry->getFilename() ] = true;
				}
			}

			$extensions = get_scan_extensions();
			$known      = $this->get_registered_file_names( $directory );
			$stems      = $this->index_stems( array_keys( $file_names ) );

			foreach ( array_keys( $file_names ) as $file_name ) {
				$extension     = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
				$relative_path = ltrim( $directory . '/' . $file_name, '/' );

				if ( ! in_array( $extension, $extensions, true ) || isset( $known[ $file_name ] ) || $this->is_derivative( $file_name, $stems ) ) {
					continue;
				}

				/**
				 * Filter whether the unregistered-files scan reports a file.
				 *
				 * @since 0.1.0
				 *
				 * @param bool   $include       Default true.
				 * @param string $relative_path Path relative to the uploads directory.
				 */
				$include = (bool) filter_var( apply_filters( 'mlfixer_include_unregistered_file', true, $relative_path ), FILTER_VALIDATE_BOOLEAN );

				if ( $include ) {
					$findings[] = $this->build_finding( $relative_path, $full_dir . '/' . $file_name );
				}
			}
		}

		return $findings;
	}

	/**
	 * Build the finding for one file.
	 *
	 * @since 0.1.0
	 * @param string $relative_path Path relative to the uploads directory.
	 * @param string $full_path     Absolute path.
	 * @return array
	 */
	private function build_finding( string $relative_path, string $full_path ): array {
		return array(
			'key'    => $relative_path,
			'label'  => $relative_path,
			'detail' => sprintf(
				/* translators: 1: file size, e.g. "208 KB"; 2: date the file was last modified */
				__( '%1$s, modified %2$s', 'media-library-fixer' ),
				size_format( (int) filesize( $full_path ) ),
				wp_date( get_option( 'date_format' ), (int) filemtime( $full_path ) )
			),
			'url'    => wp_get_upload_dir()['baseurl'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) ),
			'data'   => array( 'path' => $relative_path ),
		);
	}

	/**
	 * Every file name in a directory that an attachment accounts for: originals, sub-sizes, pre-scaled originals and edit backups.
	 *
	 * @since 0.1.0
	 * @param string $directory Directory relative to the uploads directory.
	 * @return array<string,true> File names as keys.
	 */
	private function get_registered_file_names( string $directory ): array {
		global $wpdb;

		$known = array();

		// A top-level file has no directory part; LIKE 'dir/%' also matches subdirectories, which are filtered below.
		if ( '' === $directory ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk lookup by attached-file path; no API does this.
			$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value NOT LIKE '%/%'" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk lookup by attached-file path; no API does this.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", $wpdb->esc_like( $directory . '/' ) . '%' ) );
		}

		$rows = array_filter( $rows, static fn( object $row ): bool => ltrim( dirname( '/' . $row->meta_value ), '/' ) === $directory );

		update_meta_cache( 'post', array_map( static fn( object $row ): int => (int) $row->post_id, $rows ) );

		foreach ( $rows as $row ) {
			$known[ wp_basename( $row->meta_value ) ] = true;

			$metadata     = wp_get_attachment_metadata( (int) $row->post_id, true );
			$backup_sizes = get_post_meta( (int) $row->post_id, '_wp_attachment_backup_sizes', true );
			$size_lists   = array(
				is_array( $metadata ) && is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array(),
				is_array( $backup_sizes ) ? $backup_sizes : array(),
			);

			foreach ( $size_lists as $sizes ) {
				foreach ( $sizes as $size ) {
					if ( is_array( $size ) && is_string( $size['file'] ?? null ) ) {
						$known[ $size['file'] ] = true;
					}
				}
			}

			if ( is_array( $metadata ) && is_string( $metadata['original_image'] ?? null ) ) {
				$known[ $metadata['original_image'] ] = true;
			}
		}

		return $known;
	}

	/**
	 * Index the file names in a directory by name without extension.
	 *
	 * @since 0.1.0
	 * @param string[] $file_names File names.
	 * @return array<string,true>
	 */
	private function index_stems( array $file_names ): array {
		$stems = array();

		foreach ( $file_names as $file_name ) {
			$stems[ pathinfo( $file_name, PATHINFO_FILENAME ) ] = true;
		}

		return $stems;
	}

	/**
	 * Whether an image is a copy WordPress generated from another file in the same directory.
	 *
	 * Covers resized ("-300x200"), scaled, rotated, edited ("-e1700000000000") and
	 * PDF preview ("-pdf") copies, in any combination. Only image files are checked, and only
	 * when the file they were made from is present.
	 *
	 * @since 0.1.0
	 * @param string             $file_name File name.
	 * @param array<string,true> $stems     Names without extension of every file in the directory.
	 * @return bool
	 */
	private function is_derivative( string $file_name, array $stems ): bool {
		$is_derivative = false;
		$file_type     = wp_check_filetype( $file_name );

		if ( is_string( $file_type['type'] ) && str_starts_with( $file_type['type'], 'image/' ) ) {
			$stem = pathinfo( $file_name, PATHINFO_FILENAME );

			do {
				$parent_stem = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated|e\d{13}|pdf)(?:@2x)?$/', '', $stem );
				$stripped    = $parent_stem !== $stem;

				if ( $stripped && isset( $stems[ $parent_stem ] ) ) {
					$is_derivative = true;
				}

				$stem = $parent_stem;
			} while ( $stripped && ! $is_derivative );
		}

		return $is_derivative;
	}

	/**
	 * Register one file as an attachment.
	 *
	 * @since 0.1.0
	 * @param array $finding Finding from scan_unit().
	 * @return string|\WP_Error
	 */
	public function fix_finding( array $finding ): string|\WP_Error {
		$relative_path = is_string( $finding['data']['path'] ?? null ) ? $finding['data']['path'] : '';
		$importer      = new Attachment_Importer();
		$imported      = '' === $relative_path ? new \WP_Error( 'mlfixer_bad_finding', __( 'The finding has no file path.', 'media-library-fixer' ) ) : $importer->import( $relative_path );

		if ( is_wp_error( $imported ) ) {
			$result = $imported;
		} else {
			$date_sources = array(
				DATE_SOURCE_DOCUMENT => _x( 'document properties', 'where an attachment date came from', 'media-library-fixer' ),
				DATE_SOURCE_FOLDER   => _x( 'upload folder', 'where an attachment date came from', 'media-library-fixer' ),
				DATE_SOURCE_MODIFIED => _x( 'file modified time', 'where an attachment date came from', 'media-library-fixer' ),
			);

			$result = sprintf(
				/* translators: 1: attachment ID; 2: attachment title; 3: date; 4: where the date came from, e.g. "document properties" */
				__( 'Imported as #%1$d "%2$s", dated %3$s from %4$s.', 'media-library-fixer' ),
				$imported['attachment_id'],
				$imported['title'],
				wp_date( get_option( 'date_format' ), $imported['date'] ),
				$date_sources[ $imported['date_source'] ] ?? $imported['date_source']
			);

			if ( '' !== $imported['warning'] ) {
				$result .= ' ' . sprintf(
					/* translators: %s: error message from WordPress */
					__( 'Previews were not generated: %s', 'media-library-fixer' ),
					$imported['warning']
				);
			}
		}

		return $result;
	}
}
