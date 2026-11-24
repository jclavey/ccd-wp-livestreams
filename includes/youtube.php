<?php
/**
 * YouTube helpers: links, thumbnails, and reading a playlist.
 *
 * Service livestreams are usually unlisted, and YouTube's RSS feeds leave
 * unlisted videos out. The public playlist page lists them, so the plugin
 * reads the page's ytInitialData JSON. That isn't an official API: if
 * YouTube changes the page, ccd_ls_fetch_playlist() returns an error and
 * entries can still be added by hand.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gets the video ID from a YouTube link or a bare ID.
 *
 * Accepts watch?v=, /live/, /embed/, /shorts/ and youtu.be links, with or
 * without extra query args such as &list=.
 *
 * @param string $input A link or ID.
 * @return string|false The 11-character video ID, or false.
 */
function ccd_ls_parse_video_id( $input ) {
	$input = trim( (string) $input );
	if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $input ) ) {
		return $input;
	}
	if ( '' === $input ) {
		return false;
	}
	if ( ! preg_match( '#^https?://#i', $input ) ) {
		$input = 'https://' . $input;
	}
	$parts = wp_parse_url( $input );
	if ( empty( $parts['host'] ) ) {
		return false;
	}
	$host = strtolower( preg_replace( '/^(www\.|m\.|music\.)/i', '', $parts['host'] ) );
	$path = isset( $parts['path'] ) ? $parts['path'] : '';

	$candidate = '';
	if ( 'youtu.be' === $host ) {
		$candidate = ltrim( $path, '/' );
	} elseif ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com' ), true ) ) {
		if ( preg_match( '#^/(?:live|embed|shorts|v)/([^/?]+)#', $path, $m ) ) {
			$candidate = $m[1];
		} elseif ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$candidate = isset( $query['v'] ) ? (string) $query['v'] : '';
		}
	}
	return preg_match( '/^[A-Za-z0-9_-]{11}$/', $candidate ) ? $candidate : false;
}

/**
 * Gets the playlist ID from a YouTube link or a bare ID.
 *
 * @param string $input A link containing list=, or a playlist ID.
 * @return string|false
 */
function ccd_ls_parse_playlist_id( $input ) {
	$input = trim( (string) $input );
	if ( preg_match( '/^[A-Za-z0-9_-]{12,64}$/', $input ) ) {
		return $input;
	}
	if ( preg_match( '/[?&]list=([A-Za-z0-9_-]{12,64})/', $input, $m ) ) {
		return $m[1];
	}
	return false;
}

/**
 * The link the page uses for a video.
 *
 * @param string $video_id The video ID.
 * @return string
 */
function ccd_ls_watch_url( $video_id ) {
	return 'https://www.youtube.com/live/' . rawurlencode( $video_id );
}

/**
 * A YouTube thumbnail URL.
 *
 * @param string $video_id The video ID.
 * @param string $size     maxresdefault (1280×720) or hqdefault (480×360).
 * @return string
 */
function ccd_ls_thumbnail_url( $video_id, $size = 'maxresdefault' ) {
	return 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/' . $size . '.jpg';
}

/**
 * Picks the best thumbnail for a video: maxresdefault if YouTube has one,
 * otherwise hqdefault (which every video has).
 *
 * @param string $video_id The video ID.
 * @return string The thumbnail URL.
 */
function ccd_ls_find_thumbnail( $video_id ) {
	$maxres = ccd_ls_thumbnail_url( $video_id, 'maxresdefault' );

	/**
	 * Short-circuits the thumbnail check, e.g. in tests.
	 *
	 * @param string|null $url      A thumbnail URL to use, or null to check.
	 * @param string      $video_id The video ID.
	 */
	$pre = apply_filters( 'ccd_ls_pre_find_thumbnail', null, $video_id );
	if ( null !== $pre ) {
		return (string) $pre;
	}

	$response = wp_remote_head( $maxres, array( 'timeout' => 5 ) );
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		return $maxres;
	}
	return ccd_ls_thumbnail_url( $video_id, 'hqdefault' );
}

