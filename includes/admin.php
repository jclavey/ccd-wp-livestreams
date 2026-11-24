<?php
/**
 * The Livestreams admin page: add streams (suggested from the playlists or
 * entered by hand) in one table, edit an entry, see the most recent entries
 * and delete them. Plus the Settings page.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

const CCD_LS_RECENT_COUNT = 10;

// Before priority 10, so the post type's "All entries" item can attach to it.
add_action( 'admin_menu', 'ccd_ls_admin_menu', 9 );

/**
 * Adds the Livestreams menu and its main page.
 */
function ccd_ls_admin_menu() {
	$hook = add_menu_page(
		__( 'Livestreams', 'ccd-livestreams' ),
		__( 'Livestreams', 'ccd-livestreams' ),
		'manage_options',
		CCD_LS_ADMIN_SLUG,
		'ccd_ls_render_admin_page',
		'dashicons-video-alt3',
		22
	);
	add_submenu_page(
		CCD_LS_ADMIN_SLUG,
		__( 'Livestreams', 'ccd-livestreams' ),
		__( 'Add & recent', 'ccd-livestreams' ),
		'manage_options',
		CCD_LS_ADMIN_SLUG,
		'ccd_ls_render_admin_page'
	);
	add_action( 'load-' . $hook, 'ccd_ls_admin_assets' );
}

add_action( 'admin_menu', 'ccd_ls_settings_menu', 20 );

/**
 * Adds the Settings page last, after "All entries".
 */
function ccd_ls_settings_menu() {
	add_submenu_page(
		CCD_LS_ADMIN_SLUG,
		__( 'Livestreams settings', 'ccd-livestreams' ),
		__( 'Settings', 'ccd-livestreams' ),
		'manage_options',
		'ccd-livestreams-settings',
		'ccd_ls_render_settings_page'
	);
}

/**
 * Loads the Media Library and the page script on the main page.
 */
function ccd_ls_admin_assets() {
	add_action(
		'admin_enqueue_scripts',
		static function () {
			wp_enqueue_media();
			wp_enqueue_script( 'ccd-ls-admin', CCD_LS_URL . 'assets/admin.js', array( 'jquery' ), CCD_LS_VERSION, true );
			wp_localize_script(
				'ccd-ls-admin',
				'ccdLivestreamsAdmin',
				array(
					'title'  => __( 'Choose an image for this livestream', 'ccd-livestreams' ),
					'button' => __( 'Use this image', 'ccd-livestreams' ),
				)
			);
			wp_add_inline_style(
				'common',
				'.ccd-ls-add,.ccd-ls-recent{max-width:1100px}.ccd-ls-recent td{vertical-align:middle}'
				// Top-aligned with fixed-height boxes, so a note under one box doesn't move the others.
				. '.ccd-ls-add tbody td,.ccd-ls-add tbody th.check-column{vertical-align:top}.ccd-ls-add tbody input:not([type=checkbox]){height:36px;min-height:36px;margin:0}.ccd-ls-add tbody .check-column input{margin:8px 0 0}'
				. '.ccd-ls-add .check-column{width:2.2em;padding:8px 0 8px 10px}.ccd-ls-add thead .check-column{vertical-align:middle}.ccd-ls-add thead .check-column input{margin:0}.ccd-ls-add input[type=date]{width:10.5em}.ccd-ls-add input[type=time]{width:7em}'
				. '.ccd-ls-add input.ccd-ls-title{width:100%;min-width:12em}.ccd-ls-add input.ccd-ls-video{width:100%;min-width:16em}'
				. '.ccd-ls-hint{display:block;color:#646970;font-size:12px;margin-top:2px}.ccd-ls-row-error td{background:#fcf0f1}.ccd-ls-row-error .ccd-ls-hint.error{color:#b32d2e}'
				. '.ccd-ls-shown{display:inline-block;margin-left:6px;padding:0 6px;border-radius:3px;background:#dff0d8;color:#2a6b2a;font-size:12px}'
				. '.ccd-ls-status{display:inline-block;padding:0 6px;border-radius:3px;background:#f0f0f1;font-size:12px}.ccd-ls-status-live{background:#fcf0f1;color:#b32d2e}'
				. '.ccd-ls-thumb-sm{width:96px;height:54px;object-fit:cover;display:block;border-radius:2px}.ccd-ls-thumb-lg{width:320px;max-width:100%;aspect-ratio:16/9;object-fit:cover;display:block;margin-bottom:8px}'
				. '.ccd-ls-toolbar{display:flex;gap:12px;align-items:center;margin:8px 0}'
			);
		}
	);
}

