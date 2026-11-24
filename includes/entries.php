<?php
/**
 * Reading and writing entries.
 *
 * Everything that changes entries goes through ccd_ls_save_entry(), so the
 * admin form, the suggestions and the tests share the same validation.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many days back the page shows: today and the 13 days before it.
 */
const CCD_LS_WINDOW_DAYS = 13;

/**
 * Checks a date string is a real calendar date in Y-m-d form.
 *
 * @param string $date The date to check.
 * @return string|false The date, or false if it isn't valid.
 */
function ccd_ls_validate_date( $date ) {
	$date = trim( (string) $date );
	$dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
	if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
		return false;
	}
	return $date;
}

/**
 * Checks a time string is a valid 24-hour time. Accepts H:i, G:i and H.i.
 *
 * @param string $time The time to check.
 * @return string|false The time as H:i, or false if it isn't valid.
 */
function ccd_ls_validate_time( $time ) {
	if ( ! preg_match( '/^\s*([01]?\d|2[0-3])[:.]([0-5]\d)\s*$/', (string) $time, $m ) ) {
		return false;
	}
	return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
}

/**
 * Formats an entry date (Y-m-d) for display.
 *
 * @param string $date   The entry date.
 * @param string $format A PHP date format.
 * @return string
 */
function ccd_ls_format_date( $date, $format ) {
	$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, wp_timezone() );
	return $dt ? wp_date( $format, $dt->getTimestamp(), wp_timezone() ) : '';
}

/**
 * Formats an entry time (H:i) as 12-hour time without am/pm, e.g. "6:15".
 *
 * @param string $time The entry time.
 * @return string
 */
function ccd_ls_format_time( $time ) {
	$time = ccd_ls_validate_time( $time );
	if ( ! $time ) {
		return '';
	}
	list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
	$h12           = $h % 12;
	return sprintf( '%d:%02d', 0 === $h12 ? 12 : $h12, $m );
}

/**
 * The caption for an entry, e.g. "22.11.26 – 6:15 Evening Service".
 *
 * @param array $entry See ccd_ls_entry_data().
 * @return string Plain text.
 */
function ccd_ls_caption( $entry ) {
	$caption = sprintf(
		'%1$s – %2$s %3$s',
		ccd_ls_format_date( $entry['date'], 'd.m.y' ),
		ccd_ls_format_time( $entry['time'] ),
		$entry['title']
	);

	/**
	 * Filters an entry's caption.
	 *
	 * @param string $caption The caption, plain text.
	 * @param array  $entry   See ccd_ls_entry_data().
	 */
	return trim( (string) apply_filters( 'ccd_ls_caption', $caption, $entry ) );
}

/**
 * Today's date in the site's timezone.
 *
 * @return string Y-m-d.
 */
function ccd_ls_today() {
	return current_datetime()->format( 'Y-m-d' );
}

/**
 * Today if it's Sunday, otherwise the coming Sunday, in the site's timezone.
 * The default date for a new entry, and the last date suggestions cover.
 *
 * @param string $today Today, Y-m-d. Defaults to the real today.
 * @return string Y-m-d.
 */
function ccd_ls_upcoming_sunday( $today = '' ) {
	$today = $today ? DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() ) : current_datetime();
	$days  = ( 7 - (int) $today->format( 'w' ) ) % 7;
	return $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
}

/**
 * The first date the page shows: 13 days before today.
 *
 * @param string $today Today, Y-m-d. Defaults to the real today.
 * @return string Y-m-d.
 */
function ccd_ls_window_start( $today = '' ) {
	$today = $today ? $today : ccd_ls_today();
	$dt    = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() );
	return $dt->modify( '-' . CCD_LS_WINDOW_DAYS . ' days' )->format( 'Y-m-d' );
}

/**
 * Finds a live (not trashed) entry for a YouTube video.
 *
 * @param string $video_id   The video ID.
 * @param int    $exclude_id An entry to ignore, e.g. the one being edited.
 * @return int The entry ID, or 0 if there is none.
 */