/**
 * Reads a date and time from a stream title such as "22 Nov 11.15am",
 * "29 December 6.15pm" or "Sunday 3rd Jan 2027 10:30 am".
 *
 * @param string $title    The video title.
 * @param string $ref_date A date near the stream, Y-m-d, used to choose the
 *                         year when the title has none: the scheduled date
 *                         for an upcoming stream, otherwise today.
 * @return array{date:string,time:string} The date (Y-m-d) and time (H:i).
 *         Either is '' if it couldn't be read.
 */
function ccd_ls_parse_title( $title, $ref_date ) {
	$title  = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
	$result = array(
		'date' => '',
		'time' => '',
	);

	$months = array( 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' );
	if ( preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:,?\s+(\d{4}))?/i', $title, $m ) ) {
		$day   = (int) $m[1];
		$month = array_search( strtolower( $m[2] ), $months, true ) + 1;
		$year  = ! empty( $m[3] ) ? (int) $m[3] : ccd_ls_nearest_year( $month, $day, $ref_date );
		if ( $year && checkdate( $month, $day, $year ) ) {
			$result['date'] = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}
	}

	if ( preg_match( '/\b(\d{1,2})(?:[.:](\d{2}))?\s*(am|pm)\b/i', $title, $m ) ) {
		$hour = (int) $m[1];
		$min  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		if ( $hour >= 1 && $hour <= 12 && $min < 60 ) {
			$hour           = ( $hour % 12 ) + ( 'pm' === strtolower( $m[3] ) ? 12 : 0 );
			$result['time'] = sprintf( '%02d:%02d', $hour, $min );
		}
	} elseif ( preg_match( '/\b([01]?\d|2[0-3])[:.]([0-5]\d)\b/', $title, $m ) ) {
		$result['time'] = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	return $result;
}

/**
 * Chooses the year that puts a day and month closest to a reference date,
 * so "3 Jan" read in late December is next January.
 *
 * @param int    $month    1-12.
 * @param int    $day      1-31.
 * @param string $ref_date Y-m-d.
 * @return int The year, or 0 if the day doesn't exist near the reference.
 */
function ccd_ls_nearest_year( $month, $day, $ref_date ) {
	$ref = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ref_date );
	if ( ! $ref ) {
		return 0;
	}
	$ref_year = (int) $ref->format( 'Y' );
	$best     = 0;
	$best_gap = PHP_INT_MAX;
	foreach ( array( $ref_year - 1, $ref_year, $ref_year + 1 ) as $year ) {
		if ( ! checkdate( $month, $day, $year ) ) {
			continue;
		}
		$gap = abs( DateTimeImmutable::createFromFormat( '!Y-n-j', "$year-$month-$day" )->getTimestamp() - $ref->getTimestamp() );
		if ( $gap < $best_gap ) {
			$best     = $year;
			$best_gap = $gap;
		}
	}
	return $best;
}

/**
 * Reads the videos from a playlist page's HTML.
 *
 * Handles the current lockupViewModel layout and the older
 * playlistVideoRenderer one.
 *
 * @param string $html The playlist page.
 * @return array[]|WP_Error A list of videos in playlist order, each with
 *         video_id, title, status ('upcoming', 'live' or 'streamed'),
 *         scheduled_year (int, 0 if unknown) and days_ago (roughly how long
 *         ago a streamed video was streamed; null if unknown).
 */
function ccd_ls_parse_playlist_html( $html ) {
	$pos = strpos( (string) $html, 'ytInitialData' );
	if ( false === $pos ) {
		return new WP_Error( 'playlist_unreadable', __( 'YouTube returned a page without playlist data.', 'ccd-livestreams' ) );
	}
	$start = strpos( $html, '{', $pos );
	$json  = false !== $start ? ccd_ls_extract_json_object( $html, $start ) : '';
	$data  = '' !== $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'playlist_unreadable', __( 'The playlist data could not be read.', 'ccd-livestreams' ) );
	}

	$videos = array();
	ccd_ls_collect_videos( $data, $videos );
	return array_values( $videos );
}

