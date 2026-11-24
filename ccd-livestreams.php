<?php
/**
 * Plugin Name:       CCD Livestreams
 * Plugin URI:        https://github.com/jclavey/ccd-wp-livestreams
 * Description:       Manage the recent service livestreams from one admin page, with suggestions from YouTube playlists, and show the last two weeks as a Kadence grid.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  kadence-blocks
 * Author:            Jonathan Clavey
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ccd-livestreams
 *
 * @package CCD_Livestreams
 */

defined( 'ABSPATH' ) || exit;

define( 'CCD_LS_VERSION', '1.0.0' );
define( 'CCD_LS_FILE', __FILE__ );
define( 'CCD_LS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCD_LS_URL', plugin_dir_url( __FILE__ ) );
define( 'CCD_LS_POST_TYPE', 'ccd_ls_entry' );
define( 'CCD_LS_ADMIN_SLUG', 'ccd-livestreams' );

require_once CCD_LS_DIR . 'includes/post-type.php';
require_once CCD_LS_DIR . 'includes/entries.php';
require_once CCD_LS_DIR . 'includes/youtube.php';
require_once CCD_LS_DIR . 'includes/settings.php';
require_once CCD_LS_DIR . 'includes/suggestions.php';
require_once CCD_LS_DIR . 'includes/render.php';
require_once CCD_LS_DIR . 'includes/block.php';

if ( is_admin() ) {
	require_once CCD_LS_DIR . 'includes/admin.php';
	require_once CCD_LS_DIR . 'includes/admin-list.php';
}
