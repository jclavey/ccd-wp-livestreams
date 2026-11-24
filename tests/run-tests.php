<?php
/**
 * PASS/FAIL checks for CCD Livestreams. Run with WP-CLI on a test site:
 *
 *   wp eval-file wp-content/plugins/ccd-livestreams/tests/run-tests.php
 *
 * It creates its own entries, dated in 2099 so they don't overlap real
 * ones, and deletes them at the end. YouTube is never contacted: playlist
 * pages and thumbnail checks are stubbed with filters. The settings are
 * restored afterwards.
 *
 * Set CCD_LS_FIXTURE_DIR to a folder of saved playlist pages (*.html) to
 * also check the parser against real YouTube pages.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

// WP-CLI runs this file inside a function, so shared state lives in $GLOBALS.
$GLOBALS['ccd_ls_failures'] = 0;

/**
 * Prints one result.
 *
 * @param string $name What was checked.
 * @param bool   $ok   Whether it passed.
 * @param mixed  $got  What was actually returned, shown on failure.
 */
function ccd_ls_test( $name, $ok, $got = null ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( ! $ok && null !== $got ) {
		echo ' — got ' . var_export( $got, true ); // phpcs:ignore
	}
	echo "\n";
	if ( ! $ok ) {
		++$GLOBALS['ccd_ls_failures'];
	}
}

/**
 * Builds a fake playlist page in YouTube's current (lockupViewModel) layout.
 *
 * @param array[] $videos Each: id, title, meta (metadata text).
 * @return string HTML.
 */
function ccd_ls_test_playlist_page( $videos ) {
	$items = array();
	foreach ( $videos as $v ) {
		$items[] = array(
			'lockupViewModel' => array(
				'contentId'   => $v['id'],
				'contentType' => 'LOCKUP_CONTENT_TYPE_VIDEO',
				'metadata'    => array(
					'lockupMetadataViewModel' => array(
						'title'    => array( 'content' => $v['title'] ),
						'metadata' => array(
							'contentMetadataViewModel' => array(
								'metadataRows' => array(
									array( 'metadataParts' => array( array( 'text' => array( 'content' => 'A Channel' ) ) ) ),
									array( 'metadataParts' => array( array( 'text' => array( 'content' => $v['meta'] ) ) ) ),
								),
							),
						),
					),
				),
			),
		);
	}
	$data = array( 'contents' => array( 'twoColumnBrowseResultsRenderer' => array( 'tabs' => array( array( 'content' => $items ) ) ) ) );
	return '<html><script>var ytInitialData = ' . wp_json_encode( $data ) . ';</script><script>var other = {"a":"}"};</script></html>';
}

/**
 * Calls ccd_ls_add_rows(), which is only loaded in the admin.
 *
 * @param array $rows The rows.
 * @return array|null
 */
function ccd_ls_add_rows_for_test( $rows ) {
	if ( ! function_exists( 'ccd_ls_add_rows' ) ) {
		require_once CCD_LS_DIR . 'includes/admin.php';
	}
	return ccd_ls_add_rows( $rows );
}

wp_set_current_user( 1 );

// Never contact YouTube for thumbnails.
add_filter(
	'ccd_ls_pre_find_thumbnail',
	static function ( $pre, $video_id ) {
		return 'NOMAXRES123' === $video_id ? ccd_ls_thumbnail_url( $video_id, 'hqdefault' ) : ccd_ls_thumbnail_url( $video_id );
	},
	10,
	2
);

