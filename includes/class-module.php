<?php
/**
 * Module Base Class
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * A Media Library hygiene task: a read-only scan that produces findings, and a fix applied to selected findings.
 *
 * The scan is split into units of work so Job_Runner can spread it over several
 * requests. See docs/developers/writing-a-module.md.
 *
 * A finding is an array with these keys:
 *
 * - `key`    (string) Unique within one scan. Identifies the finding when the user selects it.
 * - `label`  (string) Plain text shown in the first column.
 * - `detail` (string) Plain text shown in the second column.
 * - `url`    (string) Optional link for the label.
 * - `data`   (array)  Optional module-private values, handed back to fix_finding().
 *
 * @since 0.1.0
 */
abstract class Module {

	/**
	 * Unique ID: lower-case letters, digits and hyphens.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Heading shown on the tool page.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	abstract public function get_title(): string;

	/**
	 * One or two sentences describing what the scan looks for and what the fix does.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	abstract public function get_description(): string;

	/**
	 * Label for the button that applies the fix to selected findings.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	abstract public function get_fix_label(): string;

	/**
	 * Question shown in a confirmation dialog before the fix runs, or '' for none.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_fix_confirmation(): string {
		return '';
	}

	/**
	 * Column headings for the label and detail of each finding.
	 *
	 * @since 0.1.0
	 * @return array{label:string,detail:string}
	 */
	public function get_columns(): array {
		return array(
			'label'  => _x( 'Item', 'findings table column heading', 'media-library-fixer' ),
			'detail' => _x( 'Details', 'findings table column heading', 'media-library-fixer' ),
		);
	}

	/**
	 * List the units of work the scan is split into.
	 *
	 * Each unit must survive a JSON round trip: strings, numbers and arrays of them.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	abstract public function get_scan_units(): array;

	/**
	 * Scan one unit of work.
	 *
	 * @since 0.1.0
	 * @param mixed $unit One entry from get_scan_units().
	 * @return array[] Findings; see the class docblock for their shape.
	 */
	abstract public function scan_unit( mixed $unit ): array;

	/**
	 * Apply the fix to one finding.
	 *
	 * Re-check the problem before acting: time may have passed since the scan.
	 *
	 * @since 0.1.0
	 * @param array $finding A finding returned by scan_unit().
	 * @return string|\WP_Error A one-line summary of what was done, or why it failed.
	 */
	abstract public function fix_finding( array $finding ): string|\WP_Error;
}
