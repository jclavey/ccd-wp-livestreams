<?php
/**
 * Suggested entries, from the playlists in Settings.
 *
 * A suggestion is a playlist video that hasn't been added yet, dated from
 * 13 days ago (the start of the page's window) up to the upcoming Sunday.
 * The date and time are read from the video title; the title comes from
 * the playlist's default title.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the suggestions.
 *
 * @param bool   $force Skip the playlist cache.
 * @param string $today Today, Y-m-d. Defaults to the real today.
 * @return array{items:array[],errors:array[]} items: date, time, title,
 *         video_id, video_title, status, playlist (the playlist's default
 *         title). errors: playlist_id, message.
 */
function ccd_ls_get_suggestions( $force = false, $today = '' ) {
	$today    = $today ? $today : ccd_ls_today();
	$from     = ccd_ls_window_start( $today );
	$to       = ccd_ls_upcoming_sunday( $today );
	$existing = array_flip( ccd_ls_existing_video_ids() );
	$items    = array();
	$errors   = array();

	foreach ( ccd_ls_settings()['playlists'] as $playlist ) {
		$videos = ccd_ls_fetch_playlist( $playlist['id'], $force );
		if ( is_wp_error( $videos ) ) {
			$errors[] = array(
				'playlist_id' => $playlist['id'],
				'title'       => $playlist['title'],
				'message'     => $videos->get_error_message(),
			);
			continue;
		}

		foreach ( $videos as $video ) {
			if ( isset( $existing[ $video['video_id'] ] ) || isset( $items[ $video['video_id'] ] ) ) {
				continue;
			}
			// The year is the one nearest when YouTube says it was streamed
			// ("Streamed 1 yr ago"), or nearest today. An upcoming stream uses
			// the year YouTube gives for its scheduled date.
			$ref = $today;
			if ( null !== $video['days_ago'] ) {
				$ref = DateTimeImmutable::createFromFormat( '!Y-m-d', $today )->modify( '-' . (int) $video['days_ago'] . ' days' )->format( 'Y-m-d' );
			}
			$parsed = ccd_ls_parse_title( $video['title'], $ref );
			if ( $video['scheduled_year'] && $parsed['date'] && ! preg_match( '/\b2\d{3}\b/', $video['title'] ) ) {
				$parsed['date'] = ccd_ls_validate_date( $video['scheduled_year'] . substr( $parsed['date'], 4 ) ) ? $video['scheduled_year'] . substr( $parsed['date'], 4 ) : '';
			}

			// A title without a readable date can't be placed, so it is only
			// suggested while it's live (as today). Otherwise add it by hand.
			if ( '' === $parsed['date'] && 'live' === $video['status'] ) {
				$parsed['date'] = $today;
			}
			if ( '' === $parsed['date'] || $parsed['date'] < $from || $parsed['date'] > $to ) {
				continue;
			}
			// A stream that has already happened can't be dated in the future.
			if ( 'streamed' === $video['status'] && $parsed['date'] > $today ) {
				continue;
			}

			$items[ $video['video_id'] ] = array(
				'date'        => $parsed['date'],
				'time'        => $parsed['time'],
				'title'       => '' !== $playlist['title'] ? $playlist['title'] : $video['title'],
				'video_id'    => $video['video_id'],
				'video_title' => $video['title'],
				'status'      => $video['status'],
				'playlist'    => $playlist['title'],
			);
		}
	}

	$items = array_values( $items );
	usort(
		$items,
		static function ( $a, $b ) {
			// Oldest first, the order they happened.
			return strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] );
		}
	);

	return array(
		'items'  => $items,
		'errors' => $errors,
	);
}