// --- Links -------------------------------------------------------------
$ccd_ls_id = 'Xy12ab-CD_e';
foreach ( array(
	'Xy12ab-CD_e',
	'https://www.youtube.com/live/Xy12ab-CD_e',
	'https://www.youtube.com/live/Xy12ab-CD_e?si=abc',
	'https://www.youtube.com/watch?v=Xy12ab-CD_e&list=PLabcdefghijkl',
	'https://m.youtube.com/watch?feature=share&v=Xy12ab-CD_e',
	'https://youtu.be/Xy12ab-CD_e?t=10',
	'youtube.com/embed/Xy12ab-CD_e',
	' https://www.youtube.com/shorts/Xy12ab-CD_e ',
) as $ccd_ls_input ) {
	ccd_ls_test( 'video ID from ' . trim( $ccd_ls_input ), $ccd_ls_id === ccd_ls_parse_video_id( $ccd_ls_input ), ccd_ls_parse_video_id( $ccd_ls_input ) );
}
foreach ( array( '', 'hello', 'https://example.com/watch?v=Xy12ab-CD_e', 'https://www.youtube.com/watch?v=short', 'https://www.youtube.com/playlist?list=PLabcdefghijkl' ) as $ccd_ls_input ) {
	ccd_ls_test( 'no video ID from "' . $ccd_ls_input . '"', false === ccd_ls_parse_video_id( $ccd_ls_input ) );
}
ccd_ls_test( 'playlist ID from a watch link', 'PLexamplePlaylist0123456789_-ab' === ccd_ls_parse_playlist_id( 'https://www.youtube.com/watch?v=ZZZZZZZZZZ1&list=PLexamplePlaylist0123456789_-ab' ) );
ccd_ls_test( 'playlist ID from a playlist link', 'PLabcdefghijkl' === ccd_ls_parse_playlist_id( 'https://www.youtube.com/playlist?list=PLabcdefghijkl' ) );
ccd_ls_test( 'bare playlist ID', 'PLabcdefghijkl' === ccd_ls_parse_playlist_id( 'PLabcdefghijkl' ) );
ccd_ls_test( 'no playlist ID from a video link', false === ccd_ls_parse_playlist_id( 'https://youtu.be/Xy12ab-CD_e' ) );
ccd_ls_test( 'watch URL uses /live/', 'https://www.youtube.com/live/Xy12ab-CD_e' === ccd_ls_watch_url( $ccd_ls_id ) );

// --- Titles ------------------------------------------------------------
$ccd_ls_titles = array(
	array( '22 Nov 11.15am', '2026-11-23', '2026-11-22', '11:15' ),
	array( '22 Nov 6.15pm', '2026-11-23', '2026-11-22', '18:15' ),
	array( '29 December 6.15pm', '2026-11-23', '2026-12-29', '18:15' ),
	array( '3 Jan 11.15am', '2026-12-20', '2027-01-03', '11:15' ),
	array( '28 Dec 10.15am', '2027-01-10', '2026-12-28', '10:15' ),
	array( 'Sunday 3rd Jan 2027 10:30 am', '2026-06-01', '2027-01-03', '10:30' ),
	array( '25 Dec 12pm', '2026-12-01', '2026-12-25', '12:00' ),
	array( '1 Jan 12.30am', '2026-12-31', '2027-01-01', '00:30' ),
	array( 'Evening service 7 Sept 18:15', '2026-09-01', '2026-09-07', '18:15' ),
	array( 'Carol service', '2026-12-01', '', '' ),
	array( '30 Feb 11.15am', '2026-02-01', '', '11:15' ),
);
foreach ( $ccd_ls_titles as $ccd_ls_t ) {
	$ccd_ls_got = ccd_ls_parse_title( $ccd_ls_t[0], $ccd_ls_t[1] );
	ccd_ls_test(
		sprintf( 'title "%s" near %s reads as %s %s', $ccd_ls_t[0], $ccd_ls_t[1], $ccd_ls_t[2], $ccd_ls_t[3] ),
		$ccd_ls_got === array( 'date' => $ccd_ls_t[2], 'time' => $ccd_ls_t[3] ),
		$ccd_ls_got
	);
}

// --- Times and captions -------------------------------------------------
ccd_ls_test( 'time 18:15 shows as 6:15', '6:15' === ccd_ls_format_time( '18:15' ) );
ccd_ls_test( 'time 11:15 shows as 11:15', '11:15' === ccd_ls_format_time( '11:15' ) );
ccd_ls_test( 'time 00:30 shows as 12:30', '12:30' === ccd_ls_format_time( '00:30' ) );
ccd_ls_test( 'time 9.30 is accepted as 09:30', '09:30' === ccd_ls_validate_time( '9.30' ) );
ccd_ls_test( 'time 24:00 rejected', false === ccd_ls_validate_time( '24:00' ) );
ccd_ls_test(
	'caption format',
	'22.11.26 – 6:15 Evening Service' === ccd_ls_caption(
		array(
			'date'  => '2026-11-22',
			'time'  => '18:15',
			'title' => 'Evening Service',
		)
	)
);

