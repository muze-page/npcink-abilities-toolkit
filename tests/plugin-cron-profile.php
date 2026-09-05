<?php
/**
 * Behavioral regression for package-profile cleanup cron ownership.
 *
 * @package NpcinkAbilitiesToolkit
 */

$mode = $argv[1] ?? 'default';
if ( ! in_array( $mode, array( 'default', 'light' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/plugin-cron-profile.php default|light\n" );
	exit( 2 );
}

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'] = 'light' === $mode
	? array( array( 'timestamp' => time() + 3600, 'recurrence' => 'daily', 'hook' => 'npcink_abilities_toolkit_cleanup_media_backups' ) )
	: array();
$GLOBALS['npcink_abilities_toolkit_unit_cleared_hooks'] = array();

function wp_next_scheduled( $hook ) {
	foreach ( $GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'] as $event ) {
		if ( (string) $hook === (string) ( $event['hook'] ?? '' ) ) {
			return (int) ( $event['timestamp'] ?? 0 );
		}
	}
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'][] = array(
		'timestamp'  => (int) $timestamp,
		'recurrence' => (string) $recurrence,
		'hook'       => (string) $hook,
	);
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['npcink_abilities_toolkit_unit_cleared_hooks'][] = (string) $hook;
	$GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'] = array_values(
		array_filter(
			$GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'],
			static fn( $event ) => (string) $hook !== (string) ( $event['hook'] ?? '' )
		)
	);
	return true;
}

if ( 'light' === $mode ) {
	add_filter(
		'npcink_abilities_toolkit_enabled_packages',
		static function ( $packages ) {
			$packages['core_write'] = false;
			return $packages;
		}
	);
}

Npcink_Abilities_Toolkit\Plugin::instance()->boot();

$hook = 'npcink_abilities_toolkit_cleanup_media_backups';
$cleanup_actions = $GLOBALS['npcink_abilities_toolkit_unit_actions'][ $hook ] ?? array();
$scheduled_events = $GLOBALS['npcink_abilities_toolkit_unit_scheduled_events'];
if ( 'default' === $mode ) {
	if ( 1 !== count( $cleanup_actions ) || 1 !== count( $scheduled_events ) || 'daily' !== (string) ( $scheduled_events[0]['recurrence'] ?? '' ) ) {
		fwrite( STDERR, "Default profile did not register and schedule one daily media cleanup.\n" );
		exit( 1 );
	}
} elseif ( ! empty( $cleanup_actions ) || ! empty( $scheduled_events ) || array( $hook ) !== $GLOBALS['npcink_abilities_toolkit_unit_cleared_hooks'] ) {
	fwrite( STDERR, "Light profile did not remove the existing core_write media cleanup schedule.\n" );
	exit( 1 );
}

echo 'OK: media cleanup cron profile (' . $mode . ")\n";