function ccd_ls_find_entry_by_video( $video_id, $exclude_id = 0 ) {
	$ids = get_posts(
		array(
			'post_type'        => CCD_LS_POST_TYPE,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'meta_key'         => CCD_LS_META_VIDEO, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => (string) $video_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			'post__not_in'     => $exclude_id ? array( (int) $exclude_id ) : array(),
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * The video IDs of every live (not trashed) entry.
 *
 * @return string[]
 */
function ccd_ls_existing_video_ids() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')",
			CCD_LS_META_VIDEO,
			CCD_LS_POST_TYPE
		)
	);
	return array_map( 'strval', $ids );
}

/**
 * Adds or updates an entry.
 *
 * @param array $data     Keys: date (Y-m-d), time (H:i), title, video (a
 *                        YouTube URL or ID), image_id (optional override
 *                        image; 0 for none). When updating, a missing
 *                        image_id keeps the current one.
 * @param int   $entry_id The entry to update, or 0 to add a new one.
 * @return int|WP_Error The entry ID, or an error with the code invalid_date,
 *                      invalid_time, invalid_title, invalid_video,
 *                      invalid_image, not_found or duplicate (whose data
 *                      holds the existing entry's ID).
 */
function ccd_ls_save_entry( $data, $entry_id = 0 ) {
	$data     = wp_parse_args( (array) $data, array( 'date' => '', 'time' => '', 'title' => '', 'video' => '' ) );
	$entry_id = (int) $entry_id;
	$date     = ccd_ls_validate_date( $data['date'] );
	$time     = ccd_ls_validate_time( $data['time'] );
	$title    = trim( sanitize_text_field( (string) $data['title'] ) );
	$video_id = ccd_ls_parse_video_id( $data['video'] );

	if ( ! $date ) {
		return new WP_Error( 'invalid_date', __( 'Please enter a valid date.', 'ccd-livestreams' ) );
	}
	if ( ! $time ) {
		return new WP_Error( 'invalid_time', __( 'Please enter a valid time, e.g. 11:15 or 18:15.', 'ccd-livestreams' ) );
	}
	if ( '' === $title ) {
		return new WP_Error( 'invalid_title', __( 'Please enter a title, e.g. Morning Service.', 'ccd-livestreams' ) );
	}
	if ( ! $video_id ) {
		return new WP_Error( 'invalid_video', __( 'Please enter a YouTube link.', 'ccd-livestreams' ) );
	}
	if ( $entry_id && CCD_LS_POST_TYPE !== get_post_type( $entry_id ) ) {
		return new WP_Error( 'not_found', __( 'That entry no longer exists.', 'ccd-livestreams' ) );
	}

	$image_id = null;
	if ( array_key_exists( 'image_id', $data ) ) {
		$image_id = (int) $data['image_id'];
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			return new WP_Error( 'invalid_image', __( 'Please choose an image from the Media Library.', 'ccd-livestreams' ) );
		}
	}

	$existing = ccd_ls_find_entry_by_video( $video_id, $entry_id );
	if ( $existing ) {
		return new WP_Error(
			'duplicate',
			__( 'That video has already been added.', 'ccd-livestreams' ),
			$existing
		);
	}

	$meta = array(
		CCD_LS_META_DATE  => $date,
		CCD_LS_META_TIME  => $time,
		CCD_LS_META_VIDEO => $video_id,
	);

	// Look up the thumbnail when the video is new or has changed.
	$old_video = $entry_id ? (string) get_post_meta( $entry_id, CCD_LS_META_VIDEO, true ) : '';
	if ( ! $entry_id || $old_video !== $video_id || '' === (string) get_post_meta( $entry_id, CCD_LS_META_THUMB, true ) ) {
		$meta[ CCD_LS_META_THUMB ] = ccd_ls_find_thumbnail( $video_id );
	}
	if ( null !== $image_id ) {
		$meta[ CCD_LS_META_IMAGE ] = $image_id;
	}

	$post = array(
		'post_type'   => CCD_LS_POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => $title,
		'meta_input'  => $meta,
	);

	if ( $entry_id ) {
		$post['ID'] = $entry_id;
		return wp_update_post( wp_slash( $post ), true );
	}
	return wp_insert_post( wp_slash( $post ), true );
}