// --- Window ---------------------------------------------------------------
ccd_ls_test( 'window starts 13 days back', '2026-11-09' === ccd_ls_window_start( '2026-11-22' ) );
ccd_ls_test( 'upcoming Sunday from a Monday', '2026-11-29' === ccd_ls_upcoming_sunday( '2026-11-23' ) );
ccd_ls_test( 'upcoming Sunday on a Sunday is today', '2026-11-22' === ccd_ls_upcoming_sunday( '2026-11-22' ) );

// --- Playlist parsing --------------------------------------------------
$ccd_ls_page   = ccd_ls_test_playlist_page(
	array(
		array( 'id' => 'AAAAAAAAAA1', 'title' => '3 Jan 11.15am', 'meta' => 'Scheduled for 03/01/2100, 03:15' ),
		array( 'id' => 'AAAAAAAAAA2', 'title' => '25 Jan 11.15am', 'meta' => 'Scheduled for 25/01/2099, 03:15' ),
		array( 'id' => 'AAAAAAAAAA3', 'title' => '18 Jan 11.15am', 'meta' => '79 views · Streamed 1 day ago' ),
		array( 'id' => 'AAAAAAAAAA4', 'title' => '11 Jan 11.15am', 'meta' => 'Streamed 8 days ago' ),
		array( 'id' => 'AAAAAAAAAA5', 'title' => '4 Jan 11.15am', 'meta' => 'Streamed 2 weeks ago' ),
		array( 'id' => 'AAAAAAAAAA6', 'title' => 'Carol service', 'meta' => 'Streamed 1 month ago' ),
		array( 'id' => 'AAAAAAAAAA8', 'title' => '19 Jan 11.15am', 'meta' => '82 · Streamed 1 yr ago' ),
		array( 'id' => 'AAAAAAAAAA7', 'title' => 'Special &amp; live', 'meta' => '12 watching' ),
	)
);
$ccd_ls_videos = ccd_ls_parse_playlist_html( $ccd_ls_page );
ccd_ls_test( 'playlist page parses', is_array( $ccd_ls_videos ) && 8 === count( $ccd_ls_videos ), is_wp_error( $ccd_ls_videos ) ? $ccd_ls_videos->get_error_message() : count( $ccd_ls_videos ) );
ccd_ls_test( 'upcoming video has status and year', 'upcoming' === $ccd_ls_videos[0]['status'] && 2100 === $ccd_ls_videos[0]['scheduled_year'] );
ccd_ls_test( 'streamed video has status', 'streamed' === $ccd_ls_videos[2]['status'] && 0 === $ccd_ls_videos[2]['scheduled_year'] );
ccd_ls_test( 'live video has status, title decoded', 'live' === $ccd_ls_videos[7]['status'] && 'Special & live' === $ccd_ls_videos[7]['title'], $ccd_ls_videos[7] );
ccd_ls_test( 'streamed age read in days', 8 === $ccd_ls_videos[3]['days_ago'] && 365 === $ccd_ls_videos[6]['days_ago'] && null === $ccd_ls_videos[0]['days_ago'] );
foreach ( array( 'Streamed 18 hr ago' => 0, 'Streamed 1 day ago' => 1, 'Streamed 2 wk ago' => 14, 'Streamed 3 weeks ago' => 21, 'Streamed 2 mo ago' => 60, 'Streamed 1 yr ago' => 365, '79 views' => null ) as $ccd_ls_text => $ccd_ls_days ) {
	ccd_ls_test( 'age of "' . $ccd_ls_text . '"', $ccd_ls_days === ccd_ls_parse_age( $ccd_ls_text ), ccd_ls_parse_age( $ccd_ls_text ) );
}
ccd_ls_test( 'page without data is an error', is_wp_error( ccd_ls_parse_playlist_html( '<html>consent</html>' ) ) );

$ccd_ls_old = ccd_ls_parse_playlist_html(
	'<script>var ytInitialData = ' . wp_json_encode(
		array(
			'x' => array(
				array(
					'playlistVideoRenderer' => array(
						'videoId'           => 'BBBBBBBBBB1',
						'title'             => array( 'runs' => array( array( 'text' => '6 Dec 6.15pm' ) ) ),
						'upcomingEventData' => array( 'startTime' => (string) gmmktime( 18, 15, 0, 12, 6, 2026 ) ),
					),
				),
			),
		)
	) . ';</script>'
);
ccd_ls_test( 'older playlistVideoRenderer layout parses', is_array( $ccd_ls_old ) && 1 === count( $ccd_ls_old ) && 'upcoming' === $ccd_ls_old[0]['status'] && 2026 === $ccd_ls_old[0]['scheduled_year'], $ccd_ls_old );

