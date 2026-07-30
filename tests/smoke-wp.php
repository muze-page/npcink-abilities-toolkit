<?php
/**
 * WordPress smoke test for the standalone plugin.
 *
 * Run through WP-CLI:
 * wp eval-file tests/smoke-wp.php
 *
 * @package NpcinkAbilitiesToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This smoke test must run inside WordPress through WP-CLI.\n" );
	exit( 1 );
}

$GLOBALS['npcink_abilities_toolkit_smoke_assertions'] = 0;
$npcink_abilities_toolkit_smoke_profile = sanitize_key( (string) ( getenv( 'NPCINK_ABILITIES_TOOLKIT_SMOKE_PROFILE' ) ?: 'default' ) );
if ( '' === $npcink_abilities_toolkit_smoke_profile ) {
	$npcink_abilities_toolkit_smoke_profile = 'default';
}
$npcink_abilities_toolkit_smoke_run_id                 = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'smoke-', true );
$npcink_abilities_toolkit_smoke_pattern                = 'Npcink Abilities Toolkit Smoke ' . $npcink_abilities_toolkit_smoke_run_id;
$npcink_abilities_toolkit_smoke_post_fixture_ids       = array();
$npcink_abilities_toolkit_smoke_comment_fixture_ids    = array();
$npcink_abilities_toolkit_smoke_attachment_fixture_ids = array();
$npcink_abilities_toolkit_smoke_term_fixtures          = array();
$npcink_abilities_toolkit_smoke_user_fixture_ids        = array();
$npcink_abilities_toolkit_smoke_cleanup_completed      = false;

/**
 * Asserts a smoke-test condition.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Assertion message.
 * @return void
 */
function npcink_abilities_toolkit_smoke_assert( $condition, $message ) {
	++$GLOBALS['npcink_abilities_toolkit_smoke_assertions'];

	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	echo "[ok] {$message}\n";
}

/**
 * Registers a post fixture for cleanup even when the smoke test fails.
 *
 * @param int $post_id Post id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_register_post_fixture( $post_id ) {
	global $npcink_abilities_toolkit_smoke_post_fixture_ids;

	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	$npcink_abilities_toolkit_smoke_post_fixture_ids[ $post_id ] = true;
}

/**
 * Registers a comment fixture for cleanup even when the smoke test fails.
 *
 * @param int $comment_id Comment id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_register_comment_fixture( $comment_id ) {
	global $npcink_abilities_toolkit_smoke_comment_fixture_ids;

	$comment_id = (int) $comment_id;
	if ( $comment_id <= 0 ) {
		return;
	}

	$npcink_abilities_toolkit_smoke_comment_fixture_ids[ $comment_id ] = true;
}

/**
 * Registers a media fixture for cleanup even when the smoke test fails.
 *
 * @param int $attachment_id Attachment post id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_register_attachment_fixture( $attachment_id ) {
	global $npcink_abilities_toolkit_smoke_attachment_fixture_ids;

	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		return;
	}

	$npcink_abilities_toolkit_smoke_attachment_fixture_ids[ $attachment_id ] = true;
	update_post_meta( $attachment_id, '_npcink_abilities_toolkit_smoke_fixture_run_id', npcink_abilities_toolkit_smoke_run_id() );
}

/**
 * Returns the current smoke run id.
 *
 * @return string
 */
function npcink_abilities_toolkit_smoke_run_id() {
	global $npcink_abilities_toolkit_smoke_run_id;

	return (string) $npcink_abilities_toolkit_smoke_run_id;
}

/**
 * Finds attachment fixtures tagged with the current run id.
 *
 * @return int[]
 */
function npcink_abilities_toolkit_smoke_current_run_attachment_ids() {
	return array_values(
		array_unique(
			array_filter(
				array_map(
					'absint',
					get_posts(
						array(
							'fields'         => 'ids',
							'meta_key'       => '_npcink_abilities_toolkit_smoke_fixture_run_id',
							'meta_value'     => npcink_abilities_toolkit_smoke_run_id(),
							'post_status'    => 'inherit',
							'post_type'      => 'attachment',
							'posts_per_page' => -1,
						)
					)
				)
			)
		)
	);
}

/**
 * Finds reserved-prefix smoke media attachments.
 *
 * @return int[]
 */
function npcink_abilities_toolkit_smoke_known_media_fixture_leak_ids() {
	global $wpdb;

	$ids      = array();
	$prefixes = array(
		'Npcink Abilities Toolkit Smoke ',
		'npcink-abilities-toolkit-smoke-media-asset',
	);

	foreach ( $prefixes as $prefix ) {
		$like = '%' . $wpdb->esc_like( $prefix ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id
					AND pm.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment'
					AND (
						p.post_title LIKE %s
						OR p.post_name LIKE %s
						OR p.guid LIKE %s
						OR pm.meta_value LIKE %s
					)",
				$like,
				$like,
				$like,
				$like
			)
		);
		$ids  = array_merge( $ids, array_map( 'absint', is_array( $rows ) ? $rows : array() ) );
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Asserts no known smoke media fixture remains in the media library.
 *
 * @return void
 */
function npcink_abilities_toolkit_smoke_assert_no_media_fixture_leaks() {
	$leaks = array_values(
		array_unique(
			array_merge(
				npcink_abilities_toolkit_smoke_current_run_attachment_ids(),
				npcink_abilities_toolkit_smoke_known_media_fixture_leak_ids()
			)
		)
	);

	npcink_abilities_toolkit_smoke_assert( empty( $leaks ), 'Smoke leaves no registered or reserved-prefix media fixtures behind.' );
}

/**
 * Registers a taxonomy term fixture for cleanup even when the smoke test fails.
 *
 * @param int    $term_id Term id.
 * @param string $taxonomy Taxonomy id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_register_term_fixture( $term_id, $taxonomy ) {
	global $npcink_abilities_toolkit_smoke_term_fixtures;

	$term_id  = (int) $term_id;
	$taxonomy = (string) $taxonomy;
	if ( $term_id <= 0 || '' === $taxonomy ) {
		return;
	}

	$npcink_abilities_toolkit_smoke_term_fixtures[ $taxonomy . ':' . $term_id ] = array(
		'term_id'  => $term_id,
		'taxonomy' => $taxonomy,
	);
}

/**
 * Registers a user fixture for cleanup even when the smoke test fails.
 *
 * @param int $user_id User id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_register_user_fixture( $user_id ) {
	global $npcink_abilities_toolkit_smoke_user_fixture_ids;

	$user_id = (int) $user_id;
	if ( $user_id > 0 ) {
		$npcink_abilities_toolkit_smoke_user_fixture_ids[ $user_id ] = true;
	}
}

/**
 * Deletes registered smoke fixtures.
 *
 * @return void
 */
function npcink_abilities_toolkit_smoke_cleanup_fixtures() {
	global $npcink_abilities_toolkit_smoke_post_fixture_ids, $npcink_abilities_toolkit_smoke_comment_fixture_ids, $npcink_abilities_toolkit_smoke_attachment_fixture_ids, $npcink_abilities_toolkit_smoke_term_fixtures, $npcink_abilities_toolkit_smoke_user_fixture_ids, $npcink_abilities_toolkit_smoke_cleanup_completed;

	if ( $npcink_abilities_toolkit_smoke_cleanup_completed ) {
		return;
	}

	$npcink_abilities_toolkit_smoke_cleanup_completed = true;

	foreach ( array_keys( (array) $npcink_abilities_toolkit_smoke_comment_fixture_ids ) as $comment_id ) {
		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 || ! get_comment( $comment_id ) ) {
			continue;
		}

		if ( false === wp_delete_comment( $comment_id, true ) ) {
			fwrite( STDERR, '[warn] failed to delete smoke comment fixture ' . $comment_id . "\n" );
		}
	}

	$attachment_ids = array_values(
		array_unique(
			array_filter(
				array_merge(
					array_map( 'absint', array_keys( (array) $npcink_abilities_toolkit_smoke_attachment_fixture_ids ) ),
					npcink_abilities_toolkit_smoke_current_run_attachment_ids(),
					npcink_abilities_toolkit_smoke_known_media_fixture_leak_ids()
				)
			)
		)
	);
	foreach ( $attachment_ids as $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			continue;
		}

		if ( false === wp_delete_attachment( $attachment_id, true ) ) {
			fwrite( STDERR, '[warn] failed to delete smoke attachment fixture ' . $attachment_id . "\n" );
		}
	}

	foreach ( array_keys( (array) $npcink_abilities_toolkit_smoke_post_fixture_ids ) as $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || false === get_post_type( $post_id ) ) {
			continue;
		}

		if ( false === wp_delete_post( $post_id, true ) ) {
			fwrite( STDERR, '[warn] failed to delete smoke post fixture ' . $post_id . "\n" );
		}
	}

	foreach ( (array) $npcink_abilities_toolkit_smoke_term_fixtures as $term_fixture ) {
		$term_id  = (int) ( $term_fixture['term_id'] ?? 0 );
		$taxonomy = (string) ( $term_fixture['taxonomy'] ?? '' );
		if ( $term_id <= 0 || '' === $taxonomy || ! term_exists( $term_id, $taxonomy ) ) {
			continue;
		}

		$deleted = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $deleted ) || false === $deleted ) {
			fwrite( STDERR, '[warn] failed to delete smoke term fixture ' . $taxonomy . ':' . $term_id . "\n" );
		}
	}

	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	foreach ( array_keys( (array) $npcink_abilities_toolkit_smoke_user_fixture_ids ) as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 && get_userdata( $user_id ) && ! wp_delete_user( $user_id ) ) {
			fwrite( STDERR, '[warn] failed to delete smoke user fixture ' . $user_id . "\n" );
		}
	}
}

register_shutdown_function( 'npcink_abilities_toolkit_smoke_cleanup_fixtures' );

/**
 * Checks whether a REST collection response contains an ability name.
 *
 * @param mixed  $items REST response data.
 * @param string $name Ability name.
 * @return bool
 */