/**
 * Returns the JSON object starting at $start, by matching braces outside
 * strings.
 *
 * @param string $text  The text.
 * @param int    $start The offset of the opening brace.
 * @return string The object, or '' if it isn't closed.
 */
function ccd_ls_extract_json_object( $text, $start ) {
	$depth  = 0;
	$in_str = false;
	$len    = strlen( $text );
	for ( $i = $start; $i < $len; $i++ ) {
		$c = $text[ $i ];
		if ( $in_str ) {
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c ) {
				$in_str = false;
			}
		} elseif ( '"' === $c ) {
			$in_str = true;
		} elseif ( '{' === $c ) {
			++$depth;
		} elseif ( '}' === $c ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $text, $start, $i - $start + 1 );
			}
		}
	}
	return '';
}

/**
 * Walks ytInitialData and collects the playlist's videos, keyed by ID.
 *
 * @param mixed $node   The current node.
 * @param array $videos The videos found so far.
 */
function ccd_ls_collect_videos( $node, &$videos ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	if ( isset( $node['lockupViewModel']['contentId'] ) ) {
		$lockup = $node['lockupViewModel'];
		$meta   = isset( $lockup['metadata']['lockupMetadataViewModel'] ) ? $lockup['metadata']['lockupMetadataViewModel'] : array();
		$title  = isset( $meta['title']['content'] ) ? (string) $meta['title']['content'] : '';
		$parts  = array();
		if ( isset( $meta['metadata']['contentMetadataViewModel']['metadataRows'] ) ) {
			foreach ( (array) $meta['metadata']['contentMetadataViewModel']['metadataRows'] as $row ) {
				foreach ( isset( $row['metadataParts'] ) ? (array) $row['metadataParts'] : array() as $part ) {
					if ( isset( $part['text']['content'] ) ) {
						$parts[] = (string) $part['text']['content'];
					}
				}
			}
		}
		$is_video = ! isset( $lockup['contentType'] ) || false !== strpos( (string) $lockup['contentType'], 'VIDEO' );
		$id       = (string) $lockup['contentId'];
		if ( $is_video && preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) && ! isset( $videos[ $id ] ) ) {
			$videos[ $id ] = ccd_ls_video_from_text( $id, $title, implode( ' · ', $parts ) );
		}
		return;
	}

	if ( isset( $node['playlistVideoRenderer']['videoId'] ) ) {
		$r     = $node['playlistVideoRenderer'];
		$id    = (string) $r['videoId'];
		$title = '';
		if ( isset( $r['title']['runs'][0]['text'] ) ) {
			$title = (string) $r['title']['runs'][0]['text'];
		} elseif ( isset( $r['title']['simpleText'] ) ) {
			$title = (string) $r['title']['simpleText'];
		}
		$text  = '';
		if ( isset( $r['videoInfo']['runs'] ) ) {
			$text = implode( '', wp_list_pluck( (array) $r['videoInfo']['runs'], 'text' ) );
		}
		$video = ccd_ls_video_from_text( $id, $title, $text );
		if ( isset( $r['upcomingEventData']['startTime'] ) ) {
			$video['days_ago']       = null;
			$video['status']         = 'upcoming';
			$video['scheduled_year'] = (int) gmdate( 'Y', (int) $r['upcomingEventData']['startTime'] );
		}
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) && ! isset( $videos[ $id ] ) ) {
			$videos[ $id ] = $video;
		}
		return;
	}

	foreach ( $node as $child ) {
		if ( is_array( $child ) ) {
			ccd_ls_collect_videos( $child, $videos );
		}
	}
}

