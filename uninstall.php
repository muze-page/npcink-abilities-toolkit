<?php
/**
 * Removes persistent plugin-owned options.
 *
 * @package NpcinkAbilitiesToolkit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes persistent Toolkit options from the current site.
 *
 * Expiring transients and object-cache entries are intentionally left to their
 * normal expiry/eviction lifecycle.
 *
 * @return void
 */
function npcink_abilities_toolkit_uninstall_current_site() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'npcink_abilities_toolkit_cleanup_media_backups' );
	}
	delete_option( 'npcink_abilities_toolkit_catalog_observability_state' );
	delete_option( 'npcink_abilities_toolkit_read_cache_version' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$npcink_abilities_toolkit_site_offset = 0;
	$npcink_abilities_toolkit_site_limit = 100;
	do {
		$npcink_abilities_toolkit_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $npcink_abilities_toolkit_site_limit,
				'offset' => $npcink_abilities_toolkit_site_offset,
			)
		);
		foreach ( $npcink_abilities_toolkit_site_ids as $npcink_abilities_toolkit_site_id ) {
			switch_to_blog( (int) $npcink_abilities_toolkit_site_id );
			npcink_abilities_toolkit_uninstall_current_site();
			restore_current_blog();
		}
		$npcink_abilities_toolkit_site_offset += count( $npcink_abilities_toolkit_site_ids );
	} while ( count( $npcink_abilities_toolkit_site_ids ) === $npcink_abilities_toolkit_site_limit );
} else {
	npcink_abilities_toolkit_uninstall_current_site();
}
