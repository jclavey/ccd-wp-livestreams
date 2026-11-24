<?php
/**
 * Plugin settings: Livestreams > Settings.
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many playlist rows the Settings page offers.
 */
const CCD_LS_MAX_PLAYLISTS = 6;

/**
 * Returns the plugin settings merged with their defaults.
 *
 * @return array{playlists:array[],empty_message:string}
 */
function ccd_ls_settings() {
	$settings = wp_parse_args(
		(array) get_option( 'ccd_ls_settings', array() ),
		array(
			'playlists'     => array(),
			'empty_message' => ccd_ls_default_empty_message(),
		)
	);
	$settings['playlists'] = array_values( array_filter( (array) $settings['playlists'], 'is_array' ) );
	return $settings;
}

/**
 * The default message shown when there are no recent livestreams.
 *
 * @return string
 */
function ccd_ls_default_empty_message() {
	return __( 'No recent livestreams – check back soon.', 'ccd-livestreams' );
}

/**
 * Cleans the submitted settings.
 *
 * @param mixed $input The submitted value.
 * @return array
 */
function ccd_ls_sanitize_settings( $input ) {
	$input     = is_array( $input ) ? $input : array();
	$playlists = array();
	$rows      = isset( $input['playlists'] ) && is_array( $input['playlists'] ) ? $input['playlists'] : array();
	foreach ( $rows as $row ) {
		$url   = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
		if ( '' === $url ) {
			continue;
		}
		$id = ccd_ls_parse_playlist_id( $url );
		if ( ! $id ) {
			add_settings_error(
				'ccd_ls_settings',
				'invalid_playlist',
				/* translators: %s: what was entered. */
				sprintf( __( '“%s” is not a YouTube playlist link, so it was not saved.', 'ccd-livestreams' ), $url )
			);
			continue;
		}
		$playlists[] = array(
			'id'    => $id,
			'title' => $title,
		);
	}
	$message = isset( $input['empty_message'] ) ? sanitize_text_field( (string) $input['empty_message'] ) : '';

	return array(
		'playlists'     => array_slice( $playlists, 0, CCD_LS_MAX_PLAYLISTS ),
		'empty_message' => $message,
	);
}

add_action( 'admin_init', 'ccd_ls_register_settings' );

/**
 * Registers the settings and their fields.
 */
function ccd_ls_register_settings() {
	register_setting(
		'ccd_ls',
		'ccd_ls_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'ccd_ls_sanitize_settings',
		)
	);

	add_settings_section(
		'ccd_ls_playlists',
		__( 'Playlists', 'ccd-livestreams' ),
		static function () {
			printf(
				'<p>%s</p>',
				esc_html__( 'The YouTube playlists the livestreams are added to. Streams in these playlists are suggested on the Livestreams page, with the default title filled in. Paste a playlist link, or any video link that includes &list=.', 'ccd-livestreams' )
			);
		},
		'ccd_ls'
	);

	add_settings_field( 'playlists', __( 'Playlists', 'ccd-livestreams' ), 'ccd_ls_render_playlists_field', 'ccd_ls', 'ccd_ls_playlists' );

	add_settings_section( 'ccd_ls_display', __( 'Display', 'ccd-livestreams' ), '__return_false', 'ccd_ls' );

	add_settings_field(
		'empty_message',
		__( 'When there are no recent livestreams', 'ccd-livestreams' ),
		static function () {
			printf(
				'<input type="text" class="large-text" name="ccd_ls_settings[empty_message]" id="ccd-ls-empty-message" value="%1$s"><p class="description">%2$s</p>',
				esc_attr( ccd_ls_settings()['empty_message'] ),
				esc_html__( 'Shown in place of the grid when nothing is dated in the last two weeks. Leave blank to show nothing.', 'ccd-livestreams' )
			);
		},
		'ccd_ls',
		'ccd_ls_display',
		array( 'label_for' => 'ccd-ls-empty-message' )
	);
}

/**
 * Prints the playlist rows: the saved ones, then blank rows up to the
 * maximum (at least two).
 */
function ccd_ls_render_playlists_field() {
	$playlists = ccd_ls_settings()['playlists'];
	$count     = min( CCD_LS_MAX_PLAYLISTS, max( 2, count( $playlists ) + 1 ) );
	?>
	<table class="widefat striped ccd-ls-playlists" style="max-width:900px">
		<thead>
			<tr>
				<th scope="col" style="padding:8px 10px"><?php esc_html_e( 'Playlist link', 'ccd-livestreams' ); ?></th>
				<th scope="col" style="width:30%;padding:8px 10px"><?php esc_html_e( 'Default title', 'ccd-livestreams' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			for ( $i = 0; $i < $count; $i++ ) :
				$id    = isset( $playlists[ $i ]['id'] ) ? $playlists[ $i ]['id'] : '';
				$title = isset( $playlists[ $i ]['title'] ) ? $playlists[ $i ]['title'] : '';
				$url   = $id ? 'https://www.youtube.com/playlist?list=' . $id : '';
				?>
				<tr>
					<td>
						<input type="url" class="large-text" name="ccd_ls_settings[playlists][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.youtube.com/playlist?list=…" aria-label="<?php esc_attr_e( 'Playlist link', 'ccd-livestreams' ); ?>">
						<?php if ( $url ) : ?>
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open on YouTube', 'ccd-livestreams' ); ?></a>
						<?php endif; ?>
					</td>
					<td><input type="text" class="regular-text" style="width:100%" name="ccd_ls_settings[playlists][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'e.g. Morning Service', 'ccd-livestreams' ); ?>" aria-label="<?php esc_attr_e( 'Default title', 'ccd-livestreams' ); ?>"></td>
				</tr>
			<?php endfor; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Clear a link to remove that playlist. Save to get another blank row.', 'ccd-livestreams' ); ?></p>
	<?php
}