/**
 * The main page URL, with optional query args.
 *
 * @param array $args Query args to add.
 * @return string
 */
function ccd_ls_admin_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . CCD_LS_ADMIN_SLUG ) );
}

/**
 * The error messages for each save error code.
 *
 * @return string[]
 */
function ccd_ls_error_messages() {
	return array(
		'invalid_date'  => __( 'Please enter a valid date.', 'ccd-livestreams' ),
		'invalid_time'  => __( 'Please enter a valid time, e.g. 11:15 or 18:15.', 'ccd-livestreams' ),
		'invalid_title' => __( 'Please enter a title, e.g. Morning Service.', 'ccd-livestreams' ),
		'invalid_video' => __( 'Please enter a YouTube link.', 'ccd-livestreams' ),
		'invalid_image' => __( 'Please choose an image from the Media Library.', 'ccd-livestreams' ),
		'not_found'     => __( 'That entry no longer exists.', 'ccd-livestreams' ),
		'duplicate'     => __( 'That video has already been added.', 'ccd-livestreams' ),
	);
}

/**
 * The transient that carries rows that failed to save back to the form.
 *
 * @return string
 */
function ccd_ls_failed_rows_key() {
	return 'ccd_ls_failed_rows_' . get_current_user_id();
}

/**
 * Prints the notice for the last action, read from the query string.
 */
function ccd_ls_admin_notices() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$done   = isset( $_GET['ccd_ls_done'] ) ? sanitize_key( $_GET['ccd_ls_done'] ) : '';
	$error  = isset( $_GET['ccd_ls_error'] ) ? sanitize_key( $_GET['ccd_ls_error'] ) : '';
	$entry  = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
	$added  = isset( $_GET['added'] ) ? absint( $_GET['added'] ) : 0;
	$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
	// phpcs:enable

	if ( 'added' === $done ) {
		if ( $added ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				/* translators: %d: number of livestreams. */
				esc_html( sprintf( _n( '%d livestream added.', '%d livestreams added.', $added, 'ccd-livestreams' ), $added ) )
			);
		}
		if ( $failed ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				/* translators: %d: number of rows. */
				esc_html( sprintf( _n( '%d row could not be added. Fix it below and click Add selected again.', '%d rows could not be added. Fix them below and click Add selected again.', $failed, 'ccd-livestreams' ), $failed ) )
			);
		}
		if ( ! $added && ! $failed ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html__( 'Nothing was added. Tick the rows to add.', 'ccd-livestreams' ) );
		}
	} elseif ( 'updated' === $done ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Livestream updated.', 'ccd-livestreams' ) );
	} elseif ( 'deleted' === $done && $entry ) {
		$undo = wp_nonce_url( admin_url( 'post.php?post=' . $entry . '&action=untrash' ), 'untrash-post_' . $entry );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Livestream moved to the Trash.', 'ccd-livestreams' ),
			esc_url( $undo ),
			esc_html__( 'Undo', 'ccd-livestreams' )
		);
	}

	if ( $error ) {
		$messages = ccd_ls_error_messages();
		$message  = isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The livestream could not be saved.', 'ccd-livestreams' );
		$link     = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$existing = isset( $_GET['existing'] ) ? absint( $_GET['existing'] ) : 0;
		if ( 'duplicate' === $error && $existing ) {
			$link = sprintf(
				' <a href="%s">%s</a>',
				esc_url( ccd_ls_admin_url( array( 'edit' => $existing ) ) ),
				esc_html__( 'Edit that entry instead', 'ccd-livestreams' )
			);
		}
		printf( '<div class="notice notice-error"><p>%s%s</p></div>', esc_html( $message ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput -- $link is escaped above.
	}
}

/**
 * Renders the main page.
 */
function ccd_ls_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing = $edit_id ? ccd_ls_entry_data( $edit_id ) : null;
	if ( $editing && 'trash' === get_post_status( $edit_id ) ) {
		$editing = null;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Livestreams', 'ccd-livestreams' ); ?></h1>

		<?php
		if ( ! ccd_ls_kadence_available() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Kadence Blocks is not active, so the livestreams will not appear on the site. You can still add and edit entries.', 'ccd-livestreams' )
			);
		}
		if ( $edit_id && ! $editing ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That entry no longer exists.', 'ccd-livestreams' ) );
		}
		ccd_ls_admin_notices();

		if ( $editing ) {
			ccd_ls_render_edit_form( $editing );
		} else {
			ccd_ls_render_add_table();
		}

		ccd_ls_render_recent_table();
		?>
	</div>
	<?php
}