function npcink_abilities_toolkit_smoke_has_ability_name( $items, $name ) {
	if ( ! is_array( $items ) ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( is_array( $item ) && isset( $item['name'] ) && $name === $item['name'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the Toolkit-owned abilities registered in this WordPress process.
 *
 * @return array<string,mixed>
 */
function npcink_abilities_toolkit_smoke_local_abilities() {
	if ( ! class_exists( 'Npcink_Abilities_Toolkit\\Plugin' ) ) {
		return array();
	}

	$plugin = Npcink_Abilities_Toolkit\Plugin::instance();
	if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'abilities' ) ) {
		return array();
	}

	$registrar = $plugin->abilities();
	if ( ! is_object( $registrar ) || ! method_exists( $registrar, 'all' ) ) {
		return array();
	}

	$abilities = $registrar->all();
	return is_array( $abilities ) ? $abilities : array();
}

/**
 * Fetches all ability catalog pages through REST.
 *
 * @param string $namespace Optional namespace filter.
 * @param string $status_message Assertion message for the first page.
 * @return array<int,mixed>
 */
function npcink_abilities_toolkit_smoke_rest_catalog( $namespace, $status_message ) {
	$items = array();
	$page = 1;
	$total_pages = 1;

	do {
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', $page );
		if ( '' !== $namespace ) {
			$request->set_param( 'namespace', $namespace );
		}

		$response = rest_do_request( $request );
		if ( 1 === $page ) {
			npcink_abilities_toolkit_smoke_assert( 200 === (int) $response->get_status(), $status_message );
		} else {
			npcink_abilities_toolkit_smoke_assert( 200 === (int) $response->get_status(), "Authenticated abilities REST catalog page {$page} returns 200." );
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$items = array_merge( $items, $data );
		}

		$headers = $response->get_headers();
		$total_pages = isset( $headers['X-WP-TotalPages'] ) ? max( 1, (int) $headers['X-WP-TotalPages'] ) : $total_pages;
		++$page;
	} while ( $page <= $total_pages );

	return $items;
}

/**
 * Fetches one ability detail through REST.
 *
 * @param string $ability_id Ability id.
 * @return array<string,mixed>
 */
function npcink_abilities_toolkit_smoke_rest_ability( $ability_id ) {
	$request  = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $ability_id );
	$response = rest_do_request( $request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $response->get_status(), "Authenticated ability REST detail for {$ability_id} returns 200." );

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}

/**
 * Fetches the Toolkit runtime contract endpoint.
 *
 * @return array<string,mixed>
 */
function npcink_abilities_toolkit_smoke_runtime_contract() {
	$request  = new WP_REST_Request( 'GET', '/npcink-abilities-toolkit/v1/contract' );
	$response = rest_do_request( $request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $response->get_status(), 'Runtime contract REST endpoint returns 200.' );

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}

/**
 * Asserts the runtime contract is metadata-only and boundary-safe.
 *
 * @param array<string,mixed> $contract Runtime contract.
 * @param int                 $expected_ability_count Expected ability count.
 * @return void
 */
function npcink_abilities_toolkit_smoke_assert_runtime_contract( array $contract, $expected_ability_count ) {
	npcink_abilities_toolkit_smoke_assert( 'npcink_abilities_toolkit_contract.v1' === (string) ( $contract['schema_version'] ?? '' ), 'Runtime contract exposes the expected schema version.' );
	npcink_abilities_toolkit_smoke_assert( defined( 'NPCINK_ABILITIES_TOOLKIT_VERSION' ) && NPCINK_ABILITIES_TOOLKIT_VERSION === (string) ( $contract['plugin_version'] ?? '' ), 'Runtime contract exposes the active plugin version.' );
	npcink_abilities_toolkit_smoke_assert( '1' === (string) ( $contract['runtime_contract_endpoint_version'] ?? '' ), 'Runtime contract exposes endpoint version.' );
	npcink_abilities_toolkit_smoke_assert( 'npcink_abilities_toolkit' === (string) ( $contract['compatibility']['contract_family'] ?? '' ), 'Runtime contract exposes Toolkit compatibility family.' );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $contract['compatibility']['metadata_only'] ?? false ), 'Runtime contract stays metadata-only.' );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $contract['compatibility']['wordpress_abilities_api_required'] ?? false ), 'Runtime contract declares WordPress Abilities API requirement.' );
	npcink_abilities_toolkit_smoke_assert( $expected_ability_count === (int) ( $contract['ability_count'] ?? -1 ), 'Runtime contract ability count matches the active package profile.' );
	npcink_abilities_toolkit_smoke_assert( $expected_ability_count === array_sum( (array) ( $contract['ability_risk_counts'] ?? array() ) ), 'Runtime contract risk counts add up to the ability count.' );
	npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit' === (string) ( $contract['catalog']['ability_definitions_owner'] ?? '' ), 'Runtime contract names Toolkit as ability definitions owner.' );
	npcink_abilities_toolkit_smoke_assert( 'wordpress_abilities_api' === (string) ( $contract['catalog']['ability_catalog_source'] ?? '' ), 'Runtime contract points hosts to WordPress Abilities API catalog.' );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $contract['schema_controls']['callback_free_hashes'] ?? false ), 'Runtime contract exposes callback-free schema hashes.' );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $contract['write_controls']['dry_run_default'] ?? false ), 'Runtime contract keeps dry-run as the write default.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['write_controls']['commit_default'] ?? true ), 'Runtime contract keeps commit disabled by default.' );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $contract['write_controls']['host_governed_writes'] ?? false ), 'Runtime contract keeps writes host-governed.' );
	npcink_abilities_toolkit_smoke_assert( 'wordpress_abilities_api' === (string) ( $contract['execution_controls']['read_execution_surface'] ?? '' ), 'Runtime contract keeps read execution on WordPress Abilities API.' );
	npcink_abilities_toolkit_smoke_assert( 'host_runtime_after_governance' === (string) ( $contract['execution_controls']['write_execution_surface'] ?? '' ), 'Runtime contract leaves write execution to host runtime after governance.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['execution_controls']['approval_storage'] ?? true ), 'Runtime contract excludes approval storage from Toolkit.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['execution_controls']['audit_truth'] ?? true ), 'Runtime contract excludes audit truth from Toolkit.' );
	npcink_abilities_toolkit_smoke_assert( 'host_governance_layer' === (string) ( $contract['boundary']['approval_truth_owner'] ?? '' ), 'Runtime contract leaves approval truth with the host governance layer.' );
	npcink_abilities_toolkit_smoke_assert( 'host_governance_layer' === (string) ( $contract['boundary']['audit_truth_owner'] ?? '' ), 'Runtime contract leaves audit truth with the host governance layer.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['forbidden_payloads']['approval_records'] ?? true ), 'Runtime contract forbids approval records.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['forbidden_payloads']['audit_records'] ?? true ), 'Runtime contract forbids audit records.' );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $contract['forbidden_payloads']['runtime_state'] ?? true ), 'Runtime contract forbids runtime state.' );

	foreach ( array( 'ability_ids_hash', 'ability_contracts_hash', 'workflow_recipes_hash' ) as $hash_key ) {
		npcink_abilities_toolkit_smoke_assert( 0 === strpos( (string) ( $contract[ $hash_key ] ?? '' ), 'sha256:' ), "Runtime contract exposes {$hash_key} as a sha256 digest." );
	}

	$contract_json = wp_json_encode( $contract );
	foreach ( array( 'execute_callback', 'permission_callback', 'Closure', ABSPATH ) as $forbidden_fragment ) {
		npcink_abilities_toolkit_smoke_assert( false === strpos( (string) $contract_json, (string) $forbidden_fragment ), 'Runtime contract JSON omits internal fragment ' . $forbidden_fragment . '.' );
	}
}

/**
 * Asserts that one REST ability detail exposes governance fields.
 *
 * @param string $ability_id Ability id.
 * @param string $risk_level Expected risk.
 * @param bool   $requires_approval Expected approval requirement.
 * @return void
 */
function npcink_abilities_toolkit_smoke_assert_rest_governance_contract( $ability_id, $risk_level, $requires_approval ) {
	$ability = npcink_abilities_toolkit_smoke_rest_ability( $ability_id );

	npcink_abilities_toolkit_smoke_assert( $ability_id === (string) ( $ability['name'] ?? '' ), "{$ability_id} REST detail keeps the real ability id." );
	npcink_abilities_toolkit_smoke_assert( is_array( $ability['input_schema'] ?? null ), "{$ability_id} REST detail exposes input_schema." );
	npcink_abilities_toolkit_smoke_assert( is_array( $ability['output_schema'] ?? null ), "{$ability_id} REST detail exposes output_schema." );
	npcink_abilities_toolkit_smoke_assert( $risk_level === (string) ( $ability['meta']['npcink']['risk_level'] ?? '' ), "{$ability_id} REST detail exposes risk_level." );
	npcink_abilities_toolkit_smoke_assert( $requires_approval === (bool) ( $ability['meta']['npcink']['requires_approval'] ?? ! $requires_approval ), "{$ability_id} REST detail exposes requires_approval." );
}

/**
 * Asserts that one REST ability detail exposes implementation posture metadata.
 *
 * @param string $ability_id Ability id.
 * @return void
 */
function npcink_abilities_toolkit_smoke_assert_rest_implementation_posture( $ability_id ) {
	$ability = npcink_abilities_toolkit_smoke_rest_ability( $ability_id );
	$posture = isset( $ability['meta']['npcink']['implementation_posture'] ) && is_array( $ability['meta']['npcink']['implementation_posture'] )
		? $ability['meta']['npcink']['implementation_posture']
		: array();

	npcink_abilities_toolkit_smoke_assert( 'npcink_abilities_toolkit_implementation_posture.v1' === (string) ( $posture['schema_version'] ?? '' ), "{$ability_id} REST detail exposes implementation posture schema." );
	npcink_abilities_toolkit_smoke_assert( 'host_governed_dry_run_first' === (string) ( $posture['write_posture'] ?? '' ), "{$ability_id} REST detail exposes dry-run-first write posture." );
	npcink_abilities_toolkit_smoke_assert( 'host_runtime_approval_context_required' === (string) ( $posture['commit_authority'] ?? '' ), "{$ability_id} REST detail leaves commit authority with host runtime." );
	npcink_abilities_toolkit_smoke_assert( 'host_governance_layer' === (string) ( $posture['final_authorization_owner'] ?? '' ), "{$ability_id} REST detail leaves final authorization with host governance." );
	npcink_abilities_toolkit_smoke_assert( 'host_governance_layer' === (string) ( $posture['approval_truth_owner'] ?? '' ), "{$ability_id} REST detail leaves approval truth with host governance." );
	npcink_abilities_toolkit_smoke_assert( 'host_governance_layer' === (string) ( $posture['audit_truth_owner'] ?? '' ), "{$ability_id} REST detail leaves audit truth with host governance." );
	npcink_abilities_toolkit_smoke_assert( true === (bool) ( $posture['dry_run_default'] ?? false ), "{$ability_id} REST detail defaults posture to dry-run." );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $posture['commit_default'] ?? true ), "{$ability_id} REST detail disables commit by default." );
	npcink_abilities_toolkit_smoke_assert( false === (bool) ( $posture['direct_wordpress_write_default'] ?? true ), "{$ability_id} REST detail disables direct WordPress write by default." );
	npcink_abilities_toolkit_smoke_assert( ! empty( $posture['reference_patterns'] ) && is_array( $posture['reference_patterns'] ), "{$ability_id} REST detail exposes reference patterns." );
	npcink_abilities_toolkit_smoke_assert( ! empty( $posture['verification_contract'] ) && is_array( $posture['verification_contract'] ), "{$ability_id} REST detail exposes verification contract." );
	npcink_abilities_toolkit_smoke_assert( ! empty( $posture['required_host_evidence'] ) && is_array( $posture['required_host_evidence'] ), "{$ability_id} REST detail exposes required host evidence." );
	foreach ( array( 'workflow_runtime', 'queue_or_scheduler', 'model_routing', 'provider_credentials', 'approval_storage', 'audit_storage' ) as $forbidden_flag ) {
		npcink_abilities_toolkit_smoke_assert( false === (bool) ( $posture[ $forbidden_flag ] ?? true ), "{$ability_id} REST detail excludes {$forbidden_flag} ownership." );
	}
}

npcink_abilities_toolkit_smoke_assert( function_exists( 'wp_register_ability' ), 'WordPress Abilities API registration function exists.' );
npcink_abilities_toolkit_smoke_assert( function_exists( 'wp_register_ability_category' ), 'WordPress Abilities API category registration function exists.' );
npcink_abilities_toolkit_smoke_assert( function_exists( 'npcink_abilities_toolkit_register_readonly' ), 'Plugin public readonly registration helper is loaded.' );

if ( function_exists( 'wp_get_abilities' ) ) {
	wp_get_abilities();
}

$admin_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin_id = isset( $admin_ids[0] ) ? (int) $admin_ids[0] : 0;
npcink_abilities_toolkit_smoke_assert( $admin_id > 0, 'An administrator user is available for authenticated REST smoke checks.' );

$subscriber_id = wp_insert_user(
	array(
		'user_login' => sanitize_user( 'npcink-smoke-' . npcink_abilities_toolkit_smoke_run_id(), true ),
		'user_pass'  => wp_generate_password( 24, true, true ),
		'role'       => 'subscriber',
	)
);
npcink_abilities_toolkit_smoke_assert( ! is_wp_error( $subscriber_id ) && (int) $subscriber_id > 0, 'A subscriber fixture is available for authenticated permission-denial smoke checks.' );
npcink_abilities_toolkit_smoke_register_user_fixture( (int) $subscriber_id );
wp_set_current_user( (int) $subscriber_id );
$subscriber_contract_response = rest_do_request( new WP_REST_Request( 'GET', '/npcink-abilities-toolkit/v1/contract' ) );
npcink_abilities_toolkit_smoke_assert( 403 === (int) $subscriber_contract_response->get_status(), 'Authenticated subscriber cannot read the manage_options runtime contract.' );

wp_set_current_user( $admin_id );

$abilities_data = npcink_abilities_toolkit_smoke_rest_catalog( '', 'Authenticated abilities REST catalog returns 200.' );
$provider_abilities_data = npcink_abilities_toolkit_smoke_rest_catalog( 'npcink-abilities-toolkit', 'Authenticated provider namespace REST catalog returns 200.' );
$runtime_contract = npcink_abilities_toolkit_smoke_runtime_contract();
npcink_abilities_toolkit_smoke_assert_runtime_contract( $runtime_contract, count( npcink_abilities_toolkit_smoke_local_abilities() ) );
if ( 'light_core_read' !== $npcink_abilities_toolkit_smoke_profile ) {
	npcink_abilities_toolkit_smoke_assert(
		npcink_abilities_toolkit_smoke_has_ability_name( $provider_abilities_data, 'npcink-abilities-toolkit/wp-diagnostics-summary' ),
		'Authenticated abilities REST catalog contains the standalone diagnostics ability.'
	);
	npcink_abilities_toolkit_smoke_assert(
		npcink_abilities_toolkit_smoke_has_ability_name( $provider_abilities_data, 'npcink-abilities-toolkit/wp-ops-diagnostics-detail' ),
		'Authenticated abilities REST catalog contains the standalone ops diagnostics detail ability.'
	);
	npcink_abilities_toolkit_smoke_assert(
		npcink_abilities_toolkit_smoke_has_ability_name( $provider_abilities_data, 'npcink-abilities-toolkit/list-workflow-recipes' ),
		'Authenticated abilities REST catalog contains the workflow recipe discovery ability.'
	);
	npcink_abilities_toolkit_smoke_assert_rest_governance_contract( 'npcink-abilities-toolkit/create-draft', 'write', true );
	npcink_abilities_toolkit_smoke_assert_rest_governance_contract( 'npcink-abilities-toolkit/set-post-seo-meta', 'write', true );
	npcink_abilities_toolkit_smoke_assert_rest_governance_contract( 'npcink-abilities-toolkit/approve-comment', 'write', true );
	npcink_abilities_toolkit_smoke_assert_rest_governance_contract( 'npcink-abilities-toolkit/list-workflow-recipes', 'read', false );
	npcink_abilities_toolkit_smoke_assert_rest_implementation_posture( 'npcink-abilities-toolkit/create-draft' );
	npcink_abilities_toolkit_smoke_assert_rest_implementation_posture( 'npcink-abilities-toolkit/update-post-blocks' );
	npcink_abilities_toolkit_smoke_assert_rest_implementation_posture( 'npcink-abilities-toolkit/update-media-details' );
	npcink_abilities_toolkit_smoke_assert_rest_implementation_posture( 'npcink-abilities-toolkit/trash-post' );
}

if ( 'light_core_read' === $npcink_abilities_toolkit_smoke_profile ) {
	$light_profile_local_abilities = npcink_abilities_toolkit_smoke_local_abilities();
	foreach ( array( 'npcink-abilities-toolkit/site-info', 'npcink-abilities-toolkit/get-post', 'npcink-abilities-toolkit/list-posts', 'npcink-abilities-toolkit/search-posts', 'npcink-abilities-toolkit/search-post-meta' ) as $expected_core_read_id ) {
		npcink_abilities_toolkit_smoke_assert(
			isset( $light_profile_local_abilities[ $expected_core_read_id ] ),
			"Light profile keeps core WordPress read ability {$expected_core_read_id}."
		);
	}
	foreach ( array( 'npcink-abilities-toolkit/get-site-operations-dashboard', 'npcink-abilities-toolkit/get-nonproduction-content-inventory', 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan', 'npcink-abilities-toolkit/build-content-inventory-fix-plan', 'npcink-abilities-toolkit/build-media-inventory-fix-plan', 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan', 'npcink-abilities-toolkit/route-content-intent', 'npcink-abilities-toolkit/build-pattern-page-plan', 'npcink-abilities-toolkit/build-article-block-plan', 'npcink-abilities-toolkit/wp-diagnostics-summary', 'npcink-abilities-toolkit/wp-ops-diagnostics-detail', 'npcink-abilities-toolkit/list-workflow-recipes', 'npcink-abilities-toolkit/create-draft', 'npcink-abilities-toolkit/get-comment-queue-health' ) as $disabled_ability_id ) {
		npcink_abilities_toolkit_smoke_assert(
			! isset( $light_profile_local_abilities[ $disabled_ability_id ] ),
			"Light profile disables optional ability {$disabled_ability_id}."
		);
	}

	echo 'Smoke OK: ' . (int) $GLOBALS['npcink_abilities_toolkit_smoke_assertions'] . " assertions\n";
	return;
}
foreach (
	array(
		'npcink-abilities-toolkit/site-info',
		'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/list-post-types',
		'npcink-abilities-toolkit/list-taxonomies',
		'npcink-abilities-toolkit/count-posts',
		'npcink-abilities-toolkit/list-pages-tree',
		'npcink-abilities-toolkit/list-posts',
		'npcink-abilities-toolkit/get-post',
		'npcink-abilities-toolkit/get-post-context',
		'npcink-abilities-toolkit/get-content-publishing-checklist',
		'npcink-abilities-toolkit/get-content-inventory-health',
		'npcink-abilities-toolkit/get-nonproduction-content-inventory',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/get-bulk-publishing-checklist',
		'npcink-abilities-toolkit/get-internal-link-opportunity-report',
		'npcink-abilities-toolkit/get-site-operations-dashboard',
		'npcink-abilities-toolkit/get-post-publish-risk-report',
		'npcink-abilities-toolkit/get-article-publish-preflight-context',
		'npcink-abilities-toolkit/get-content-refresh-opportunities',
		'npcink-abilities-toolkit/get-old-article-refresh-context',
		'npcink-abilities-toolkit/get-internal-link-graph-health',
		'npcink-abilities-toolkit/get-media-cleanup-opportunities',
		'npcink-abilities-toolkit/list-media-backups',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
		'npcink-abilities-toolkit/build-media-alt-apply-plan',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-rename-plan',
		'npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions',
		'npcink-abilities-toolkit/propose-post-taxonomy-terms',
		'npcink-abilities-toolkit/get-page-structure-health',
		'npcink-abilities-toolkit/get-seo-geo-gap-report',
		'npcink-abilities-toolkit/get-site-style-baseline',
		'npcink-abilities-toolkit/build-article-workflow-context',
		'npcink-abilities-toolkit/get-publishing-calendar-context',
		'npcink-abilities-toolkit/get-media-inventory-health',
		'npcink-abilities-toolkit/inspect-media-asset',
		'npcink-abilities-toolkit/get-post-seo-geo-readiness',
		'npcink-abilities-toolkit/get-site-topic-coverage-report',
		'npcink-abilities-toolkit/get-taxonomy-inventory-health',
		'npcink-abilities-toolkit/get-revision-change-risk-report',
			'npcink-abilities-toolkit/resolve-url-to-post',
			'npcink-abilities-toolkit/get-post-blocks',
			'npcink-abilities-toolkit/list-post-revisions',
			'npcink-abilities-toolkit/get-block-theme-context',
				'npcink-abilities-toolkit/get-template-blocks',
				'npcink-abilities-toolkit/get-template-part-blocks',
				'npcink-abilities-toolkit/build-block-theme-site-plan',
				'npcink-abilities-toolkit/route-content-intent',
				'npcink-abilities-toolkit/build-pattern-page-plan',
			'npcink-abilities-toolkit/build-article-block-plan',
		'npcink-abilities-toolkit/list-media',
		'npcink-abilities-toolkit/list-terms',
		'npcink-abilities-toolkit/list-taxonomy-terms',
		'npcink-abilities-toolkit/list-categories',
		'npcink-abilities-toolkit/list-tags',
		'npcink-abilities-toolkit/get-term',
		'npcink-abilities-toolkit/propose-post-excerpt',
		'npcink-abilities-toolkit/resolve-post-metadata-plan',
			'npcink-abilities-toolkit/list-users',
			'npcink-abilities-toolkit/list-comments',
			'npcink-abilities-toolkit/build-comment-moderation-suggest',
			'npcink-abilities-toolkit/compose-comment-moderation-result',
			'npcink-abilities-toolkit/build-comment-mention-reply-suggest',
			'npcink-abilities-toolkit/read-comment-trigger-queue',
			'npcink-abilities-toolkit/get-comment-queue-health',
			'npcink-abilities-toolkit/get-comment-action-priority-queue',
			'npcink-abilities-toolkit/get-comment-compliance-handoff',
			'npcink-abilities-toolkit/compose-comment-mention-reply-result',
			'npcink-abilities-toolkit/build-comment-moderation-batch-suggest',
			'npcink-abilities-toolkit/compose-comment-moderation-batch-result',
			'npcink-abilities-toolkit/list-menus',
			'npcink-abilities-toolkit/get-menu',
			'npcink-abilities-toolkit/search-posts',
			'npcink-abilities-toolkit/search-post-meta',
			'npcink-abilities-toolkit/resolve-internal-link-targets',
			'npcink-abilities-toolkit/build-inline-image-blocks',
			'npcink-abilities-toolkit/build-media-seo-assets',
			'npcink-abilities-toolkit/geo-analyze',
			'npcink-abilities-toolkit/optimize-media-metadata',
			'npcink-abilities-toolkit/position-inline-image-blocks',
			'npcink-abilities-toolkit/build-article-optimization-report',
			'npcink-abilities-toolkit/seo-report-context',
			'npcink-abilities-toolkit/read-post-optimization-context',
			'npcink-abilities-toolkit/build-article-single-optimization-suggest',
			'npcink-abilities-toolkit/build-article-optimization-apply-plan',
			'npcink-abilities-toolkit/compose-article-optimization-apply-result',
			'npcink-abilities-toolkit/extract-reference-post-style',
			'npcink-abilities-toolkit/extract-style-baseline',
			'npcink-abilities-toolkit/build-article-production-fingerprint',
			'npcink-abilities-toolkit/check-article-production-duplicate',
			'npcink-abilities-toolkit/review-article-output-light',
			'npcink-abilities-toolkit/compose-article-production-result',
			'npcink-abilities-toolkit/compose-article-draft-result',
			'npcink-abilities-toolkit/resolve-article-publication-decision',
			'npcink-abilities-toolkit/build-article-style-profile',
			'npcink-abilities-toolkit/get-post-stats',
		'npcink-abilities-toolkit/list-revisions',
		'npcink-abilities-toolkit/get-post-meta',
		'npcink-abilities-toolkit/list-pages',
		'npcink-abilities-toolkit/get-page',
		'npcink-abilities-toolkit/inspect-page-structure',
		'npcink-abilities-toolkit/create-draft',
			'npcink-abilities-toolkit/update-post',
			'npcink-abilities-toolkit/set-post-seo-meta',
			'npcink-abilities-toolkit/patch-post-content',
			'npcink-abilities-toolkit/update-post-blocks',
			'npcink-abilities-toolkit/update-template-blocks',
			'npcink-abilities-toolkit/upsert-template-blocks',
			'npcink-abilities-toolkit/update-template-part-blocks',
			'npcink-abilities-toolkit/set-post-slug',
		'npcink-abilities-toolkit/set-post-author',
		'npcink-abilities-toolkit/set-post-template',
		'npcink-abilities-toolkit/set-post-format',
		'npcink-abilities-toolkit/create-term',
		'npcink-abilities-toolkit/update-term',
		'npcink-abilities-toolkit/set-post-terms',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/patch-setting-value',
		'npcink-abilities-toolkit/upload-media-from-url',
			'npcink-abilities-toolkit/optimize-media-asset',
				'npcink-abilities-toolkit/replace-media-file',
				'npcink-abilities-toolkit/restore-media-backup',
				'npcink-abilities-toolkit/rename-media-file',
			'npcink-abilities-toolkit/adopt-cloud-media-derivative',
		'npcink-abilities-toolkit/set-post-featured-image',
		'npcink-abilities-toolkit/schedule-post',
		'npcink-abilities-toolkit/publish-post',
		'npcink-abilities-toolkit/restore-post',
		'npcink-abilities-toolkit/approve-comment',
		'npcink-abilities-toolkit/reply-comment',
		'npcink-abilities-toolkit/delete-term',
		'npcink-abilities-toolkit/merge-terms',
		'npcink-abilities-toolkit/bulk-update-post-terms',
		'npcink-abilities-toolkit/spam-comment',
		'npcink-abilities-toolkit/trash-comment',
		'npcink-abilities-toolkit/delete-media-permanently',
		'npcink-abilities-toolkit/trash-post',
		'npcink-abilities-toolkit/delete-post-permanently',
	) as $migrated_ability_id
) {
	npcink_abilities_toolkit_smoke_assert(
		! function_exists( 'wp_has_ability' ) || wp_has_ability( $migrated_ability_id ),
		"WordPress registry contains core package ability {$migrated_ability_id}."
	);
}

$categories_request  = new WP_REST_Request( 'GET', '/wp-abilities/v1/categories' );
$categories_response = rest_do_request( $categories_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $categories_response->get_status(), 'Authenticated categories REST catalog returns 200.' );

$diagnostics_run_request  = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/wp-diagnostics-summary/run' );
$diagnostics_run_request->set_query_params( array( 'input' => array() ) );
	$diagnostics_run_response = rest_do_request( $diagnostics_run_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $diagnostics_run_response->get_status(), 'Authenticated diagnostics ability run returns 200.' );
	$diagnostics_run_data = $diagnostics_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( isset( $diagnostics_run_data['current_user']['included'] ) && false === $diagnostics_run_data['current_user']['included'], 'Diagnostics summary omits current user details by default.' );

	$ops_diagnostics_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/wp-ops-diagnostics-detail/run' );
	$ops_diagnostics_run_request->set_query_params( array( 'input' => array( 'max_cron_events' => 5, 'max_plugins_per_group' => 5 ) ) );
	$ops_diagnostics_run_response = rest_do_request( $ops_diagnostics_run_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $ops_diagnostics_run_response->get_status(), 'Authenticated ops diagnostics detail ability run returns 200.' );
	$ops_diagnostics_run_data = $ops_diagnostics_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( 'summary' === (string) ( $ops_diagnostics_run_data['profile'] ?? '' ), 'Ops diagnostics defaults to the summary profile.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_run_data['current_user']['included'] ) && false === $ops_diagnostics_run_data['current_user']['included'], 'Ops diagnostics omits current user details by default.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_run_data['database']['included'] ) && false === $ops_diagnostics_run_data['database']['included'], 'Ops diagnostics omits database table status by default.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_run_data['plugins']['included'] ) && false === $ops_diagnostics_run_data['plugins']['included'], 'Ops diagnostics summary profile omits plugin rows by default.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_run_data['error_log']['included'] ) && false === $ops_diagnostics_run_data['error_log']['included'], 'Ops diagnostics summary profile omits error log inspection by default.' );

	$ops_diagnostics_detail_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/wp-ops-diagnostics-detail/run' );
	$ops_diagnostics_detail_request->set_query_params( array( 'input' => array( 'profile' => 'detail', 'max_cron_events' => 5, 'max_plugins_per_group' => 5 ) ) );
	$ops_diagnostics_detail_response = rest_do_request( $ops_diagnostics_detail_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $ops_diagnostics_detail_response->get_status(), 'Authenticated ops diagnostics detail profile run returns 200.' );
	$ops_diagnostics_detail_data = $ops_diagnostics_detail_response->get_data();
	npcink_abilities_toolkit_smoke_assert( 'detail' === (string) ( $ops_diagnostics_detail_data['profile'] ?? '' ), 'Ops diagnostics returns the requested detail profile.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_detail_data['plugins']['groups_included']['inactive'] ) && false === $ops_diagnostics_detail_data['plugins']['groups_included']['inactive'], 'Ops diagnostics detail profile omits inactive plugin rows by default.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_detail_data['plugins']['max_plugins_per_group'] ) && 5 === (int) $ops_diagnostics_detail_data['plugins']['max_plugins_per_group'], 'Ops diagnostics detail profile honors plugin group row bounds.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_detail_data['error_log']['summary']['fatal_count'] ), 'Ops diagnostics detail profile returns error log severity summary without log contents.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_detail_data['error_log']['source_summary'] ) && is_array( $ops_diagnostics_detail_data['error_log']['source_summary'] ), 'Ops diagnostics detail profile returns error log source summary.' );
	npcink_abilities_toolkit_smoke_assert( isset( $ops_diagnostics_detail_data['error_log']['top_messages'] ) && is_array( $ops_diagnostics_detail_data['error_log']['top_messages'] ), 'Ops diagnostics detail profile returns error log top message summary.' );
	if ( ! empty( $ops_diagnostics_detail_data['error_log']['source_summary'] ) ) {
		$ops_diagnostics_first_source = reset( $ops_diagnostics_detail_data['error_log']['source_summary'] );
		npcink_abilities_toolkit_smoke_assert( is_array( $ops_diagnostics_first_source ) && array_key_exists( 'message_fingerprint', $ops_diagnostics_first_source ), 'Ops diagnostics source summary includes message fingerprints when entries exist.' );
	}

	$workflow_recipes_run_request  = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/list-workflow-recipes/run' );
	$workflow_recipes_run_request->set_query_params( array( 'input' => array() ) );
	$workflow_recipes_run_response = rest_do_request( $workflow_recipes_run_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $workflow_recipes_run_response->get_status(), 'Authenticated workflow recipe discovery ability run returns 200.' );
	$workflow_recipes_run_data = $workflow_recipes_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( isset( $workflow_recipes_run_data['cases']['article_publish_preflight'] ), 'Workflow recipe discovery returns publish preflight definition.' );
	npcink_abilities_toolkit_smoke_assert( isset( $workflow_recipes_run_data['cases']['article_optimization'] ), 'Workflow recipe discovery returns article optimization definition.' );
	npcink_abilities_toolkit_smoke_assert( 'media_optimization_v1' === (string) ( $workflow_recipes_run_data['cases']['media_optimization']['recipe_aliases'][0] ?? '' ), 'Workflow recipe discovery returns canonical media optimization projection alias.' );
	npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/build-media-optimization-plan' === (string) ( $workflow_recipes_run_data['cases']['media_optimization']['entrypoint_ability_id'] ?? '' ), 'Media optimization workflow projection uses the read-only plan entrypoint.' );
	npcink_abilities_toolkit_smoke_assert( 'approval_request' === (string) ( $workflow_recipes_run_data['cases']['media_optimization']['handoff']['kind'] ?? '' ), 'Media optimization workflow projection hands off a governed approval request.' );
	npcink_abilities_toolkit_smoke_assert( isset( $workflow_recipes_run_data['cases']['article_media_handoff'] ), 'Workflow recipe discovery returns article media handoff definition.' );

	$workflow_recipe_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-workflow-recipe/run' );
	$workflow_recipe_run_request->set_query_params(
		array(
			'input' => array(
				'recipe_id' => 'npcink-abilities-toolkit/recipes/comment-compliance-handoff',
			),
		)
	);
	$workflow_recipe_run_response = rest_do_request( $workflow_recipe_run_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $workflow_recipe_run_response->get_status(), 'Authenticated workflow recipe detail ability run returns 200.' );
	$workflow_recipe_run_data = $workflow_recipe_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/get-comment-compliance-handoff' === (string) ( $workflow_recipe_run_data['entrypoint_ability_id'] ?? '' ), 'Workflow recipe detail returns comment handoff entrypoint.' );

	$smoke_post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $npcink_abilities_toolkit_smoke_pattern . ' Context Post',
			'post_content' => '<!-- wp:paragraph --><p>This local smoke post verifies the post context and publishing checklist abilities.</p><!-- /wp:paragraph -->',
			'post_excerpt' => 'Smoke context post.',
		),
		true
	);
	npcink_abilities_toolkit_smoke_assert( ! is_wp_error( $smoke_post_id ) && (int) $smoke_post_id > 0, 'Temporary smoke post is available for post-context ability runs.' );
	npcink_abilities_toolkit_smoke_register_post_fixture( (int) $smoke_post_id );
	update_post_meta( (int) $smoke_post_id, '_npcink_ai_smoke_search_marker', 'local smoke metadata search marker' );

$post_context_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-post-context/run' );
$post_context_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'include_meta'  => false,
			'include_terms' => false,
		),
	)
);
$post_context_run_response = rest_do_request( $post_context_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $post_context_run_response->get_status(), 'Authenticated post context ability run returns 200.' );
$post_context_run_data = $post_context_run_response->get_data();

$search_posts_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/search-posts/run' );
$search_posts_run_request->set_query_params(
	array(
		'input' => array(
			'search'    => 'context',
			'post_types' => array( 'post' ),
			'statuses'  => array( 'draft' ),
			'per_page'  => 5,
		),
	)
);
$search_posts_run_response = rest_do_request( $search_posts_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $search_posts_run_response->get_status(), 'Authenticated post keyword search ability run returns 200.' );
$search_posts_run_data = $search_posts_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( ! empty( $search_posts_run_data['items'] ), 'Post keyword search returns the local smoke post candidate.' );

$search_meta_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/search-post-meta/run' );
$search_meta_run_request->set_query_params(
	array(
		'input' => array(
			'search'    => 'metadata search marker',
			'meta_keys' => array( '_npcink_ai_smoke_search_marker' ),
			'statuses'  => array( 'draft' ),
			'per_page'  => 5,
		),
	)
);
$search_meta_run_response = rest_do_request( $search_meta_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $search_meta_run_response->get_status(), 'Authenticated post meta search ability run returns 200.' );
$search_meta_run_data = $search_meta_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( ! empty( $search_meta_run_data['items'][0]['matched_meta_keys'] ), 'Post meta search returns matched meta keys.' );

$publishing_checklist_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-content-publishing-checklist/run' );
$publishing_checklist_run_request->set_query_params(
	array(
		'input' => array(
			'post_id' => (int) $smoke_post_id,
		),
	)
);
$publishing_checklist_run_response = rest_do_request( $publishing_checklist_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $publishing_checklist_run_response->get_status(), 'Authenticated publishing checklist ability run returns 200.' );
$publishing_checklist_run_data = $publishing_checklist_run_response->get_data();

$smoke_candidate_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => $npcink_abilities_toolkit_smoke_pattern . ' Internal Link Candidate',
		'post_content' => '<p>This published smoke post provides a related ability workflow candidate for internal link opportunity checks.</p>',
	),
	true
);
npcink_abilities_toolkit_smoke_assert( ! is_wp_error( $smoke_candidate_id ) && (int) $smoke_candidate_id > 0, 'Temporary smoke candidate post is available for internal-link ability runs.' );
npcink_abilities_toolkit_smoke_register_post_fixture( (int) $smoke_candidate_id );