$ccd_ls_fixtures = getenv( 'CCD_LS_FIXTURE_DIR' );
if ( $ccd_ls_fixtures && is_dir( $ccd_ls_fixtures ) ) {
	foreach ( glob( rtrim( $ccd_ls_fixtures, '/' ) . '/*.html' ) as $ccd_ls_file ) {
		$ccd_ls_real = ccd_ls_parse_playlist_html( (string) file_get_contents( $ccd_ls_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ccd_ls_read = is_array( $ccd_ls_real ) ? array_filter(
			$ccd_ls_real,
			static function ( $v ) {
				return '' !== ccd_ls_parse_title( $v['title'], ccd_ls_today() )['date'];
			}
		) : array();
		ccd_ls_test(
			'real page ' . basename( $ccd_ls_file ) . ' parses, most titles have a date',
			is_array( $ccd_ls_real ) && count( $ccd_ls_real ) > 10 && count( $ccd_ls_read ) >= 0.9 * count( $ccd_ls_real ),
			is_array( $ccd_ls_real ) ? count( $ccd_ls_read ) . '/' . count( $ccd_ls_real ) : $ccd_ls_real
		);
	}
}

// --- Saving --------------------------------------------------------------
$ccd_ls_saved_settings = get_option( 'ccd_ls_settings', null );
$ccd_ls_ids            = array();

$ccd_ls_base = array(
	'date'  => '2099-01-18',
	'time'  => '11:15',
	'title' => 'Morning Service',
	'video' => 'https://www.youtube.com/live/AAAAAAAAAA3',
);
foreach ( array(
	'invalid_date'  => array( 'date' => '2099-02-30' ),
	'invalid_time'  => array( 'time' => '25:00' ),
	'invalid_title' => array( 'title' => '  ' ),
	'invalid_video' => array( 'video' => 'https://example.com/' ),
	'invalid_image' => array( 'image_id' => 999999 ),
) as $ccd_ls_code => $ccd_ls_change ) {
	$ccd_ls_r = ccd_ls_save_entry( array_merge( $ccd_ls_base, $ccd_ls_change ) );
	ccd_ls_test( 'rejects ' . $ccd_ls_code, is_wp_error( $ccd_ls_r ) && $ccd_ls_code === $ccd_ls_r->get_error_code(), is_wp_error( $ccd_ls_r ) ? $ccd_ls_r->get_error_code() : $ccd_ls_r );
	if ( ! is_wp_error( $ccd_ls_r ) ) {
		$ccd_ls_ids[] = $ccd_ls_r;
	}
}

$ccd_ls_entries = array(
	'm18' => array( '2099-01-18', '11:15', 'Morning Service', 'AAAAAAAAAA3' ),
	'e18' => array( '2099-01-18', '18:15', 'Evening Service', 'https://youtu.be/CCCCCCCCCC3' ),
	'm11' => array( '2099-01-11', '11:15', 'Morning Service', 'AAAAAAAAAA4' ),
	'e11' => array( '2099-01-11', '18:15', 'Evening Service', 'CCCCCCCCCC4' ),
	'm04' => array( '2099-01-04', '11:15', 'Morning Service', 'AAAAAAAAAA5' ),
	'w14' => array( '2099-01-14', '19:30', 'Midweek <b>Communion</b>', 'NOMAXRES123' ),
	'f25' => array( '2099-01-25', '11:15', 'Morning Service', 'EEEEEEEEEE1' ),
);
foreach ( $ccd_ls_entries as $ccd_ls_key => $ccd_ls_e ) {
	$ccd_ls_r = ccd_ls_save_entry(
		array(
			'date'  => $ccd_ls_e[0],
			'time'  => $ccd_ls_e[1],
			'title' => $ccd_ls_e[2],
			'video' => $ccd_ls_e[3],
		)
	);
	ccd_ls_test( 'saves entry ' . $ccd_ls_key, is_int( $ccd_ls_r ), is_wp_error( $ccd_ls_r ) ? $ccd_ls_r->get_error_code() : $ccd_ls_r );
	$ccd_ls_ids[ $ccd_ls_key ] = $ccd_ls_r;
}

$ccd_ls_dup = ccd_ls_save_entry( array_merge( $ccd_ls_base, array( 'video' => 'https://www.youtube.com/watch?v=AAAAAAAAAA3' ) ) );
ccd_ls_test( 'same video twice is a duplicate', is_wp_error( $ccd_ls_dup ) && 'duplicate' === $ccd_ls_dup->get_error_code() && $ccd_ls_ids['m18'] === $ccd_ls_dup->get_error_data() );
ccd_ls_test( 'same date twice is allowed', is_int( $ccd_ls_ids['e18'] ) );

$ccd_ls_data = ccd_ls_entry_data( $ccd_ls_ids['e18'] );
ccd_ls_test( 'video stored as ID', 'CCCCCCCCCC3' === $ccd_ls_data['video_id'] );
ccd_ls_test( 'maxres thumbnail stored', ccd_ls_thumbnail_url( 'CCCCCCCCCC3' ) === $ccd_ls_data['thumb'] );
ccd_ls_test( 'hqdefault fallback stored', false !== strpos( ccd_ls_entry_data( $ccd_ls_ids['w14'] )['thumb'], 'hqdefault' ) );
ccd_ls_test( 'title is sanitized', 'Midweek Communion' === ccd_ls_entry_data( $ccd_ls_ids['w14'] )['title'], ccd_ls_entry_data( $ccd_ls_ids['w14'] )['title'] );

$ccd_ls_r = ccd_ls_save_entry( array_merge( $ccd_ls_base, array( 'title' => 'Remembrance Service' ) ), $ccd_ls_ids['m18'] );
ccd_ls_test( 'editing keeps its own video', $ccd_ls_ids['m18'] === $ccd_ls_r && 'Remembrance Service' === get_the_title( $ccd_ls_ids['m18'] ) );
ccd_ls_save_entry( $ccd_ls_base, $ccd_ls_ids['m18'] );

// --- Window and order -----------------------------------------------------
$ccd_ls_shown = wp_list_pluck( ccd_ls_get_shown_entries( '2099-01-18' ), 'id' );
$ccd_ls_want  = array( $ccd_ls_ids['m18'], $ccd_ls_ids['e18'], $ccd_ls_ids['w14'], $ccd_ls_ids['m11'], $ccd_ls_ids['e11'] );
ccd_ls_test( 'shown on Sunday 18 Jan: 18th, 14th, 11th in order; not the 4th or the 25th', $ccd_ls_want === $ccd_ls_shown, $ccd_ls_shown );
$ccd_ls_shown = wp_list_pluck( ccd_ls_get_shown_entries( '2099-01-17' ), 'id' );
ccd_ls_test( 'on Saturday 17 Jan the 4th still shows and the 18th does not', in_array( $ccd_ls_ids['m04'], $ccd_ls_shown, true ) && ! in_array( $ccd_ls_ids['m18'], $ccd_ls_shown, true ) );

// --- Suggestions ------------------------------------------------------------
update_option(
	'ccd_ls_settings',
	array(
		'playlists'     => array(
			array(
				'id'    => 'PLtestmorning1',
				'title' => 'Morning Service',
			),
		),
		'empty_message' => 'Nothing yet',
	)
);
$ccd_ls_stub = static function ( $pre, $playlist_id ) use ( $ccd_ls_page ) {
	return 'PLtestmorning1' === $playlist_id ? $ccd_ls_page : $pre;
};
add_filter( 'ccd_ls_pre_fetch_playlist', $ccd_ls_stub, 10, 2 );
wp_trash_post( $ccd_ls_ids['m11'] );
$ccd_ls_sugg = ccd_ls_get_suggestions( true, '2099-01-19' );
$ccd_ls_vids = wp_list_pluck( $ccd_ls_sugg['items'], 'video_id' );
ccd_ls_test( 'suggests the trashed 11 Jan, the live stream (as today) and the 25 Jan upcoming stream, oldest first', array( 'AAAAAAAAAA4', 'AAAAAAAAAA7', 'AAAAAAAAAA2' ) === $ccd_ls_vids, $ccd_ls_vids );
ccd_ls_test( 'suggestion has date, time and default title', isset( $ccd_ls_sugg['items'][2] ) && '2099-01-25' === $ccd_ls_sugg['items'][2]['date'] && '11:15' === $ccd_ls_sugg['items'][2]['time'] && 'Morning Service' === $ccd_ls_sugg['items'][2]['title'] );
ccd_ls_test( 'last year\'s "19 Jan" stream, streamed 1 yr ago, is not suggested for today', ! in_array( 'AAAAAAAAAA8', $ccd_ls_vids, true ) );
ccd_ls_test( 'live suggestion is dated today', isset( $ccd_ls_sugg['items'][1] ) && '2099-01-19' === $ccd_ls_sugg['items'][1]['date'] );
wp_untrash_post( $ccd_ls_ids['m11'] );
ccd_ls_test( 'untrashed entry is published again', 'publish' === get_post_status( $ccd_ls_ids['m11'] ) );

$ccd_ls_sugg = ccd_ls_get_suggestions( true, '2099-12-31' );
ccd_ls_test( 'on 31 Dec the 3 Jan stream is suggested for next year', array( 'AAAAAAAAAA7', 'AAAAAAAAAA1' ) === wp_list_pluck( $ccd_ls_sugg['items'], 'video_id' ) && '2100-01-03' === $ccd_ls_sugg['items'][1]['date'], $ccd_ls_sugg['items'] );

remove_filter( 'ccd_ls_pre_fetch_playlist', $ccd_ls_stub, 10 );
add_filter(
	'ccd_ls_pre_fetch_playlist',
	static function () {
		return '<html>Before you continue to YouTube</html>';
	}
);
$ccd_ls_sugg = ccd_ls_get_suggestions( true, '2099-01-19' );
ccd_ls_test( 'unreadable playlist gives an error, not suggestions', ! $ccd_ls_sugg['items'] && 1 === count( $ccd_ls_sugg['errors'] ) );

// --- Adding rows ------------------------------------------------------------
$ccd_ls_rows = ccd_ls_add_rows_for_test(
	array(
		array( 'add' => '1', 'date' => '2099-01-21', 'time' => '19:30', 'title' => 'Prayer', 'video' => 'DDDDDDDDDD1' ),
		array( 'add' => '1', 'date' => '2099-01-21', 'time' => '', 'title' => 'No time', 'video' => 'DDDDDDDDDD2' ),
		array( 'date' => '2099-01-21', 'time' => '10:00', 'title' => 'Not ticked', 'video' => 'DDDDDDDDDD3' ),
		array( 'add' => '1', 'date' => '', 'time' => '', 'title' => '', 'video' => '' ),
		array( 'add' => '1', 'date' => '2099-01-21', 'time' => '10:00', 'title' => 'Dup', 'video' => 'AAAAAAAAAA3' ),
	)
);
if ( $ccd_ls_rows ) {
	ccd_ls_test( 'add rows: 1 added', 1 === count( $ccd_ls_rows['added'] ) );
	ccd_ls_test( 'add rows: 2 failed (no time, duplicate)', array( 'invalid_time', 'duplicate' ) === wp_list_pluck( $ccd_ls_rows['failed'], 'code' ), $ccd_ls_rows['failed'] );
	ccd_ls_test( 'add rows: duplicate points at the existing entry', $ccd_ls_ids['m18'] === $ccd_ls_rows['failed'][1]['existing'] );
	$ccd_ls_ids = array_merge( $ccd_ls_ids, $ccd_ls_rows['added'] );
}

// --- Rendering ----------------------------------------------------------------
if ( ccd_ls_kadence_available() ) {
	$ccd_ls_html = ccd_ls_render_grid( '2099-01-18' );
	ccd_ls_test( 'three Kadence rows for five entries', 3 === substr_count( $ccd_ls_html, 'wp-block-kadence-rowlayout' ), substr_count( $ccd_ls_html, 'wp-block-kadence-rowlayout' ) );
	ccd_ls_test( 'five Kadence images', 5 === substr_count( $ccd_ls_html, 'wp-block-kadence-image ' ) );
	ccd_ls_test( 'two-column rows', 3 === substr_count( $ccd_ls_html, 'kt-has-2-columns' ) );
	ccd_ls_test( 'Kadence row CSS with the 4rem column gap', false !== strpos( $ccd_ls_html, '.kb-row-layout-idccd-ls-row-1 > .kt-row-column-wrap{' ) && false !== strpos( $ccd_ls_html, 'column-gap:var(--global-kb-gap-lg, 4rem)' ) );
	ccd_ls_test( 'first caption is the morning of the 18th', false !== strpos( $ccd_ls_html, '<figcaption><a href="https://www.youtube.com/live/AAAAAAAAAA3" target="_blank" rel="noopener">18.01.99 – 11:15 Morning Service</a></figcaption>' ) );
	ccd_ls_test( 'evening caption uses 6:15', false !== strpos( $ccd_ls_html, '18.01.99 – 6:15 Evening Service' ) );
	ccd_ls_test( 'image links to the stream in a new tab', false !== strpos( $ccd_ls_html, '<a href="https://www.youtube.com/live/CCCCCCCCCC3" class="kb-advanced-image-link" target="_blank" rel="noopener noreferrer"><img src="https://i.ytimg.com/vi/CCCCCCCCCC3/maxresdefault.jpg" alt="18.01.99 – 6:15 Evening Service"' ) );
	ccd_ls_test( 'order: 18th morning, 18th evening, 14th, 11th morning, 11th evening', preg_match( '/AAAAAAAAAA3.*CCCCCCCCCC3.*NOMAXRES123.*AAAAAAAAAA4.*CCCCCCCCCC4/s', $ccd_ls_html ) );
	$ccd_ls_row2 = preg_match( '/kb-row-layout-idccd-ls-row-2(.*?)kb-row-layout-idccd-ls-row-3/s', $ccd_ls_html, $ccd_ls_m ) ? $ccd_ls_m[1] : '';
	ccd_ls_test( 'a day with one stream has a row of its own', false !== strpos( $ccd_ls_row2, 'kadence-columnccd-ls-col-3' ) && false === strpos( $ccd_ls_row2, 'ccd-ls-col-4' ) && false !== strpos( $ccd_ls_row2, 'NOMAXRES123' ) );
	ccd_ls_test( 'the next day starts the next row', 1 === preg_match( '/kb-row-layout-idccd-ls-row-3.*?kadence-columnccd-ls-col-4.*?AAAAAAAAAA4.*?kadence-columnccd-ls-col-5.*?CCCCCCCCCC4/s', $ccd_ls_html ) && false === strpos( $ccd_ls_html, 'ccd-ls-col-6' ) );
	$ccd_ls_grid = ccd_ls_grid_blocks(
		array_map(
			static function ( $date ) {
				return array(
					'id'       => 0,
					'date'     => $date,
					'time'     => '11:15',
					'title'    => 'Service',
					'video_id' => 'AAAAAAAAAA3',
					'url'      => ccd_ls_watch_url( 'AAAAAAAAAA3' ),
					'thumb'    => ccd_ls_thumbnail_url( 'AAAAAAAAAA3' ),
					'image_id' => 0,
				);
			},
			array( '2099-01-18', '2099-01-18', '2099-01-18', '2099-01-11' )
		)
	);
	ccd_ls_test( 'three streams on one day: 2 + 1, then the next day', array( 2, 1, 1 ) === array_map( static function ( $row ) { return count( $row['innerBlocks'] ); }, $ccd_ls_grid ) );
	ccd_ls_test( 'empty window shows the message', '<p class="ccd-ls-empty">Nothing yet</p>' === ccd_ls_render_grid( '2099-06-01' ) );
	ccd_ls_test( 'shortcode registered', shortcode_exists( 'ccd_livestreams' ) );
	ccd_ls_test( 'block registered', WP_Block_Type_Registry::get_instance()->is_registered( 'ccd-livestreams/grid' ) );
} else {
	ccd_ls_test( 'Kadence Blocks is active (needed for the render checks)', false );
}

// --- Clean up -------------------------------------------------------------------
foreach ( $ccd_ls_ids as $ccd_ls_id ) {
	if ( is_int( $ccd_ls_id ) ) {
		wp_delete_post( $ccd_ls_id, true );
	}
}
if ( null === $ccd_ls_saved_settings ) {
	delete_option( 'ccd_ls_settings' );
} else {
	update_option( 'ccd_ls_settings', $ccd_ls_saved_settings );
}

echo $GLOBALS['ccd_ls_failures'] ? "\n{$GLOBALS['ccd_ls_failures']} FAILED\n" : "\nAll passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput

