<?php
/**
 * Renders the shown entries as a grid of Kadence Blocks.
 *
 * Each pair of entries is a kadence/rowlayout with two equal columns, and
 * each entry is a kadence/column holding a kadence/image: the thumbnail,
 * linked to the stream, with a linked caption. The blocks go through
 * render_block(), so Kadence Blocks adds its own CSS exactly as it does
 * for blocks saved in a page.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Kadence Blocks is active and the blocks the grid uses exist.
 *
 * @return bool
 */
function ccd_ls_kadence_available() {
	$registry = WP_Block_Type_Registry::get_instance();
	return $registry->is_registered( 'kadence/rowlayout' )
		&& $registry->is_registered( 'kadence/column' )
		&& $registry->is_registered( 'kadence/image' );
}

/**
 * The attributes of each row. Filter `ccd_ls_row_attributes` to change the
 * gaps or spacing.
 *
 * @param int $row The row number, starting at 1.
 * @return array
 */
function ccd_ls_row_attributes( $row ) {
	$attributes = array(
		'uniqueID'     => 'ccd-ls-row-' . (int) $row,
		'columns'      => 2,
		'colLayout'    => 'equal',
		// 4rem between the columns, as on the original hand-built page.
		'columnGutter' => 'wider',
		'kbVersion'    => 2,
	);

	/**
	 * Filters the kadence/rowlayout attributes of a row.
	 *
	 * @param array $attributes The block attributes.
	 * @param int   $row        The row number, starting at 1.
	 */
	return (array) apply_filters( 'ccd_ls_row_attributes', $attributes, $row );
}

/**
 * The image for an entry: the override image from the Media Library if set,
 * otherwise the YouTube thumbnail.
 *
 * @param array $entry See ccd_ls_entry_data().
 * @return string The <img> HTML.
 */
function ccd_ls_image_html( $entry ) {
	$alt = ccd_ls_caption( $entry );

	if ( $entry['image_id'] ) {
		$html = wp_get_attachment_image(
			$entry['image_id'],
			'large',
			false,
			array(
				'class'   => 'kb-img wp-image-' . (int) $entry['image_id'],
				'alt'     => $alt,
				'loading' => 'lazy',
			)
		);
		if ( $html ) {
			return $html;
		}
	}

	$is_maxres = false !== strpos( $entry['thumb'], 'maxresdefault' );
	// hqdefault is 4:3 with black bars, so it is cropped back to 16:9.
	return sprintf(
		'<img src="%1$s" alt="%2$s" width="%3$d" height="%4$d" class="kb-img ccd-ls-thumb" loading="lazy" decoding="async" style="aspect-ratio:16/9;object-fit:cover;width:100%%;height:auto">',
		esc_url( $entry['thumb'] ),
		esc_attr( $alt ),
		$is_maxres ? 1280 : 480,
		$is_maxres ? 720 : 360
	);
}

/**
 * Builds a parsed kadence/image block for one entry, matching the markup
 * Kadence saves for a linked image with a caption.
 *
 * @param array $entry See ccd_ls_entry_data().
 * @param int   $n     The entry's position, starting at 1.
 * @return array A parsed block.
 */
function ccd_ls_image_block( $entry, $n ) {
	$unique_id = 'ccd-ls-image-' . (int) $n;
	$url       = esc_url( $entry['url'] );

	$html = sprintf(
		'<figure class="wp-block-kadence-image kb-image%1$s size-large"><a href="%2$s" class="kb-advanced-image-link" target="_blank" rel="noopener noreferrer">%3$s</a><figcaption><a href="%2$s" target="_blank" rel="noopener">%4$s</a></figcaption></figure>',
		esc_attr( $unique_id ),
		$url,
		ccd_ls_image_html( $entry ),
		esc_html( ccd_ls_caption( $entry ) )
	);

	return array(
		'blockName'    => 'kadence/image',
		'attrs'        => array(
			'uniqueID'   => $unique_id,
			'id'         => (int) $entry['image_id'],
			'link'       => $entry['url'],
			'linkTarget' => '_blank',
			'sizeSlug'   => 'large',
		),
		'innerBlocks'  => array(),
		'innerHTML'    => $html,
		'innerContent' => array( $html ),
	);
}

/**
 * Builds a parsed kadence/column block holding one entry.
 *
 * @param array $entry See ccd_ls_entry_data().
 * @param int   $n     The entry's position, starting at 1.
 * @return array A parsed block.
 */
function ccd_ls_column_block( $entry, $n ) {
	$unique_id = 'ccd-ls-col-' . (int) $n;
	$open      = sprintf( '<div class="wp-block-kadence-column kadence-column%s"><div class="kt-inside-inner-col">', esc_attr( $unique_id ) );
	$close     = '</div></div>';

	return array(
		'blockName'    => 'kadence/column',
		'attrs'        => array(
			'uniqueID'  => $unique_id,
			'kbVersion' => 2,
		),
		'innerBlocks'  => array( ccd_ls_image_block( $entry, $n ) ),
		'innerHTML'    => $open . $close,
		'innerContent' => array( $open, null, $close ),
	);
}

/**
 * Builds the parsed kadence/rowlayout blocks for a list of entries. Each
 * date starts a new row, with up to two entries per row, so a day with a
 * single stream has a row of its own with the stream in the left half.
 *
 * @param array[] $entries See ccd_ls_entry_data(), in display order.
 * @return array[] Parsed blocks.
 */
function ccd_ls_grid_blocks( $entries ) {
	$by_date = array();
	foreach ( $entries as $entry ) {
		$by_date[ $entry['date'] ][] = $entry;
	}
	$pairs = array();
	foreach ( $by_date as $day ) {
		$pairs = array_merge( $pairs, array_chunk( $day, 2 ) );
	}

	$rows = array();
	$n    = 0;
	foreach ( $pairs as $i => $pair ) {
		$columns = array();
		$content = array();
		foreach ( $pair as $entry ) {
			$columns[] = ccd_ls_column_block( $entry, ++$n );
			$content[] = null;
		}
		$rows[] = array(
			'blockName'    => 'kadence/rowlayout',
			'attrs'        => ccd_ls_row_attributes( $i + 1 ),
			'innerBlocks'  => $columns,
			'innerHTML'    => '',
			'innerContent' => $content,
		);
	}
	return $rows;
}

/**
 * Renders the shown entries. When there are none, shows the empty message
 * from Settings.
 *
 * @param string $today Today, Y-m-d. Defaults to the real today.
 * @return string HTML.
 */
function ccd_ls_render_grid( $today = '' ) {
	if ( ! ccd_ls_kadence_available() ) {
		return current_user_can( 'manage_options' )
			? '<!-- CCD Livestreams: Kadence Blocks is not active. -->'
			: '';
	}

	/**
	 * Filters the entries shown, in display order.
	 *
	 * @param array[] $entries See ccd_ls_entry_data().
	 */
	$entries = (array) apply_filters( 'ccd_ls_entries', ccd_ls_get_shown_entries( $today ) );

	if ( ! $entries ) {
		$message = ccd_ls_settings()['empty_message'];
		return '' === $message ? '' : '<p class="ccd-ls-empty">' . esc_html( $message ) . '</p>';
	}

	$html = '';
	foreach ( ccd_ls_grid_blocks( $entries ) as $block ) {
		$html .= render_block( $block );
	}
	return $html;
}
