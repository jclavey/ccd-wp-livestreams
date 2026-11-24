<?php
/**
 * The "Livestreams grid" block and the [ccd_livestreams] shortcode.
 *
 * Both render the entries from the last two weeks. The block has no options
 * of its own.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'ccd_ls_register_block' );

/**
 * Registers the block, its editor script and the shortcode.
 */
function ccd_ls_register_block() {
	wp_register_script(
		'ccd-ls-block-editor',
		CCD_LS_URL . 'assets/block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		CCD_LS_VERSION,
		true
	);
	wp_localize_script(
		'ccd-ls-block-editor',
		'ccdLivestreams',
		array(
			'manageUrl' => admin_url( 'admin.php?page=' . CCD_LS_ADMIN_SLUG ),
		)
	);

	register_block_type(
		CCD_LS_DIR . 'block.json',
		array(
			'render_callback' => 'ccd_ls_render_block',
		)
	);

	add_shortcode( 'ccd_livestreams', 'ccd_ls_render_shortcode' );
}

/**
 * Renders the shortcode.
 *
 * @return string HTML.
 */
function ccd_ls_render_shortcode() {
	return ccd_ls_render_grid();
}

/**
 * Renders the block. The output is wrapped so the theme's block spacing
 * applies, and it takes any extra CSS class set in the editor.
 *
 * @return string HTML.
 */
function ccd_ls_render_block() {
	$html = ccd_ls_render_grid();
	if ( '' === $html || 0 === strpos( $html, '<!--' ) ) {
		return $html;
	}
	return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
}
