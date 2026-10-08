<?php
/**
 * Job Runner
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Runs a module's scan and fix in time-limited steps, keeping progress in a per-module option between requests.
 *
 * @since 0.1.0
 */
class Job_Runner {

	/**
	 * Module being run.
	 *
	 * @since 0.1.0
	 * @var Module
	 */
	private Module $module;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @param Module $module Module to run.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Get the stored job state, filling in defaults.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function get_state(): array {
		$defaults = array(
			'phase'       => PHASE_IDLE,
			'units'       => array(),
			'unit_index'  => 0,
			'findings'    => array(),
			'scan_errors' => array(),
			'scanned_at'  => 0,
			'queue'       => array(),
			'queue_index' => 0,
			'fixed_count' => 0,
			'failed'      => array(),
		);
		$stored   = get_option( OPTION_JOB_PREFIX . $this->module->get_id(), array() );

		return array_merge( $defaults, is_array( $stored ) ? array_intersect_key( $stored, $defaults ) : array() );
	}

	/**
	 * Store the job state, not autoloaded.
	 *
	 * @since 0.1.0
	 * @param array $state Job state.
	 */
	private function save_state( array $state ): void {
		update_option( OPTION_JOB_PREFIX . $this->module->get_id(), $state, false );
	}

	/**
	 * Forget the scan results and any progress.
	 *
	 * @since 0.1.0
	 */
	public function reset(): void {
		delete_option( OPTION_JOB_PREFIX . $this->module->get_id() );
	}

	/**
	 * Begin a fresh scan, discarding previous results.
	 *
	 * @since 0.1.0
	 * @return array Job state.
	 */
	public function start_scan(): array {
		$state = $this->get_state();

		$state['phase']       = PHASE_SCANNING;
		$state['units']       = array_values( $this->module->get_scan_units() );
		$state['unit_index']  = 0;
		$state['findings']    = array();
		$state['scan_errors'] = array();
		$state['scanned_at']  = 0;
		$state['queue']       = array();
		$state['queue_index'] = 0;

		$this->save_state( $state );

		return $state;
	}

	/**
	 * Scan units until the time limit passes or the scan completes; always at least one.
	 *
	 * @since 0.1.0
	 * @param int $time_limit Seconds; 0 runs to completion.
	 * @return array Job state.
	 */
	public function scan_step( int $time_limit ): array {
		$state      = $this->get_state();
		$started_at = microtime( true );
		$unit_count = count( $state['units'] );

		while ( PHASE_SCANNING === $state['phase'] && $state['unit_index'] < $unit_count ) {
			$unit = $state['units'][ $state['unit_index'] ];

			try {
				foreach ( $this->module->scan_unit( $unit ) as $finding ) {
					if ( $this->is_valid_finding( $finding ) ) {
						$state['findings'][ $finding['key'] ] = $finding;
					}
				}
			} catch ( \Throwable $throwable ) {
				$message                = sprintf( 'Scan of %s failed on unit %s: %s', $this->module->get_id(), wp_json_encode( $unit ), $throwable->getMessage() );
				$state['scan_errors'][] = $message;
				log_error( $message );
			}

			++$state['unit_index'];

			if ( $time_limit > 0 && microtime( true ) - $started_at >= $time_limit ) {
				break;
			}
		}

		if ( PHASE_SCANNING === $state['phase'] && $state['unit_index'] >= $unit_count ) {
			$state['phase']      = PHASE_SCANNED;
			$state['units']      = array();
			$state['unit_index'] = 0;
			$state['scanned_at'] = time();
		}

		$this->save_state( $state );

		return $state;
	}

	/**
	 * Check a finding has the keys the runner and the page rely on.
	 *
	 * @since 0.1.0
	 * @param mixed $finding Candidate finding.
	 * @return bool
	 */
	private function is_valid_finding( mixed $finding ): bool {
		$is_valid = is_array( $finding )
			&& is_string( $finding['key'] ?? null ) && '' !== $finding['key']
			&& is_string( $finding['label'] ?? null )
			&& is_string( $finding['detail'] ?? '' )
			&& is_array( $finding['data'] ?? array() );

		if ( ! $is_valid ) {
			log_error( sprintf( 'Module %s returned a malformed finding; skipped.', $this->module->get_id() ) );
		}

		return $is_valid;
	}

