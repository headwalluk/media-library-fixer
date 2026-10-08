<?php
/**
 * Tool Page Template
 *
 * Renders one empty panel per module; assets/admin/tool-page.js fills in status,
 * progress and results.
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 *
 * Variables available in this template:
 * @var array<string,\Media_Library_Fixer\Module> $modules           Registered modules
 * @var string                                    $settings_page_url URL of the settings page
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

echo '<div class="wrap mlfixer-wrap">';
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

printf(
	'<p>%s <a href="%s">%s</a></p>',
	esc_html__( 'Scanning only reads; nothing changes until you press an action button. Take a backup before importing or deleting in bulk.', 'media-library-fixer' ),
	esc_url( $settings_page_url ),
	esc_html__( 'Settings', 'media-library-fixer' )
);

printf( '<noscript><div class="notice notice-error"><p>%s</p></div></noscript>', esc_html__( 'This page needs JavaScript.', 'media-library-fixer' ) );

foreach ( $modules as $mlfixer_module ) {
	printf(
		'<section class="mlfixer-module card" data-module="%s" aria-labelledby="mlfixer-heading-%s">',
		esc_attr( $mlfixer_module->get_id() ),
		esc_attr( $mlfixer_module->get_id() )
	);

	printf( '<h2 id="mlfixer-heading-%s">%s</h2>', esc_attr( $mlfixer_module->get_id() ), esc_html( $mlfixer_module->get_title() ) );
	printf( '<p>%s</p>', esc_html( $mlfixer_module->get_description() ) );

	echo '<p class="mlfixer-controls">';
	printf( '<button type="button" class="button button-primary" data-role="scan" disabled>%s</button> ', esc_html_x( 'Scan', 'button label', 'media-library-fixer' ) );
	printf( '<button type="button" class="button" data-role="stop" hidden>%s</button> ', esc_html_x( 'Stop', 'button label', 'media-library-fixer' ) );
	printf( '<button type="button" class="button-link" data-role="clear" hidden>%s</button>', esc_html_x( 'Clear results', 'button label', 'media-library-fixer' ) );
	echo '</p>';

	echo '<p class="mlfixer-status" data-role="status" role="status" aria-live="polite"></p>';
	echo '<div class="mlfixer-progress" data-role="progress" hidden><progress max="100" value="0"></progress> <span data-role="progress-text"></span></div>';
	echo '<div class="mlfixer-results" data-role="results"></div>';
	echo '<ol class="mlfixer-log" data-role="log" hidden></ol>';

	echo '</section>';
}

echo '</div>';