/**
 * Builds a video record from its ID, title and metadata text.
 *
 * @param string $id    The video ID.
 * @param string $title The title.
 * @param string $text  Metadata text, e.g. "Scheduled for 29/11/2026, 03:15".
 * @return array
 */
function ccd_ls_video_from_text( $id, $title, $text ) {
	$status = 'streamed';
	$year   = 0;
	if ( preg_match( '/\b(scheduled|premieres?|waiting)\b/i', $text ) ) {
		$status = 'upcoming';
		if ( preg_match( '/\b(2\d{3})\b/', $text, $m ) ) {
			$year = (int) $m[1];
		}
	} elseif ( preg_match( '/\b(watching|live now)\b/i', $text ) ) {
		$status = 'live';
	}
	return array(
		'video_id'       => $id,
		'title'          => html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ),
		'status'         => $status,
		'scheduled_year' => $year,
		'days_ago'       => 'streamed' === $status ? ccd_ls_parse_age( $text ) : null,
	);
}

/**
 * Reads roughly how many days ago a video was streamed from text such as
 * "Streamed 8 days ago", "Streamed 2 wk ago" or "Streamed 1 yr ago".
 *
 * @param string $text The metadata text.
 * @return int|null Days, or null if the text has no age.
 */
function ccd_ls_parse_age( $text ) {
	if ( ! preg_match( '/\b(\d+)\s*(seconds?|secs?|minutes?|mins?|hours?|hrs?|days?|weeks?|wks?|months?|mos?|years?|yrs?)\s+ago\b/i', (string) $text, $m ) ) {
		return null;
	}
	$units = array(
		's'  => 0,
		'mi' => 0,
		'h'  => 0,
		'd'  => 1,
		'w'  => 7,
		'mo' => 30,
		'y'  => 365,
	);
	$unit  = strtolower( $m[2] );
	$key   = 0 === strpos( $unit, 'mo' ) ? 'mo' : ( 0 === strpos( $unit, 'mi' ) ? 'mi' : $unit[0] );
	return (int) $m[1] * $units[ $key ];
}

/**
 * Fetches a playlist's videos, cached for 15 minutes.
 *
 * @param string $playlist_id The playlist ID.
 * @param bool   $force       Skip the cache.
 * @return array[]|WP_Error See ccd_ls_parse_playlist_html().
 */
function ccd_ls_fetch_playlist( $playlist_id, $force = false ) {
	$key = 'ccd_ls_pl_' . md5( $playlist_id );
	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	/**
	 * Short-circuits fetching a playlist page, e.g. in tests.
	 *
	 * @param string|null $html        The page HTML to use, or null to fetch.
	 * @param string      $playlist_id The playlist ID.
	 */
	$html = apply_filters( 'ccd_ls_pre_fetch_playlist', null, $playlist_id );

	if ( null === $html ) {
		$response = wp_remote_get(
			'https://www.youtube.com/playlist?list=' . rawurlencode( $playlist_id ) . '&hl=en-GB&persist_hl=1',
			array(
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
				'headers'    => array(
					'Accept-Language' => 'en-GB,en;q=0.9',
					// Skips the EU cookie consent page.
					'Cookie'          => 'SOCS=CAI; CONSENT=YES+1',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'playlist_fetch', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'playlist_fetch', sprintf( __( 'YouTube returned HTTP %d.', 'ccd-livestreams' ), $code ) );
		}
		$html = wp_remote_retrieve_body( $response );
	}

	$videos = ccd_ls_parse_playlist_html( (string) $html );
	if ( is_wp_error( $videos ) ) {
		return $videos;
	}
	if ( ! $videos ) {
		return new WP_Error( 'playlist_empty', __( 'No videos were found in the playlist. Check that it is public or unlisted, not private.', 'ccd-livestreams' ) );
	}
	set_transient( $key, $videos, 15 * MINUTE_IN_SECONDS );
	return $videos;
}