/**
 * Turns an entry post into the data the admin and the page need.
 *
 * @param WP_Post|int $post The entry.
 * @return array{id:int,date:string,time:string,title:string,video_id:string,url:string,thumb:string,image_id:int}|null
 *         Null if the entry is incomplete.
 */
function ccd_ls_entry_data( $post ) {
	$post = get_post( $post );
	if ( ! $post || CCD_LS_POST_TYPE !== $post->post_type ) {
		return null;
	}
	$date     = ccd_ls_validate_date( get_post_meta( $post->ID, CCD_LS_META_DATE, true ) );
	$time     = ccd_ls_validate_time( get_post_meta( $post->ID, CCD_LS_META_TIME, true ) );
	$video_id = ccd_ls_parse_video_id( get_post_meta( $post->ID, CCD_LS_META_VIDEO, true ) );
	if ( ! $date || ! $time || ! $video_id ) {
		return null;
	}
	$thumb    = (string) get_post_meta( $post->ID, CCD_LS_META_THUMB, true );
	$image_id = (int) get_post_meta( $post->ID, CCD_LS_META_IMAGE, true );
	if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
		$image_id = 0;
	}

	return array(
		'id'       => (int) $post->ID,
		'date'     => $date,
		'time'     => $time,
		'title'    => $post->post_title,
		'video_id' => $video_id,
		'url'      => ccd_ls_watch_url( $video_id ),
		'thumb'    => '' !== $thumb ? $thumb : ccd_ls_thumbnail_url( $video_id, 'hqdefault' ),
		'image_id' => $image_id,
	);
}

/**
 * Sorts entries newest date first and, within a day, earliest time first.
 *
 * @param array[] $entries See ccd_ls_entry_data().
 * @return array[]
 */
function ccd_ls_sort_entries( $entries ) {
	usort(
		$entries,
		static function ( $a, $b ) {
			if ( $a['date'] !== $b['date'] ) {
				return strcmp( $b['date'], $a['date'] );
			}
			if ( $a['time'] !== $b['time'] ) {
				return strcmp( $a['time'], $b['time'] );
			}
			return $a['id'] - $b['id'];
		}
	);
	return $entries;
}

/**
 * Returns published entries dated between two dates, inclusive, sorted with
 * ccd_ls_sort_entries().
 *
 * @param string $from  First date, Y-m-d.
 * @param string $to    Last date, Y-m-d.
 * @return array[] See ccd_ls_entry_data().
 */
function ccd_ls_get_entries_between( $from, $to ) {
	$posts = get_posts(
		array(
			'post_type'        => CCD_LS_POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 100,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => CCD_LS_META_DATE,
					'value'   => array( $from, $to ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
			),
		)
	);
	return ccd_ls_sort_entries( array_filter( array_map( 'ccd_ls_entry_data', $posts ) ) );
}

/**
 * The entries the page shows today: dated from 13 days ago to today.
 *
 * @param string $today Today, Y-m-d. Defaults to the real today.
 * @return array[] See ccd_ls_entry_data().
 */
function ccd_ls_get_shown_entries( $today = '' ) {
	$today = $today ? $today : ccd_ls_today();
	return ccd_ls_get_entries_between( ccd_ls_window_start( $today ), $today );
}

/**
 * Returns the most recent published entries, newest first. Future dates
 * are included.
 *
 * @param int $limit How many to return.
 * @return array[] See ccd_ls_entry_data().
 */
function ccd_ls_get_recent_entries( $limit ) {
	$posts = get_posts(
		array(
			'post_type'        => CCD_LS_POST_TYPE,
			'post_status'      => 'publish',
			'meta_key'         => CCD_LS_META_DATE, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'          => array(
				'meta_value' => 'DESC',
				'ID'         => 'DESC',
			),
			'posts_per_page'   => max( 1, (int) $limit ),
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	return ccd_ls_sort_entries( array_filter( array_map( 'ccd_ls_entry_data', $posts ) ) );
}
