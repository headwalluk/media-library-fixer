<?php
/**
 * Plugin Constants
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Prefixes shared by every option and post meta key the plugin stores.
 *
 * The uninstaller deletes by prefix, so a new key is covered as long as it uses one.
 *
 * @since 0.1.0
 */
const OPTION_PREFIX = 'mlfixer_';
const META_PREFIX   = '_mlfixer_';

/**
 * Options.
 *
 * @since 0.1.0
 */
const OPTION_FILE_EXTENSIONS          = 'mlfixer_file_extensions';
const OPTION_SCAN_SCOPE               = 'mlfixer_scan_scope';
const OPTION_DATE_SOURCE              = 'mlfixer_date_source';
const OPTION_TITLE_SOURCE             = 'mlfixer_title_source';
const OPTION_DESCRIBE_DOCUMENT        = 'mlfixer_describe_document';
const OPTION_GENERATE_PREVIEWS        = 'mlfixer_generate_previews';
const OPTION_DELETE_DATA_ON_UNINSTALL = 'mlfixer_delete_data_on_uninstall';

/**
 * Job state, one non-autoloaded option per module: OPTION_JOB_PREFIX . module ID.
 *
 * @since 0.1.0
 */
const OPTION_JOB_PREFIX = 'mlfixer_job_';

/**
 * Post meta on attachments this plugin created: Unix timestamp of the import.
 *
 * @since 0.1.0
 */
const META_IMPORTED = '_mlfixer_imported';

/**
 * Scan scopes.
 *
 * @since 0.1.0
 */
const SCAN_SCOPE_DATED = 'dated';
const SCAN_SCOPE_ALL   = 'all';

/**
 * Date sources for imported attachments.
 *
 * @since 0.1.0
 */
const DATE_SOURCE_DOCUMENT = 'document';
const DATE_SOURCE_FOLDER   = 'folder';
const DATE_SOURCE_MODIFIED = 'modified';

/**
 * Title sources for imported attachments.
 *
 * @since 0.1.0
 */
const TITLE_SOURCE_FILENAME = 'filename';
const TITLE_SOURCE_DOCUMENT = 'document';

/**
 * Job phases.
 *
 * @since 0.1.0
 */
const PHASE_IDLE     = 'idle';
const PHASE_SCANNING = 'scanning';
const PHASE_SCANNED  = 'scanned';
const PHASE_FIXING   = 'fixing';

/**
 * Admin page slugs, AJAX action and nonce action.
 *
 * @since 0.1.0
 */
const TOOL_PAGE_SLUG     = 'media-library-fixer';
const SETTINGS_PAGE_SLUG = 'media-library-fixer-settings';
const SETTINGS_GROUP     = 'mlfixer_settings';
const AJAX_ACTION        = 'mlfixer_job';
const NONCE_ACTION       = 'mlfixer_job';

/**
 * Capability required to scan, import or delete.
 *
 * @since 0.1.0
 */
const REQUIRED_CAPABILITY = 'manage_options';

/**
 * Seconds of work per batch request before handing back to the browser.
 *
 * Kept well under common 30-second PHP and proxy timeouts. Filterable through
 * mlfixer_batch_time_limit.
 *
 * @since 0.1.0
 */
const DEFAULT_BATCH_TIME_LIMIT = 8;

/**
 * Attachment IDs per unit of work in the missing-files scan.
 *
 * @since 0.1.0
 */
const ATTACHMENT_ID_CHUNK_SIZE = 500;

/**
 * Largest PDF the metadata reader loads into memory, in bytes.
 *
 * Filterable through mlfixer_pdf_max_bytes.
 *
 * @since 0.1.0
 */
const PDF_MAX_BYTES = 64 * MB_IN_BYTES;

/**
 * Earliest embedded document date accepted as real: the first public PDF release.
 *
 * @since 0.1.0
 */
const PDF_EARLIEST_DATE = '1993-06-15';

/**
 * Defaults.
 *
 * @since 0.1.0
 */
const DEFAULT_FILE_EXTENSIONS          = 'pdf';
const DEFAULT_SCAN_SCOPE               = SCAN_SCOPE_DATED;
const DEFAULT_DATE_SOURCE              = DATE_SOURCE_DOCUMENT;
const DEFAULT_TITLE_SOURCE             = TITLE_SOURCE_FILENAME;
const DEFAULT_DESCRIBE_DOCUMENT        = true;
const DEFAULT_GENERATE_PREVIEWS        = false;
const DEFAULT_DELETE_DATA_ON_UNINSTALL = false;
