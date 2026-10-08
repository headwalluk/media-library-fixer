<?php
/**
 * Tool Page Class
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Tools → Fix Media Library, and the AJAX endpoint its script drives.
 *
 * @since 0.1.0
 */
class Tool_Page {

	/**
	 * Admin page hook suffix, set when the page is added.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 */
	public function run(): void {
		add_action( 'admin_menu', array( $this, 'add_tool_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . AJAX_ACTION, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Add the page under Tools.
	 *
	 * @since 0.1.0
	 */
	public function add_tool_page(): void {
		$hook_suffix = add_management_page(
			__( 'Fix Media Library', 'media-library-fixer' ),
			__( 'Fix Media Library', 'media-library-fixer' ),
			REQUIRED_CAPABILITY,
			TOOL_PAGE_SLUG,
			array( $this, 'render_tool_page' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Enqueue the page script and styles on the tool page only.
	 *
	 * @since 0.1.0
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( mixed $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'media-library-fixer-tool', MEDIA_LIBRARY_FIXER_URL . 'assets/admin/tool-page.css', array(), MEDIA_LIBRARY_FIXER_VERSION );
		wp_enqueue_script( 'media-library-fixer-tool', MEDIA_LIBRARY_FIXER_URL . 'assets/admin/tool-page.js', array(), MEDIA_LIBRARY_FIXER_VERSION, true );

		$modules = array();

		foreach ( get_modules() as $module ) {
			$modules[] = array(
				'id'           => $module->get_id(),
				'fixLabel'     => $module->get_fix_label(),
				'confirmation' => $module->get_fix_confirmation(),
				'columns'      => $module->get_columns(),
			);
		}

		wp_localize_script(
			'media-library-fixer-tool',
			'mlfixerToolPage',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => AJAX_ACTION,
				'nonce'   => wp_create_nonce( NONCE_ACTION ),
				'modules' => $modules,
				'strings' => array(
					'scan'            => _x( 'Scan', 'button label', 'media-library-fixer' ),
					'rescan'          => _x( 'Scan again', 'button label', 'media-library-fixer' ),
					'clear'           => _x( 'Clear results', 'button label', 'media-library-fixer' ),
					'stop'            => _x( 'Stop', 'button label', 'media-library-fixer' ),
					'selectAll'       => __( 'Select all', 'media-library-fixer' ),
					'notScanned'      => __( 'Not scanned yet.', 'media-library-fixer' ),
					/* translators: %1$s: date and time of the scan; %2$d: number of items found */
					'scannedSummary'  => __( 'Last scanned %1$s: %2$d found.', 'media-library-fixer' ),
					'scanning'        => __( 'Scanning…', 'media-library-fixer' ),
					'fixing'          => __( 'Working…', 'media-library-fixer' ),
					'stopping'        => __( 'Stopping after this batch…', 'media-library-fixer' ),
					/* translators: %1$d: items done; %2$d: total items */
					'progress'        => __( '%1$d of %2$d', 'media-library-fixer' ),
					/* translators: %d: number of items selected */
					'selectedCount'   => __( '%d selected', 'media-library-fixer' ),
					/* translators: %1$d: items fixed; %2$d: items that failed */
					'fixSummary'      => __( 'Finished: %1$d done, %2$d failed.', 'media-library-fixer' ),
					'scanErrors'      => __( 'Some parts of the scan failed; see the PHP error log. The results below may be incomplete.', 'media-library-fixer' ),
					'requestFailed'   => __( 'The request failed:', 'media-library-fixer' ),
					'noneFound'       => __( 'Nothing found.', 'media-library-fixer' ),
					'resumeFix'       => __( 'A previous run was interrupted. Continue it?', 'media-library-fixer' ),
					'continueRunning' => _x( 'Continue', 'button label', 'media-library-fixer' ),
				),
			)
		);
	}

	/**
	 * Render the tool page.
	 *
	 * @since 0.1.0
	 */
	public function render_tool_page(): void {
		if ( ! current_user_can( REQUIRED_CAPABILITY ) ) {
			return;
		}

		$modules           = get_modules();
		$settings_page_url = get_settings_page_url();

		require MEDIA_LIBRARY_FIXER_PATH . 'views/tool-page.php';
	}

	/**
	 * Handle one AJAX request from the tool page: status, scan, fix or reset for one module.
	 *
	 * @since 0.1.0
	 */
	public function handle_ajax(): void {
		check_ajax_referer( NONCE_ACTION );

		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$module    = get_module( $module_id );

		if ( ! current_user_can( REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'media-library-fixer' ) ), 403 );
		} elseif ( null === $module ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'media-library-fixer' ) ), 400 );
		} else {
			wp_raise_memory_limit( 'admin' );

			$runner   = new Job_Runner( $module );
			$messages = array();
			$error    = null;

			switch ( $operation ) {
				case 'status':
					$state = $runner->get_state();
					break;

				case 'scan_start':
					$state = $runner->start_scan();
					break;

				case 'scan_step':
					$state = $runner->scan_step( get_batch_time_limit() );
					break;

				case 'fix_start':
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and type-checked below; keys are only compared against stored findings.
					$keys  = isset( $_POST['keys'] ) ? json_decode( wp_unslash( $_POST['keys'] ), true ) : array();
					$state = $runner->start_fix( is_array( $keys ) ? array_filter( $keys, 'is_string' ) : array() );

					if ( is_wp_error( $state ) ) {
						$error = $state;
					}
					break;

				case 'fix_step':
					$step     = $runner->fix_step( get_batch_time_limit() );
					$state    = $step['state'];
					$messages = $step['messages'];
					break;

				case 'reset':
					$runner->reset();
					$state = $runner->get_state();
					break;

				default:
					$error = new \WP_Error( 'mlfixer_unknown_operation', __( 'Unknown operation.', 'media-library-fixer' ) );
					break;
			}

			if ( null === $error ) {
				wp_send_json_success( $this->build_response( $runner, $state, $messages ) );
			} else {
				wp_send_json_error( array( 'message' => $error->get_error_message() ), 400 );
			}
		}
	}

	/**
	 * Shape a job state for the page script.
	 *
	 * Findings are only sent when the phase is idle or scanned, so the large list doesn't travel with every progress step.
	 *
	 * @since 0.1.0
	 * @param Job_Runner $runner   Runner for the module.
	 * @param array      $state    Job state.
	 * @param array      $messages Messages from a fix step.
	 * @return array
	 */
	private function build_response( Job_Runner $runner, array $state, array $messages ): array {
		$response = array_merge(
			$runner->get_progress( $state ),
			array(
				'phase'        => $state['phase'],
				'scannedAt'    => $state['scanned_at'] > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['scanned_at'] ) : '',
				'findingCount' => count( $state['findings'] ),
				'scanErrors'   => count( $state['scan_errors'] ),
				'fixedCount'   => (int) $state['fixed_count'],
				'failedCount'  => count( $state['failed'] ),
				'messages'     => $messages,
			)
		);

		if ( PHASE_SCANNED === $state['phase'] || PHASE_IDLE === $state['phase'] ) {
			$response['findings'] = array_map(
				static fn( array $finding ): array => array(
					'key'    => $finding['key'],
					'label'  => $finding['label'],
					'detail' => (string) ( $finding['detail'] ?? '' ),
					'url'    => (string) ( $finding['url'] ?? '' ),
				),
				array_values( $state['findings'] )
			);
		}

		return $response;
	}
}
