<?php
/**
 * The standard "All entries" list screen, and sending its edit links to the
 * plugin's own form instead of the post editor.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_' . CCD_LS_POST_TYPE . '_posts_columns', 'ccd_ls_list_columns' );

/**
 * Sets the list columns: image, title, date and time instead of the post date.
 *
 * @param string[] $columns The default columns.
 * @return string[]
 */
function ccd_ls_list_columns( $columns ) {
	return array(
		'cb'           => $columns['cb'],
		'ccd_ls_image' => __( 'Image', 'ccd-livestreams' ),
		'title'        => __( 'Title', 'ccd-livestreams' ),
		'ccd_ls_date'  => __( 'Date', 'ccd-livestreams' ),
		'ccd_ls_time'  => __( 'Time', 'ccd-livestreams' ),
	);
}

add_action( 'manage_' . CCD_LS_POST_TYPE . '_posts_custom_column', 'ccd_ls_list_column', 10, 2 );

/**
 * Prints the custom column values.
 *
 * @param string $column  The column key.
 * @param int    $post_id The entry.
 */
function ccd_ls_list_column( $column, $post_id ) {
	$entry = ccd_ls_entry_data( $post_id );
	if ( ! $entry ) {
		return;
	}
	if ( 'ccd_ls_date' === $column ) {
		echo esc_html( ccd_ls_format_date( $entry['date'], 'D j M Y' ) );
	} elseif ( 'ccd_ls_time' === $column ) {
		echo esc_html( $entry['time'] );
	} elseif ( 'ccd_ls_image' === $column ) {
		$image = $entry['image_id'] ? wp_get_attachment_image_url( $entry['image_id'], 'thumbnail' ) : $entry['thumb'];
		printf(
			'<a href="%1$s" target="_blank" rel="noopener"><img src="%2$s" alt="" loading="lazy" style="width:96px;height:54px;object-fit:cover;display:block"></a>',
			esc_url( $entry['url'] ),
			esc_url( $image )
		);
	}
}

add_filter( 'manage_edit-' . CCD_LS_POST_TYPE . '_sortable_columns', 'ccd_ls_sortable_columns' );

/**
 * Makes the Date column sortable.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function ccd_ls_sortable_columns( $columns ) {
	$columns['ccd_ls_date'] = array( 'ccd_ls_date', true );
	return $columns;
}

add_action( 'pre_get_posts', 'ccd_ls_list_order' );

/**
 * Sorts the list by entry date, newest first, unless another order was
 * chosen.
 *
 * @param WP_Query $query The query.
 */
function ccd_ls_list_order( $query ) {
	if ( ! $query->is_main_query() || CCD_LS_POST_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}
	$orderby = $query->get( 'orderby' );
	if ( '' === $orderby || 'ccd_ls_date' === $orderby ) {
		$query->set( 'meta_key', CCD_LS_META_DATE );
		$query->set( 'orderby', 'meta_value' );
		if ( '' === $orderby ) {
			$query->set( 'order', 'DESC' );
		}
	}
}

add_filter( 'post_row_actions', 'ccd_ls_row_actions', 10, 2 );

/**
 * Removes Quick Edit, which can't edit the date, time or link.
 *
 * @param string[] $actions Row actions.
 * @param WP_Post  $post    The entry.
 * @return string[]
 */
function ccd_ls_row_actions( $actions, $post ) {
	if ( CCD_LS_POST_TYPE === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'] );
	}
	return $actions;
}

add_filter( 'bulk_actions-edit-' . CCD_LS_POST_TYPE, 'ccd_ls_bulk_actions' );

/**
 * Removes the bulk Edit action for the same reason.
 *
 * @param string[] $actions Bulk actions.
 * @return string[]
 */
function ccd_ls_bulk_actions( $actions ) {
	unset( $actions['edit'] );
	return $actions;
}

add_filter( 'get_edit_post_link', 'ccd_ls_edit_link', 10, 2 );

/**
 * Points edit links for entries at the plugin's form.
 *
 * @param string $link    The edit link.
 * @param int    $post_id The post.
 * @return string
 */
function ccd_ls_edit_link( $link, $post_id ) {
	if ( CCD_LS_POST_TYPE === get_post_type( $post_id ) ) {
		return ccd_ls_admin_url( array( 'edit' => (int) $post_id ) );
	}
	return $link;
}

add_action( 'load-post.php', 'ccd_ls_redirect_editor' );
add_action( 'load-post-new.php', 'ccd_ls_redirect_editor' );

/**
 * Sends anyone opening the post editor for an entry to the plugin's page.
 * Other post.php actions, such as trash and restore, still work.
 */
function ccd_ls_redirect_editor() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- redirect only.
	global $pagenow;

	if ( 'post-new.php' === $pagenow ) {
		$type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
		if ( CCD_LS_POST_TYPE === $type ) {
			wp_safe_redirect( ccd_ls_admin_url() );
			exit;
		}
		return;
	}

	$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	// phpcs:enable
	if ( 'edit' === $action && $post_id && CCD_LS_POST_TYPE === get_post_type( $post_id ) ) {
		wp_safe_redirect( ccd_ls_admin_url( array( 'edit' => $post_id ) ) );
		exit;
	}
}