$inventory_health_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-content-inventory-health/run' );
$inventory_health_run_request->set_query_params(
	array(
		'input' => array(
			'post_type' => 'post',
			'status'    => 'any',
			'per_page'  => 10,
		),
	)
);
$inventory_health_run_response = rest_do_request( $inventory_health_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $inventory_health_run_response->get_status(), 'Authenticated content inventory health ability run returns 200.' );

$test_inventory_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-nonproduction-content-inventory/run' );
$test_inventory_run_request->set_query_params(
	array(
		'input' => array(
			'patterns' => array( $npcink_abilities_toolkit_smoke_pattern ),
			'per_page' => 10,
		),
	)
);
$test_inventory_run_response = rest_do_request( $test_inventory_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $test_inventory_run_response->get_status(), 'Authenticated nonproduction content inventory ability run returns 200.' );
$test_inventory_run_data = $test_inventory_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( true === ( $test_inventory_run_data['data']['detected'] ?? null ), 'Nonproduction content inventory detects smoke content.' );

$test_cleanup_plan_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan/run' );
$test_cleanup_plan_run_request->set_query_params(
	array(
		'input' => array(
			'patterns'    => array( $npcink_abilities_toolkit_smoke_pattern ),
			'max_actions' => 5,
		),
	)
);
$test_cleanup_plan_run_response = rest_do_request( $test_cleanup_plan_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $test_cleanup_plan_run_response->get_status(), 'Authenticated nonproduction content cleanup plan ability run returns 200.' );
$test_cleanup_plan_run_data = $test_cleanup_plan_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( false === ( $test_cleanup_plan_run_data['data']['commit_execution'] ?? null ), 'Nonproduction content cleanup plan does not execute commits.' );
npcink_abilities_toolkit_smoke_assert( 'batch' === (string) ( $test_cleanup_plan_run_data['data']['proposal_mode'] ?? '' ), 'Nonproduction content cleanup plan requests batch proposal mode.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $test_cleanup_plan_run_data['data']['batch_approval'] ?? false ), 'Nonproduction content cleanup plan requests one approval for the generated batch.' );

$content_fix_plan_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-content-inventory-fix-plan/run' );
$content_fix_plan_run_request->set_query_params(
	array(
		'input' => array(
			'post_ids'    => array( (int) $smoke_post_id ),
			'issue_types' => array( 'seo_title', 'seo_description', 'featured_media' ),
			'max_actions' => 5,
		),
	)
);
$content_fix_plan_run_response = rest_do_request( $content_fix_plan_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $content_fix_plan_run_response->get_status(), 'Authenticated content inventory fix plan ability run returns 200.' );
$content_fix_plan_run_data = $content_fix_plan_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( false === ( $content_fix_plan_run_data['data']['commit_execution'] ?? null ), 'Content inventory fix plan does not execute commits.' );

$bulk_checklist_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-bulk-publishing-checklist/run' );
$bulk_checklist_run_request->set_query_params(
	array(
		'input' => array(
			'post_ids' => array( (int) $smoke_post_id, (int) $smoke_candidate_id ),
		),
	)
);
$bulk_checklist_run_response = rest_do_request( $bulk_checklist_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $bulk_checklist_run_response->get_status(), 'Authenticated bulk publishing checklist ability run returns 200.' );

$internal_link_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-internal-link-opportunity-report/run' );
$internal_link_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'focus_keyword' => 'ability workflow',
			'max_targets'   => 3,
		),
	)
);
$internal_link_run_response = rest_do_request( $internal_link_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $internal_link_run_response->get_status(), 'Authenticated internal link opportunity ability run returns 200.' );
$internal_link_run_data = $internal_link_run_response->get_data();

$site_operations_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-site-operations-dashboard/run' );
$site_operations_run_request->set_query_params(
	array(
		'input' => array(
			'post_type' => 'post',
			'per_page'  => 10,
		),
	)
);
$site_operations_run_response = rest_do_request( $site_operations_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $site_operations_run_response->get_status(), 'Authenticated site operations dashboard ability run returns 200.' );

$publish_risk_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-post-publish-risk-report/run' );
$publish_risk_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'focus_keyword' => 'ability workflow',
		),
	)
);
$publish_risk_run_response = rest_do_request( $publish_risk_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $publish_risk_run_response->get_status(), 'Authenticated post publish risk report ability run returns 200.' );
$publish_risk_run_data = $publish_risk_run_response->get_data();

$article_publish_preflight_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-article-publish-preflight-context/run' );
$article_publish_preflight_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'focus_keyword' => 'ability workflow',
			'window_days'   => 30,
		),
	)
);
$article_publish_preflight_run_response = rest_do_request( $article_publish_preflight_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_publish_preflight_run_response->get_status(), 'Authenticated article publish preflight context ability run returns 200.' );
$article_publish_preflight_run_data = $article_publish_preflight_run_response->get_data();

$refresh_opportunities_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-content-refresh-opportunities/run' );
$refresh_opportunities_run_request->set_query_params(
	array(
		'input' => array(
			'post_type'      => 'post',
			'status'         => 'any',
			'per_page'       => 10,
			'min_word_count' => 200,
		),
	)
);
$refresh_opportunities_run_response = rest_do_request( $refresh_opportunities_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $refresh_opportunities_run_response->get_status(), 'Authenticated content refresh opportunities ability run returns 200.' );
$refresh_opportunities_run_data = $refresh_opportunities_run_response->get_data();

$link_graph_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-internal-link-graph-health/run' );
$link_graph_run_request->set_query_params(
	array(
		'input' => array(
			'post_type' => 'post',
			'status'    => 'any',
			'per_page'  => 10,
		),
	)
);
$link_graph_run_response = rest_do_request( $link_graph_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $link_graph_run_response->get_status(), 'Authenticated internal link graph health ability run returns 200.' );
$link_graph_run_data = $link_graph_run_response->get_data();

$old_article_refresh_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-old-article-refresh-context/run' );
$old_article_refresh_run_request->set_query_params(
	array(
		'input' => array(
			'post_type'     => 'post',
			'status'        => 'any',
			'per_page'      => 10,
			'topic_seed'    => 'ability workflow',
			'post_id'       => (int) $smoke_post_id,
			'focus_keyword' => 'ability workflow',
		),
	)
);
$old_article_refresh_run_response = rest_do_request( $old_article_refresh_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $old_article_refresh_run_response->get_status(), 'Authenticated old article refresh context ability run returns 200.' );
$old_article_refresh_run_data = $old_article_refresh_run_response->get_data();

$post_optimization_context_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/read-post-optimization-context/run' );
$post_optimization_context_run_request->set_query_params(
	array(
		'input' => array(
			'post_id' => (int) $smoke_post_id,
		),
	)
);
$post_optimization_context_run_response = rest_do_request( $post_optimization_context_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $post_optimization_context_run_response->get_status(), 'Authenticated post optimization context ability run returns 200.' );
$post_optimization_context_run_data = $post_optimization_context_run_response->get_data();

$seo_report_context_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/seo-report-context/run' );
$seo_report_context_run_request->set_query_params(
	array(
		'input' => array(
			'input'         => 'This local smoke article needs more direct coverage for the workflow proof.',
			'focus_keyword' => 'ability workflow',
		),
	)
);
$seo_report_context_run_response = rest_do_request( $seo_report_context_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $seo_report_context_run_response->get_status(), 'Authenticated SEO report context ability run returns 200.' );
$seo_report_context_run_data = $seo_report_context_run_response->get_data();

$article_optimization_suggest_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-article-single-optimization-suggest/run' );
$article_optimization_suggest_run_request->set_query_params(
	array(
		'input' => array(
			'post'              => array(
				'id'        => (int) $smoke_post_id,
				'title'     => $npcink_abilities_toolkit_smoke_pattern . ' Context Post',
				'status'    => 'draft',
				'post_type' => 'post',
				'excerpt'   => 'Smoke context post.',
				'slug'      => 'npcink-abilities-toolkit-smoke-context-post',
				'content'   => 'This local smoke article needs a clearer optimization handoff.',
				'seo'       => array(
					'provider'    => 'generic',
					'title'       => '',
					'description' => '',
				),
			),
			'generated_excerpt' => array(
				'proposal_text' => 'A concise smoke excerpt for the optimization workflow.',
			),
			'generated_seo'     => array(
				'title'       => 'Ability Workflow Smoke',
				'description' => 'A smoke-tested optimization workflow handoff.',
			),
			'seo_analysis'      => $seo_report_context_run_data['data'] ?? array(),
			'geo_analysis'      => array(
				'recommendations' => array(
					array(
						'type'     => 'faq_candidate',
						'priority' => 'medium',
						'title'    => 'Add an answer-first FAQ.',
						'detail'   => 'Clarify the workflow proof with a direct answer.',
					),
				),
			),
			'focus_keyword'     => 'ability workflow',
			'keywords'          => array( 'ability workflow' ),
		),
	)
);
$article_optimization_suggest_run_response = rest_do_request( $article_optimization_suggest_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_optimization_suggest_run_response->get_status(), 'Authenticated article optimization suggestion ability run returns 200.' );
$article_optimization_suggest_run_data = $article_optimization_suggest_run_response->get_data();

$article_optimization_apply_plan_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-article-optimization-apply-plan/run' );
$article_optimization_apply_plan_run_request->set_query_params(
	array(
		'input' => array(
			'post'              => array(
				'id'      => (int) $smoke_post_id,
				'title'   => $npcink_abilities_toolkit_smoke_pattern . ' Context Post',
				'status'  => 'draft',
				'excerpt' => 'Smoke context post.',
			),
			'report'            => array(
				'summary' => array(
					'status'                => 'needs_attention',
					'high_priority_count'   => 1,
					'total_recommendations' => 2,
				),
				'geo'     => array(
					'summary' => array(
						'faq_candidate_count' => 1,
					),
				),
			),
			'optimization_plan' => array(
				'excerpt_mode' => 'apply',
				'seo_mode'     => 'suggest',
			),
			'generated_excerpt' => array(
				'proposal_text' => 'A concise smoke excerpt for the optimization workflow.',
			),
		),
	)
);
$article_optimization_apply_plan_run_response = rest_do_request( $article_optimization_apply_plan_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_optimization_apply_plan_run_response->get_status(), 'Authenticated article optimization apply plan ability run returns 200.' );
$article_optimization_apply_plan_run_data = $article_optimization_apply_plan_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( 'article_optimization_apply_plan' === (string) ( $article_optimization_apply_plan_run_data['data']['artifact_type'] ?? '' ), 'Article optimization apply plan declares the Core-ready artifact type.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_optimization_apply_plan_run_data['data']['requires_approval'] ?? false ), 'Article optimization apply plan requires host approval.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_optimization_apply_plan_run_data['data']['dry_run'] ?? false ), 'Article optimization apply plan remains dry-run.' );
npcink_abilities_toolkit_smoke_assert( false === (bool) ( $article_optimization_apply_plan_run_data['data']['commit_execution'] ?? true ), 'Article optimization apply plan does not execute commits.' );
$article_optimization_smoke_actions = is_array( $article_optimization_apply_plan_run_data['data']['write_actions'] ?? null ) ? $article_optimization_apply_plan_run_data['data']['write_actions'] : array();
npcink_abilities_toolkit_smoke_assert( 1 === count( $article_optimization_smoke_actions ), 'Article optimization apply plan emits one governed write action for the reviewed excerpt.' );
npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/update-post' === (string) ( $article_optimization_smoke_actions[0]['target_ability_id'] ?? '' ), 'Article optimization apply plan targets update-post for excerpt changes.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_optimization_smoke_actions[0]['input']['dry_run'] ?? false ) && false === (bool) ( $article_optimization_smoke_actions[0]['input']['commit'] ?? true ), 'Article optimization apply plan write action is dry-run and non-commit.' );

