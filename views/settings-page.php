<?php
/**
 * Settings Page Template
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 *
 * Variables available in this template:
 * @var array  $settings             Current values, keyed as $option_names
 * @var bool   $can_generate_pdf_previews Whether the image editor can read PDFs in web requests
 * @var array  $option_names         Option names, keyed by setting
 * @var array  $scan_scope_choices   SCAN_SCOPE_* value => label
 * @var array  $date_source_choices  DATE_SOURCE_* value => label
 * @var array  $title_source_choices TITLE_SOURCE_* value => label
 * @var string $settings_group       Settings API group
 * @var string $tool_page_url        URL of Tools → Fix Media Library
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Print a table row of radio buttons.
 *
 * @param string $heading     Row heading.
 * @param string $option_name Option name.
 * @param array  $choices     Value => label.
 * @param string $current     Selected value.
 * @param string $description Help text below the choices.
 */
$mlfixer_print_radio_row = static function ( string $heading, string $option_name, array $choices, string $current, string $description ): void {
	printf( '<tr><th scope="row">%s</th><td><fieldset><legend class="screen-reader-text"><span>%s</span></legend>', esc_html( $heading ), esc_html( $heading ) );

	foreach ( $choices as $value => $label ) {
		printf(
			'<label><input type="radio" name="%s" value="%s" %s> %s</label><br>',
			esc_attr( $option_name ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label )
		);
	}

	printf( '<p class="description">%s</p></fieldset></td></tr>', esc_html( $description ) );
};

/**
 * Print a table row with one checkbox.
 *
 * @param string $heading     Row heading.
 * @param string $option_name Option name.
 * @param bool   $checked     Whether ticked.
 * @param string $label       Checkbox label.
 * @param string $description Help text.
 */
$mlfixer_print_checkbox_row = static function ( string $heading, string $option_name, bool $checked, string $label, string $description ): void {
	printf(
		'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" id="%1$s" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
		esc_attr( $option_name ),
		esc_html( $heading ),
		checked( $checked, true, false ),
		esc_html( $label ),
		esc_html( $description )
	);
};

echo '<div class="wrap">';
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

printf(
	'<p>%s <a href="%s">%s</a></p>',
	esc_html__( 'These settings control how Tools → Fix Media Library scans and imports files.', 'media-library-fixer' ),
	esc_url( $tool_page_url ),
	esc_html__( 'Open the tool', 'media-library-fixer' )
);

echo '<form method="post" action="options.php">';
settings_fields( $settings_group );

// Scanning.
printf( '<h2>%s</h2>', esc_html_x( 'Scanning', 'settings section heading', 'media-library-fixer' ) );
echo '<table class="form-table" role="presentation"><tbody>';

printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" name="%1$s" id="%1$s" value="%3$s" class="regular-text"><p class="description">%4$s</p></td></tr>',
	esc_attr( $option_names['file_extensions'] ),
	esc_html__( 'File types', 'media-library-fixer' ),
	esc_attr( $settings['file_extensions'] ),
	esc_html__( 'Comma-separated file extensions to look for, e.g. "pdf, docx, xlsx". Only types WordPress allows in the Media Library are accepted.', 'media-library-fixer' )
);

$mlfixer_print_radio_row(
	__( 'Folders to scan', 'media-library-fixer' ),
	$option_names['scan_scope'],
	$scan_scope_choices,
	$settings['scan_scope'],
	__( 'WordPress files uploads into year/month folders unless that option is turned off under Settings → Media.', 'media-library-fixer' )
);

echo '</tbody></table>';

// Importing.
printf( '<h2>%s</h2>', esc_html_x( 'Importing', 'settings section heading', 'media-library-fixer' ) );
echo '<table class="form-table" role="presentation"><tbody>';

$mlfixer_print_radio_row(
	__( 'Date', 'media-library-fixer' ),
	$option_names['date_source'],
	$date_source_choices,
	$settings['date_source'],
	__( 'Sets the Media Library item\'s upload date. A file copied from another server usually carries the date of the copy as its modified time, so that choice is rarely accurate. Each choice falls back to the next one down when it has no date.', 'media-library-fixer' )
);

$mlfixer_print_radio_row(
	__( 'Title', 'media-library-fixer' ),
	$option_names['title_source'],
	$title_source_choices,
	$settings['title_source'],
	__( 'Embedded titles are often left over from a template ("Layout 1", "Microsoft Word - draft.docx"); those are ignored.', 'media-library-fixer' )
);

$mlfixer_print_checkbox_row(
	__( 'Description', 'media-library-fixer' ),
	$option_names['describe_document'],
	$settings['describe_document'],
	__( 'Add the document\'s author, subject and keywords to the description', 'media-library-fixer' ),
	__( 'Makes imported documents findable by those properties in the Media Library search.', 'media-library-fixer' )
);

$mlfixer_print_checkbox_row(
	__( 'PDF previews', 'media-library-fixer' ),
	$option_names['generate_previews'],
	$settings['generate_previews'],
	__( 'Generate preview images for imported PDFs', 'media-library-fixer' ),
	$can_generate_pdf_previews
		? __( 'Matches what WordPress does for an uploaded PDF. Slower, and writes a few image files beside each PDF.', 'media-library-fixer' )
		: __( 'This server\'s image editor cannot read PDFs from the web, so previews will not be made when importing from this page, nor for PDFs uploaded through the Media Library. WP-CLI may still be able to make them; see the documentation.', 'media-library-fixer' )
);

echo '</tbody></table>';

// Uninstall.
printf( '<h2>%s</h2>', esc_html_x( 'Uninstall', 'settings section heading', 'media-library-fixer' ) );
echo '<table class="form-table" role="presentation"><tbody>';

$mlfixer_print_checkbox_row(
	__( 'Plugin data', 'media-library-fixer' ),
	$option_names['delete_data_on_uninstall'],
	$settings['delete_data_on_uninstall'],
	__( 'Delete all plugin data when uninstalled', 'media-library-fixer' ),
	__( 'When the plugin is deleted from the Plugins screen, also delete its settings, saved scan results and the marker on imported attachments. Imported attachments themselves are always kept.', 'media-library-fixer' )
);

echo '</tbody></table>';

submit_button();

echo '</form></div>';
