<?php
/**
 * The entry post type.
 *
 * One post per livestream. The post title is the free-text title, e.g.
 * "Morning Service". The date (Y-m-d), time (H:i), YouTube video ID,
 * thumbnail URL and optional override image are post meta.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

const CCD_LS_META_DATE  = '_ccd_ls_date';
const CCD_LS_META_TIME  = '_ccd_ls_time';
const CCD_LS_META_VIDEO = '_ccd_ls_video_id';
const CCD_LS_META_THUMB = '_ccd_ls_thumb_url';
const CCD_LS_META_IMAGE = '_ccd_ls_image_id';

add_action( 'init', 'ccd_ls_register_post_type' );

/**
 * Registers the entry post type. Entries are managed in the admin only, by
 * users who can manage options (Administrators), and have no public URLs.
 */
function ccd_ls_register_post_type() {
	$cap = 'manage_options';

	register_post_type(
		CCD_LS_POST_TYPE,
		array(
			'labels'       => array(
				'name'               => __( 'Livestreams', 'ccd-livestreams' ),
				'singular_name'      => __( 'Livestream', 'ccd-livestreams' ),
				'menu_name'          => __( 'Livestreams', 'ccd-livestreams' ),
				'all_items'          => __( 'All entries', 'ccd-livestreams' ),
				'add_new_item'       => __( 'Add livestream', 'ccd-livestreams' ),
				'edit_item'          => __( 'Edit livestream', 'ccd-livestreams' ),
				'search_items'       => __( 'Search livestreams', 'ccd-livestreams' ),
				'not_found'          => __( 'No livestreams found.', 'ccd-livestreams' ),
				'not_found_in_trash' => __( 'No livestreams found in Trash.', 'ccd-livestreams' ),
			),
			'public'       => false,
			'show_ui'      => true,
			// The list screen sits under the plugin's own admin page.
			'show_in_menu' => CCD_LS_ADMIN_SLUG,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
			'has_archive'  => false,
			'rewrite'      => false,
			'query_var'    => false,
			'map_meta_cap' => true,
			'capabilities' => array(
				'create_posts'           => $cap,
				'edit_posts'             => $cap,
				'edit_others_posts'      => $cap,
				'edit_published_posts'   => $cap,
				'edit_private_posts'     => $cap,
				'publish_posts'          => $cap,
				'read_private_posts'     => $cap,
				'delete_posts'           => $cap,
				'delete_others_posts'    => $cap,
				'delete_published_posts' => $cap,
				'delete_private_posts'   => $cap,
				'read'                   => $cap,
			),
		)
	);

	add_filter( 'wp_untrash_post_status', 'ccd_ls_untrash_status', 10, 3 );

	$meta = array(
		CCD_LS_META_DATE  => 'string',
		CCD_LS_META_TIME  => 'string',
		CCD_LS_META_VIDEO => 'string',
		CCD_LS_META_THUMB => 'string',
		CCD_LS_META_IMAGE => 'integer',
	);
	foreach ( $meta as $key => $type ) {
		register_post_meta(
			CCD_LS_POST_TYPE,
			$key,
			array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
}

/**
 * Restores a trashed entry to its previous status (published) instead of
 * WordPress's default of draft, so "Undo" puts it straight back on the page.
 *
 * @param string $new_status      The status WordPress would restore.
 * @param int    $post_id         The post.
 * @param string $previous_status The status before it was trashed.
 * @return string
 */
function ccd_ls_untrash_status( $new_status, $post_id, $previous_status ) {
	if ( CCD_LS_POST_TYPE === get_post_type( $post_id ) && $previous_status ) {
		return $previous_status;
	}
	return $new_status;
}
