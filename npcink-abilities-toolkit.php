<?php
/**
 * Plugin Name: Npcink Abilities Toolkit
 * Description: Standalone WordPress Abilities API package toolkit for safely exposing agent-callable abilities.
 * Version: 0.5.5
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Npcink
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: npcink-abilities-toolkit
 * Domain Path: /languages
 *
 * @package NpcinkAbilitiesToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NPCINK_ABILITIES_TOOLKIT_VERSION', '0.5.5' );
define( 'NPCINK_ABILITIES_TOOLKIT_FILE', __FILE__ );
define( 'NPCINK_ABILITIES_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );

require_once NPCINK_ABILITIES_TOOLKIT_DIR . 'includes/Autoloader.php';
Npcink_Abilities_Toolkit\Autoloader::register();
require_once NPCINK_ABILITIES_TOOLKIT_DIR . 'includes/functions.php';

add_action(
	'init',
	static function () {
		Npcink_Abilities_Toolkit\Plugin::instance()->boot();
	},
	0
);

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			$plugin = Npcink_Abilities_Toolkit\Plugin::instance();
			$plugin->boot();
			$plugin->abilities()->emit_manual_catalog_refresh( 'activation' );
		}
	);
	register_deactivation_hook(
		__FILE__,
		static function () {
			$hook = 'npcink_abilities_toolkit_cleanup_media_backups';
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	);
}