$content_intent_route_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/route-content-intent/run' );
$content_intent_route_run_request->set_query_params(
	array(
		'input' => array(
			'prompt' => 'Build a modern landing page with an image and responsive layout.',
		),
	)
);
$content_intent_route_run_response = rest_do_request( $content_intent_route_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $content_intent_route_run_response->get_status(), 'Authenticated content intent route ability run returns 200.' );
$content_intent_route_run_data = $content_intent_route_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( 'content_intent_route' === (string) ( $content_intent_route_run_data['data']['artifact_type'] ?? '' ), 'Content intent route declares the routing artifact type.' );
npcink_abilities_toolkit_smoke_assert( 'pattern_page_plan' === (string) ( $content_intent_route_run_data['data']['route']['route'] ?? '' ), 'Content intent route maps landing page prompts to the pattern page recipe.' );
npcink_abilities_toolkit_smoke_assert( false === (bool) ( $content_intent_route_run_data['data']['prompt_is_authorization'] ?? true ), 'Content intent route keeps prompts non-authorizing.' );
npcink_abilities_toolkit_smoke_assert( false === isset( $content_intent_route_run_data['data']['write_actions'] ), 'Content intent route does not emit write actions.' );

$content_intent_guardrailed_homepage_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/route-content-intent/run' );
$content_intent_guardrailed_homepage_request->set_query_params(
	array(
		'input' => array(
			'prompt' => '把首页改造成一个基础落地页：顶部有清晰的大标题和简短介绍，下面有一个行动按钮，再下面展示最新文章和分类入口。不要改导航，不要改 global styles，不要写 theme.json，不要写主题文件，不要输出 raw template HTML，只通过块主题模板 proposal 来处理。',
		),
	)
);
$content_intent_guardrailed_homepage_response = rest_do_request( $content_intent_guardrailed_homepage_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $content_intent_guardrailed_homepage_response->get_status(), 'Authenticated guardrailed homepage template intent route ability run returns 200.' );
$content_intent_guardrailed_homepage_data = $content_intent_guardrailed_homepage_response->get_data();
npcink_abilities_toolkit_smoke_assert( 'block_theme_site_plan' === (string) ( $content_intent_guardrailed_homepage_data['data']['route']['route'] ?? '' ), 'Content intent route keeps guardrailed homepage layout prompts on the block theme site plan route.' );
npcink_abilities_toolkit_smoke_assert( 'customize_template_layout' === (string) ( $content_intent_guardrailed_homepage_data['data']['route']['recommended_plan_input']['intent'] ?? '' ), 'Content intent route recommends template layout for guardrailed homepage prompts.' );
npcink_abilities_toolkit_smoke_assert( 'homepage_landing' === (string) ( $content_intent_guardrailed_homepage_data['data']['route']['recommended_plan_input']['layout_profile'] ?? '' ), 'Content intent route recommends homepage landing for guardrailed homepage prompts.' );
npcink_abilities_toolkit_smoke_assert( false === isset( $content_intent_guardrailed_homepage_data['data']['write_actions'] ), 'Content intent route does not emit write actions for guardrailed homepage prompts.' );

foreach (
	array(
		array(
			'prompt' => 'Change the navigation menu and add a Products link.',
			'reason' => 'navigation_write_not_supported',
			'label'  => 'navigation menu writes',
		),
		array(
			'prompt' => 'Change global styles and write a theme.json color patch.',
			'reason' => 'global_styles_write_not_supported',
			'label'  => 'global style writes',
		),
		array(
			'prompt' => 'Directly execute a custom HTML template change.',
			'reason' => 'custom_html_template_not_supported',
			'label'  => 'custom HTML template writes',
		),
	) as $content_intent_unsupported_case
) {
	$content_intent_unsupported_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/route-content-intent/run' );
	$content_intent_unsupported_request->set_query_params(
		array(
			'input' => array(
				'prompt' => $content_intent_unsupported_case['prompt'],
			),
		)
	);
	$content_intent_unsupported_response = rest_do_request( $content_intent_unsupported_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $content_intent_unsupported_response->get_status(), 'Authenticated content intent unsupported route ability run returns 200 for ' . $content_intent_unsupported_case['label'] . '.' );
	$content_intent_unsupported_data = $content_intent_unsupported_response->get_data();
	npcink_abilities_toolkit_smoke_assert( 'unsupported' === (string) ( $content_intent_unsupported_data['data']['route']['route'] ?? '' ), 'Content intent route fails closed for ' . $content_intent_unsupported_case['label'] . '.' );
	npcink_abilities_toolkit_smoke_assert( '' === (string) ( $content_intent_unsupported_data['data']['route']['plan_ability_id'] ?? 'unexpected' ), 'Content intent route does not select a plan ability for ' . $content_intent_unsupported_case['label'] . '.' );
	npcink_abilities_toolkit_smoke_assert( $content_intent_unsupported_case['reason'] === (string) ( $content_intent_unsupported_data['data']['route']['unsupported_reason'] ?? '' ), 'Content intent route reports the precise unsupported reason for ' . $content_intent_unsupported_case['label'] . '.' );
	npcink_abilities_toolkit_smoke_assert( false === isset( $content_intent_unsupported_data['data']['write_actions'] ), 'Content intent route does not emit write actions for ' . $content_intent_unsupported_case['label'] . '.' );
}

$article_block_plan_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-article-block-plan/run' );
$article_block_plan_run_request->set_query_params(
	array(
		'input' => array(
			'title'              => 'Toolkit Article Block Smoke',
			'article_template'   => 'comparison-review',
			'responsive_profile' => 'article_standard',
			'media_strategy'     => 'none',
			'variables'          => array(
				'dek'         => 'Gutenberg-native article block smoke.',
				'intro'       => 'This verifies article block planning through the WordPress Abilities REST surface.',
				'takeaways'   => array(
					'Draft only',
					'Proposal required',
					'Mobile columns stack',
				),
				'sections'    => array(
					array(
						'title'      => 'Editorial structure',
						'paragraphs' => array( 'The article is composed from headings, paragraphs, lists, comparison columns, and FAQ details.' ),
					),
					array(
						'title'      => 'Governance',
						'paragraphs' => array( 'The plan remains dry-run and hands off to Core proposals.' ),
					),
					array(
						'title'      => 'Responsiveness',
						'paragraphs' => array( 'Columns use Gutenberg mobile stacking.' ),
					),
				),
				'comparisons' => array(
					array(
						'title'       => 'HTML',
						'description' => 'Flexible but harder to keep editable.',
					),
					array(
						'title'       => 'Blocks',
						'description' => 'Structured and reviewable.',
					),
				),
			),
		),
	)
);
$article_block_plan_run_response = rest_do_request( $article_block_plan_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_block_plan_run_response->get_status(), 'Authenticated article block plan ability run returns 200.' );
$article_block_plan_run_data = $article_block_plan_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( 'article_block_plan' === (string) ( $article_block_plan_run_data['data']['artifact_type'] ?? '' ), 'Article block plan declares the Core-ready artifact type.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_block_plan_run_data['data']['requires_approval'] ?? false ), 'Article block plan requires host approval.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_block_plan_run_data['data']['dry_run'] ?? false ), 'Article block plan remains dry-run.' );
npcink_abilities_toolkit_smoke_assert( false === (bool) ( $article_block_plan_run_data['data']['commit_execution'] ?? true ), 'Article block plan does not execute commits.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_block_plan_run_data['data']['editorial_quality']['uses_native_blocks'] ?? false ), 'Article block plan reports native block usage.' );
npcink_abilities_toolkit_smoke_assert( true === (bool) ( $article_block_plan_run_data['data']['responsive_quality']['uses_mobile_stack'] ?? false ), 'Article block plan reports mobile stacking.' );
$article_block_smoke_actions = is_array( $article_block_plan_run_data['data']['write_actions'] ?? null ) ? $article_block_plan_run_data['data']['write_actions'] : array();
npcink_abilities_toolkit_smoke_assert( 2 === count( $article_block_smoke_actions ), 'Article block plan emits create and block update actions.' );
npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/create-draft' === (string) ( $article_block_smoke_actions[0]['target_ability_id'] ?? '' ), 'Article block plan first targets create-draft.' );
npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/update-post-blocks' === (string) ( $article_block_smoke_actions[1]['target_ability_id'] ?? '' ), 'Article block plan second targets update-post-blocks.' );
npcink_abilities_toolkit_smoke_assert( '$outputs.create-article-draft.post_id' === (string) ( $article_block_smoke_actions[1]['input']['post_id'] ?? '' ), 'Article block plan uses the draft output reference.' );

$article_optimization_apply_result_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/compose-article-optimization-apply-result/run' );
$article_optimization_apply_result_run_request->set_query_params(
	array(
		'input' => array(
			'report'        => array(
				'summary' => array(
					'status' => 'needs_attention',
				),
			),
			'apply_plan'    => $article_optimization_apply_plan_run_data['data'] ?? array(),
			'apply_excerpt' => array(
				'updated' => false,
				'post_id' => (int) $smoke_post_id,
			),
		),
	)
);
$article_optimization_apply_result_run_response = rest_do_request( $article_optimization_apply_result_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_optimization_apply_result_run_response->get_status(), 'Authenticated article optimization apply result ability run returns 200.' );
$article_optimization_apply_result_run_data = $article_optimization_apply_result_run_response->get_data();

$smoke_attachment_id = wp_insert_post(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_title'     => $npcink_abilities_toolkit_smoke_pattern . ' Media Asset',
		'post_mime_type' => 'image/jpeg',
		'post_excerpt'   => '',
		'post_content'   => '',
	),
	true
);
npcink_abilities_toolkit_smoke_assert( ! is_wp_error( $smoke_attachment_id ) && (int) $smoke_attachment_id > 0, 'Temporary smoke media asset is available for media inventory ability runs.' );
npcink_abilities_toolkit_smoke_register_attachment_fixture( (int) $smoke_attachment_id );
update_post_meta( (int) $smoke_attachment_id, '_wp_attached_file', '2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg' );
update_post_meta(
	(int) $smoke_attachment_id,
	'_wp_attachment_metadata',
	array(
		'width'    => 2600,
		'height'   => 1400,
		'file'     => '2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg',
		'filesize' => 900000,
	)
);

$media_health_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-media-inventory-health/run' );
$media_health_run_request->set_query_params(
	array(
		'input' => array(
			'mime_type' => 'image',
			'per_page'  => 10,
		),
	)
);
$media_health_run_response = rest_do_request( $media_health_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_health_run_response->get_status(), 'Authenticated media inventory health ability run returns 200.' );

$media_inspect_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/inspect-media-asset/run' );
$media_inspect_run_request->set_query_params(
	array(
		'input' => array(
			'attachment_id' => (int) $smoke_attachment_id,
		),
	)
);
$media_inspect_run_response = rest_do_request( $media_inspect_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_inspect_run_response->get_status(), 'Authenticated media asset inspection ability run returns 200.' );
$media_inspect_run_data = $media_inspect_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( true === ( $media_inspect_run_data['meta']['readonly'] ?? null ), 'Media asset inspection remains read-only.' );

