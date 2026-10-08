<?php
/**
 * WP-CLI Commands
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Scan and fix the Media Library from the command line.
 *
 * Runs the same modules as Tools → Fix Media Library, and shares their saved scan results.
 *
 * @since 0.1.0
 */
class CLI_Commands {

	/**
	 * List the available modules.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp media-library-fixer modules
	 *     +--------------------+----------------------------------------------+---------+----------+
	 *     | id                 | title                                        | phase   | findings |
	 *     +--------------------+----------------------------------------------+---------+----------+
	 *     | unregistered-files | Files missing from the Media Library         | scanned | 2365     |
	 *     | missing-files      | Media Library items with missing files       | idle    | 0        |
	 *     +--------------------+----------------------------------------------+---------+----------+
	 *
	 * @since 0.1.0
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function modules( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Required by WP-CLI signature.
		$rows = array();

		foreach ( get_modules() as $module ) {
			$state  = ( new Job_Runner( $module ) )->get_state();
			$rows[] = array(
				'id'       => $module->get_id(),
				'title'    => $module->get_title(),
				'phase'    => $state['phase'],
				'findings' => count( $state['findings'] ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'title', 'phase', 'findings' ) );
	}

	/**
	 * Scan with one module and list what it found.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module ID, from `wp media-library-fixer modules`.
	 *
	 * [--format=<format>]
	 * : Output format for the findings.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp media-library-fixer scan unregistered-files --format=count
	 *     2365
	 *
	 * @since 0.1.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$module = $this->get_module_or_exit( $args[0] );
		$runner = new Job_Runner( $module );

		$runner->start_scan();
		$state = $runner->scan_step( 0 );

		foreach ( $state['scan_errors'] as $scan_error ) {
			\WP_CLI::warning( $scan_error );
		}

		$rows = array_map(
			static fn( array $finding ): array => array(
				'key'    => $finding['key'],
				'label'  => $finding['label'],
				'detail' => $finding['detail'] ?? '',
			),
			array_values( $state['findings'] )
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'key', 'label', 'detail' ) );
	}

	/**
	 * Apply a module's fix to the findings of its last scan.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module ID, from `wp media-library-fixer modules`.
	 *
	 * [<key>...]
	 * : Finding keys to fix, as listed by `scan`. Fixes every finding when omitted.
	 *
	 * [--limit=<number>]
	 * : Fix at most this many findings.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * A run that was interrupted, here or on the tool page, is resumed instead; keys and
	 * --limit are then ignored.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import ten files as a trial
	 *     $ wp media-library-fixer fix unregistered-files --limit=10
	 *
	 *     # Import one file
	 *     $ wp media-library-fixer fix unregistered-files 2021/10/Annual_Report.pdf
	 *
	 * @since 0.1.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function fix( array $args, array $assoc_args ): void {
		$module = $this->get_module_or_exit( array_shift( $args ) );
		$runner = new Job_Runner( $module );
		$state  = $runner->get_state();
		$keys   = array() === $args ? array_keys( $state['findings'] ) : $args;
		$limit  = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		if ( $limit > 0 ) {
			$keys = array_slice( $keys, 0, $limit );
		}

		if ( PHASE_FIXING === $state['phase'] ) {
			$remaining = count( $state['queue'] ) - (int) $state['queue_index'];

			\WP_CLI::confirm( sprintf( 'An interrupted run has %d item(s) left. Resume it?', $remaining ), $assoc_args );
		} else {
			\WP_CLI::confirm(
				sprintf( '%s: %d item(s) from the scan of %s. Continue?', $module->get_fix_label(), count( $keys ), $state['scanned_at'] > 0 ? wp_date( 'Y-m-d H:i', $state['scanned_at'] ) : 'never' ),
				$assoc_args
			);

			$started = $runner->start_fix( array_map( 'strval', $keys ) );

			if ( is_wp_error( $started ) ) {
				\WP_CLI::error( $started->get_error_message() );
			}

			$remaining = count( $started['queue'] );
		}

		wp_raise_memory_limit( 'admin' );

		$progress = \WP_CLI\Utils\make_progress_bar( $module->get_fix_label(), $remaining );
		$failures = array();

		do {
			$step = $runner->fix_step( 1 );

			foreach ( $step['messages'] as $message ) {
				$progress->tick();

				if ( 'error' === $message['type'] ) {
					$failures[] = $message['text'];
				} else {
					\WP_CLI::log( $message['text'] );
				}
			}
		} while ( PHASE_FIXING === $step['state']['phase'] );

		$progress->finish();

		foreach ( $failures as $failure ) {
			\WP_CLI::warning( $failure );
		}

		\WP_CLI::success( sprintf( '%d done, %d failed.', $step['state']['fixed_count'], count( $failures ) ) );
	}

	/**
	 * Delete attachments that Media Library Fixer imported, keeping their files.
	 *
	 * Removes the attachment records, their metadata and any generated previews or resized
	 * copies. The imported files themselves stay where they are, so a later scan lists them
	 * again. Changes made to the items since the import (titles, descriptions, categories) are
	 * lost.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : Attachments to remove. Every imported attachment when omitted.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp media-library-fixer undo-import --yes
	 *     Success: 2366 removed, 0 failed. Their files were kept.
	 *
	 * @subcommand undo-import
	 * @since 0.1.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function undo_import( array $args, array $assoc_args ): void {
		$attachment_ids = array_map( 'intval', $args );

		if ( array() === $attachment_ids ) {
			$attachment_ids = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_key'    => META_IMPORTED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- One-off command.
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			);
		}

		if ( array() === $attachment_ids ) {
			\WP_CLI::success( 'There are no imported attachments.' );
		} else {
			\WP_CLI::confirm( sprintf( 'Delete %d imported attachment record(s), keeping their files? Edits made to them since the import will be lost.', count( $attachment_ids ) ), $assoc_args );

			$importer = new Attachment_Importer();
			$progress = \WP_CLI\Utils\make_progress_bar( 'Removing imported attachments', count( $attachment_ids ) );
			$failures = array();

			foreach ( $attachment_ids as $attachment_id ) {
				$removed = $importer->remove_import( $attachment_id );

				if ( is_wp_error( $removed ) ) {
					$failures[] = sprintf( '#%d: %s', $attachment_id, $removed->get_error_message() );
				}

				$progress->tick();
			}

			$progress->finish();

			foreach ( $failures as $failure ) {
				\WP_CLI::warning( $failure );
			}

			\WP_CLI::success( sprintf( '%d removed, %d failed. Their files were kept.', count( $attachment_ids ) - count( $failures ), count( $failures ) ) );
		}
	}

	/**
	 * Look up a module or end the command with an error.
	 *
	 * @since 0.1.0
	 * @param mixed $module_id Module ID.
	 * @return Module
	 */
	private function get_module_or_exit( mixed $module_id ): Module {
		$module = is_string( $module_id ) ? get_module( $module_id ) : null;

		if ( null === $module ) {
			\WP_CLI::error( sprintf( 'Unknown module "%s". Run `wp media-library-fixer modules` for the list.', (string) $module_id ) );
		}

		return $module;
	}
}
