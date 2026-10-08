<?php
/**
 * Missing Files Module
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Finds attachments whose file is no longer on disk, and deletes those attachment records.
 *
 * @since 0.1.0
 */
class Missing_Files_Module extends Module {

	/**
	 * Unique ID.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_id(): string {
		return 'missing-files';
	}

	/**
	 * Heading shown on the tool page.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Media Library items with missing files', 'media-library-fixer' );
	}

	/**
	 * What the scan looks for and what the fix does.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks every attachment for its file on disk. Deleting removes the attachment record and any resized copies still on disk; links to the item elsewhere on the site will break. Files held on remote storage by an offload plugin are not checked.', 'media-library-fixer' );
	}

	/**
	 * Fix button label.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_fix_label(): string {
		return __( 'Delete selected attachment records', 'media-library-fixer' );
	}

	/**
	 * Confirmation before deleting.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_fix_confirmation(): string {
		return __( 'Permanently delete the selected attachment records? This cannot be undone. Take a database backup first if you are unsure.', 'media-library-fixer' );
	}

	/**
	 * Column headings.
	 *
	 * @since 0.1.0
	 * @return array{label:string,detail:string}
	 */
	public function get_columns(): array {
		return array(
			'label'  => _x( 'Attachment', 'findings table column heading', 'media-library-fixer' ),
			'detail' => _x( 'Expected file', 'findings table column heading', 'media-library-fixer' ),
		);
	}

	/**
	 * One unit per range of ATTACHMENT_ID_CHUNK_SIZE post IDs, as [first ID, last ID].
	 *
	 * @since 0.1.0
	 * @return array<int,int[]>
	 */
	public function get_scan_units(): array {
		global $wpdb;

		$units = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ID bounds for batching; no API returns them.
		$bounds = $wpdb->get_row( "SELECT MIN(ID) AS first_id, MAX(ID) AS last_id FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

		if ( null !== $bounds && null !== $bounds->first_id ) {
			for ( $first_id = (int) $bounds->first_id; $first_id <= (int) $bounds->last_id; $first_id += ATTACHMENT_ID_CHUNK_SIZE ) {
				$units[] = array( $first_id, $first_id + ATTACHMENT_ID_CHUNK_SIZE - 1 );
			}
		}

		return $units;
	}

	/**
	 * Check the attachments in one ID range.
	 *
	 * @since 0.1.0
	 * @param mixed $unit [first ID, last ID].
	 * @return array[]
	 */
	public function scan_unit( mixed $unit ): array {
		global $wpdb;

		$findings = array();

		if ( is_array( $unit ) && 2 === count( $unit ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched ID-range scan; WP_Query would load full post objects.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID BETWEEN %d AND %d ORDER BY ID", (int) $unit[0], (int) $unit[1] ) );

			update_meta_cache( 'post', array_map( 'intval', $attachment_ids ) );

			foreach ( $attachment_ids as $attachment_id ) {
				$finding = $this->check_attachment( (int) $attachment_id );

				if ( null !== $finding ) {
					$findings[] = $finding;
				}
			}
		}

		return $findings;
	}

	/**
	 * Check one attachment.
	 *
	 * @since 0.1.0
	 * @param int $attachment_id Attachment ID.
	 * @return array|null A finding when the file is missing, otherwise null.
	 */
	private function check_attachment( int $attachment_id ): ?array {
		$finding       = null;
		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$full_path     = (string) get_attached_file( $attachment_id );

		if ( '' !== $full_path && wp_is_stream( $full_path ) ) {
			// Remote storage; checking it would mean a network request per file.
		} elseif ( '' === $attached_file || ! file_exists( $full_path ) ) {
			$title   = get_the_title( $attachment_id );
			$finding = array(
				'key'    => (string) $attachment_id,
				'label'  => sprintf(
					/* translators: 1: attachment title; 2: attachment ID */
					__( '%1$s (#%2$d)', 'media-library-fixer' ),
					'' === $title ? __( '(no title)', 'media-library-fixer' ) : $title,
					$attachment_id
				),
				'detail' => '' === $attached_file ? __( 'No file is recorded for this attachment.', 'media-library-fixer' ) : $attached_file,
				'url'    => admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ),
				'data'   => array( 'attachment_id' => $attachment_id ),
			);
		} else {
			// File present.
		}

		return $finding;
	}

	/**
	 * Delete one attachment record, if its file is still missing.
	 *
	 * @since 0.1.0
	 * @param array $finding Finding from scan_unit().
	 * @return string|\WP_Error
	 */
	public function fix_finding( array $finding ): string|\WP_Error {
		$attachment_id = is_numeric( $finding['data']['attachment_id'] ?? null ) ? (int) $finding['data']['attachment_id'] : 0;
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			$result = new \WP_Error( 'mlfixer_not_attachment', __( 'The attachment no longer exists.', 'media-library-fixer' ) );
		} elseif ( null === $this->check_attachment( $attachment_id ) ) {
			$result = new \WP_Error( 'mlfixer_file_present', __( 'The file is present again, so the attachment was kept.', 'media-library-fixer' ) );
		} elseif ( ! wp_delete_attachment( $attachment_id, true ) instanceof \WP_Post ) {
			$result = new \WP_Error( 'mlfixer_delete_failed', __( 'WordPress could not delete the attachment.', 'media-library-fixer' ) );
		} else {
			$result = sprintf(
				/* translators: 1: attachment ID; 2: attachment title */
				__( 'Deleted attachment #%1$d "%2$s".', 'media-library-fixer' ),
				$attachment_id,
				$attachment->post_title
			);
		}

		return $result;
	}
}