$media_optimize_run_request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/optimize-media-asset/run' );
$media_optimize_run_request->set_header( 'Content-Type', 'application/json' );
$media_optimize_run_request->set_body(
	wp_json_encode(
		array(
			'input' => array(
				'attachment_id' => (int) $smoke_attachment_id,
				'dry_run'       => true,
			),
		)
	)
);
$media_optimize_run_response = rest_do_request( $media_optimize_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_optimize_run_response->get_status(), 'Authenticated media asset optimization dry-run ability run returns 200.' );
$media_optimize_run_data = $media_optimize_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( true === ( $media_optimize_run_data['dry_run'] ?? null ), 'Media asset optimization defaults to governed dry-run.' );
npcink_abilities_toolkit_smoke_assert( false === ( $media_optimize_run_data['replace_original'] ?? null ), 'Media asset optimization dry-run does not replace originals.' );
update_post_meta(
	(int) $smoke_attachment_id,
	'_npcink_ai_media_optimized_derivatives',
	array(
		array(
			'format'           => 'webp',
			'mime_type'        => 'image/webp',
			'file_basename'    => 'npcink-abilities-toolkit-smoke-media-asset-optimized.webp',
			'relative_file'    => '2026/06/npcink-abilities-toolkit-smoke-media-asset-optimized.webp',
			'url'              => content_url( 'uploads/2026/06/npcink-abilities-toolkit-smoke-media-asset-optimized.webp' ),
			'width'            => 1920,
			'height'           => 1034,
			'quality'          => 82,
			'filesize_bytes'   => 300000,
			'generated_at_gmt' => gmdate( 'c' ),
		),
	)
);
$media_replace_run_request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/replace-media-file/run' );
$media_replace_run_request->set_header( 'Content-Type', 'application/json' );
$media_replace_run_request->set_body(
	wp_json_encode(
		array(
			'input' => array(
				'attachment_id'                 => (int) $smoke_attachment_id,
				'derivative_relative_file'      => '2026/06/npcink-abilities-toolkit-smoke-media-asset-optimized.webp',
				'expected_current_relative_file' => '2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg',
				'dry_run'                       => true,
			),
		)
	)
);
$media_replace_run_response = rest_do_request( $media_replace_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_replace_run_response->get_status(), 'Authenticated media file replacement dry-run ability run returns 200.' );
	$media_replace_run_data = $media_replace_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( true === ( $media_replace_run_data['dry_run'] ?? null ), 'Media file replacement defaults to governed dry-run.' );
	npcink_abilities_toolkit_smoke_assert( true === ( $media_replace_run_data['original_preserved'] ?? null ), 'Media file replacement plans a preserved backup.' );

	$media_rename_run_request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/rename-media-file/run' );
	$media_rename_run_request->set_header( 'Content-Type', 'application/json' );
	$media_rename_run_request->set_body(
		wp_json_encode(
			array(
				'input' => array(
					'attachment_id'                  => (int) $smoke_attachment_id,
					'target_file_name'               => 'npcink-abilities-toolkit-smoke-media-asset-renamed.jpg',
					'expected_current_relative_file' => '2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg',
					'dry_run'                       => true,
				),
			)
		)
	);
	$media_rename_run_response = rest_do_request( $media_rename_run_request );
	npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_rename_run_response->get_status(), 'Authenticated media file rename dry-run ability run returns 200.' );
	$media_rename_run_data = $media_rename_run_response->get_data();
	npcink_abilities_toolkit_smoke_assert( true === ( $media_rename_run_data['dry_run'] ?? null ), 'Media file rename defaults to governed dry-run.' );
	npcink_abilities_toolkit_smoke_assert( false === ( $media_rename_run_data['renamed'] ?? null ), 'Media file rename dry-run does not move files.' );

	$media_cleanup_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-media-cleanup-opportunities/run' );
$media_cleanup_run_request->set_query_params(
	array(
		'input' => array(
			'mime_type' => 'image',
			'per_page'  => 10,
		),
	)
);
$media_cleanup_run_response = rest_do_request( $media_cleanup_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_cleanup_run_response->get_status(), 'Authenticated media cleanup opportunities ability run returns 200.' );

$media_fix_plan_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-media-inventory-fix-plan/run' );
$media_fix_plan_run_request->set_query_params(
	array(
		'input' => array(
			'attachment_ids'  => array( (int) $smoke_attachment_id ),
			'article_title'   => $npcink_abilities_toolkit_smoke_pattern,
			'article_excerpt' => 'Smoke media metadata context.',
			'focus_keyword'   => 'ability workflow',
			'max_actions'     => 5,
		),
	)
);
$media_fix_plan_run_response = rest_do_request( $media_fix_plan_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_fix_plan_run_response->get_status(), 'Authenticated media inventory fix plan ability run returns 200.' );
$media_fix_plan_run_data = $media_fix_plan_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( false === ( $media_fix_plan_run_data['data']['commit_execution'] ?? null ), 'Media inventory fix plan does not execute commits.' );

$media_seo_assets_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-media-seo-assets/run' );
$media_seo_assets_run_request->set_query_params(
	array(
		'input' => array(
			'article'                   => array(
				'title'   => $npcink_abilities_toolkit_smoke_pattern . ' Context Post',
				'excerpt' => 'Smoke media handoff context.',
			),
			'resolved_image_source'     => array(
				'featured' => array(
					'image_origin' => 'ai_generated',
					'prompt'       => 'Generated smoke media prompt',
					'role'         => 'featured',
				),
				'inline'   => array(
					array(
						'provider_hint'   => 'pexels',
						'provider_title'  => 'Smoke provider image',
						'section_heading' => 'Workflow proof',
					),
				),
			),
			'generated_featured_upload' => array(
				'attachment_id' => (int) $smoke_attachment_id,
				'url'           => content_url( 'uploads/2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg' ),
				'file_name'     => 'npcink-abilities-toolkit-smoke-media-asset.jpg',
			),
		),
	)
);
$media_seo_assets_run_response = rest_do_request( $media_seo_assets_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $media_seo_assets_run_response->get_status(), 'Authenticated media SEO assets ability run returns 200.' );
$media_seo_assets_run_data = $media_seo_assets_run_response->get_data();

$inline_image_blocks_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-inline-image-blocks/run' );
$inline_image_blocks_run_request->set_query_params(
	array(
		'input' => array(
			'uploaded_inline_media' => array(
				array(
					'attachment_id' => (int) $smoke_attachment_id,
					'url'           => content_url( 'uploads/2026/06/npcink-abilities-toolkit-smoke-media-asset.jpg' ),
					'alt'           => 'Smoke inline alt',
					'caption'       => 'Smoke inline caption',
				),
			),
			'inline_plan'           => array(
				array(
					'alt'           => 'Smoke inline alt',
					'caption'       => 'Smoke inline caption',
					'placement_key' => 'smoke-inline-proof',
				),
			),
		),
	)
);
$inline_image_blocks_run_response = rest_do_request( $inline_image_blocks_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $inline_image_blocks_run_response->get_status(), 'Authenticated inline image blocks ability run returns 200.' );
$inline_image_blocks_run_data = $inline_image_blocks_run_response->get_data();

$position_inline_blocks_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/position-inline-image-blocks/run' );
$position_inline_blocks_run_request->set_query_params(
	array(
		'input' => array(
			'existing_blocks' => array(
				array(
					'blockName'   => 'core/paragraph',
					'attrs'       => array(),
					'innerHTML'   => '<p>Intro</p>',
					'innerBlocks' => array(),
				),
				array(
					'blockName'   => 'core/heading',
					'attrs'       => array(
						'anchor' => 'workflow-proof',
					),
					'innerHTML'   => '<h2>Workflow proof</h2>',
					'innerBlocks' => array(),
				),
			),
			'inline_blocks'   => $inline_image_blocks_run_data['data']['blocks'] ?? array(),
			'inline_plan'     => array(
				array(
					'placement_key'   => 'smoke-inline-proof',
					'placement'       => 'after_heading',
					'section_anchor'  => 'workflow-proof',
					'section_heading' => 'Workflow proof',
				),
			),
		),
	)
);
$position_inline_blocks_run_response = rest_do_request( $position_inline_blocks_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $position_inline_blocks_run_response->get_status(), 'Authenticated inline image block positioning ability run returns 200.' );
$position_inline_blocks_run_data = $position_inline_blocks_run_response->get_data();

$seo_geo_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-post-seo-geo-readiness/run' );
$seo_geo_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'focus_keyword' => 'ability workflow',
		),
	)
);
$seo_geo_run_response = rest_do_request( $seo_geo_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $seo_geo_run_response->get_status(), 'Authenticated post SEO/GEO readiness ability run returns 200.' );

$topic_coverage_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-site-topic-coverage-report/run' );
$topic_coverage_run_request->set_query_params(
	array(
		'input' => array(
			'post_type'  => 'post',
			'status'     => 'any',
			'per_page'   => 10,
			'topic_seed' => 'ability workflow',
		),
	)
);
$topic_coverage_run_response = rest_do_request( $topic_coverage_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $topic_coverage_run_response->get_status(), 'Authenticated site topic coverage ability run returns 200.' );

$taxonomy_health_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-taxonomy-inventory-health/run' );
$taxonomy_health_run_request->set_query_params(
	array(
		'input' => array(
			'taxonomy' => 'category',
			'per_page' => 10,
		),
	)
);
$taxonomy_health_run_response = rest_do_request( $taxonomy_health_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $taxonomy_health_run_response->get_status(), 'Authenticated taxonomy inventory health ability run returns 200.' );

$taxonomy_consolidation_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions/run' );
$taxonomy_consolidation_run_request->set_query_params(
	array(
		'input' => array(
			'taxonomy' => 'post_tag',
			'per_page' => 10,
		),
	)
);
$taxonomy_consolidation_run_response = rest_do_request( $taxonomy_consolidation_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $taxonomy_consolidation_run_response->get_status(), 'Authenticated taxonomy consolidation suggestions ability run returns 200.' );

$smoke_tag_result = function_exists( 'wp_insert_term' )
	? wp_insert_term( 'Ability Workflow Smoke ' . $npcink_abilities_toolkit_smoke_run_id, 'post_tag' )
	: new WP_Error( 'missing_wp_insert_term', 'wp_insert_term unavailable.' );
$smoke_tag_created = ! is_wp_error( $smoke_tag_result );
if ( is_wp_error( $smoke_tag_result ) && 'term_exists' === (string) $smoke_tag_result->get_error_code() ) {
	$smoke_tag_error_data = $smoke_tag_result->get_error_data();
	$smoke_tag_id = (int) ( is_array( $smoke_tag_error_data ) ? ( $smoke_tag_error_data['term_id'] ?? 0 ) : $smoke_tag_error_data );
} else {
	$smoke_tag_id = (int) ( is_array( $smoke_tag_result ) ? ( $smoke_tag_result['term_id'] ?? 0 ) : 0 );
}
npcink_abilities_toolkit_smoke_assert( $smoke_tag_id > 0, 'Temporary smoke tag is available for taxonomy proposal ability runs.' );
if ( ! empty( $smoke_tag_created ) ) {
	npcink_abilities_toolkit_smoke_register_term_fixture( (int) $smoke_tag_id, 'post_tag' );
}

$post_taxonomy_proposal_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/propose-post-taxonomy-terms/run' );
$post_taxonomy_proposal_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'            => (int) $smoke_post_id,
			'taxonomy'           => 'post_tag',
			'mode'               => 'append',
			'candidate_term_ids' => array( $smoke_tag_id ),
			'candidate_terms'    => array( 'Unmatched Smoke Topic' ),
		),
	)
);
$post_taxonomy_proposal_run_response = rest_do_request( $post_taxonomy_proposal_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $post_taxonomy_proposal_run_response->get_status(), 'Authenticated post taxonomy terms proposal ability run returns 200.' );
$post_taxonomy_proposal_run_data = $post_taxonomy_proposal_run_response->get_data();
npcink_abilities_toolkit_smoke_assert( 'npcink-abilities-toolkit/set-post-terms' === (string) ( $post_taxonomy_proposal_run_data['data']['proposal']['target_ability_id'] ?? '' ), 'Post taxonomy terms proposal targets set-post-terms.' );
npcink_abilities_toolkit_smoke_assert( false === ( $post_taxonomy_proposal_run_data['data']['proposal']['commit_execution'] ?? null ), 'Post taxonomy terms proposal does not execute commits.' );