	/**
	 * Queue the selected findings for fixing.
	 *
	 * @since 0.1.0
	 * @param string[] $keys Finding keys; unknown keys are ignored.
	 * @return array|\WP_Error Job state, or an error when there is nothing to fix.
	 */
	public function start_fix( array $keys ): array|\WP_Error {
		$state = $this->get_state();
		$queue = array_values( array_unique( array_filter( $keys, static fn( mixed $key ): bool => is_string( $key ) && isset( $state['findings'][ $key ] ) ) ) );

		if ( PHASE_SCANNED !== $state['phase'] ) {
			$result = new \WP_Error( 'mlfixer_not_scanned', __( 'Run a scan before fixing.', 'media-library-fixer' ) );
		} elseif ( array() === $queue ) {
			$result = new \WP_Error( 'mlfixer_nothing_selected', __( 'None of the selected items are in the scan results.', 'media-library-fixer' ) );
		} else {
			$state['phase']       = PHASE_FIXING;
			$state['queue']       = $queue;
			$state['queue_index'] = 0;
			$state['fixed_count'] = 0;
			$state['failed']      = array();

			$this->save_state( $state );

			$result = $state;
		}

		return $result;
	}

	/**
	 * Fix queued findings until the time limit passes or the queue empties; always at least one.
	 *
	 * @since 0.1.0
	 * @param int $time_limit Seconds; 0 runs to completion.
	 * @return array{state:array,messages:array<int,array{type:string,text:string}>} Job state and one message per finding handled.
	 */
	public function fix_step( int $time_limit ): array {
		$state       = $this->get_state();
		$messages    = array();
		$started_at  = microtime( true );
		$queue_count = count( $state['queue'] );

		while ( PHASE_FIXING === $state['phase'] && $state['queue_index'] < $queue_count ) {
			$key     = $state['queue'][ $state['queue_index'] ];
			$finding = $state['findings'][ $key ] ?? null;

			if ( null === $finding ) {
				$outcome = new \WP_Error( 'mlfixer_gone', __( 'No longer in the scan results.', 'media-library-fixer' ) );
			} else {
				try {
					$outcome = $this->module->fix_finding( $finding );
				} catch ( \Throwable $throwable ) {
					$outcome = new \WP_Error( 'mlfixer_exception', $throwable->getMessage() );
				}
			}

			$label = is_array( $finding ) ? $finding['label'] : $key;

			if ( is_wp_error( $outcome ) ) {
				$state['failed'][] = array(
					'label'   => $label,
					'message' => $outcome->get_error_message(),
				);
				$messages[]        = array(
					'type' => 'error',
					'text' => $label . ': ' . $outcome->get_error_message(),
				);
				log_error( sprintf( 'Fix in %s failed for %s: %s', $this->module->get_id(), $label, $outcome->get_error_message() ) );
			} else {
				unset( $state['findings'][ $key ] );
				++$state['fixed_count'];
				$messages[] = array(
					'type' => 'success',
					'text' => $label . ': ' . $outcome,
				);
			}

			++$state['queue_index'];

			if ( $time_limit > 0 && microtime( true ) - $started_at >= $time_limit ) {
				break;
			}
		}

		if ( PHASE_FIXING === $state['phase'] && $state['queue_index'] >= $queue_count ) {
			$state['phase']       = PHASE_SCANNED;
			$state['queue']       = array();
			$state['queue_index'] = 0;
		}

		$this->save_state( $state );

		return array(
			'state'    => $state,
			'messages' => $messages,
		);
	}

	/**
	 * Progress through the current phase.
	 *
	 * @since 0.1.0
	 * @param array $state Job state.
	 * @return array{done:int,total:int}
	 */
	public function get_progress( array $state ): array {
		if ( PHASE_SCANNING === $state['phase'] ) {
			$progress = array(
				'done'  => (int) $state['unit_index'],
				'total' => count( $state['units'] ),
			);
		} elseif ( PHASE_FIXING === $state['phase'] ) {
			$progress = array(
				'done'  => (int) $state['queue_index'],
				'total' => count( $state['queue'] ),
			);
		} else {
			$progress = array(
				'done'  => 0,
				'total' => 0,
			);
		}

		return $progress;
	}
}
