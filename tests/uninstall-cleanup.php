<?php
/**
 * Behavioral regression for uninstall cleanup.
 *
 * @package NpcinkAbilitiesToolkit
 */

$mode = $argv[1] ?? 'single';
if ( ! in_array( $mode, array( 'single', 'multisite' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/uninstall-cleanup.php single|multisite\n" );
	exit( 2 );
}

define( 'WP_UNINSTALL_PLUGIN', 'npcink-abilities-toolkit/npcink-abilities-toolkit.php' );
$GLOBALS['npcink_uninstall_deleted_options'] = array();
$GLOBALS['npcink_uninstall_switched_sites'] = array();
$GLOBALS['npcink_uninstall_restores'] = 0;
$GLOBALS['npcink_uninstall_cleared_hooks'] = array();

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['npcink_uninstall_cleared_hooks'][] = (string) $hook;
	return true;
}

/**
 * Records an option deletion.
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( $option ) {
	$GLOBALS['npcink_uninstall_deleted_options'][] = (string) $option;
	return true;
}

/**
 * Reports the requested test mode.
 *
 * @return bool
 */
function is_multisite() {
	return 'multisite' === $GLOBALS['npcink_uninstall_mode'];
}

/**
 * Supplies one bounded page of test sites.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return array<int,int>
 */
function get_sites( array $args ) {
	return 0 === (int) ( $args['offset'] ?? 0 ) ? array( 11, 22 ) : array();
}

/**
 * Records a blog switch.
 *
 * @param int $site_id Site id.
 * @return bool
 */
function switch_to_blog( $site_id ) {
	$GLOBALS['npcink_uninstall_switched_sites'][] = (int) $site_id;
	return true;
}

/**
 * Records a blog restore.
 *
 * @return bool
 */
function restore_current_blog() {
	++$GLOBALS['npcink_uninstall_restores'];
	return true;
}

$GLOBALS['npcink_uninstall_mode'] = $mode;
require dirname( __DIR__ ) . '/uninstall.php';

$expected_options = array(
	'npcink_abilities_toolkit_catalog_observability_state',
	'npcink_abilities_toolkit_read_cache_version',
	'npcink_abilities_toolkit_media_backup_cleanup_cursor',
	'npcink_abilities_toolkit_media_backup_manual_cleanup_cursor',
);
$expected_deleted = 'multisite' === $mode ? array_merge( $expected_options, $expected_options ) : $expected_options;
if ( $expected_deleted !== $GLOBALS['npcink_uninstall_deleted_options'] ) {
	fwrite( STDERR, 'Unexpected uninstall deletions: ' . json_encode( $GLOBALS['npcink_uninstall_deleted_options'] ) . "\n" );
	exit( 1 );
}
$expected_cleared_hooks = 'multisite' === $mode
	? array( 'npcink_abilities_toolkit_cleanup_media_backups', 'npcink_abilities_toolkit_cleanup_media_backups' )
	: array( 'npcink_abilities_toolkit_cleanup_media_backups' );
if ( $expected_cleared_hooks !== $GLOBALS['npcink_uninstall_cleared_hooks'] ) {
	fwrite( STDERR, "Uninstall did not clear the media backup cleanup cron.\n" );
	exit( 1 );
}
if ( 'multisite' === $mode ) {
	if ( array( 11, 22 ) !== $GLOBALS['npcink_uninstall_switched_sites'] || 2 !== $GLOBALS['npcink_uninstall_restores'] ) {
		fwrite( STDERR, "Multisite uninstall did not restore every visited site.\n" );
		exit( 1 );
	}
} elseif ( array() !== $GLOBALS['npcink_uninstall_switched_sites'] || 0 !== $GLOBALS['npcink_uninstall_restores'] ) {
	fwrite( STDERR, "Single-site uninstall unexpectedly switched sites.\n" );
	exit( 1 );
}

echo 'OK: uninstall cleanup (' . $mode . ")\n";