$revision_risk_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-revision-change-risk-report/run' );
$revision_risk_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'       => (int) $smoke_post_id,
			'max_revisions' => 5,
		),
	)
);
$revision_risk_run_response = rest_do_request( $revision_risk_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $revision_risk_run_response->get_status(), 'Authenticated revision change risk ability run returns 200.' );

$smoke_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $npcink_abilities_toolkit_smoke_pattern . ' Structure Page',
		'post_content' => '<p>This local smoke page verifies page structure health.</p>',
	),
	true
);
npcink_abilities_toolkit_smoke_assert( ! is_wp_error( $smoke_page_id ) && (int) $smoke_page_id > 0, 'Temporary smoke page is available for page structure ability runs.' );
npcink_abilities_toolkit_smoke_register_post_fixture( (int) $smoke_page_id );

$page_structure_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-page-structure-health/run' );
$page_structure_run_request->set_query_params(
	array(
		'input' => array(
			'page_id' => (int) $smoke_page_id,
		),
	)
);
$page_structure_run_response = rest_do_request( $page_structure_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $page_structure_run_response->get_status(), 'Authenticated page structure health ability run returns 200.' );

$seo_geo_gap_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-seo-geo-gap-report/run' );
$seo_geo_gap_run_request->set_query_params(
	array(
		'input' => array(
			'post_type'  => 'post',
			'status'     => 'any',
			'per_page'   => 10,
			'topic_seed' => 'ability workflow',
		),
	)
);
$seo_geo_gap_run_response = rest_do_request( $seo_geo_gap_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $seo_geo_gap_run_response->get_status(), 'Authenticated SEO/GEO gap report ability run returns 200.' );
$seo_geo_gap_run_data = $seo_geo_gap_run_response->get_data();

$site_style_baseline_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-site-style-baseline/run' );
$site_style_baseline_run_request->set_query_params(
	array(
		'input' => array(
			'mode'  => 'site_recent',
			'limit' => 3,
		),
	)
);
$site_style_baseline_run_response = rest_do_request( $site_style_baseline_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $site_style_baseline_run_response->get_status(), 'Authenticated site style baseline ability run returns 200.' );
$site_style_baseline_run_data = $site_style_baseline_run_response->get_data();

$article_workflow_context_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-article-workflow-context/run' );
$article_workflow_context_run_request->set_query_params(
	array(
		'input' => array(
			'workflow'   => 'publish',
			'post_id'    => (int) $smoke_post_id,
			'topic_seed' => 'ability workflow',
		),
	)
);
$article_workflow_context_run_response = rest_do_request( $article_workflow_context_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $article_workflow_context_run_response->get_status(), 'Authenticated article workflow context ability run returns 200.' );
$article_workflow_context_run_data = $article_workflow_context_run_response->get_data();

$publishing_calendar_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-publishing-calendar-context/run' );
$publishing_calendar_run_request->set_query_params(
	array(
		'input' => array(
			'post_type'   => 'post',
			'window_days' => 30,
			'per_page'    => 10,
		),
	)
);
$publishing_calendar_run_response = rest_do_request( $publishing_calendar_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $publishing_calendar_run_response->get_status(), 'Authenticated publishing calendar context ability run returns 200.' );
$publishing_calendar_run_data = $publishing_calendar_run_response->get_data();

$smoke_comment_id = wp_insert_comment(
	array(
		'comment_post_ID'      => (int) $smoke_post_id,
		'comment_author'       => $npcink_abilities_toolkit_smoke_pattern,
		'comment_author_email' => 'smoke@example.test',
		'comment_content'      => '@admin please check this smoke comment queue item.',
		'comment_approved'     => '0',
	)
);
npcink_abilities_toolkit_smoke_assert( (int) $smoke_comment_id > 0, 'Temporary smoke comment is available for comment queue ability runs.' );
npcink_abilities_toolkit_smoke_register_comment_fixture( (int) $smoke_comment_id );

$comment_queue_health_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-comment-queue-health/run' );
$comment_queue_health_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'  => (int) $smoke_post_id,
			'status'   => 'hold',
			'per_page' => 10,
		),
	)
);
$comment_queue_health_run_response = rest_do_request( $comment_queue_health_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $comment_queue_health_run_response->get_status(), 'Authenticated comment queue health ability run returns 200.' );
$comment_queue_health_run_data = $comment_queue_health_run_response->get_data();

$comment_action_queue_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-comment-action-priority-queue/run' );
$comment_action_queue_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'  => (int) $smoke_post_id,
			'status'   => 'hold',
			'per_page' => 10,
		),
	)
);
$comment_action_queue_run_response = rest_do_request( $comment_action_queue_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $comment_action_queue_run_response->get_status(), 'Authenticated comment action priority queue ability run returns 200.' );
$comment_action_queue_run_data = $comment_action_queue_run_response->get_data();

$comment_compliance_handoff_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/get-comment-compliance-handoff/run' );
$comment_compliance_handoff_run_request->set_query_params(
	array(
		'input' => array(
			'post_id'             => (int) $smoke_post_id,
			'status'              => 'hold',
			'per_page'            => 10,
			'selected_comment_id' => (int) $smoke_comment_id,
		),
	)
);
$comment_compliance_handoff_run_response = rest_do_request( $comment_compliance_handoff_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $comment_compliance_handoff_run_response->get_status(), 'Authenticated comment compliance handoff ability run returns 200.' );
$comment_compliance_handoff_run_data = $comment_compliance_handoff_run_response->get_data();

$comment_moderation_suggest_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-comment-moderation-suggest/run' );
$comment_moderation_suggest_run_request->set_query_params(
	array(
		'input' => array(
			'comment_id'      => (int) $smoke_comment_id,
			'mode'            => 'suggest',
			'allowed_actions' => array( 'approve', 'reply', 'escalate', 'spam', 'trash' ),
		),
	)
);
$comment_moderation_suggest_run_response = rest_do_request( $comment_moderation_suggest_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $comment_moderation_suggest_run_response->get_status(), 'Authenticated comment moderation suggestion ability run returns 200.' );
$comment_moderation_suggest_run_data = $comment_moderation_suggest_run_response->get_data();

$comment_mention_suggest_run_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-comment-mention-reply-suggest/run' );
$comment_mention_suggest_run_request->set_query_params(
	array(
		'input' => array(
			'comment_id'   => (int) $smoke_comment_id,
			'trigger_type' => 'mention',
		),
	)
);
$comment_mention_suggest_run_response = rest_do_request( $comment_mention_suggest_run_request );
npcink_abilities_toolkit_smoke_assert( 200 === (int) $comment_mention_suggest_run_response->get_status(), 'Authenticated comment mention reply suggestion ability run returns 200.' );
$comment_mention_suggest_run_data = $comment_mention_suggest_run_response->get_data();

npcink_abilities_toolkit_smoke_assert(
	true === ( $post_context_run_data['success'] ?? null )
	&& true === ( $publishing_checklist_run_data['success'] ?? null )
	&& true === ( $publish_risk_run_data['success'] ?? null )
	&& true === ( $article_publish_preflight_run_data['success'] ?? null )
	&& true === ( $article_workflow_context_run_data['success'] ?? null )
	&& true === ( $publishing_calendar_run_data['success'] ?? null ),
	'Publishing preflight workflow returns success envelopes across context, checklist, risk, preflight bundle, workflow context, and calendar.'
);
npcink_abilities_toolkit_smoke_assert(
	in_array( 'publish_risk', (array) ( $article_workflow_context_run_data['data']['sections'] ?? array() ), true ),
	'Publishing preflight workflow context includes publish risk section.'
);
npcink_abilities_toolkit_smoke_assert(
	true === ( $refresh_opportunities_run_data['success'] ?? null )
	&& true === ( $seo_geo_gap_run_data['success'] ?? null )
	&& true === ( $site_style_baseline_run_data['success'] ?? null )
	&& true === ( $link_graph_run_data['success'] ?? null )
	&& true === ( $internal_link_run_data['success'] ?? null )
	&& true === ( $old_article_refresh_run_data['success'] ?? null ),
	'Content refresh workflow returns success envelopes across refresh, SEO/GEO gap, style, link graph, link opportunity, and refresh bundle context.'
);
npcink_abilities_toolkit_smoke_assert(
	true === ( $post_optimization_context_run_data['success'] ?? null )
	&& true === ( $seo_report_context_run_data['success'] ?? null )
	&& true === ( $article_optimization_suggest_run_data['success'] ?? null )
	&& true === ( $article_optimization_apply_plan_run_data['success'] ?? null )
	&& true === ( $article_optimization_apply_result_run_data['success'] ?? null ),
	'Article optimization workflow returns success envelopes across context, SEO report, suggestion, apply plan, and apply result.'
);
npcink_abilities_toolkit_smoke_assert(
	true === ( $media_seo_assets_run_data['success'] ?? null )
	&& true === ( $inline_image_blocks_run_data['success'] ?? null )
	&& true === ( $position_inline_blocks_run_data['success'] ?? null ),
	'Article media handoff workflow returns success envelopes across media assets, inline blocks, and positioning.'
);
npcink_abilities_toolkit_smoke_assert(
	false === ( $media_fix_plan_run_data['data']['commit_execution'] ?? null )
	&& ! isset( $media_seo_assets_run_data['data']['commit_execution'] ),
	'Article media handoff workflow keeps media write execution outside the default read chain.'
);
npcink_abilities_toolkit_smoke_assert(
	true === ( $comment_queue_health_run_data['success'] ?? null )
	&& true === ( $comment_action_queue_run_data['success'] ?? null )
	&& true === ( $comment_compliance_handoff_run_data['success'] ?? null )
	&& true === ( $comment_moderation_suggest_run_data['success'] ?? null )
	&& true === ( $comment_mention_suggest_run_data['success'] ?? null ),
	'Comment compliance workflow returns success envelopes across queue, priority, handoff bundle, moderation suggestion, and reply handoff.'
);
npcink_abilities_toolkit_smoke_assert(
	true === ( $comment_mention_suggest_run_data['data']['trigger']['trigger_detected'] ?? null ),
	'Comment compliance workflow detects the smoke mention reply trigger.'
);

npcink_abilities_toolkit_smoke_cleanup_fixtures();
npcink_abilities_toolkit_smoke_assert( false === get_userdata( (int) $subscriber_id ), 'Smoke subscriber fixture is deleted after smoke.' );
npcink_abilities_toolkit_smoke_assert( null === get_comment( (int) $smoke_comment_id ), 'Smoke comment fixture is deleted after smoke.' );
npcink_abilities_toolkit_smoke_assert( false === get_post_type( (int) $smoke_page_id ), 'Smoke page fixture is deleted after smoke.' );
npcink_abilities_toolkit_smoke_assert( false === get_post_type( (int) $smoke_attachment_id ), 'Smoke media fixture is deleted after smoke.' );
npcink_abilities_toolkit_smoke_assert_no_media_fixture_leaks();
npcink_abilities_toolkit_smoke_assert( false === get_post_type( (int) $smoke_candidate_id ), 'Smoke candidate post fixture is deleted after smoke.' );
npcink_abilities_toolkit_smoke_assert( false === get_post_type( (int) $smoke_post_id ), 'Smoke context post fixture is deleted after smoke.' );
if ( ! empty( $smoke_tag_created ) ) {
	npcink_abilities_toolkit_smoke_assert( 0 === (int) term_exists( (int) $smoke_tag_id, 'post_tag' ), 'Smoke tag fixture is deleted after smoke.' );
}

wp_set_current_user( 0 );
$anonymous_response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
npcink_abilities_toolkit_smoke_assert(
	in_array( (int) $anonymous_response->get_status(), array( 401, 403 ), true ),
	'Anonymous abilities REST catalog request is blocked as expected.'
);

echo 'Smoke OK: ' . (int) $GLOBALS['npcink_abilities_toolkit_smoke_assertions'] . " assertions\n";