/**
 * Prints one row of the Add streams table.
 *
 * @param int|string $i   The row index, or __i__ in the template.
 * @param array $row Keys: checked, date, time, title, video, hint, status,
 *                   error.
 */
function ccd_ls_render_add_row( $i, $row ) {
	$row      = wp_parse_args(
		$row,
		array(
			'checked' => false,
			'date'    => '',
			'time'    => '',
			'title'   => '',
			'video'   => '',
			'hint'    => '',
			'status'  => '',
			'error'   => '',
		)
	);
	$name     = 'rows[' . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $i ) . ']';
	$statuses = array(
		'upcoming' => __( 'Scheduled', 'ccd-livestreams' ),
		'live'     => __( 'Live now', 'ccd-livestreams' ),
		'streamed' => __( 'Streamed', 'ccd-livestreams' ),
	);
	?>
	<tr class="ccd-ls-row<?php echo $row['error'] ? ' ccd-ls-row-error' : ''; ?>">
		<th scope="row" class="check-column">
			<input type="checkbox" class="ccd-ls-check" name="<?php echo esc_attr( $name ); ?>[add]" value="1" <?php checked( $row['checked'] ); ?> aria-label="<?php esc_attr_e( 'Add this livestream', 'ccd-livestreams' ); ?>">
		</th>
		<td><input type="date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $row['date'] ); ?>" aria-label="<?php esc_attr_e( 'Date', 'ccd-livestreams' ); ?>"></td>
		<td><input type="time" name="<?php echo esc_attr( $name ); ?>[time]" value="<?php echo esc_attr( $row['time'] ); ?>" aria-label="<?php esc_attr_e( 'Time', 'ccd-livestreams' ); ?>"></td>
		<td><input type="text" class="ccd-ls-title" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $row['title'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Morning Service', 'ccd-livestreams' ); ?>" aria-label="<?php esc_attr_e( 'Title', 'ccd-livestreams' ); ?>"></td>
		<td>
			<input type="text" class="ccd-ls-video" name="<?php echo esc_attr( $name ); ?>[video]" value="<?php echo esc_attr( $row['video'] ); ?>" placeholder="https://www.youtube.com/live/…" aria-label="<?php esc_attr_e( 'YouTube link', 'ccd-livestreams' ); ?>">
			<?php if ( $row['status'] && isset( $statuses[ $row['status'] ] ) ) : ?>
				<span class="ccd-ls-hint">
					<span class="ccd-ls-status ccd-ls-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $statuses[ $row['status'] ] ); ?></span>
					<?php echo esc_html( $row['hint'] ); ?>
				</span>
			<?php elseif ( $row['hint'] ) : ?>
				<span class="ccd-ls-hint"><?php echo esc_html( $row['hint'] ); ?></span>
			<?php endif; ?>
			<?php if ( $row['error'] ) : ?>
				<span class="ccd-ls-hint error"><?php echo wp_kses( $row['error'], array( 'a' => array( 'href' => array() ) ) ); ?></span>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Renders the Add streams table: rows that failed last time, then the
 * suggestions from the playlists, then a blank row for adding by hand.
 */
function ccd_ls_render_add_table() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
	$refresh = isset( $_GET['refresh'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'ccd_ls_refresh' );

	$failed = get_transient( ccd_ls_failed_rows_key() );
	delete_transient( ccd_ls_failed_rows_key() );
	$failed = is_array( $failed ) ? $failed : array();

	$playlists   = ccd_ls_settings()['playlists'];
	$suggestions = $playlists ? ccd_ls_get_suggestions( $refresh ) : array(
		'items'  => array(),
		'errors' => array(),
	);

	$rows     = array();
	$seen     = array();
	$messages = ccd_ls_error_messages();
	foreach ( $failed as $row ) {
		$message = isset( $messages[ $row['code'] ] ) ? esc_html( $messages[ $row['code'] ] ) : esc_html__( 'This row could not be saved.', 'ccd-livestreams' );
		if ( 'duplicate' === $row['code'] && ! empty( $row['existing'] ) ) {
			$message .= ' <a href="' . esc_url( ccd_ls_admin_url( array( 'edit' => (int) $row['existing'] ) ) ) . '">' . esc_html__( 'Edit that entry', 'ccd-livestreams' ) . '</a>';
		}
		$rows[] = array(
			'checked' => 'duplicate' !== $row['code'],
			'date'    => $row['date'],
			'time'    => $row['time'],
			'title'   => $row['title'],
			'video'   => $row['video'],
			'error'   => $message,
		);
		$id = ccd_ls_parse_video_id( $row['video'] );
		if ( $id ) {
			$seen[ $id ] = true;
		}
	}
	foreach ( $suggestions['items'] as $item ) {
		if ( isset( $seen[ $item['video_id'] ] ) ) {
			continue;
		}
		$rows[] = array(
			'checked' => true,
			'date'    => $item['date'],
			'time'    => $item['time'],
			'title'   => $item['title'],
			'video'   => ccd_ls_watch_url( $item['video_id'] ),
			'status'  => $item['status'],
			/* translators: %s: the video's title on YouTube. */
			'hint'    => sprintf( __( 'on YouTube: “%s”', 'ccd-livestreams' ), $item['video_title'] ),
		);
	}
	$suggested = count( $suggestions['items'] );
	$rows[]    = array( 'hint' => __( 'Add a stream by hand: fill in this row.', 'ccd-livestreams' ) );

	$refresh_url = wp_nonce_url( ccd_ls_admin_url( array( 'refresh' => 1 ) ), 'ccd_ls_refresh' );
	?>
	<h2><?php esc_html_e( 'Add streams', 'ccd-livestreams' ); ?></h2>

	<div class="ccd-ls-toolbar">
		<?php if ( $playlists ) : ?>
			<span>
				<?php
				if ( $suggested ) {
					/* translators: %d: number of suggestions. */
					echo esc_html( sprintf( _n( '%d stream from the playlists is ready to add. Check the details, untick any you don’t want, then click Add selected.', '%d streams from the playlists are ready to add. Check the details, untick any you don’t want, then click Add selected.', $suggested, 'ccd-livestreams' ), $suggested ) );
				} elseif ( ! $suggestions['errors'] ) {
					esc_html_e( 'Everything in the playlists up to the coming Sunday has been added.', 'ccd-livestreams' );
				}
				?>
			</span>
			<a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Check playlists again', 'ccd-livestreams' ); ?></a>
		<?php else : ?>
			<span>
				<?php
				printf(
					/* translators: %s: link to the Settings page. */
					esc_html__( 'Add your YouTube playlists in %s to get suggestions here.', 'ccd-livestreams' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=ccd-livestreams-settings' ) ) . '">' . esc_html__( 'Settings', 'ccd-livestreams' ) . '</a>'
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php foreach ( $suggestions['errors'] as $error ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: playlist default title or ID, 2: the error. */
					__( 'Couldn’t read the %1$s playlist (%2$s). Add its streams by hand below.', 'ccd-livestreams' ),
					'' !== $error['title'] ? $error['title'] : $error['playlist_id'],
					$error['message']
				)
			);
			?>
		</p></div>
	<?php endforeach; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ccd_ls_add">
		<?php wp_nonce_field( 'ccd_ls_add' ); ?>
		<table class="widefat striped ccd-ls-add">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" class="ccd-ls-check-all" aria-label="<?php esc_attr_e( 'Select all', 'ccd-livestreams' ); ?>"></td>
					<th scope="col"><?php esc_html_e( 'Date', 'ccd-livestreams' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'ccd-livestreams' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'ccd-livestreams' ); ?></th>
					<th scope="col"><?php esc_html_e( 'YouTube link', 'ccd-livestreams' ); ?></th>
				</tr>
			</thead>
			<tbody class="ccd-ls-rows">
				<?php
				foreach ( $rows as $i => $row ) {
					ccd_ls_render_add_row( $i, $row );
				}
				?>
			</tbody>
		</table>
		<template id="ccd-ls-row-template">
			<?php ccd_ls_render_add_row( '__i__', array() ); ?>
		</template>
		<p class="submit ccd-ls-toolbar">
			<?php submit_button( __( 'Add selected', 'ccd-livestreams' ), 'primary', 'submit', false ); ?>
			<button type="button" class="button ccd-ls-add-row"><?php esc_html_e( '+ Add another row', 'ccd-livestreams' ); ?></button>
		</p>
		<p class="description">
			<?php esc_html_e( 'The page shows streams dated in the last two weeks, from the start of each day. The caption is the date, the time and the title, e.g. “22.11.26 – 11:15 Morning Service”.', 'ccd-livestreams' ); ?>
		</p>
	</form>
	<?php
}

/**
 * Renders the form for editing one entry.
 *
 * @param array $entry See ccd_ls_entry_data().
 */
function ccd_ls_render_edit_form( $entry ) {
	$image_url = $entry['image_id'] ? wp_get_attachment_image_url( $entry['image_id'], 'medium_large' ) : '';
	?>
	<h2>
		<?php
		/* translators: %s: the entry's caption. */
		echo esc_html( sprintf( __( 'Edit: %s', 'ccd-livestreams' ), ccd_ls_caption( $entry ) ) );
		?>
	</h2>
	<form class="ccd-ls-edit" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ccd_ls_save">
		<input type="hidden" name="entry" value="<?php echo (int) $entry['id']; ?>">
		<?php wp_nonce_field( 'ccd_ls_save_' . $entry['id'] ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ccd-ls-date"><?php esc_html_e( 'Date', 'ccd-livestreams' ); ?></label></th>
				<td><input type="date" id="ccd-ls-date" name="date" value="<?php echo esc_attr( $entry['date'] ); ?>" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="ccd-ls-time"><?php esc_html_e( 'Time', 'ccd-livestreams' ); ?></label></th>
				<td><input type="time" id="ccd-ls-time" name="time" value="<?php echo esc_attr( $entry['time'] ); ?>" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="ccd-ls-title"><?php esc_html_e( 'Title', 'ccd-livestreams' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="ccd-ls-title" name="title" value="<?php echo esc_attr( $entry['title'] ); ?>" required>
					<p class="description"><?php esc_html_e( 'The caption is the date, the time and this title, e.g. “22.11.26 – 11:15 Morning Service”.', 'ccd-livestreams' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ccd-ls-video"><?php esc_html_e( 'YouTube link', 'ccd-livestreams' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="ccd-ls-video" name="video" value="<?php echo esc_attr( $entry['url'] ); ?>" required>
					<p class="description"><a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open on YouTube', 'ccd-livestreams' ); ?></a></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Image', 'ccd-livestreams' ); ?></th>
				<td>
					<input type="hidden" id="ccd-ls-image" name="image_id" value="<?php echo (int) $entry['image_id']; ?>">
					<img id="ccd-ls-image-preview" class="ccd-ls-thumb-lg" src="<?php echo esc_url( $image_url ? $image_url : $entry['thumb'] ); ?>" data-youtube="<?php echo esc_url( $entry['thumb'] ); ?>" alt="">
					<button type="button" class="button" id="ccd-ls-image-choose"><?php esc_html_e( 'Choose a different image', 'ccd-livestreams' ); ?></button>
					<button type="button" class="button-link" id="ccd-ls-image-remove" <?php echo $entry['image_id'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Use the YouTube thumbnail', 'ccd-livestreams' ); ?></button>
					<p class="description"><?php esc_html_e( 'The YouTube thumbnail is used unless you choose an image from the Media Library.', 'ccd-livestreams' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<?php submit_button( __( 'Save changes', 'ccd-livestreams' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( ccd_ls_admin_url() ); ?>"><?php esc_html_e( 'Cancel', 'ccd-livestreams' ); ?></a>
		</p>
	</form>
	<?php
}

/**
 * Renders the table of the most recent entries.
 */
function ccd_ls_render_recent_table() {
	$entries = ccd_ls_get_recent_entries( CCD_LS_RECENT_COUNT );
	$shown   = wp_list_pluck( ccd_ls_get_shown_entries(), 'id' );
	$total   = (int) wp_count_posts( CCD_LS_POST_TYPE )->publish;
	$all_url = admin_url( 'edit.php?post_type=' . CCD_LS_POST_TYPE );
	?>
	<h2><?php esc_html_e( 'Recent entries', 'ccd-livestreams' ); ?></h2>
	<?php if ( ! $entries ) : ?>
		<p><?php esc_html_e( 'No livestreams yet. Add the first ones above.', 'ccd-livestreams' ); ?></p>
		<?php
		return;
	endif;
	?>
	<table class="widefat striped ccd-ls-recent">
		<thead>
			<tr>
				<th scope="col" style="width:110px"><?php esc_html_e( 'Image', 'ccd-livestreams' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'ccd-livestreams' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Caption', 'ccd-livestreams' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'ccd-livestreams' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $entry ) : ?>
				<?php $image = $entry['image_id'] ? wp_get_attachment_image_url( $entry['image_id'], 'thumbnail' ) : $entry['thumb']; ?>
				<tr>
					<td><a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener"><img class="ccd-ls-thumb-sm" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy"></a></td>
					<td>
						<strong><?php echo esc_html( ccd_ls_format_date( $entry['date'], 'D j M Y' ) ); ?></strong>
						<?php if ( in_array( $entry['id'], $shown, true ) ) : ?>
							<span class="ccd-ls-shown"><?php esc_html_e( 'On the page', 'ccd-livestreams' ); ?></span>
						<?php endif; ?>
					</td>
					<td><a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( ccd_ls_caption( $entry ) ); ?></a></td>
					<td>
						<a href="<?php echo esc_url( ccd_ls_admin_url( array( 'edit' => $entry['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'ccd-livestreams' ); ?></a>
						|
						<a class="submitdelete" style="color:#b32d2e" href="<?php echo esc_url( ccd_ls_delete_url( $entry['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'ccd-livestreams' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<a href="<?php echo esc_url( $all_url ); ?>">
			<?php
			/* translators: %d: number of entries. */
			echo esc_html( sprintf( _n( 'View all entries (%d)', 'View all entries (%d)', $total, 'ccd-livestreams' ), $total ) );
			?>
		</a>
	</p>
	<?php
}

/**
 * The URL that moves an entry to the Trash.
 *
 * @param int $entry_id The entry.
 * @return string
 */
function ccd_ls_delete_url( $entry_id ) {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=ccd_ls_delete&entry=' . (int) $entry_id ),
		'ccd_ls_delete_' . (int) $entry_id
	);
}

/**
 * Saves the ticked rows of the Add streams table.
 *
 * @param array $rows The submitted rows, unslashed.
 * @return array{added:int[],failed:array[]} The new entry IDs, and the rows
 *         that failed with their error code (and the existing entry for
 *         duplicates).
 */
function ccd_ls_add_rows( $rows ) {
	$added  = array();
	$failed = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['add'] ) ) {
			continue;
		}
		$data = array();
		foreach ( array( 'date', 'time', 'title', 'video' ) as $key ) {
			$data[ $key ] = isset( $row[ $key ] ) ? sanitize_text_field( (string) $row[ $key ] ) : '';
		}
		// A ticked row left completely blank is ignored.
		if ( '' === implode( '', $data ) ) {
			continue;
		}
		$result = ccd_ls_save_entry( $data );
		if ( is_wp_error( $result ) ) {
			$failed[] = $data + array(
				'code'     => $result->get_error_code(),
				'existing' => 'duplicate' === $result->get_error_code() ? (int) $result->get_error_data() : 0,
			);
		} else {
			$added[] = (int) $result;
		}
	}
	return array(
		'added'  => $added,
		'failed' => $failed,
	);
}

add_action( 'admin_post_ccd_ls_add', 'ccd_ls_handle_add' );

/**
 * Saves the Add streams table, then returns to the main page.
 */
function ccd_ls_handle_add() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ccd-livestreams' ), 403 );
	}
	check_admin_referer( 'ccd_ls_add' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in ccd_ls_add_rows().
	$rows   = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
	$result = ccd_ls_add_rows( $rows );

	if ( $result['failed'] ) {
		set_transient( ccd_ls_failed_rows_key(), $result['failed'], HOUR_IN_SECONDS );
	}

	wp_safe_redirect(
		ccd_ls_admin_url(
			array(
				'ccd_ls_done' => 'added',
				'added'       => count( $result['added'] ),
				'failed'      => count( $result['failed'] ),
			)
		)
	);
	exit;
}

add_action( 'admin_post_ccd_ls_save', 'ccd_ls_handle_save' );

/**
 * Saves the edit form, then returns to the main page.
 */
function ccd_ls_handle_save() {
	$entry_id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ccd-livestreams' ), 403 );
	}
	check_admin_referer( 'ccd_ls_save_' . $entry_id );

	$data = array( 'image_id' => isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0 );
	foreach ( array( 'date', 'time', 'title', 'video' ) as $key ) {
		$data[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	$result = ccd_ls_save_entry( $data, $entry_id );

	if ( is_wp_error( $result ) ) {
		$args = array(
			'ccd_ls_error' => $result->get_error_code(),
			'edit'         => $entry_id,
		);
		if ( 'duplicate' === $result->get_error_code() ) {
			$args['existing'] = (int) $result->get_error_data();
		}
		wp_safe_redirect( ccd_ls_admin_url( $args ) );
		exit;
	}

	wp_safe_redirect(
		ccd_ls_admin_url(
			array(
				'ccd_ls_done' => 'updated',
				'entry'       => (int) $result,
			)
		)
	);
	exit;
}

add_action( 'admin_post_ccd_ls_delete', 'ccd_ls_handle_delete' );

/**
 * Moves an entry to the Trash.
 */
function ccd_ls_handle_delete() {
	$entry_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ccd-livestreams' ), 403 );
	}
	check_admin_referer( 'ccd_ls_delete_' . $entry_id );

	if ( CCD_LS_POST_TYPE !== get_post_type( $entry_id ) || ! wp_trash_post( $entry_id ) ) {
		wp_safe_redirect( ccd_ls_admin_url( array( 'ccd_ls_error' => 'not_found' ) ) );
		exit;
	}

	wp_safe_redirect(
		ccd_ls_admin_url(
			array(
				'ccd_ls_done' => 'deleted',
				'entry'       => $entry_id,
			)
		)
	);
	exit;
}

/**
 * Renders the Settings page.
 */
function ccd_ls_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Livestreams settings', 'ccd-livestreams' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'ccd_ls' );
			do_settings_sections( 'ccd_ls' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

add_filter( 'plugin_action_links_' . plugin_basename( CCD_LS_FILE ), 'ccd_ls_plugin_action_links' );

/**
 * Adds "Manage" and "Settings" links on the Plugins screen.
 *
 * @param string[] $links The existing links.
 * @return string[]
 */
function ccd_ls_plugin_action_links( $links ) {
	array_unshift(
		$links,
		sprintf( '<a href="%s">%s</a>', esc_url( ccd_ls_admin_url() ), esc_html__( 'Manage', 'ccd-livestreams' ) ),
		sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=ccd-livestreams-settings' ) ), esc_html__( 'Settings', 'ccd-livestreams' ) )
	);
	return $links;
}
