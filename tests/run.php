<?php
/**
 * Lightweight regression tests.
 *
 * @package NpcinkAbilitiesToolkit
 */

require_once __DIR__ . '/bootstrap.php';

use Npcink_Abilities_Toolkit\Integration\Npcink_Catalog_Bridge;
use Npcink_Abilities_Toolkit\Packages\Core_Comment_Pack_Classifier;
use Npcink_Abilities_Toolkit\Packages\Core_Comment_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Destructive_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Read_Pack_Classifier;
use Npcink_Abilities_Toolkit\Packages\Core_Read_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Write_Package;
use Npcink_Abilities_Toolkit\Packages\Read_Definitions\Media_Governance_Read_Definitions;
use Npcink_Abilities_Toolkit\Plugin;
use Npcink_Abilities_Toolkit\Registry\Ability_Registrar;
use Npcink_Abilities_Toolkit\Registry\Annotation_Normalizer;
use Npcink_Abilities_Toolkit\Registry\Category_Registrar;
use Npcink_Abilities_Toolkit\Registry\Contract_Normalizer;
use Npcink_Abilities_Toolkit\Registry\Schema_Normalizer;
use Npcink_Abilities_Toolkit\Rest\Contract_Controller;
use Npcink_Abilities_Toolkit\Security\Permission_Callbacks;
use Npcink_Abilities_Toolkit\Support\Gutenberg_Block_Document;

$assertions = 0;
$core_read_package_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Packages/Core_Read_Package.php' );
$media_read_trait_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Packages/Read_Traits/Media_Read_Methods.php' );
$media_alt_caption_read_trait_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Packages/Read_Traits/Media_Alt_Caption_Read_Methods.php' );
$core_write_package_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Packages/Core_Write_Package.php' );
$ability_namespace_migration_map = (string) file_get_contents( dirname( __DIR__ ) . '/docs/ability-namespace-migration-map.md' );
$ability_namespace_migration_script = (string) file_get_contents( dirname( __DIR__ ) . '/scripts/audit-legacy-ability-ids.php' );
$pr_publisher = (string) file_get_contents( dirname( __DIR__ ) . '/scripts/publish-pr.sh' );
$pr_template = (string) file_get_contents( dirname( __DIR__ ) . '/.github/pull_request_template.md' );
$composer_source = (string) file_get_contents( dirname( __DIR__ ) . '/composer.json' );

function npcink_abilities_toolkit_assert_true( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function npcink_abilities_toolkit_assert_same( $expected, $actual, $message ) {
	npcink_abilities_toolkit_assert_true( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

npcink_abilities_toolkit_assert_same( false, current_user_can( 'manage_option' ), 'unit capability defaults fail closed for unknown or misspelled capabilities' );
$unknown_capability_callback = Permission_Callbacks::for_capability( 'manage_option' );
npcink_abilities_toolkit_assert_same( false, $unknown_capability_callback(), 'permission callbacks preserve fail-closed behavior for unknown capabilities' );

npcink_abilities_toolkit_assert_true(
	false !== strpos( $composer_source, '"pr:publish": "bash scripts/publish-pr.sh"' )
	&& false !== strpos( $pr_template, '## Scope' )
	&& false !== strpos( $pr_template, '## Boundary' )
	&& false !== strpos( $pr_template, '## Verification' )
	&& false !== strpos( $pr_template, '## Risk' )
	&& false !== strpos( $pr_publisher, 'git status --porcelain' )
	&& false !== strpos( $pr_publisher, 'git merge-base --is-ancestor "origin/${base_branch}" HEAD' )
	&& false !== strpos( $pr_publisher, '--body-file "${body_path}"' )
	&& false !== strpos( $pr_publisher, '--auto --squash --match-head-commit "${head_sha}"' )
	&& false === strpos( $pr_publisher, '--delete-branch' ),
	'PR publisher validates the checked-in body contract and preserves protected multi-worktree merging.'
);

function npcink_abilities_toolkit_assert_output_schema_declares_payload_keys( Gutenberg_Block_Document $document, array $schema, array $payload, $message ) {
	npcink_abilities_toolkit_assert_same( false, $schema['additionalProperties'] ?? null, "{$message} keeps a strict output schema" );
	npcink_abilities_toolkit_assert_same( array(), $document->output_schema_missing_payload_keys( $schema, $payload ), "{$message} output schema declares returned payload keys" );
}

function npcink_abilities_toolkit_count_plan_actions_for_ability( array $actions, $ability_id ) {
	$count = 0;
	foreach ( $actions as $action ) {
		if ( $ability_id === (string) ( $action['target_ability_id'] ?? '' ) ) {
			++$count;
		}
	}

	return $count;
}

function npcink_abilities_toolkit_cloud_artifact_fixture( array $overrides = array() ) {
	$artifact = array_merge(
		array(
			'artifact_id'        => 'art_ffffffffffffffffffffffffffffffff',
			'expires_at'         => gmdate( 'c', time() + 600 ),
			'mime_type'          => 'image/webp',
			'format'             => 'webp',
			'width'              => 1600,
			'height'             => 862,
			'filesize_bytes'     => 210000,
			'sha256'             => hash( 'sha256', 'cloud-artifact-fixture' ),
			'suggested_filename' => 'cloud-artifact.webp',
			'filename_basis'     => array(
				'owner'                          => 'wordpress_write_ability_final',
				'strategy'                       => 'format_checksum',
				'final_sanitize_unique_required' => true,
			),
			'processing_warnings' => array(),
		),
		$overrides
	);
	if ( ! array_key_exists( 'transform_facts', $overrides ) ) {
		$source_filesize = 260000;
		$output_filesize = (int) $artifact['filesize_bytes'];
		$artifact['transform_facts'] = array(
			'source_checksum' => 'sha256:' . hash( 'sha256', 'source-fixture' ),
			'output_checksum' => 'sha256:' . (string) $artifact['sha256'],
			'source_format' => 'jpeg',
			'output_format' => (string) $artifact['format'],
			'source_mime_type' => 'image/jpeg',
			'output_mime_type' => (string) $artifact['mime_type'],
			'source_width' => 1600,
			'source_height' => 862,
			'output_width' => (int) $artifact['width'],
			'output_height' => (int) $artifact['height'],
			'source_filesize_bytes' => $source_filesize,
			'output_filesize_bytes' => $output_filesize,
			'source_frame_count' => 1,
			'output_frame_count' => 1,
			'source_has_alpha' => false,
			'output_has_alpha' => false,
			'alpha_preserved' => true,
			'decodable' => true,
			'crop_applied' => false,
			'watermark_applied' => false,
			'resize_applied' => 1600 !== (int) $artifact['width'] || 862 !== (int) $artifact['height'],
			'encoding_mode' => 'png' === $artifact['format'] ? 'lossless' : 'lossy',
			'savings_basis_points' => max( 0, (int) ( ( $source_filesize - $output_filesize ) * 10000 / $source_filesize ) ),
			'optimization_profile' => 'auto_safe.v1',
			'source_class' => 'opaque',
			'effective_quality' => 82,
			'quality_metric' => 'ssim',
			'quality_score' => 0.99,
			'quality_threshold' => 0.985,
			'color_profile_normalized' => false,
			'qualified' => true,
			'decision_reasons' => array( 'qualified' ),
		);
	}

	return $artifact;
}

function npcink_abilities_toolkit_cloud_receive_fixture( array $artifact, $contents, array $overrides = array() ) {
	$contents = (string) $contents;
	$artifact_expiry = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::expiry_timestamp( (string) ( $artifact['expires_at'] ?? '' ) );
	$received_expiry = gmdate( 'c', $artifact_expiry );
	$ack_deadline = gmdate( 'c', min( time() + 900, $artifact_expiry - 1 ) );
	$sha256 = hash( 'sha256', $contents );
	$delivery_id = 'mdl_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	$checksum = 'sha256:' . $sha256;

	return array_replace_recursive(
		array(
			'artifact_id'    => (string) ( $artifact['artifact_id'] ?? '' ),
			'contents'       => $contents,
			'mime_type'      => (string) ( $artifact['mime_type'] ?? '' ),
			'width'          => (int) ( $artifact['width'] ?? 0 ),
			'height'         => (int) ( $artifact['height'] ?? 0 ),
			'filesize_bytes' => (int) ( $artifact['filesize_bytes'] ?? 0 ),
			'sha256'         => (string) ( $artifact['sha256'] ?? '' ),
			'expires_at'     => $received_expiry,
			'transfer_evidence' => array(
				'contract_version'      => 'media_artifact_verified_transfer.v1',
				'artifact_id'           => (string) ( $artifact['artifact_id'] ?? '' ),
				'delivery_id'           => $delivery_id,
				'received_byte_size'    => strlen( $contents ),
				'received_checksum'     => $checksum,
				'byte_size_verified'    => true,
				'checksum_verified'     => true,
				'content_type_verified' => true,
				'image_decoded'         => true,
				'dimensions_verified'   => true,
				'ack_deadline_at'       => $ack_deadline,
			),
			'delivery_ack' => array(
				'contract_version'      => 'media_artifact_delivery_ack.v1',
				'delivery_id'           => $delivery_id,
				'artifact_id'           => (string) ( $artifact['artifact_id'] ?? '' ),
				'status'                => 'acknowledged',
				'received_byte_size'    => strlen( $contents ),
				'received_checksum'     => $checksum,
				'byte_size_verified'    => true,
				'checksum_verified'     => true,
				'acknowledged_at'       => gmdate( 'c', time() - 360 ),
				'artifact_expires_at'   => $received_expiry,
				'idempotent_replay'     => false,
				'acknowledgement_scope' => 'verified_transfer_only',
			),
		),
		$overrides
	);
}

function npcink_abilities_toolkit_find_row_by_key( array $rows, $key, $value ) {
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && (string) ( $row[ $key ] ?? '' ) === (string) $value ) {
			return $row;
		}
	}

	return array();
}

function npcink_abilities_toolkit_assert_array_omits_keys( $value, array $forbidden_keys, $path ) {
	if ( ! is_array( $value ) ) {
		return;
	}

	foreach ( $value as $key => $child ) {
		if ( is_string( $key ) ) {
			npcink_abilities_toolkit_assert_true( ! in_array( $key, $forbidden_keys, true ), "{$path} omits forbidden field {$key}" );
		}

		$child_path = is_string( $key ) ? "{$path}.{$key}" : "{$path}[]";
		npcink_abilities_toolkit_assert_array_omits_keys( $child, $forbidden_keys, $child_path );
	}
}

npcink_abilities_toolkit_assert_true( false !== strpos( $ability_namespace_migration_map, 'https://magick-ai.local/' ), 'Ability namespace migration map names magick-ai.local as the primary local acceptance site.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $ability_namespace_migration_map, 'temporary isolation fixtures' ), 'Ability namespace migration map keeps npcink.local as a temporary isolation fixture.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $ability_namespace_migration_map, 'is invalid for new runtime calls' ) && false !== strpos( $ability_namespace_migration_map, 'npcink-abilities-toolkit/create-draft' ), 'Ability namespace migration map declares old create-draft invalid for new calls.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $ability_namespace_migration_map, 'Cloud runtime/detail' ), 'Ability namespace migration map keeps Cloud out of ability truth ownership.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $ability_namespace_migration_script, 'compatibility_aliases' ), 'Ability namespace audit script reports whether compatibility aliases are enabled.' );

function npcink_abilities_toolkit_observability_events_of_kind( array $events, $event_kind ) {
	return array_values(
		array_filter(
			$events,
			static function ( $event ) use ( $event_kind ) {
				return is_array( $event ) && (string) ( $event['event_kind'] ?? '' ) === (string) $event_kind;
			}
		)
	);
}

function npcink_abilities_toolkit_assert_event_has_safe_event_id( array $event, $prefix, $message ) {
	$event_id = (string) ( $event['event_id'] ?? '' );
	npcink_abilities_toolkit_assert_true( 0 === strpos( $event_id, $prefix ), "{$message} event_id uses {$prefix} prefix" );
	npcink_abilities_toolkit_assert_true( 1 === preg_match( '/^[a-z0-9_]+$/', $event_id ), "{$message} event_id uses bounded id characters" );
}

function npcink_abilities_toolkit_assert_observability_event_is_metadata_only( array $event, $message ) {
	npcink_abilities_toolkit_assert_array_omits_keys(
		$event,
		array(
			'args',
			'auth',
			'authorization',
			'callback',
			'callback_input',
			'content',
			'cookie',
			'definition',
			'execute_callback',
			'input',
			'nonce',
			'payload',
			'payload_json',
			'permission_callback',
			'prompt',
			'raw',
			'raw_callback_input',
			'request',
			'response',
			'secret',
			'token',
		),
		$message
	);
}

$admin_test_page = file_get_contents( __DIR__ . '/../includes/Admin/Test_Page.php' );
$autoloader_source = file_get_contents( __DIR__ . '/../includes/Autoloader.php' );
$core_write_source = file_get_contents( __DIR__ . '/../includes/Packages/Core_Write_Package.php' );
$media_reference_discovery_write_trait = file_get_contents( __DIR__ . '/../includes/Packages/Write_Traits/Media_Reference_Discovery_Write_Methods.php' );
$post_attribute_write_trait = file_get_contents( __DIR__ . '/../includes/Packages/Write_Traits/Post_Attribute_Write_Methods.php' );
$site_editor_write_trait = file_get_contents( __DIR__ . '/../includes/Packages/Write_Traits/Site_Editor_Write_Methods.php' );
$structural_split_plan = file_get_contents( __DIR__ . '/../docs/structural-split-plan.md' );
npcink_abilities_toolkit_assert_true( is_string( $autoloader_source ) && false !== strpos( $autoloader_source, '_Write_Methods' ) && false !== strpos( $autoloader_source, 'Packages/Write_Traits/' ), 'internal autoloader maps write method traits lazily' );
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, 'use Post_Attribute_Write_Methods;' ), 'Core write package composes the post attribute write trait' );
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false === strpos( $core_write_source, 'public function set_post_slug(' ), 'Core write package does not duplicate moved post attribute methods' );
foreach ( array( 'set_post_slug', 'set_post_author', 'set_post_template', 'set_post_format' ) as $moved_post_attribute_method ) {
	npcink_abilities_toolkit_assert_true( is_string( $post_attribute_write_trait ) && false !== strpos( $post_attribute_write_trait, 'function ' . $moved_post_attribute_method . '(' ), 'post attribute trait owns moved method: ' . $moved_post_attribute_method );
}
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, 'use Site_Editor_Write_Methods;' ), 'Core write package composes the Site Editor write trait' );
foreach ( array( 'update_post_blocks', 'update_template_blocks', 'upsert_template_blocks', 'update_template_part_blocks', 'update_site_editor_entity_blocks', 'normalize_template_slug', 'active_theme_stylesheet', 'find_template_override_post', 'normalize_blocks_input', 'count_blocks_recursive', 'serialize_blocks_native' ) as $moved_site_editor_method ) {
	npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false === strpos( $core_write_source, 'function ' . $moved_site_editor_method . '(' ), 'Core write package does not duplicate moved Site Editor method: ' . $moved_site_editor_method );
	npcink_abilities_toolkit_assert_true( is_string( $site_editor_write_trait ) && false !== strpos( $site_editor_write_trait, 'function ' . $moved_site_editor_method . '(' ), 'Site Editor trait owns moved method: ' . $moved_site_editor_method );
}
foreach ( array( 'npcink-abilities-toolkit/update-post-blocks', 'npcink-abilities-toolkit/update-template-blocks', 'npcink-abilities-toolkit/upsert-template-blocks', 'npcink-abilities-toolkit/update-template-part-blocks' ) as $site_editor_ability_id ) {
	npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, "'" . $site_editor_ability_id . "' => array(" ), 'Core write package remains the definition owner for Site Editor ability: ' . $site_editor_ability_id );
}
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, 'use Media_Reference_Discovery_Write_Methods;' ), 'Core write package composes the media reference discovery trait' );
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, "hash_file( 'sha256'" ) && false !== strpos( $core_write_source, "'metadata_fingerprint'" ) && false !== strpos( $core_write_source, "'new_media_fingerprint'" ) && false !== strpos( $core_write_source, "'derived_from_media_fingerprint'" ), 'media write paths use real SHA-256 content fingerprints, keep metadata fingerprints separate, and record replacement lineage' );
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, "'transform_type'" ) && false !== strpos( $core_write_source, "'visual_reuse_policy'" ) && false !== strpos( $core_write_source, "'transform_facts'" ) && false !== strpos( $core_write_source, 'media_visual_reuse_policy_from_facts' ), 'media replacement history records transform facts and the bounded visual-evidence reuse policy' );
npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, 'media_file_operation_is_verified' ) && false !== strpos( $core_write_source, "do_action( 'npcink_abilities_toolkit_media_file_version_changed'" ), 'media replacement and restore publish a version-change event only through final verification' );
foreach ( array( 'media_content_reference_pairs_for_plan', 'media_content_reference_dynamic_sized_pairs', 'media_content_reference_source_relative_files', 'media_content_reference_without_unique_suffix', 'merge_media_content_reference_pairs', 'media_content_reference_candidate_posts', 'media_content_reference_needles', 'media_content_reference_url_path' ) as $moved_media_reference_discovery_method ) {
	npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false === strpos( $core_write_source, 'function ' . $moved_media_reference_discovery_method . '(' ), 'Core write package does not duplicate moved media reference discovery method: ' . $moved_media_reference_discovery_method );
	npcink_abilities_toolkit_assert_true( is_string( $media_reference_discovery_write_trait ) && false !== strpos( $media_reference_discovery_write_trait, 'function ' . $moved_media_reference_discovery_method . '(' ), 'media reference discovery trait owns moved method: ' . $moved_media_reference_discovery_method );
}
foreach ( array( 'build_media_content_reference_repairs', 'validate_media_content_reference_repair_permissions', 'apply_media_content_reference_repairs', 'media_file_operation_verification' ) as $retained_media_reference_write_method ) {
	npcink_abilities_toolkit_assert_true( is_string( $core_write_source ) && false !== strpos( $core_write_source, 'function ' . $retained_media_reference_write_method . '(' ), 'Core write package retains media reference governance method: ' . $retained_media_reference_write_method );
}
foreach ( array( 'Baseline Inventory', 'Accepted Sequence', 'Gate Per Slice', 'Core_Write_Package.php', 'tests/run.php', 'Post_Attribute_Write_Methods', 'Site_Editor_Write_Methods', 'Media_Reference_Discovery_Write_Methods' ) as $required_structural_split_text ) {
	npcink_abilities_toolkit_assert_true( is_string( $structural_split_plan ) && false !== strpos( $structural_split_plan, $required_structural_split_text ), 'structural split plan preserves incremental extraction guidance: ' . $required_structural_split_text );
}
npcink_abilities_toolkit_assert_true( false !== strpos( $core_read_package_source, 'use Media_Alt_Caption_Read_Methods;' ), 'Core read package composes the media ALT/caption read trait' );
$moved_media_alt_caption_methods = array(
	'build_media_alt_caption_review_set',
	'build_media_alt_apply_plan',
	'media_alt_caption_review_source_policy',
	'media_alt_caption_index_image_context_evidence',
	'media_alt_caption_apply_image_context_evidence',
	'media_alt_caption_public_image_context_evidence',
	'media_alt_caption_image_context_evidence_request',
	'media_alt_caption_item_status',
	'media_alt_caption_candidate_quality',
	'media_alt_caption_candidate_quality_assessment',
	'media_alt_caption_review_quality_summary',
	'media_alt_caption_candidate_rejection_reason',
	'media_alt_caption_candidate_context_profile',
	'media_alt_caption_merge_candidate_confidence',
	'media_alt_caption_candidate_needs_context_confirmation',
	'media_alt_caption_clean_candidate',
	'media_alt_caption_normalized_candidate',
	'media_alt_caption_is_duplicate_metadata',
	'media_alt_caption_is_url_or_source_text',
	'media_alt_caption_is_runtime_provenance_text',
	'media_alt_caption_is_camera_default',
	'media_alt_caption_candidate_is_too_short',
	'media_alt_caption_is_too_generic_candidate',
	'media_alt_caption_has_metadata_conflict',
	'media_alt_caption_sentence',
	'media_alt_caption_filename_descriptor',
	'media_alt_caption_is_filename_like',
	'media_alt_caption_trim_chars',
	'media_alt_caption_text_length',
	'media_alt_caption_sanitize_string_list',
	'media_alt_caption_sanitize_payload',
);
foreach ( $moved_media_alt_caption_methods as $moved_media_alt_caption_method ) {
	npcink_abilities_toolkit_assert_true( false === strpos( $media_read_trait_source, 'function ' . $moved_media_alt_caption_method . '(' ), 'general media read trait does not duplicate moved ALT/caption method: ' . $moved_media_alt_caption_method );
	npcink_abilities_toolkit_assert_true( false !== strpos( $media_alt_caption_read_trait_source, 'function ' . $moved_media_alt_caption_method . '(' ), 'media ALT/caption read trait owns moved method: ' . $moved_media_alt_caption_method );
}
$admin_css = file_get_contents( __DIR__ . '/../assets/admin.css' );
$admin_js = file_get_contents( __DIR__ . '/../assets/admin.js' );
$plugin_source = file_get_contents( __DIR__ . '/../includes/Plugin.php' );
$docs_readme = (string) file_get_contents( __DIR__ . '/../docs/README.md' );
$third_party_plugin_guide = (string) file_get_contents( __DIR__ . '/../docs/third-party-plugin-guide.md' );
$public_api_doc = (string) file_get_contents( __DIR__ . '/../docs/public-api.md' );
$ability_contract_reuse_readiness = (string) file_get_contents( __DIR__ . '/../docs/ability-contract-reuse-readiness-2026-07-08.md' );
$media_boundary_doc = (string) file_get_contents( __DIR__ . '/../docs/media-format-attention-boundary.md' );
$oss_storage_shim_doc = (string) file_get_contents( __DIR__ . '/../docs/oss-storage-compatibility-shim.md' );
$oss_storage_shim_example = (string) file_get_contents( __DIR__ . '/../examples/oss-storage-shim.php' );
npcink_abilities_toolkit_assert_true( false !== strpos( $docs_readme, 'OSS Storage Compatibility Shim' ), 'documentation guide links the OSS storage shim contract' );
npcink_abilities_toolkit_assert_true( false !== strpos( $docs_readme, 'Ability Contract Reuse Readiness - 2026-07-08' ), 'documentation guide links the ability contract reuse readiness record' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) file_get_contents( __DIR__ . '/../README.md' ), 'ability-contract-reuse-readiness-2026-07-08.md' ), 'root README links the ability contract reuse readiness record' );
foreach (
	array(
		'Ability Contract Reuse Readiness',
		'ability_contracts',
		'proposal_handoff',
		'execution_profiles',
		'product_surface',
		'signed_transport',
		'runtime_detail',
		'No new Toolkit ability or runtime code is needed for this pass.',
		'implementation_posture',
		'meta.npcink.implementation_posture',
		'composer check:contracts',
		'composer check:consumer',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post-blocks',
		'npcink-abilities-toolkit/set-post-terms',
		'npcink-abilities-toolkit/update-media-details',
		'Stop and write a boundary note or ADR',
		'final WordPress mutation policy',
		'npcink-ai-client-adapter',
		'composer analyse:phpstan',
	) as $required_reuse_readiness_text
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $ability_contract_reuse_readiness, $required_reuse_readiness_text ), 'ability contract reuse readiness keeps required text: ' . $required_reuse_readiness_text );
}
npcink_abilities_toolkit_assert_true( false !== strpos( $third_party_plugin_guide, 'npcink_abilities_toolkit_media_storage_inspection' ), 'third-party guide documents the media storage inspection shim' );
npcink_abilities_toolkit_assert_true( false !== strpos( $public_api_doc, 'npcink_abilities_toolkit_media_storage_inspection' ), 'public API documents the media storage inspection filter' );
npcink_abilities_toolkit_assert_true( false !== strpos( $media_boundary_doc, 'oss-storage-compatibility-shim.md' ), 'media boundary links the OSS storage shim contract' );
npcink_abilities_toolkit_assert_true( false !== strpos( $oss_storage_shim_doc, 'Toolkit does not own' ), 'OSS storage shim contract preserves Toolkit ownership boundaries' );
npcink_abilities_toolkit_assert_true( false !== strpos( $oss_storage_shim_doc, 'remote_storage_write_requires_adapter' ), 'OSS storage shim contract keeps fail-closed blocked reasons' );
npcink_abilities_toolkit_assert_true( false !== strpos( $oss_storage_shim_doc, 'Extra fields' ) && false !== strpos( $oss_storage_shim_doc, 'not preserved' ), 'OSS storage shim contract documents bounded preserved fields' );
npcink_abilities_toolkit_assert_true( false !== strpos( $oss_storage_shim_example, 'npcink_abilities_toolkit_media_storage_inspection' ), 'OSS storage shim example uses the public storage inspection filter' );
npcink_abilities_toolkit_assert_true( false !== strpos( $oss_storage_shim_example, "'provider_api'" ), 'OSS storage shim example declares provider API readiness only through metadata' );
npcink_abilities_toolkit_assert_true( false === strpos( strtolower( $oss_storage_shim_doc . $oss_storage_shim_example ), 'access_key' ), 'OSS storage shim docs and example do not mention provider access keys' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'PARENT_MENU_SLUG' ), 'admin test page knows the shared Npcink AI parent slug' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, "const PARENT_MENU_SLUG    = 'npcink-ai';" ), 'admin test page targets the shared Npcink AI parent menu.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, "const MENU_SLUG           = 'npcink-abilities-toolkit';" ), 'admin test page uses the canonical Abilities admin slug' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'npcink-ai-tabs npcink-abilities-toolkit-tabs' ) && false !== strpos( $admin_test_page, 'npcink-ai-tab-active' ) && false !== strpos( $admin_test_page, 'aria-current="page"' ), 'admin test page uses the shared Npcink AI tab visual standard.' );
npcink_abilities_toolkit_assert_true( false === strpos( $admin_test_page, 'nav-tab-wrapper' ) && false === strpos( $admin_test_page, 'nav-tab-active' ), 'admin test page no longer uses boxed WordPress nav tabs.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_css, '.npcink-ai-tabs' ) && false !== strpos( $admin_css, '.npcink-ai-tab-active' ), 'admin CSS includes the shared Npcink AI tab visual standard.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $plugin_source, 'plugin_action_links_' ) && false !== strpos( $plugin_source, 'filter_plugin_action_links' ), 'plugin screen exposes an abilities shortcut when the admin page is enabled.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $plugin_source, "esc_html__( 'View Abilities', 'npcink-abilities-toolkit' )" ), 'plugin screen abilities shortcut uses the plugin text domain.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $plugin_source, 'menu_page_url' ) && false !== strpos( $plugin_source, 'admin.php?page=npcink-abilities-toolkit' ) && false !== strpos( $plugin_source, 'tools.php?page=npcink-abilities-toolkit' ), 'plugin screen abilities shortcut targets the registered menu page or standalone Tools fallback.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, '$hook_suffixes' ), 'admin test page stores real WordPress hook suffixes for asset loading.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'Next actions' ), 'admin overview provides a clear post-install next action area.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'View Abilities' ) && false !== strpos( $admin_test_page, 'Open Checks' ) && false !== strpos( $admin_test_page, 'View Connection Info' ) && false !== strpos( $admin_test_page, 'Open Developer Access' ), 'admin overview links post-install users to distinct ability tasks.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'get_callback_issue_count' ), 'admin overview summarizes callback readiness before catalog inspection.' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'add_submenu_page' ), 'admin test page can attach to the shared Npcink AI menu' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, 'add_management_page' ), 'admin test page keeps the standalone Tools fallback' );
npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, "'Npcink Abilities Toolkit',\n\t\t\t\t__( 'AI Ability Set', 'npcink-abilities-toolkit' )," ), 'admin test page registers user-facing abilities page and submenu titles when attached' );
npcink_abilities_toolkit_assert_true( false === strpos( $admin_test_page, 'npcink_abilities_toolkit_nonce' ), 'admin diagnostic tab and filter URLs stay stable without one-time GET nonces.' );
$old_admin_slug = 'npcink-abilities-toolkit-' . 'test';
npcink_abilities_toolkit_assert_true( false === strpos( $admin_test_page, $old_admin_slug ), 'admin test page no longer uses the old test admin slug' );
foreach (
	array(
		'Review the WordPress abilities this site exposes to AI clients',
		'Site ability status',
		'Write safeguards',
		'host approval',
		'has_host_menu',
		'Next actions',
		'View site abilities',
		'Run safe checks',
		'Use a host product',
		'Developer access',
		'Open Checks',
		'Open Developer Access',
			'This plugin exposes WordPress abilities',
			'Available AI Abilities',
			'Ability name, description, category, or technical ID',
			'What it allows',
			'Developer-only ability IDs and schema signals are kept in Developer Access',
			'Requires host approval',
			'$allowed_per_page = array( 5, 10, 25, 50, 100 )',
			"'npcink_abilities_toolkit_per_page', '10'",
		'Site Checks',
		'What it proves',
		'What it does not prove',
		'The site can safely return basic WordPress information',
		'It does not call an AI model',
		'The site can return a support-friendly environment summary',
		'It does not expose secrets',
		'Use Available Abilities to review what the site exposes',
		'Run Redacted Diagnostics',
		'Check results',
		'plain summary table',
		'No check has run yet',
		'npcink-abilities-toolkit-check-summary',
		'npcink-abilities-toolkit-check-summary-body',
		'Raw response for support',
		'Full JSON is kept here for support and developer troubleshooting',
		'get_check_summary_labels',
		'data-check-summary-labels',
		'Developer Access',
		'Connection values',
		'Raw discovery fetches',
		'Copy Abilities Endpoint',
		'Copy Contract Endpoint',
		'npcink-abilities-toolkit-contract-endpoint',
		'Most site users do not need this tab',
		'npcink_abilities_toolkit_readonly_check',
		'data-npcink-abilities-toolkit-readonly-check',
			'Run Site Info',
			'Catalog export',
			'Ability ID export',
			'Ability technical catalog',
			'Technical IDs, categories, schema availability, and callback status',
			'Ability ID',
			'Home URL and Site URL differ',
			'render_status_summary',
		'render_ability_catalog',
		'render_site_checks',
		'render_developer_access',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $admin_test_page, $required ), 'admin test page keeps the user-facing ability surface: ' . $required );
}

foreach (
	array(
		'setCheckSummary',
		'summarizeReadonlyPayload',
		'npcink-abilities-toolkit-check-summary-body',
		'data-check-summary-labels',
		'REST ' . "' + payload.status",
	) as $required
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $admin_js, $required ), 'admin check JavaScript keeps the plain summary result renderer: ' . $required );
}

$admin_surface_standard = file_get_contents( __DIR__ . '/../docs/admin-surface-standard.md' );
foreach (
	array(
		'site-operator ability status',
		'AI Ability Set',
		'available ability count',
		'write-safeguard posture',
		'Available Abilities',
		'Developer Access',
		'contract endpoint should be visible as a copyable host/runtime',
		'stable, shareable admin URLs',
		'Core proposal approval',
		'OpenClaw handoff',
		'Cloud API key',
		'Time Display',
		'WordPress site timezone',
		'Y-m-d H:i:s',
		'Keep those output field names and semantics stable',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $admin_surface_standard, $required ), 'admin surface standard documents ability page boundary: ' . $required );
}

$plugin_readme = file_get_contents( __DIR__ . '/../readme.txt' );
foreach (
	array(
		'Third-Party Integration Quickstart',
		'npcink_abilities_toolkit_register_readonly',
		'npcink_abilities_toolkit_register_write_proposal',
		'Third-party provider callbacks should not perform final host-governed commits',
		'/wp-json/npcink-abilities-toolkit/v1/contract',
		'does not replace the WordPress Abilities API',
		'does not run abilities',
		'https://github.com/muze-page/npcink-abilities-toolkit',
		'If the `wp-abilities/v1` REST routes are missing',
		'Abilities API baseline or compatibility plugin',
		'Frequently Asked Questions',
		'Does this plugin run AI models?',
		'Will this plugin change my posts, media, terms, comments, or settings by itself?',
		'What do the Safe Checks prove?',
		'Screenshots',
		'Site ability status overview',
		'Available Abilities catalog with filters',
		'Safe Checks tab explaining what each check proves',
		'Developer Access tab with copyable REST endpoint values',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $plugin_readme ) && false !== strpos( $plugin_readme, $required ), 'packaged readme keeps third-party integration guidance: ' . $required );
}

$host_proof_status = file_get_contents( __DIR__ . '/../docs/host-proof-status.md' );
$block_theme_host_proof = file_get_contents( __DIR__ . '/block-theme-host-proof.php' );
$block_theme_host_proof_runner = file_get_contents( __DIR__ . '/../scripts/block-theme-host-proof.sh' );
foreach (
	array(
		'route-content-intent',
		'build-pattern-page-plan',
		'build-block-theme-site-plan',
		'fingerprint_before',
		'fingerprint_after',
		'postmeta',
		'no_mutation',
		'block_theme_proof_read_surface',
		'parse_blocks',
		'do_blocks',
		"'dry_run'",
		"'commit'",
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $block_theme_host_proof ) && false !== strpos( $block_theme_host_proof, $required ), 'block-theme host proof preserves real-host no-mutation evidence: ' . $required );
}
npcink_abilities_toolkit_assert_true( is_string( $block_theme_host_proof_runner ) && false !== strpos( $block_theme_host_proof_runner, 'WP_CLI_MYSQL_SOCKET' ), 'block-theme host proof runner supports Local MySQL sockets' );
npcink_abilities_toolkit_assert_true( is_string( $block_theme_host_proof_runner ) && false !== strpos( $block_theme_host_proof_runner, 'Set WP_PATH' ), 'block-theme host proof requires an explicit real WordPress target' );
foreach (
	array(
		'Closed Proof State And Observation Queue',
		'All three proof targets in the ledger are closed',
		'Keep Toolkit in freeze/observe mode and do not add first-party abilities',
		'Real-host proof passed on 2026-07-11',
		'No new Toolkit ability gap was found',
		'examples and long-form docs outside the release zip',
		'does not block basic third-party provider',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $host_proof_status ) && false !== strpos( $host_proof_status, $required ), 'host proof status keeps the closed proof state: ' . $required );
}

$next_stage_standard = file_get_contents( __DIR__ . '/../docs/next-stage-operating-standard.md' );
foreach (
	array(
		'Current stage update',
		'intent-routing proofs are closed-loop proven',
		'There is no active proof target',
		'Do not ship the repository `docs/`, `examples/`, or scripts',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $next_stage_standard ) && false !== strpos( $next_stage_standard, $required ), 'next-stage standard keeps freeze/observe direction: ' . $required );
}

$translation_template = file_get_contents( __DIR__ . '/../languages/npcink-abilities-toolkit.pot' );
$traditional_chinese_po = __DIR__ . '/../languages/npcink-abilities-toolkit-zh_TW.po';
$traditional_chinese_mo = __DIR__ . '/../languages/npcink-abilities-toolkit-zh_TW.mo';
npcink_abilities_toolkit_assert_true( ! file_exists( $traditional_chinese_po ) && ! file_exists( $traditional_chinese_mo ), 'bundled translations do not include an incomplete Traditional Chinese locale pack' );
foreach (
	array(
		'Standalone WordPress Abilities API package toolkit for safely exposing agent-callable abilities.',
		'View Abilities',
		'AI Ability Set',
		'Available AI Abilities',
		'Developer Access',
		'Run Redacted Diagnostics',
		'Connection values',
		'Raw discovery fetches',
		'Contract Endpoint',
		'Copy Contract Endpoint',
		'Details',
		'Raw response for support',
		'Adopt Article Audio',
		'Writes reviewed generated narration or audio summary metadata to one post after host approval, or returns a dry-run preview by default.',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $translation_template ) && false !== strpos( $translation_template, $required ), 'translation template includes admin diagnostics string: ' . $required );
}

$main_plugin_header = file_get_contents( __DIR__ . '/../npcink-abilities-toolkit.php' );
npcink_abilities_toolkit_assert_true( false !== strpos( $main_plugin_header, 'Requires at least: 6.9' ), 'main plugin header requires WordPress 6.9' );
npcink_abilities_toolkit_assert_true( false !== strpos( $main_plugin_header, 'Requires PHP: 8.0' ), 'main plugin header requires PHP 8.0' );

$readme = file_get_contents( __DIR__ . '/../README.md' );
foreach (
	array(
		'first-party host-governed dry-run/write and destructive callbacks',
		'host-governed WordPress write/destructive ability packages',
		'Host-governed callbacks default to dry-run previews',
		'A real commit requires approval context',
		'admission, approval storage, audit truth, or final commit authorization',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $readme, $required ), 'README precisely scopes host-governed ability ownership: ' . $required );
}
npcink_abilities_toolkit_assert_true( false !== strpos( $readme, 'docs/article-workflow-abilities-v1.md' ), 'README links the article workflow ability map.' );

$article_workflow_doc = file_get_contents( __DIR__ . '/../docs/article-workflow-abilities-v1.md' );
foreach (
	array(
		'article_draft_v1',
		'npcink-abilities-toolkit/recipes/article-draft',
		'article_assistant_workbench',
		'npcink-abilities-toolkit/create-draft',
		'does not provide a cloud writer',
		'Toolbox owns the operator workbench',
		'Core owns proposal intake',
		'Complexity Budget',
		'not a writing product',
		'Do not add Abilities-owned article generation',
		'hosted article drafting',
		'OpenClaw benefits from the same map without receiving a special bypass',
		'Cloud Addon should not implement cloud article writing',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( false !== strpos( $article_workflow_doc, $required ), 'article workflow ability map preserves boundary: ' . $required );
}

$docs_readme = file_get_contents( __DIR__ . '/../docs/README.md' );
npcink_abilities_toolkit_assert_true( is_string( $docs_readme ) && false !== strpos( $docs_readme, 'pattern-page-reference-spike.md' ), 'docs guide links the pattern page reference spike' );
$pattern_page_reference_doc = file_get_contents( __DIR__ . '/../docs/pattern-page-reference-spike.md' );
foreach (
	array(
		'build-pattern-page-plan',
		'WordPress Core Block Patterns',
		'Spectra, CoBlocks, Getwid, Gutenverse, and Extendify',
		'AI Page Builders',
		'Do not add a third-party block dependency',
		'core-block-only',
		'Gutenberg-native',
		'design_quality',
		'custom_css_required',
		'proposal-bound and draft-safe',
		'Do not start by adding a generic visual DSL',
	) as $required
) {
	npcink_abilities_toolkit_assert_true( is_string( $pattern_page_reference_doc ) && false !== strpos( $pattern_page_reference_doc, $required ), 'pattern page reference spike preserves boundary: ' . $required );
}

function npcink_abilities_toolkit_schema_contract_fingerprint( array $schema ) {
	$properties = array();
	foreach ( (array) ( $schema['properties'] ?? array() ) as $property_key => $property_schema ) {
		if ( ! is_string( $property_key ) || ! is_array( $property_schema ) ) {
			continue;
		}

		$fingerprint = array();
		foreach ( array( 'type', 'enum', 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'maxItems' ) as $field ) {
			if ( array_key_exists( $field, $property_schema ) ) {
				$fingerprint[ $field ] = $property_schema[ $field ];
			}
		}
		if ( array_key_exists( 'additionalProperties', $property_schema ) ) {
			$additional_properties = $property_schema['additionalProperties'];
			$fingerprint['additionalProperties'] = is_array( $additional_properties )
				? array_intersect_key( $additional_properties, array( 'type' => true ) )
				: $additional_properties;
		}

		$properties[ $property_key ] = $fingerprint;
	}

	return array(
		'required'             => (array) ( $schema['required'] ?? array() ),
		'additionalProperties' => $schema['additionalProperties'] ?? null,
		'properties'           => $properties,
	);
}

function npcink_abilities_toolkit_core_governance_catalog_snapshot( array $abilities, array $ability_ids ) {
	$snapshot = array(
		'schema_version' => 'v1',
		'purpose'        => 'Core governance handoff contract snapshot for high-value abilities.',
		'abilities'      => array(),
	);

	foreach ( $ability_ids as $ability_id ) {
		$definition = $abilities[ $ability_id ] ?? array();
		npcink_abilities_toolkit_assert_true( is_array( $definition ), "snapshot ability {$ability_id} exists" );

		$snapshot['abilities'][ $ability_id ] = array(
			'category'          => (string) ( $definition['category'] ?? '' ),
			'risk_level'        => (string) ( $definition['risk_level'] ?? '' ),
			'requires_confirm'  => (bool) ( $definition['requires_confirm'] ?? false ),
			'requires_approval' => (bool) ( $definition['requires_approval'] ?? false ),
			'capability'        => (string) ( $definition['capability'] ?? '' ),
			'required_scope'    => (string) ( $definition['required_scope'] ?? '' ),
			'required_scopes'   => (array) ( $definition['required_scopes'] ?? array() ),
			'input'             => npcink_abilities_toolkit_schema_contract_fingerprint( is_array( $definition['input_schema'] ?? null ) ? $definition['input_schema'] : array() ),
			'output'            => npcink_abilities_toolkit_schema_contract_fingerprint( is_array( $definition['output_schema'] ?? null ) ? $definition['output_schema'] : array() ),
			'meta'              => array(
				'show_in_rest' => (bool) ( $definition['meta']['show_in_rest'] ?? false ),
				'mcp_public'   => (bool) ( $definition['meta']['mcp']['public'] ?? false ),
				'mcp_server'   => (string) ( $definition['meta']['mcp']['server'] ?? '' ),
				'mcp_risk'     => (string) ( $definition['meta']['mcp']['risk'] ?? '' ),
			),
		);
	}

	return $snapshot;
}

function npcink_abilities_toolkit_assert_package_read_ability_contract( $ability_id, $definition ) {
	$definition = is_array( $definition ) ? $definition : array();
	npcink_abilities_toolkit_assert_same( true, $definition['annotations']['readonly'] ?? null, "{$ability_id} is readonly" );
	npcink_abilities_toolkit_assert_same( false, $definition['annotations']['destructive'] ?? null, "{$ability_id} is not destructive" );
	npcink_abilities_toolkit_assert_same( 'read', $definition['risk_level'] ?? '', "{$ability_id} risk is read" );
	npcink_abilities_toolkit_assert_same( false, $definition['requires_approval'] ?? null, "{$ability_id} does not require host approval" );
	npcink_abilities_toolkit_assert_same( false, $definition['meta']['npcink']['requires_approval'] ?? null, "{$ability_id} Npcink metadata does not require approval" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['show_in_rest'] ?? null, "{$ability_id} is shown in REST" );
	npcink_abilities_toolkit_assert_same( 'official', $definition['source'] ?? '', "{$ability_id} is an official migrated ability" );
	npcink_abilities_toolkit_assert_true( is_callable( $definition['execute_callback'] ?? null ), "{$ability_id} execute callback is callable" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['input_schema'] ?? null ), "{$ability_id} has an input schema" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['output_schema'] ?? null ), "{$ability_id} has an output schema" );
}

function npcink_abilities_toolkit_assert_package_write_ability_contract( $ability_id, $definition ) {
	$definition = is_array( $definition ) ? $definition : array();
	npcink_abilities_toolkit_assert_same( false, $definition['annotations']['readonly'] ?? null, "{$ability_id} is not readonly" );
	npcink_abilities_toolkit_assert_same( false, $definition['annotations']['destructive'] ?? null, "{$ability_id} is not destructive" );
	npcink_abilities_toolkit_assert_same( 'write', $definition['risk_level'] ?? '', "{$ability_id} risk is write" );
	npcink_abilities_toolkit_assert_same( true, $definition['requires_confirm'] ?? null, "{$ability_id} requires host approval" );
	npcink_abilities_toolkit_assert_same( true, $definition['requires_approval'] ?? null, "{$ability_id} exposes requires_approval for governance consumers" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['npcink']['requires_approval'] ?? null, "{$ability_id} Npcink metadata requires approval" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['show_in_rest'] ?? null, "{$ability_id} is shown in REST" );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-write', $definition['category'] ?? '', "{$ability_id} uses write category" );
	npcink_abilities_toolkit_assert_same( 'official', $definition['source'] ?? '', "{$ability_id} is an official migrated write ability" );
	npcink_abilities_toolkit_assert_same( true, $definition['project_to_npcink_catalog'] ?? null, "{$ability_id} projects into Npcink AI catalog" );
	npcink_abilities_toolkit_assert_true( is_callable( $definition['execute_callback'] ?? null ), "{$ability_id} execute callback is callable" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['input_schema'] ?? null ), "{$ability_id} has an input schema" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['output_schema'] ?? null ), "{$ability_id} has an output schema" );
	npcink_abilities_toolkit_assert_same( false, $definition['input_schema']['additionalProperties'] ?? null, "{$ability_id} input schema rejects undeclared fields" );
	npcink_abilities_toolkit_assert_same( true, $definition['input_schema']['properties']['dry_run']['default'] ?? null, "{$ability_id} dry_run defaults to preview" );
	npcink_abilities_toolkit_assert_same( false, $definition['input_schema']['properties']['commit']['default'] ?? null, "{$ability_id} commit defaults to false" );
	npcink_abilities_toolkit_assert_same( 190, $definition['input_schema']['properties']['idempotency_key']['maxLength'] ?? null, "{$ability_id} idempotency key is bounded" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['mcp']['public'] ?? null, "{$ability_id} is MCP-public for governed write server discovery" );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-write', $definition['meta']['mcp']['server'] ?? '', "{$ability_id} belongs on governed write server" );
	npcink_abilities_toolkit_assert_write_like_implementation_posture( $ability_id, $definition );
}

function npcink_abilities_toolkit_assert_write_like_implementation_posture( $ability_id, $definition ) {
	$definition = is_array( $definition ) ? $definition : array();
	$posture    = isset( $definition['implementation_posture'] ) && is_array( $definition['implementation_posture'] )
		? $definition['implementation_posture']
		: array();

	npcink_abilities_toolkit_assert_same( $posture, $definition['meta']['implementation_posture'] ?? array(), "{$ability_id} mirrors implementation_posture into meta" );
	npcink_abilities_toolkit_assert_same( $posture, $definition['meta']['npcink']['implementation_posture'] ?? array(), "{$ability_id} mirrors implementation_posture into Npcink metadata" );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_implementation_posture.v1', $posture['schema_version'] ?? '', "{$ability_id} exposes implementation posture schema version" );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit', $posture['implementation_owner'] ?? '', "{$ability_id} keeps Toolkit as implementation owner" );
	npcink_abilities_toolkit_assert_same( 'wordpress_abilities_api_host_governed', $posture['execution_surface'] ?? '', "{$ability_id} exposes host-governed Abilities API execution surface" );
	npcink_abilities_toolkit_assert_same( 'host_governed_dry_run_first', $posture['write_posture'] ?? '', "{$ability_id} declares dry-run-first write posture" );
	npcink_abilities_toolkit_assert_same( 'host_runtime_approval_context_required', $posture['commit_authority'] ?? '', "{$ability_id} leaves commit authority with the host runtime" );
	npcink_abilities_toolkit_assert_same( 'host_governance_layer', $posture['final_authorization_owner'] ?? '', "{$ability_id} leaves final authorization with the host governance layer" );
	npcink_abilities_toolkit_assert_same( 'host_governance_layer', $posture['approval_truth_owner'] ?? '', "{$ability_id} leaves approval truth with the host governance layer" );
	npcink_abilities_toolkit_assert_same( 'host_governance_layer', $posture['audit_truth_owner'] ?? '', "{$ability_id} leaves audit truth with the host governance layer" );
	npcink_abilities_toolkit_assert_same( false, $posture['direct_wordpress_write_default'] ?? true, "{$ability_id} does not default to direct WordPress mutation" );
	npcink_abilities_toolkit_assert_same( true, $posture['dry_run_default'] ?? null, "{$ability_id} implementation posture defaults to dry-run" );
	npcink_abilities_toolkit_assert_same( false, $posture['commit_default'] ?? true, "{$ability_id} implementation posture disables commit by default" );
	npcink_abilities_toolkit_assert_true( ! empty( $posture['reference_patterns'] ) && is_array( $posture['reference_patterns'] ), "{$ability_id} names reference implementation patterns" );
	npcink_abilities_toolkit_assert_true( ! empty( $posture['verification_contract'] ) && is_array( $posture['verification_contract'] ), "{$ability_id} names verification contract evidence" );
	npcink_abilities_toolkit_assert_true( ! empty( $posture['required_host_evidence'] ) && is_array( $posture['required_host_evidence'] ), "{$ability_id} names required host evidence" );
	foreach ( array( 'workflow_runtime', 'queue_or_scheduler', 'model_routing', 'provider_credentials', 'approval_storage', 'audit_storage' ) as $forbidden_flag ) {
		npcink_abilities_toolkit_assert_same( false, $posture[ $forbidden_flag ] ?? true, "{$ability_id} implementation posture excludes {$forbidden_flag}" );
	}
}

function npcink_abilities_toolkit_assert_package_destructive_ability_contract( $ability_id, $definition ) {
	$definition = is_array( $definition ) ? $definition : array();
	npcink_abilities_toolkit_assert_same( false, $definition['annotations']['readonly'] ?? null, "{$ability_id} is not readonly" );
	npcink_abilities_toolkit_assert_same( true, $definition['annotations']['destructive'] ?? null, "{$ability_id} is destructive" );
	npcink_abilities_toolkit_assert_same( 'destructive', $definition['risk_level'] ?? '', "{$ability_id} risk is destructive" );
	npcink_abilities_toolkit_assert_same( true, $definition['requires_confirm'] ?? null, "{$ability_id} requires host approval" );
	npcink_abilities_toolkit_assert_same( true, $definition['requires_approval'] ?? null, "{$ability_id} exposes requires_approval for governance consumers" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['npcink']['requires_approval'] ?? null, "{$ability_id} Npcink metadata requires approval" );
	npcink_abilities_toolkit_assert_same( true, $definition['meta']['show_in_rest'] ?? null, "{$ability_id} is shown in REST" );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-write', $definition['category'] ?? '', "{$ability_id} keeps legacy write category" );
	npcink_abilities_toolkit_assert_same( 'official', $definition['source'] ?? '', "{$ability_id} is an official migrated destructive ability" );
	npcink_abilities_toolkit_assert_same( true, $definition['project_to_npcink_catalog'] ?? null, "{$ability_id} projects into Npcink AI catalog" );
	npcink_abilities_toolkit_assert_true( is_callable( $definition['execute_callback'] ?? null ), "{$ability_id} execute callback is callable" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['input_schema'] ?? null ), "{$ability_id} has an input schema" );
	npcink_abilities_toolkit_assert_true( is_array( $definition['output_schema'] ?? null ), "{$ability_id} has an output schema" );
	npcink_abilities_toolkit_assert_same( false, $definition['input_schema']['additionalProperties'] ?? null, "{$ability_id} input schema rejects undeclared fields" );
	npcink_abilities_toolkit_assert_same( true, $definition['input_schema']['properties']['dry_run']['default'] ?? null, "{$ability_id} dry_run defaults to preview" );
	npcink_abilities_toolkit_assert_same( false, $definition['input_schema']['properties']['commit']['default'] ?? null, "{$ability_id} commit defaults to false" );
	npcink_abilities_toolkit_assert_same( 190, $definition['input_schema']['properties']['idempotency_key']['maxLength'] ?? null, "{$ability_id} idempotency key is bounded" );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-write', $definition['meta']['mcp']['server'] ?? '', "{$ability_id} belongs on governed write server" );
	npcink_abilities_toolkit_assert_same( 'destructive', $definition['meta']['mcp']['risk'] ?? '', "{$ability_id} MCP risk is destructive" );
	npcink_abilities_toolkit_assert_write_like_implementation_posture( $ability_id, $definition );
}

$schema_normalizer = new Schema_Normalizer();
$annotation_normalizer = new Annotation_Normalizer();
$normalized_schema = $schema_normalizer->normalize(
	array(
		'type'       => 'object',
		'properties' => array(
			'title' => 'string',
			'meta'  => array(
				'type'       => 'object',
				'properties' => array(
					'score' => 'number',
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( 'string', $normalized_schema['properties']['title']['type'], 'schema shorthand string is normalized' );
npcink_abilities_toolkit_assert_same( 'number', $normalized_schema['properties']['meta']['properties']['score']['type'], 'nested schema shorthand is normalized' );

$destructive_annotations = $annotation_normalizer->normalize(
	array( 'instructions' => "Use carefully.\nNever skip review." ),
	'destructive'
);
npcink_abilities_toolkit_assert_same( false, $destructive_annotations['readonly'], 'destructive annotation is not readonly' );
npcink_abilities_toolkit_assert_same( true, $destructive_annotations['destructive'], 'destructive annotation is destructive' );
npcink_abilities_toolkit_assert_same( false, $destructive_annotations['idempotent'], 'destructive annotation is not idempotent' );
npcink_abilities_toolkit_assert_same( 'Use carefully. Never skip review.', $destructive_annotations['instructions'], 'annotation instructions are sanitized' );

$contract_normalizer = new Contract_Normalizer( $schema_normalizer, $annotation_normalizer );
$readonly = $contract_normalizer->normalize(
	'Acme/Site Summary',
	array(
		'label'            => 'Site Summary',
		'description'      => 'Returns site summary.',
		'channels'         => array( 'abilities_rest', 'mcp' ),
		'input_schema'     => array( 'type' => 'object' ),
		'output_schema'    => array( 'type' => 'object' ),
		'execute_callback' => static function () {
			return array();
		},
	),
	'readonly'
);
npcink_abilities_toolkit_assert_same( 'acme/sitesummary', $readonly['ability_id'], 'ability id is lowercased and stripped to machine-safe characters' );
npcink_abilities_toolkit_assert_same( true, $readonly['annotations']['readonly'], 'readonly ability annotation is readonly' );
npcink_abilities_toolkit_assert_same( false, $readonly['annotations']['destructive'], 'readonly ability is not destructive' );
npcink_abilities_toolkit_assert_same( 'read', $readonly['risk_level'], 'readonly ability risk is read' );
npcink_abilities_toolkit_assert_same( true, $readonly['meta']['show_in_rest'], 'readonly ability defaults to show_in_rest' );
npcink_abilities_toolkit_assert_same( 'read', $readonly['meta']['mcp']['risk'], 'readonly mcp risk is read' );
npcink_abilities_toolkit_assert_true( ! isset( $readonly['input_schema']['properties']['dry_run'] ), 'readonly input schema does not include write dry_run control' );
npcink_abilities_toolkit_assert_true( ! isset( $readonly['input_schema']['properties']['commit'] ), 'readonly input schema does not include write commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $readonly['input_schema']['properties']['idempotency_key'] ), 'readonly input schema does not include write idempotency_key control' );
npcink_abilities_toolkit_assert_true( ! isset( $readonly['output_schema']['properties']['commit_required'] ), 'readonly output schema does not include write commit_required field' );

$write = $contract_normalizer->normalize(
	'acme/create-draft-proposal',
	array(
		'label'            => 'Create Draft Proposal',
		'description'      => 'Builds a draft proposal.',
		'input_schema'     => array( 'type' => 'object' ),
		'output_schema'    => array( 'type' => 'object' ),
		'execute_callback' => static function () {
			return array( 'proposal_id' => 'test' );
		},
	),
	'write_proposal'
);
npcink_abilities_toolkit_assert_same( false, $write['annotations']['readonly'], 'write proposal is not readonly' );
npcink_abilities_toolkit_assert_same( 'write', $write['risk_level'], 'write proposal risk is write' );
npcink_abilities_toolkit_assert_same( true, $write['requires_confirm'], 'write proposal requires confirmation' );
npcink_abilities_toolkit_assert_same( true, $write['requires_approval'], 'write proposal exposes approval requirement alias' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-write', $write['category'], 'write proposal default category is write category' );
foreach ( array( 'dry_run', 'commit', 'idempotency_key' ) as $write_control_property ) {
	npcink_abilities_toolkit_assert_true(
		isset( $write['input_schema']['properties'][ $write_control_property ] ),
		"write proposal input schema includes {$write_control_property} control"
	);
}
npcink_abilities_toolkit_assert_same( true, $write['input_schema']['properties']['dry_run']['default'] ?? null, 'write proposal dry_run defaults to preview' );
npcink_abilities_toolkit_assert_same( false, $write['input_schema']['properties']['commit']['default'] ?? null, 'write proposal commit defaults to false' );
npcink_abilities_toolkit_assert_same( 190, $write['input_schema']['properties']['idempotency_key']['maxLength'] ?? null, 'write proposal idempotency key is bounded' );
foreach ( array( 'dry_run', 'host_governed', 'commit_required', 'preview' ) as $write_output_property ) {
	npcink_abilities_toolkit_assert_true(
		isset( $write['output_schema']['properties'][ $write_output_property ] ),
		"write proposal output schema includes {$write_output_property} field"
	);
}

$destructive = $contract_normalizer->normalize(
	'acme/delete-post',
	array(
		'label'            => 'Delete Post',
		'description'      => 'Deletes a post through a host-governed path.',
		'input_schema'     => array( 'type' => 'object' ),
		'output_schema'    => array( 'type' => 'object' ),
		'execute_callback' => static function () {
			return array( 'dry_run' => true );
		},
	),
	'destructive_host'
);
npcink_abilities_toolkit_assert_same( true, $destructive['annotations']['destructive'], 'destructive host ability is destructive' );
npcink_abilities_toolkit_assert_same( 'destructive', $destructive['risk_level'], 'destructive host ability risk is destructive' );
npcink_abilities_toolkit_assert_same( true, $destructive['requires_confirm'], 'destructive host ability requires confirmation' );
npcink_abilities_toolkit_assert_same( true, $destructive['requires_approval'], 'destructive host ability exposes approval requirement alias' );
foreach ( array( 'dry_run', 'commit', 'idempotency_key' ) as $destructive_control_property ) {
	npcink_abilities_toolkit_assert_true(
		isset( $destructive['input_schema']['properties'][ $destructive_control_property ] ),
		"destructive input schema includes {$destructive_control_property} control"
	);
}
npcink_abilities_toolkit_assert_same( true, $destructive['input_schema']['properties']['dry_run']['default'] ?? null, 'destructive dry_run defaults to preview' );
npcink_abilities_toolkit_assert_same( false, $destructive['input_schema']['properties']['commit']['default'] ?? null, 'destructive commit defaults to false' );
npcink_abilities_toolkit_assert_same( 190, $destructive['input_schema']['properties']['idempotency_key']['maxLength'] ?? null, 'destructive idempotency key is bounded' );
foreach ( array( 'dry_run', 'host_governed', 'commit_required', 'preview' ) as $destructive_output_property ) {
	npcink_abilities_toolkit_assert_true(
		isset( $destructive['output_schema']['properties'][ $destructive_output_property ] ),
		"destructive output schema includes {$destructive_output_property} field"
	);
}

$categories = new Category_Registrar();
$registrar = new Ability_Registrar( $categories, $contract_normalizer );
npcink_abilities_toolkit_assert_true(
	$registrar->add_readonly(
		'acme/site-summary',
		array(
			'label'            => 'Site Summary',
			'description'      => 'Returns site summary.',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'required_scope'   => 'cap.site.read',
			'execute_callback' => static function () {
				return array();
			},
		)
	),
	'registrar accepts namespaced readonly ability'
);
npcink_abilities_toolkit_assert_true(
	! $registrar->add_readonly(
		'invalid',
		array(
			'label' => 'Invalid',
		)
	),
	'registrar rejects unnamespaced ability'
);

add_filter(
	'npcink_abilities_toolkit_enabled_packages',
	static function ( $packages ) {
		$packages['core_write']            = false;
		$packages['core_destructive']      = false;
		$packages['core_comment']          = false;
		$packages['npcink_catalog_bridge'] = false;
		$packages['admin_test_page']       = false;
		$packages['read_cache_hooks']      = false;

		return $packages;
	}
);
$plugin = Plugin::instance();
$plugin->boot();
$loaded_textdomains = isset( $GLOBALS['npcink_abilities_toolkit_unit_loaded_textdomains'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_loaded_textdomains'] )
	? $GLOBALS['npcink_abilities_toolkit_unit_loaded_textdomains']
	: array();
$loaded_toolkit_textdomain = false;
foreach ( $loaded_textdomains as $loaded_textdomain ) {
	if (
		is_array( $loaded_textdomain )
		&& 'npcink-abilities-toolkit' === ( $loaded_textdomain['domain'] ?? '' )
		&& false !== strpos( (string) ( $loaded_textdomain['path'] ?? '' ), 'languages' )
	) {
		$loaded_toolkit_textdomain = true;
		break;
	}
}
npcink_abilities_toolkit_assert_true( $loaded_toolkit_textdomain, 'plugin boot loads bundled translations from the languages directory' );
$plugin_abilities = $plugin->abilities()->all();
npcink_abilities_toolkit_assert_true( isset( $plugin_abilities['npcink-abilities-toolkit/site-info'] ), 'package filter keeps enabled core read package' );
npcink_abilities_toolkit_assert_true( ! isset( $plugin_abilities['npcink-abilities-toolkit/create-draft'] ), 'package filter disables core write package' );
npcink_abilities_toolkit_assert_true( ! isset( $plugin_abilities['npcink-abilities-toolkit/delete-post-permanently'] ), 'package filter disables core destructive package' );
npcink_abilities_toolkit_assert_true( ! isset( $plugin_abilities['npcink-abilities-toolkit/get-comment-queue-health'] ), 'package filter disables core comment package' );
$rest_actions = isset( $GLOBALS['npcink_abilities_toolkit_unit_actions']['rest_api_init'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_actions']['rest_api_init'] )
	? $GLOBALS['npcink_abilities_toolkit_unit_actions']['rest_api_init']
	: array();
$has_contract_controller_action = false;
foreach ( $rest_actions as $rest_action ) {
	if ( is_array( $rest_action ) && isset( $rest_action[0], $rest_action[1] ) && $rest_action[0] instanceof Contract_Controller && 'register_routes' === $rest_action[1] ) {
		$has_contract_controller_action = true;
		break;
	}
}
npcink_abilities_toolkit_assert_true( $has_contract_controller_action, 'plugin registers the runtime contract route on rest_api_init' );
$contract_controller = new Contract_Controller();
$GLOBALS['npcink_abilities_toolkit_unit_current_user_caps'] = array( 'manage_options' => false );
npcink_abilities_toolkit_assert_same( false, $contract_controller->can_read_contract(), 'runtime contract route rejects callers without manage_options' );
$GLOBALS['npcink_abilities_toolkit_unit_current_user_caps'] = array( 'manage_options' => true );
npcink_abilities_toolkit_assert_same( true, $contract_controller->can_read_contract(), 'runtime contract route allows callers with manage_options' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_current_user_caps'] );
$runtime_contract    = $contract_controller->contract();
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_contract.v1', $runtime_contract['schema_version'] ?? '', 'runtime contract exposes the schema version' );
npcink_abilities_toolkit_assert_same( '0.1.0-test', $runtime_contract['plugin_version'] ?? '', 'runtime contract exposes the plugin version' );
npcink_abilities_toolkit_assert_same( '1', $runtime_contract['runtime_contract_endpoint_version'] ?? '', 'runtime contract exposes endpoint version' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit', $runtime_contract['compatibility']['contract_family'] ?? '', 'runtime contract exposes compatibility family' );
npcink_abilities_toolkit_assert_same( '1', $runtime_contract['compatibility']['minimum_adapter_contract_version'] ?? '', 'runtime contract exposes Adapter compatibility floor' );
npcink_abilities_toolkit_assert_same( true, $runtime_contract['compatibility']['metadata_only'] ?? null, 'runtime contract is metadata-only' );
npcink_abilities_toolkit_assert_same( true, $runtime_contract['compatibility']['wordpress_abilities_api_required'] ?? null, 'runtime contract declares WordPress Abilities API requirement' );
npcink_abilities_toolkit_assert_same( count( $plugin_abilities ), $runtime_contract['ability_count'] ?? null, 'runtime contract ability count follows the registered package profile' );
npcink_abilities_toolkit_assert_same( 0, $runtime_contract['ability_risk_counts']['write'] ?? null, 'runtime contract honors disabled write package in the active profile' );
npcink_abilities_toolkit_assert_same( 0, $runtime_contract['ability_risk_counts']['destructive'] ?? null, 'runtime contract honors disabled destructive package in the active profile' );
npcink_abilities_toolkit_assert_same( count( $plugin_abilities ), array_sum( (array) ( $runtime_contract['ability_risk_counts'] ?? array() ) ), 'runtime contract risk counts add up to the ability count' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit', $runtime_contract['catalog']['ability_definitions_owner'] ?? '', 'runtime contract names Toolkit as ability definitions owner' );
npcink_abilities_toolkit_assert_same( 'wordpress_abilities_api', $runtime_contract['catalog']['ability_catalog_source'] ?? '', 'runtime contract points hosts to WordPress Abilities API catalog' );
npcink_abilities_toolkit_assert_same( true, $runtime_contract['schema_controls']['callback_free_hashes'] ?? null, 'runtime contract exposes callback-free schema hashes' );
npcink_abilities_toolkit_assert_same( true, $runtime_contract['write_controls']['dry_run_default'] ?? null, 'runtime contract keeps dry-run as the default write posture' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['write_controls']['commit_default'] ?? null, 'runtime contract keeps commit disabled by default' );
npcink_abilities_toolkit_assert_same( true, $runtime_contract['write_controls']['host_governed_writes'] ?? null, 'runtime contract keeps write authority host-governed' );
npcink_abilities_toolkit_assert_same( 'wordpress_abilities_api', $runtime_contract['execution_controls']['read_execution_surface'] ?? '', 'runtime contract keeps read execution on WordPress Abilities API' );
npcink_abilities_toolkit_assert_same( 'host_runtime_after_governance', $runtime_contract['execution_controls']['write_execution_surface'] ?? '', 'runtime contract leaves write execution to host runtime after governance' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['execution_controls']['approval_storage'] ?? true, 'runtime contract excludes approval storage from Toolkit' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['execution_controls']['audit_truth'] ?? true, 'runtime contract excludes audit truth from Toolkit' );
npcink_abilities_toolkit_assert_same( 'host_governance_layer', $runtime_contract['boundary']['approval_truth_owner'] ?? '', 'runtime contract leaves approval truth with the host governance layer' );
npcink_abilities_toolkit_assert_same( 'host_governance_layer', $runtime_contract['boundary']['audit_truth_owner'] ?? '', 'runtime contract leaves audit truth with the host governance layer' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['forbidden_payloads']['approval_records'] ?? true, 'runtime contract forbids approval records' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['forbidden_payloads']['audit_records'] ?? true, 'runtime contract forbids audit records' );
npcink_abilities_toolkit_assert_same( false, $runtime_contract['forbidden_payloads']['runtime_state'] ?? true, 'runtime contract forbids runtime state' );
foreach ( array( 'ability_ids_hash', 'ability_contracts_hash', 'workflow_recipes_hash' ) as $hash_key ) {
	npcink_abilities_toolkit_assert_true( 0 === strpos( (string) ( $runtime_contract[ $hash_key ] ?? '' ), 'sha256:' ), "runtime contract exposes {$hash_key} as a sha256 digest" );
}
npcink_abilities_toolkit_assert_array_omits_keys(
	$runtime_contract,
	array(
		'callback',
		'execute_callback',
		'permission_callback',
		'secret',
		'token',
	),
	'runtime contract'
);
$runtime_contract_json = wp_json_encode( $runtime_contract );
foreach ( array( 'execute_callback', 'permission_callback', 'Closure', '/Users/muze' ) as $forbidden_fragment ) {
	npcink_abilities_toolkit_assert_true( false === strpos( (string) $runtime_contract_json, $forbidden_fragment ), 'runtime contract JSON omits internal fragment ' . $forbidden_fragment );
}
remove_all_filters( 'npcink_abilities_toolkit_enabled_packages' );

npcink_abilities_toolkit_assert_true(
	$registrar->add_write_host_governed(
		'acme/host-write',
		array(
			'label'            => 'Host Write',
			'description'      => 'Host-governed write.',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'execute_callback' => static function () {
				return array( 'dry_run' => true );
			},
		)
	),
	'registrar accepts host-governed write ability'
);
$host_write = $registrar->all()['acme/host-write'];
npcink_abilities_toolkit_assert_same( 'write_host', $host_write['mode'], 'host-governed write mode is preserved' );
npcink_abilities_toolkit_assert_same( 'write', $host_write['risk_level'], 'host-governed write risk is write' );
npcink_abilities_toolkit_assert_same( true, $host_write['requires_confirm'], 'host-governed write requires confirmation' );

$GLOBALS['npcink_abilities_toolkit_unit_options'] = array();
$GLOBALS['npcink_abilities_toolkit_unit_transients'] = array();
$GLOBALS['npcink_abilities_toolkit_unit_registered_abilities'] = array();
$GLOBALS['npcink_abilities_toolkit_unit_observability_events'] = array();
add_action(
	'npcink_abilities_toolkit_observability_event',
	static function ( $event ) {
		$GLOBALS['npcink_abilities_toolkit_unit_observability_events'][] = $event;
	}
);

$observability_categories = new Category_Registrar();
$observability_registrar = new Ability_Registrar( $observability_categories, $contract_normalizer );
npcink_abilities_toolkit_assert_true(
	$observability_registrar->add_readonly(
		'acme/observable-summary',
		array(
			'label'            => 'Observable Summary',
			'description'      => 'Returns observable summary.',
			'category'         => 'acme-observability',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'meta'             => array(
				'secret'  => 'must-not-leak',
				'payload' => array( 'raw' => true ),
			),
			'execute_callback' => static function () {
				return array( 'ok' => true );
			},
		)
	),
	'observability registrar accepts test ability'
);
npcink_abilities_toolkit_assert_true(
	$observability_registrar->add_readonly(
		'acme/observable-wp-error',
		array(
			'label'            => 'Observable WP Error',
			'description'      => 'Returns observable WP error.',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'execute_callback' => static function () {
				return new WP_Error( 'acme_callback_failed', 'Raw error message should not be emitted.', array( 'payload_json' => 'must-not-leak' ) );
			},
		)
	),
	'observability registrar accepts WP_Error callback ability'
);
npcink_abilities_toolkit_assert_true(
	$observability_registrar->add_readonly(
		'acme/observable-exception',
		array(
			'label'            => 'Observable Exception',
			'description'      => 'Throws observable exception.',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'execute_callback' => static function () {
				throw new RuntimeException( 'Raw exception message should not be emitted.' );
			},
		)
	),
	'observability registrar accepts throwing callback ability'
);
$first_hash = $observability_registrar->catalog_fingerprint();
$observability_registrar->register_with_wordpress();
$catalog_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.catalog.changed' );
npcink_abilities_toolkit_assert_same( 1, count( $catalog_events ), 'first bootstrap emits one catalog changed event' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit', $catalog_events[0]['plugin_slug'] ?? '', 'catalog event carries plugin slug' );
npcink_abilities_toolkit_assert_same( 'ok', $catalog_events[0]['status'] ?? '', 'catalog event status is ok' );
npcink_abilities_toolkit_assert_same( 'local', $catalog_events[0]['source'] ?? '', 'catalog event source remains local' );
npcink_abilities_toolkit_assert_same( 3, $catalog_events[0]['ability_count'] ?? 0, 'catalog event carries ability count' );
npcink_abilities_toolkit_assert_same( $first_hash, $catalog_events[0]['catalog_hash'] ?? '', 'catalog event carries current catalog hash' );
npcink_abilities_toolkit_assert_event_has_safe_event_id( $catalog_events[0], 'catalog_', 'catalog event' );
npcink_abilities_toolkit_assert_observability_event_is_metadata_only( $catalog_events[0], 'catalog event payload' );

$GLOBALS['npcink_abilities_toolkit_unit_registered_abilities'] = array();
$observability_registrar->register_with_wordpress();
$catalog_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.catalog.changed' );
npcink_abilities_toolkit_assert_same( 1, count( $catalog_events ), 'repeated bootstrap does not repeat catalog changed event for the same hash' );
npcink_abilities_toolkit_assert_same( 0, count( npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.ability.registered' ) ), 'ability add no longer emits per-ability registered events' );
npcink_abilities_toolkit_assert_same( 0, count( npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.ability.wordpress_registered' ) ), 'WordPress registration no longer emits per-ability events' );

npcink_abilities_toolkit_assert_true(
	$observability_registrar->add_readonly(
		'acme/observable-detail',
		array(
			'label'            => 'Observable Detail',
			'description'      => 'Returns observable detail.',
			'input_schema'     => array( 'type' => 'object' ),
			'output_schema'    => array( 'type' => 'object' ),
			'execute_callback' => static function () {
				return array( 'detail' => true );
			},
		)
	),
	'observability registrar accepts changed catalog ability'
);
$changed_hash = $observability_registrar->catalog_fingerprint();
npcink_abilities_toolkit_assert_true( $first_hash !== $changed_hash, 'catalog hash changes when ability catalog changes' );
$GLOBALS['npcink_abilities_toolkit_unit_registered_abilities'] = array();
$observability_registrar->register_with_wordpress();
$catalog_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.catalog.changed' );
npcink_abilities_toolkit_assert_same( 2, count( $catalog_events ), 'changed catalog hash emits one additional catalog changed event' );
npcink_abilities_toolkit_assert_same( $changed_hash, $catalog_events[1]['catalog_hash'] ?? '', 'changed catalog event carries new hash' );
npcink_abilities_toolkit_assert_same( $first_hash, $catalog_events[1]['previous_catalog_hash'] ?? '', 'changed catalog event carries previous hash' );
$GLOBALS['npcink_abilities_toolkit_unit_registered_abilities'] = array();
$observability_registrar->register_with_wordpress();
$catalog_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.catalog.changed' );
npcink_abilities_toolkit_assert_same( 2, count( $catalog_events ), 'same changed catalog hash is rate limited after first emit' );

$GLOBALS['npcink_abilities_toolkit_unit_options'][ Ability_Registrar::CATALOG_STATE_OPTION ] = array(
	'catalog_hash'   => $changed_hash,
	'emitted_at'     => '2026-06-01T00:00:00+00:00',
	'plugin_version' => '0.0.0-old',
	'reason'         => 'bootstrap',
);
$old_version_rate_limit_key = Ability_Registrar::CATALOG_RATE_LIMIT_PREFIX . substr( hash( 'sha256', $changed_hash . '|0.0.0-old' ), 0, 40 );
$GLOBALS['npcink_abilities_toolkit_unit_transients'][ $old_version_rate_limit_key ] = '2026-06-01T00:00:00+00:00';
$GLOBALS['npcink_abilities_toolkit_unit_registered_abilities'] = array();
$observability_registrar->register_with_wordpress();
$catalog_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.catalog.changed' );
npcink_abilities_toolkit_assert_same( 3, count( $catalog_events ), 'version change emits catalog changed even when old hash transient exists' );
npcink_abilities_toolkit_assert_same( $changed_hash, $catalog_events[2]['catalog_hash'] ?? '', 'version-change catalog event keeps unchanged hash' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog_events[2]['previous_catalog_hash'] ), 'version-change same-hash catalog event omits previous hash' );
npcink_abilities_toolkit_assert_same( NPCINK_ABILITIES_TOOLKIT_VERSION, $GLOBALS['npcink_abilities_toolkit_unit_options'][ Ability_Registrar::CATALOG_STATE_OPTION ]['plugin_version'] ?? '', 'version-change emit updates catalog state version' );

$callback = $GLOBALS['npcink_abilities_toolkit_unit_registered_abilities']['acme/observable-summary']['execute_callback'] ?? null;
npcink_abilities_toolkit_assert_true( is_callable( $callback ), 'registered ability keeps callable observed execute callback' );
$callback_result = call_user_func( $callback, array( 'raw_callback_input' => 'super-secret-callback-input' ) );
npcink_abilities_toolkit_assert_same( array( 'ok' => true ), $callback_result, 'observed callback returns original result' );
$callback_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.callback.completed' );
npcink_abilities_toolkit_assert_same( 1, count( $callback_events ), 'callback execution still emits behavior observability event' );
npcink_abilities_toolkit_assert_same( 'acme/observable-summary', $callback_events[0]['ability_id'] ?? '', 'callback event carries ability id' );
npcink_abilities_toolkit_assert_same( 'ok', $callback_events[0]['status'] ?? '', 'callback event carries successful status' );
npcink_abilities_toolkit_assert_event_has_safe_event_id( $callback_events[0], 'ability_cb_', 'callback completed event' );
npcink_abilities_toolkit_assert_observability_event_is_metadata_only( $callback_events[0], 'callback completed event payload' );
npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $callback_events[0] ), 'super-secret-callback-input' ), 'callback completed event omits raw callback input values' );

$wp_error_callback = $GLOBALS['npcink_abilities_toolkit_unit_registered_abilities']['acme/observable-wp-error']['execute_callback'] ?? null;
npcink_abilities_toolkit_assert_true( is_callable( $wp_error_callback ), 'registered WP_Error ability keeps callable observed execute callback' );
$wp_error_result = call_user_func( $wp_error_callback, array( 'payload_json' => 'super-secret-callback-input' ) );
npcink_abilities_toolkit_assert_true( is_wp_error( $wp_error_result ), 'observed WP_Error callback returns original error result' );
$failed_callback_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.callback.failed' );
npcink_abilities_toolkit_assert_same( 1, count( $failed_callback_events ), 'WP_Error callback emits one failed callback event' );
npcink_abilities_toolkit_assert_same( 'acme/observable-wp-error', $failed_callback_events[0]['ability_id'] ?? '', 'WP_Error failed event carries ability id' );
npcink_abilities_toolkit_assert_same( 'error', $failed_callback_events[0]['status'] ?? '', 'WP_Error failed event carries error status' );
npcink_abilities_toolkit_assert_same( 'abilities.callback_error', $failed_callback_events[0]['error_code'] ?? '', 'WP_Error failed event uses stable error code' );
npcink_abilities_toolkit_assert_same( 'acme_callback_failed', $failed_callback_events[0]['status_detail'] ?? '', 'WP_Error failed event carries redacted status detail' );
npcink_abilities_toolkit_assert_event_has_safe_event_id( $failed_callback_events[0], 'ability_cb_', 'WP_Error failed event' );
npcink_abilities_toolkit_assert_observability_event_is_metadata_only( $failed_callback_events[0], 'WP_Error failed event payload' );
npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $failed_callback_events[0] ), 'super-secret-callback-input' ), 'WP_Error failed event omits raw callback input values' );
npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $failed_callback_events[0] ), 'Raw error message should not be emitted.' ), 'WP_Error failed event omits raw error message' );

$exception_callback = $GLOBALS['npcink_abilities_toolkit_unit_registered_abilities']['acme/observable-exception']['execute_callback'] ?? null;
npcink_abilities_toolkit_assert_true( is_callable( $exception_callback ), 'registered exception ability keeps callable observed execute callback' );
try {
	call_user_func( $exception_callback, array( 'payload_json' => 'super-secret-callback-input' ) );
	npcink_abilities_toolkit_assert_true( false, 'observed exception callback rethrows original exception' );
} catch ( RuntimeException $exception ) {
	npcink_abilities_toolkit_assert_same( 'Raw exception message should not be emitted.', $exception->getMessage(), 'observed exception callback rethrows original exception message locally' );
}
$failed_callback_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.callback.failed' );
npcink_abilities_toolkit_assert_same( 2, count( $failed_callback_events ), 'throwing callback emits one additional failed callback event' );
npcink_abilities_toolkit_assert_same( 'acme/observable-exception', $failed_callback_events[1]['ability_id'] ?? '', 'exception failed event carries ability id' );
npcink_abilities_toolkit_assert_same( 'abilities.callback_error', $failed_callback_events[1]['error_code'] ?? '', 'exception failed event uses stable error code' );
npcink_abilities_toolkit_assert_same( 'runtimeexception', $failed_callback_events[1]['status_detail'] ?? '', 'exception failed event carries redacted exception class' );
npcink_abilities_toolkit_assert_event_has_safe_event_id( $failed_callback_events[1], 'ability_cb_', 'exception failed event' );
npcink_abilities_toolkit_assert_observability_event_is_metadata_only( $failed_callback_events[1], 'exception failed event payload' );
npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $failed_callback_events[1] ), 'super-secret-callback-input' ), 'exception failed event omits raw callback input values' );
npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $failed_callback_events[1] ), 'Raw exception message should not be emitted.' ), 'exception failed event omits raw exception message' );
$callback_events = npcink_abilities_toolkit_observability_events_of_kind( $GLOBALS['npcink_abilities_toolkit_unit_observability_events'], 'abilities.callback.completed' );
npcink_abilities_toolkit_assert_same( 1, count( $callback_events ), 'failed callbacks do not add completed callback events' );

$bridge = new Npcink_Catalog_Bridge( $registrar );
$catalog = $bridge->filter_catalog( array(), array() );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_site-summary'] ), 'catalog bridge does not project provider abilities by default' );

npcink_abilities_toolkit_assert_true(
	$registrar->add_readonly(
		'acme/projected-summary',
		array(
			'label'                     => 'Projected Summary',
			'description'               => 'Provider ability explicitly projected for Npcink AI compatibility.',
			'project_to_npcink_catalog' => true,
			'input_schema'              => array( 'type' => 'object' ),
			'output_schema'             => array( 'type' => 'object' ),
			'execute_callback'          => static function () {
				return array();
			},
		)
	),
	'registrar accepts provider ability with explicit Npcink AI projection'
);
$catalog = $bridge->filter_catalog( array(), array() );
npcink_abilities_toolkit_assert_true( isset( $catalog['acme_projected-summary'] ), 'catalog bridge projects opted-in provider ability' );
npcink_abilities_toolkit_assert_same( 'wp_ability', $catalog['acme_projected-summary']['executor_type'], 'catalog bridge uses wp_ability executor' );
npcink_abilities_toolkit_assert_same( 'acme/projected-summary', $catalog['acme_projected-summary']['wp_ability_id'], 'catalog bridge keeps wp ability id' );
npcink_abilities_toolkit_assert_same( true, $catalog['acme_projected-summary']['show_in_rest'], 'catalog bridge sets top-level show_in_rest for host catalog normalization' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-summary']['open_api_enabled'] ), 'catalog bridge does not own Open API routing policy' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-summary']['skip_catalog_manifest_fallback'] ), 'catalog bridge does not own host manifest fallback policy' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-summary']['backend_priority'] ), 'catalog bridge does not own backend priority policy' );

npcink_abilities_toolkit_assert_true(
	$registrar->add_write_host_governed(
		'acme/projected-host-write',
		array(
			'label'                     => 'Projected Host Write',
			'description'               => 'Provider write ability explicitly projected for Npcink AI compatibility.',
			'project_to_npcink_catalog' => true,
			'input_schema'              => array( 'type' => 'object' ),
			'output_schema'             => array( 'type' => 'object' ),
			'execute_callback'          => static function () {
				return array( 'dry_run' => true );
			},
		)
	),
	'registrar accepts projected host-governed write ability'
);
$catalog = $bridge->filter_catalog( array(), array() );
npcink_abilities_toolkit_assert_same( 'wp_ability', $catalog['acme_projected-host-write']['executor_type'] ?? '', 'catalog bridge projects host-governed write as wp_ability' );
npcink_abilities_toolkit_assert_same( true, $catalog['acme_projected-host-write']['requires_confirm'] ?? null, 'catalog bridge carries confirmation requirement for projected host-governed write' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-host-write']['tool_policy'] ), 'catalog bridge does not own projected host-governed write tool policy' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-host-write']['skip_catalog_manifest_fallback'] ), 'catalog bridge does not own projected host-governed write fallback policy' );

npcink_abilities_toolkit_assert_true(
	$registrar->add_destructive_host_governed(
		'acme/projected-delete-post',
		array(
			'label'                     => 'Projected Delete Post',
			'description'               => 'Provider destructive ability explicitly projected for Npcink AI compatibility.',
			'project_to_npcink_catalog' => true,
			'input_schema'              => array( 'type' => 'object' ),
			'output_schema'             => array( 'type' => 'object' ),
			'execute_callback'          => static function () {
				return array( 'dry_run' => true );
			},
		)
	),
	'registrar accepts projected destructive host-governed ability'
);
$catalog = $bridge->filter_catalog( array(), array() );
npcink_abilities_toolkit_assert_same( 'wp_ability', $catalog['acme_projected-delete-post']['executor_type'] ?? '', 'catalog bridge projects destructive ability as wp_ability' );
npcink_abilities_toolkit_assert_same( true, $catalog['acme_projected-delete-post']['requires_confirm'] ?? null, 'catalog bridge carries confirmation requirement for projected destructive ability' );
npcink_abilities_toolkit_assert_same( 'destructive', $catalog['acme_projected-delete-post']['risk_level'] ?? '', 'catalog bridge carries projected destructive risk' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-delete-post']['tool_policy'] ), 'catalog bridge does not own projected destructive tool policy' );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['acme_projected-delete-post']['skip_catalog_manifest_fallback'] ), 'catalog bridge does not own projected destructive fallback policy' );

npcink_abilities_toolkit_assert_true(
	$registrar->add_readonly(
		'npcink-abilities-toolkit/official-summary',
		array(
			'label'                     => 'Official Summary',
			'description'               => 'Official ability mirrored from a host plugin.',
			'source'                    => 'official',
			'project_to_npcink_catalog' => false,
			'input_schema'              => array( 'type' => 'object' ),
			'output_schema'             => array( 'type' => 'object' ),
			'execute_callback'          => static function () {
				return array();
			},
		)
	),
	'registrar accepts official mirrored readonly ability'
);
$catalog = $bridge->filter_catalog( array(), array() );
npcink_abilities_toolkit_assert_true( ! isset( $catalog['npcink-abilities-toolkit_official-summary'] ), 'catalog bridge does not project official mirrored abilities' );

$package_categories = new Category_Registrar();
$package_registrar = new Ability_Registrar( $package_categories, $contract_normalizer );
$block_document = new Gutenberg_Block_Document();
$core_read_package = new Core_Read_Package( $package_categories, $package_registrar );
$core_read_package->boot();
$core_write_package = new Core_Write_Package( $package_categories, $package_registrar );
$core_write_package->boot();
$media_reuse_policy_method = new ReflectionMethod( Core_Write_Package::class, 'media_visual_reuse_policy_from_facts' );
if ( PHP_VERSION_ID < 80100 ) {
	$media_reuse_policy_method->setAccessible( true );
}
npcink_abilities_toolkit_assert_same( 'reuse', $media_reuse_policy_method->invoke( $core_write_package, array( 'encoding_mode' => 'lossless', 'resize_applied' => false ) ), 'lossless encoding without resize directly reuses visual evidence' );
npcink_abilities_toolkit_assert_same( 'reuse_with_human_check', $media_reuse_policy_method->invoke( $core_write_package, array( 'encoding_mode' => 'lossy', 'source_width' => 1000, 'source_height' => 800, 'output_width' => 750, 'output_height' => 600 ) ), 'lossy compression or exactly 25 percent scaling requires human visual confirmation' );
npcink_abilities_toolkit_assert_same( 'requires_reidentification', $media_reuse_policy_method->invoke( $core_write_package, array( 'encoding_mode' => 'lossy', 'source_width' => 1000, 'source_height' => 800, 'output_width' => 749, 'output_height' => 600 ) ), 'scaling beyond the 25 percent limit requires fresh visual identification' );
npcink_abilities_toolkit_assert_same( 'requires_reidentification', $media_reuse_policy_method->invoke( $core_write_package, array( 'crop_applied' => true, 'source_width' => 1000, 'source_height' => 800, 'output_width' => 1000, 'output_height' => 800 ) ), 'crop transforms cannot reuse prior visual evidence' );
npcink_abilities_toolkit_assert_same( 'requires_reidentification', $media_reuse_policy_method->invoke( $core_write_package, array( 'alpha_preserved' => false, 'source_width' => 1000, 'source_height' => 800, 'output_width' => 1000, 'output_height' => 800 ) ), 'loss of transparency cannot reuse prior visual evidence' );
$core_destructive_package = new Core_Destructive_Package( $package_categories, $package_registrar );
$core_destructive_package->boot();
$core_comment_package = new Core_Comment_Package( $package_categories, $package_registrar );
$core_comment_package->boot();
$package_abilities = $package_registrar->all();
$migrated_read_ability_ids = array(
	'npcink-abilities-toolkit/site-info',
	'npcink-abilities-toolkit/list-post-types',
	'npcink-abilities-toolkit/list-taxonomies',
	'npcink-abilities-toolkit/count-posts',
	'npcink-abilities-toolkit/list-pages-tree',
	'npcink-abilities-toolkit/list-posts',
	'npcink-abilities-toolkit/get-post',
	'npcink-abilities-toolkit/resolve-url-to-post',
	'npcink-abilities-toolkit/get-post-blocks',
	'npcink-abilities-toolkit/list-post-revisions',
	'npcink-abilities-toolkit/list-media',
	'npcink-abilities-toolkit/resolve-media-attachment-by-url',
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
	'npcink-abilities-toolkit/compose-comment-mention-reply-result',
	'npcink-abilities-toolkit/build-comment-moderation-batch-suggest',
	'npcink-abilities-toolkit/compose-comment-moderation-batch-result',
	'npcink-abilities-toolkit/list-menus',
	'npcink-abilities-toolkit/get-menu',
	'npcink-abilities-toolkit/search-posts',
	'npcink-abilities-toolkit/resolve-internal-link-targets',
	'npcink-abilities-toolkit/build-inline-image-blocks',
	'npcink-abilities-toolkit/build-media-seo-assets',
	'npcink-abilities-toolkit/build-media-derivative-batch-plan',
	'npcink-abilities-toolkit/geo-analyze',
	'npcink-abilities-toolkit/optimize-media-metadata',
	'npcink-abilities-toolkit/position-inline-image-blocks',
	'npcink-abilities-toolkit/build-article-optimization-report',
	'npcink-abilities-toolkit/seo-report-context',
	'npcink-abilities-toolkit/read-post-optimization-context',
	'npcink-abilities-toolkit/build-article-single-optimization-suggest',
	'npcink-abilities-toolkit/build-article-optimization-apply-plan',
	'npcink-abilities-toolkit/build-content-metadata-apply-plan',
	'npcink-abilities-toolkit/build-article-audio-adoption-plan',
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
);
	$new_read_ability_ids = array(
		'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/get-post-context',
	'npcink-abilities-toolkit/get-content-publishing-checklist',
	'npcink-abilities-toolkit/get-content-inventory-health',
	'npcink-abilities-toolkit/get-nonproduction-content-inventory',
	'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
	'npcink-abilities-toolkit/build-content-inventory-fix-plan',
	'npcink-abilities-toolkit/search-post-meta',
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
		'npcink-abilities-toolkit/build-image-candidate-review-artifact',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-optimization-plan',
		'npcink-abilities-toolkit/build-media-rename-plan',
		'npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions',
		'npcink-abilities-toolkit/suggest-post-taxonomy-terms',
		'npcink-abilities-toolkit/propose-post-taxonomy-terms',
		'npcink-abilities-toolkit/get-page-structure-health',
		'npcink-abilities-toolkit/route-content-intent',
		'npcink-abilities-toolkit/build-pattern-page-plan',
		'npcink-abilities-toolkit/review-pattern-page',
		'npcink-abilities-toolkit/review-block-editor-surface',
	'npcink-abilities-toolkit/get-seo-geo-gap-report',
	'npcink-abilities-toolkit/get-site-style-baseline',
	'npcink-abilities-toolkit/build-article-workflow-context',
	'npcink-abilities-toolkit/get-publishing-calendar-context',
	'npcink-abilities-toolkit/get-media-inventory-health',
	'npcink-abilities-toolkit/inspect-media-asset',
	'npcink-abilities-toolkit/build-media-derivative-cloud-request',
	'npcink-abilities-toolkit/build-media-alt-apply-plan',
	'npcink-abilities-toolkit/get-post-seo-geo-readiness',
	'npcink-abilities-toolkit/get-site-topic-coverage-report',
	'npcink-abilities-toolkit/get-taxonomy-inventory-health',
	'npcink-abilities-toolkit/get-revision-change-risk-report',
);
$new_comment_ability_ids = array(
	'npcink-abilities-toolkit/get-comment-queue-health',
	'npcink-abilities-toolkit/get-comment-action-priority-queue',
	'npcink-abilities-toolkit/get-comment-compliance-handoff',
);
$migrated_write_ability_ids = array(
	'npcink-abilities-toolkit/create-draft',
	'npcink-abilities-toolkit/update-post',
	'npcink-abilities-toolkit/set-post-seo-meta',
	'npcink-abilities-toolkit/patch-post-content',
	'npcink-abilities-toolkit/patch-setting-value',
	'npcink-abilities-toolkit/update-post-blocks',
	'npcink-abilities-toolkit/set-post-slug',
	'npcink-abilities-toolkit/set-post-author',
	'npcink-abilities-toolkit/set-post-template',
	'npcink-abilities-toolkit/set-post-format',
	'npcink-abilities-toolkit/create-term',
	'npcink-abilities-toolkit/update-term',
	'npcink-abilities-toolkit/set-post-terms',
	'npcink-abilities-toolkit/update-media-details',
	'npcink-abilities-toolkit/upload-media-from-url',
	'npcink-abilities-toolkit/optimize-media-asset',
	'npcink-abilities-toolkit/replace-media-file',
	'npcink-abilities-toolkit/restore-media-backup',
	'npcink-abilities-toolkit/rename-media-file',
	'npcink-abilities-toolkit/set-post-featured-image',
	'npcink-abilities-toolkit/schedule-post',
	'npcink-abilities-toolkit/publish-post',
	'npcink-abilities-toolkit/restore-post',
	'npcink-abilities-toolkit/approve-comment',
	'npcink-abilities-toolkit/reply-comment',
	'npcink-abilities-toolkit/adopt-article-audio',
);
$priority_write_implementation_posture_ids = array(
	'npcink-abilities-toolkit/create-draft',
	'npcink-abilities-toolkit/update-post',
	'npcink-abilities-toolkit/update-post-blocks',
	'npcink-abilities-toolkit/set-post-terms',
	'npcink-abilities-toolkit/update-media-details',
);
$migrated_destructive_ability_ids = array(
	'npcink-abilities-toolkit/delete-term',
	'npcink-abilities-toolkit/merge-terms',
	'npcink-abilities-toolkit/bulk-update-post-terms',
	'npcink-abilities-toolkit/spam-comment',
	'npcink-abilities-toolkit/trash-comment',
	'npcink-abilities-toolkit/delete-media-permanently',
	'npcink-abilities-toolkit/trash-post',
	'npcink-abilities-toolkit/delete-post-permanently',
);
$core_governance_snapshot_path = __DIR__ . '/fixtures/core-governance-catalog-snapshot.json';
$core_governance_snapshot_json = file_get_contents( $core_governance_snapshot_path );
npcink_abilities_toolkit_assert_true( false !== $core_governance_snapshot_json, 'core governance catalog snapshot fixture is readable' );
$core_governance_expected_snapshot = json_decode( (string) $core_governance_snapshot_json, true );
npcink_abilities_toolkit_assert_true( is_array( $core_governance_expected_snapshot ), 'core governance catalog snapshot fixture decodes as an object' );
npcink_abilities_toolkit_assert_same(
	$core_governance_expected_snapshot,
	npcink_abilities_toolkit_core_governance_catalog_snapshot(
		$package_abilities,
		array_keys( (array) ( $core_governance_expected_snapshot['abilities'] ?? array() ) )
	),
	'core governance catalog snapshot matches normalized package definitions'
);
$core_snapshot_doc = file_get_contents( __DIR__ . '/../docs/core-governance-catalog-snapshot.md' );
npcink_abilities_toolkit_assert_true( is_string( $core_snapshot_doc ) && false !== strpos( $core_snapshot_doc, 'tests/fixtures/core-governance-catalog-snapshot.json' ), 'core governance catalog snapshot doc points to fixture' );
$permission_matrix_doc = file_get_contents( __DIR__ . '/../docs/permission-matrix.md' );
npcink_abilities_toolkit_assert_true( is_string( $permission_matrix_doc ) && false !== strpos( $permission_matrix_doc, 'Dry-run previews must still pass the same WordPress permission checks' ), 'permission matrix documents dry-run permission boundary' );
$schema_audit_doc = file_get_contents( __DIR__ . '/../docs/schema-boundary-audit.md' );
npcink_abilities_toolkit_assert_true( is_string( $schema_audit_doc ) && false !== strpos( $schema_audit_doc, 'REST ability details expose' ), 'schema boundary audit documents REST exposure verification' );
$smoke_wp = file_get_contents( __DIR__ . '/smoke-wp.php' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'register_shutdown_function' ), 'WordPress smoke runs fixture cleanup on shutdown' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'npcink_abilities_toolkit_smoke_register_post_fixture' ), 'WordPress smoke registers post fixtures for cleanup' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'npcink_abilities_toolkit_smoke_register_comment_fixture' ), 'WordPress smoke registers comment fixtures for cleanup' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'npcink_abilities_toolkit_smoke_register_attachment_fixture' ), 'WordPress smoke registers media fixtures for cleanup' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, '_npcink_abilities_toolkit_smoke_fixture_run_id' ), 'WordPress smoke tags media fixtures with a run id' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'npcink_abilities_toolkit_smoke_known_media_fixture_leak_ids' ), 'WordPress smoke detects reserved-prefix media leaks' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'npcink_abilities_toolkit_smoke_register_term_fixture' ), 'WordPress smoke registers taxonomy term fixtures for cleanup' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'wp_delete_post' ), 'WordPress smoke permanently deletes post fixtures' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'wp_delete_comment' ), 'WordPress smoke permanently deletes comment fixtures' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'wp_delete_attachment' ), 'WordPress smoke permanently deletes media fixtures' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'wp_delete_term' ), 'WordPress smoke deletes taxonomy term fixtures' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'Smoke media fixture is deleted after smoke.' ), 'WordPress smoke asserts media fixtures are gone at the end' );
npcink_abilities_toolkit_assert_true( is_string( $smoke_wp ) && false !== strpos( $smoke_wp, 'Smoke leaves no registered or reserved-prefix media fixtures behind.' ), 'WordPress smoke asserts no reserved-prefix media fixtures remain at the end' );
$core_consumer_example = file_get_contents( __DIR__ . '/../examples/core-governance-consumer.php' );
npcink_abilities_toolkit_assert_true( is_string( $core_consumer_example ) && false !== strpos( $core_consumer_example, 'npcink_abilities_toolkit_get_registered' ), 'core governance consumer example uses ability discovery' );
npcink_abilities_toolkit_assert_true( is_string( $core_consumer_example ) && false !== strpos( $core_consumer_example, "'ability_id' => \$ability_id" ), 'core governance consumer example prepares a real ability proposal payload' );
npcink_abilities_toolkit_assert_true( isset( $package_categories->all()['npcink-abilities-toolkit-data'] ), 'core read package registers the legacy npcink-abilities-toolkit-data category for compatibility' );
npcink_abilities_toolkit_assert_true( isset( $package_categories->all()['npcink-abilities-toolkit-pages'] ), 'core read package registers the legacy npcink-abilities-toolkit-pages category for compatibility' );
npcink_abilities_toolkit_assert_true( isset( $package_categories->all()['npcink-abilities-toolkit-comments'] ), 'core comment package registers the standalone comments category' );
npcink_abilities_toolkit_assert_true( isset( $package_categories->all()['npcink-abilities-toolkit-write'] ), 'core write package registers the legacy npcink-abilities-toolkit-write category for compatibility' );
npcink_abilities_toolkit_assert_true( isset( $package_categories->all()['npcink-abilities-toolkit-diagnostics'] ), 'core read package registers the standalone diagnostics category' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/wp-diagnostics-summary'] ), 'core read package owns standalone wp-diagnostics-summary ability' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-diagnostics', $package_abilities['npcink-abilities-toolkit/wp-diagnostics-summary']['category'], 'wp-diagnostics-summary uses standalone diagnostics category' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-diagnostics-summary']['input_schema']['properties']['include_current_user']['default'] ?? null, 'wp-diagnostics-summary omits current user details by default' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail'] ), 'core read package owns standalone wp-ops-diagnostics-detail ability' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-diagnostics', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['category'], 'wp-ops-diagnostics-detail uses standalone diagnostics category' );
npcink_abilities_toolkit_assert_true( false !== strpos( $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['description'] ?? '', 'plugin' ), 'ops diagnostics description mentions plugin details' );
npcink_abilities_toolkit_assert_same( 'summary', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['profile']['default'] ?? null, 'ops diagnostics defaults to the summary profile' );
npcink_abilities_toolkit_assert_same( array( 'summary', 'detail', 'forensics' ), $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['profile']['enum'] ?? array(), 'ops diagnostics exposes bounded diagnostic profiles' );
npcink_abilities_toolkit_assert_same( 50, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['max_cron_events']['maximum'] ?? null, 'ops diagnostics bounds returned cron events' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_log_contents']['default'] ?? null, 'ops diagnostics does not include log contents by default' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_current_user']['default'] ?? null, 'ops diagnostics omits current user details by default' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_database']['default'] ?? null, 'ops diagnostics omits database table status by default' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_log_tail'] ), 'ops diagnostics uses one log contents control' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_inactive_plugins']['default'] ?? null, 'ops diagnostics omits inactive plugin rows by default' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_plugin_updates']['default'] ?? null, 'ops diagnostics omits plugin update rows by default' );
npcink_abilities_toolkit_assert_same( 500, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['max_plugins_per_group']['maximum'] ?? null, 'ops diagnostics bounds plugin rows per group' );
npcink_abilities_toolkit_assert_same( 200, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['tail_lines']['maximum'] ?? null, 'ops diagnostics bounds returned log tail lines' );
npcink_abilities_toolkit_assert_same( 10080, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['since_minutes']['maximum'] ?? null, 'ops diagnostics bounds log since window' );
npcink_abilities_toolkit_assert_true( in_array( 'warning', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['severity']['items']['enum'] ?? array(), true ), 'ops diagnostics supports log severity filtering' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['input_schema']['properties']['include_integrations']['default'] ?? null, 'ops diagnostics omits integration diagnostics by default' );
npcink_abilities_toolkit_assert_true( in_array( 'plugins', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['output_schema']['required'] ?? array(), true ), 'ops diagnostics output requires plugins section' );
npcink_abilities_toolkit_assert_true( in_array( 'profile', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['output_schema']['required'] ?? array(), true ), 'ops diagnostics output requires profile section' );
npcink_abilities_toolkit_assert_true( in_array( 'current_user', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['output_schema']['required'] ?? array(), true ), 'ops diagnostics output requires current user section' );
npcink_abilities_toolkit_assert_true( in_array( 'integrations', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['output_schema']['required'] ?? array(), true ), 'ops diagnostics output requires integrations section' );
npcink_abilities_toolkit_assert_true( in_array( 'seo_summary', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['output_schema']['required'] ?? array(), true ), 'ops diagnostics output requires SEO summary section' );
$parse_log_entry = new ReflectionMethod( $core_read_package, 'parse_diagnostics_log_entry' );
$parse_log_entry->setAccessible( true );
$summarize_log_sources = new ReflectionMethod( $core_read_package, 'summarize_diagnostics_log_sources' );
$summarize_log_sources->setAccessible( true );
$summarize_top_messages = new ReflectionMethod( $core_read_package, 'summarize_diagnostics_top_messages' );
$summarize_top_messages->setAccessible( true );
$plugin_log_entry = $parse_log_entry->invoke( $core_read_package, '[30-May-2026 10:39:34 UTC] PHP Deprecated: Test in /srv/app/public/wp-content/plugins/plugin-check/check.php on line 10' );
$theme_log_entry = $parse_log_entry->invoke( $core_read_package, '[30-May-2026 10:40:34 UTC] PHP Warning: Test in /srv/app/public/wp-content/themes/twentytwentyfour/functions.php on line 20' );
$phar_log_entry = $parse_log_entry->invoke( $core_read_package, '[30-May-2026 10:41:34 UTC] PHP Deprecated: Using null as an array offset is deprecated in phar:///tmp/wp-cli.phar/vendor/file.php on line 30' );
$home_path_log_entry = $parse_log_entry->invoke( $core_read_package, '[30-May-2026 10:42:34 UTC] PHP Warning: mysqli_real_connect(): (HY000/2002): No such file or directory in /Users/muze/Local Sites/npcink-abilities-toolkit/app/public/wp-includes/class-wpdb.php on line 1990' );
npcink_abilities_toolkit_assert_same( 'plugin', $plugin_log_entry['source_type'] ?? '', 'ops diagnostics detects plugin log source type' );
npcink_abilities_toolkit_assert_same( 'plugin-check', $plugin_log_entry['source_hint'] ?? '', 'ops diagnostics detects plugin log source hint' );
npcink_abilities_toolkit_assert_same( 'Test', $plugin_log_entry['message_fingerprint'] ?? '', 'ops diagnostics fingerprints plugin log messages without path noise' );
npcink_abilities_toolkit_assert_same( 'theme', $theme_log_entry['source_type'] ?? '', 'ops diagnostics detects theme log source type' );
npcink_abilities_toolkit_assert_same( 'twentytwentyfour', $theme_log_entry['source_hint'] ?? '', 'ops diagnostics detects theme log source hint' );
npcink_abilities_toolkit_assert_same( 'phar', $phar_log_entry['source_type'] ?? '', 'ops diagnostics detects phar log source type' );
npcink_abilities_toolkit_assert_same( 'wp-cli', $phar_log_entry['source_hint'] ?? '', 'ops diagnostics detects wp-cli log source hint' );
npcink_abilities_toolkit_assert_same( 'wp-cli.phar', $phar_log_entry['source_basename'] ?? '', 'ops diagnostics exposes safe phar basename hint' );
npcink_abilities_toolkit_assert_same( 'wp-cli.phar', $phar_log_entry['phar_hint'] ?? '', 'ops diagnostics exposes safe phar hint' );
npcink_abilities_toolkit_assert_same( 'Using null as an array offset is deprecated', $phar_log_entry['message_fingerprint'] ?? '', 'ops diagnostics fingerprints phar messages without path noise' );
npcink_abilities_toolkit_assert_same( 'mysqli_real_connect(): (HY000/N): No such file or directory', $home_path_log_entry['message_fingerprint'] ?? '', 'ops diagnostics fingerprints home path messages without path noise' );
$log_source_summary = $summarize_log_sources->invoke( $core_read_package, array( $plugin_log_entry, $plugin_log_entry, $theme_log_entry, $phar_log_entry ) );
npcink_abilities_toolkit_assert_same( 'plugin', $log_source_summary[0]['source_type'] ?? '', 'ops diagnostics source summary sorts most frequent source first' );
npcink_abilities_toolkit_assert_same( 'plugin-check', $log_source_summary[0]['source_hint'] ?? '', 'ops diagnostics source summary groups by source hint' );
npcink_abilities_toolkit_assert_same( 'deprecated', $log_source_summary[0]['severity'] ?? '', 'ops diagnostics source summary groups by severity' );
npcink_abilities_toolkit_assert_same( 'Test', $log_source_summary[0]['message_fingerprint'] ?? '', 'ops diagnostics source summary includes message fingerprints' );
npcink_abilities_toolkit_assert_same( 2, $log_source_summary[0]['count'] ?? 0, 'ops diagnostics source summary counts repeated source entries' );
$log_top_messages = $summarize_top_messages->invoke( $core_read_package, array( $phar_log_entry, $phar_log_entry, $plugin_log_entry ) );
npcink_abilities_toolkit_assert_same( 'Using null as an array offset is deprecated', $log_top_messages[0]['fingerprint'] ?? '', 'ops diagnostics top messages sort repeated fingerprints first' );
npcink_abilities_toolkit_assert_same( 'wp-cli.phar', $log_top_messages[0]['phar_hint'] ?? '', 'ops diagnostics top messages include safe phar hint' );
npcink_abilities_toolkit_assert_same( 2, $log_top_messages[0]['count'] ?? 0, 'ops diagnostics top messages count repeated fingerprints' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/list-posts']['input_schema']['properties']['modified_after'] ), 'list-posts supports modified date filtering' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/list-posts']['input_schema']['properties']['taxonomy'] ), 'list-posts supports taxonomy filtering' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['input_schema']['properties']['post_types'] ), 'search-posts supports multiple post types' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['input_schema']['properties']['statuses'] ), 'search-posts supports multiple statuses' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['input_schema']['properties']['taxonomy'] ), 'search-posts supports taxonomy filtering' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['input_schema']['properties']['modified_after'] ), 'search-posts supports modified date filtering' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['output_schema']['properties']['filters'] ), 'search-posts returns applied filters' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-posts']['output_schema']['properties']['items']['items']['properties']['matched_fields'] ), 'search-posts returns matched field hints' );
npcink_abilities_toolkit_assert_same( array( 'search', 'meta_keys' ), $package_abilities['npcink-abilities-toolkit/search-post-meta']['input_schema']['required'] ?? array(), 'search-post-meta requires search and explicit meta keys' );
npcink_abilities_toolkit_assert_same( array( 'post_id', 'meta_key' ), $package_abilities['npcink-abilities-toolkit/get-post-meta']['input_schema']['required'] ?? array(), 'get-post-meta requires one explicit safe meta key' );
npcink_abilities_toolkit_assert_same( 10, $package_abilities['npcink-abilities-toolkit/search-post-meta']['input_schema']['properties']['meta_keys']['maxItems'] ?? null, 'search-post-meta bounds meta key count' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/search-post-meta']['input_schema']['additionalProperties'] ?? null, 'search-post-meta rejects undeclared inputs' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/search-post-meta']['output_schema']['properties']['items']['items']['properties']['matched_meta_keys'] ), 'search-post-meta returns matched meta keys' );
npcink_abilities_toolkit_assert_true( in_array( 'tree', $package_abilities['npcink-abilities-toolkit/get-menu']['output_schema']['required'] ?? array(), true ), 'get-menu returns a menu tree' );
foreach ( $migrated_read_ability_ids as $migrated_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $migrated_ability_id ] ), "core read package owns migrated {$migrated_ability_id} ability" );
	npcink_abilities_toolkit_assert_package_read_ability_contract( $migrated_ability_id, $package_abilities[ $migrated_ability_id ] );
}
foreach ( $new_read_ability_ids as $new_read_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $new_read_ability_id ] ), "core read package owns new {$new_read_ability_id} ability" );
	npcink_abilities_toolkit_assert_package_read_ability_contract( $new_read_ability_id, $package_abilities[ $new_read_ability_id ] );
}
foreach ( $new_comment_ability_ids as $new_comment_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $new_comment_ability_id ] ), "core comment package owns new {$new_comment_ability_id} ability" );
	npcink_abilities_toolkit_assert_package_read_ability_contract( $new_comment_ability_id, $package_abilities[ $new_comment_ability_id ] );
}
npcink_abilities_toolkit_assert_same( true, $package_abilities['npcink-abilities-toolkit/site-info']['project_to_npcink_catalog'], 'migrated core read abilities project into Npcink AI catalog' );
npcink_abilities_toolkit_assert_same( true, $package_abilities['npcink-abilities-toolkit/get-post-context']['project_to_npcink_catalog'], 'new official post context ability projects into Npcink AI catalog' );
npcink_abilities_toolkit_assert_same( true, $package_abilities['npcink-abilities-toolkit/get-post-context']['input_schema']['properties']['include_blocks']['default'] ?? null, 'get-post-context includes blocks by default' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/get-content-publishing-checklist']['requires_confirm'], 'publishing checklist remains readonly' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-content-inventory-health']['input_schema']['properties']['per_page']['maximum'] ?? null, 'inventory health scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-nonproduction-content-inventory']['input_schema']['properties']['per_page']['maximum'] ?? null, 'nonproduction content inventory scan is bounded to 100 items per section' );
npcink_abilities_toolkit_assert_same( 200, $package_abilities['npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan']['input_schema']['properties']['max_actions']['maximum'] ?? null, 'nonproduction content cleanup plan bounds planned actions to Adapter batch execution limit' );
npcink_abilities_toolkit_assert_same( true, $package_abilities['npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan']['input_schema']['properties']['include_posts']['default'] ?? null, 'nonproduction content cleanup plan exposes include_posts control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan']['input_schema']['properties']['mode'] ), 'nonproduction content cleanup plan does not expose unused mode input' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/build-content-inventory-fix-plan']['input_schema']['properties']['max_actions']['maximum'] ?? null, 'content inventory fix plan bounds planned actions' );
npcink_abilities_toolkit_assert_same( array( 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-content-inventory-fix-plan']['required_scopes'] ?? array(), 'content inventory fix plan remains a read-scope planning ability' );
foreach ( array( 'npcink-abilities-toolkit/get-nonproduction-content-inventory', 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan', 'npcink-abilities-toolkit/build-content-inventory-fix-plan' ) as $planning_agent_usage_id ) {
	npcink_abilities_toolkit_assert_true( ! empty( $package_abilities[ $planning_agent_usage_id ]['agent_usage']['when_to_use'] ), "{$planning_agent_usage_id} exposes agent usage guidance" );
	npcink_abilities_toolkit_assert_true( ! empty( $package_abilities[ $planning_agent_usage_id ]['agent_usage']['stopping_points'] ), "{$planning_agent_usage_id} exposes agent stopping points" );
}
npcink_abilities_toolkit_assert_same( 50, $package_abilities['npcink-abilities-toolkit/get-bulk-publishing-checklist']['input_schema']['properties']['post_ids']['maxItems'] ?? null, 'bulk publishing checklist is bounded to 50 posts' );
npcink_abilities_toolkit_assert_same( 10, $package_abilities['npcink-abilities-toolkit/get-internal-link-opportunity-report']['input_schema']['properties']['max_targets']['maximum'] ?? null, 'internal link opportunity report bounds target count' );
npcink_abilities_toolkit_assert_same( 8, $package_abilities['npcink-abilities-toolkit/resolve-internal-link-targets']['input_schema']['properties']['candidate_limit']['maximum'] ?? null, 'internal link target resolver bounds editor candidate count' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/resolve-internal-link-targets']['input_schema']['properties']['related_content_evidence'] ), 'internal link target resolver accepts supplied related-content evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/resolve-internal-link-targets']['output_schema']['properties']['data']['properties']['internal_link_candidates'] ), 'internal link target resolver declares reusable editor candidate artifact output' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-site-operations-dashboard']['input_schema']['properties']['per_page']['maximum'] ?? null, 'site operations dashboard is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( array( 'post_id' ), $package_abilities['npcink-abilities-toolkit/get-post-publish-risk-report']['input_schema']['required'] ?? array(), 'post publish risk report requires post_id' );
npcink_abilities_toolkit_assert_same( array( 'post_id' ), $package_abilities['npcink-abilities-toolkit/get-article-publish-preflight-context']['input_schema']['required'] ?? array(), 'article publish preflight context requires post_id' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-content-refresh-opportunities']['input_schema']['properties']['per_page']['maximum'] ?? null, 'content refresh opportunities scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-old-article-refresh-context']['input_schema']['properties']['per_page']['maximum'] ?? null, 'old article refresh context scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-internal-link-graph-health']['input_schema']['properties']['per_page']['maximum'] ?? null, 'internal link graph health scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-media-cleanup-opportunities']['input_schema']['properties']['per_page']['maximum'] ?? null, 'media cleanup opportunities scan is bounded to 100 assets per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['input_schema']['properties']['max_actions']['maximum'] ?? null, 'media inventory fix plan bounds planned actions' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['input_schema']['properties']['include_trash_parent_media']['default'] ?? null, 'media inventory fix plan keeps trash-parent delete opt-in disabled by default' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['input_schema']['properties']['include_unattached_nonproduction_media']['default'] ?? null, 'media inventory fix plan keeps parentless nonproduction-media delete opt-in disabled by default' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['required_scopes'] ?? array(), 'media inventory fix plan remains a read-scope planning ability' );
npcink_abilities_toolkit_assert_same( 'media_governance', $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'media adoption enhancement plan is classified as media governance' );
npcink_abilities_toolkit_assert_true( ! empty( $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['agent_usage']['when_to_use'] ), 'media inventory fix plan exposes agent usage guidance' );
npcink_abilities_toolkit_assert_true( ! empty( $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['agent_usage']['stopping_points'] ), 'media inventory fix plan exposes agent stopping points' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan'] ), 'build-media-optimization-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan']['required_scopes'] ?? array(), 'media optimization plan remains a read-scope planning ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'media_details_input', 'derivative_artifact' ), $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan']['input_schema']['required'] ?? array(), 'media optimization plan requires metadata and artifact evidence' );
$media_derivative_artifact_schema = $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan']['input_schema']['properties']['derivative_artifact'] ?? array();
npcink_abilities_toolkit_assert_same( array( 'artifact_id', 'expires_at', 'mime_type', 'format', 'width', 'height', 'filesize_bytes', 'sha256', 'suggested_filename', 'filename_basis', 'processing_warnings', 'transform_facts' ), $media_derivative_artifact_schema['required'] ?? array(), 'media optimization artifacts require the exact v3 local proposal descriptor' );
npcink_abilities_toolkit_assert_same( false, $media_derivative_artifact_schema['additionalProperties'] ?? null, 'media optimization artifacts reject undeclared descriptor fields at the public ability boundary' );
npcink_abilities_toolkit_assert_same( array( 'owner', 'strategy', 'final_sanitize_unique_required' ), $media_derivative_artifact_schema['properties']['filename_basis']['required'] ?? array(), 'media optimization artifact schemas require the complete nested WordPress filename authority basis' );
npcink_abilities_toolkit_assert_same( 26214400, $media_derivative_artifact_schema['properties']['filesize_bytes']['maximum'] ?? 0, 'media optimization artifact evidence stays within the shared 25 MiB local limit' );
npcink_abilities_toolkit_assert_same( 8192, $media_derivative_artifact_schema['properties']['width']['maximum'] ?? 0, 'media optimization artifact schemas match the Cloud 8192-pixel axis limit' );
$transform_facts_schema = $media_derivative_artifact_schema['properties']['transform_facts'] ?? array();
npcink_abilities_toolkit_assert_same( array_keys( npcink_abilities_toolkit_cloud_artifact_fixture()['transform_facts'] ), $transform_facts_schema['required'] ?? array(), 'Cloud v3 transformation facts require the complete ordered fact set' );
npcink_abilities_toolkit_assert_same( 'string', $transform_facts_schema['properties']['source_checksum']['type'] ?? '', 'Cloud v2 source checksums remain scalar strings in the WordPress ability schema' );
npcink_abilities_toolkit_assert_same( 'integer', $transform_facts_schema['properties']['source_width']['type'] ?? '', 'Cloud v2 dimensions remain scalar integers in the WordPress ability schema' );
npcink_abilities_toolkit_assert_same( 'boolean', $transform_facts_schema['properties']['decodable']['type'] ?? '', 'Cloud v2 decode evidence remains a scalar boolean in the WordPress ability schema' );
npcink_abilities_toolkit_assert_same( false, $transform_facts_schema['additionalProperties'] ?? null, 'Cloud v2 transformation facts reject undeclared fields' );
$cloud_adoption_output_schema = $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['output_schema']['properties'] ?? array();
npcink_abilities_toolkit_assert_same( array( true ), $cloud_adoption_output_schema['transfer_evidence']['properties']['image_decoded']['enum'] ?? array(), 'verified transfer schemas expose image_decoded as true-only evidence using the supported enum keyword' );
npcink_abilities_toolkit_assert_same( array( true ), $cloud_adoption_output_schema['delivery_ack']['properties']['checksum_verified']['enum'] ?? array(), 'delivery ACK schemas expose checksum_verified as true-only evidence using the supported enum keyword' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan']['input_schema']['properties']['file_name'] ), 'media optimization plan accepts a reviewed custom derivative file name' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary'] ), 'build-media-adoption-preflight-summary is registered as a read-only summary ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read', 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary']['required_scopes'] ?? array(), 'media adoption preflight summary reads media and bounded post references' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id' ), $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary']['input_schema']['required'] ?? array(), 'media adoption preflight summary only requires an attachment id' );
npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary']['input_schema']['properties']['include_settings_scan']['default'] ?? null, 'media adoption preflight summary keeps settings scans disabled by default' );
npcink_abilities_toolkit_assert_same( 'media_governance', $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'media adoption preflight summary is classified as media governance' );
npcink_abilities_toolkit_assert_true( ! empty( $package_abilities['npcink-abilities-toolkit/build-media-adoption-preflight-summary']['agent_usage']['stopping_points'] ), 'media adoption preflight summary exposes agent stopping points' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-rename-plan'] ), 'build-media-rename-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read', 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-media-rename-plan']['required_scopes'] ?? array(), 'media rename plan reads media and post references' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'target_file_name' ), $package_abilities['npcink-abilities-toolkit/build-media-rename-plan']['input_schema']['required'] ?? array(), 'media rename plan requires attachment and target filename' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan'] ), 'build-pattern-page-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/route-content-intent'] ), 'route-content-intent is registered as a read-only intent routing ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite'] ), 'evaluate-gutenberg-recipe-suite is registered as a read-only recipe evaluation ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/get-gutenberg-block-capability-catalog'] ), 'get-gutenberg-block-capability-catalog is registered as a read-only composition contract ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan'] ), 'compose-gutenberg-block-plan is registered as a read-only composer repair ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/inspect-gutenberg-composition-contract'] ), 'inspect-gutenberg-composition-contract is registered as a read-only composition contract inspection ability' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/inspect-block-theme-surface'] ), 'inspect-block-theme-surface is registered as a read-only block theme inspection ability' );
npcink_abilities_toolkit_assert_same( array( 'post.read' ), $package_abilities['npcink-abilities-toolkit/route-content-intent']['required_scopes'] ?? array(), 'content intent router remains a read-scope ability' );
npcink_abilities_toolkit_assert_same( array( 'prompt' ), $package_abilities['npcink-abilities-toolkit/route-content-intent']['input_schema']['required'] ?? array(), 'content intent router only requires the natural-language prompt' );
npcink_abilities_toolkit_assert_same( array( 'auto', 'page', 'post', 'site_template', 'template_part', 'unsupported' ), $package_abilities['npcink-abilities-toolkit/route-content-intent']['input_schema']['properties']['target_hint']['enum'] ?? array(), 'content intent router exposes bounded target hints' );
npcink_abilities_toolkit_assert_same( array( 'post.read', 'site.read' ), $package_abilities['npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite']['required_scopes'] ?? array(), 'Gutenberg recipe evaluation advertises post and Site Editor read scopes' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite']['input_schema']['properties']['cases'] ), 'Gutenberg recipe evaluation accepts explicit test cases' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite']['input_schema']['properties']['media_fixture'] ), 'Gutenberg recipe evaluation accepts a reviewed media fixture' );
npcink_abilities_toolkit_assert_same( array( 'post.read', 'site.read' ), $package_abilities['npcink-abilities-toolkit/get-gutenberg-block-capability-catalog']['required_scopes'] ?? array(), 'Gutenberg block capability catalog advertises post and Site Editor read scopes' );
npcink_abilities_toolkit_assert_same( array( 'all', 'page', 'post', 'template' ), $package_abilities['npcink-abilities-toolkit/get-gutenberg-block-capability-catalog']['input_schema']['properties']['surface']['enum'] ?? array(), 'Gutenberg block capability catalog exposes bounded surfaces' );
npcink_abilities_toolkit_assert_same( array( 'post.read', 'site.read' ), $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['required_scopes'] ?? array(), 'Gutenberg composer repair loop advertises post and Site Editor read scopes' );
npcink_abilities_toolkit_assert_same( array( 'prompt' ), $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['input_schema']['required'] ?? array(), 'Gutenberg composer repair loop only requires a natural-language prompt' );
npcink_abilities_toolkit_assert_same( array( 'auto', 'saas_landing', 'editorial_article', 'comparison_review', 'product_docs', 'block_theme_template' ), $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['input_schema']['properties']['composer_profile_id']['enum'] ?? array(), 'Gutenberg composer repair loop exposes bounded composer profiles' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['input_schema']['properties']['plan_input'] ), 'Gutenberg composer repair loop accepts bounded planner input overrides' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['input_schema']['properties']['repair_once'] ), 'Gutenberg composer repair loop exposes one-pass repair control' );
npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/compose-gutenberg-block-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'Gutenberg composer repair loop is classified as page governance' );
npcink_abilities_toolkit_assert_same( array( 'post.read', 'site.read' ), $package_abilities['npcink-abilities-toolkit/inspect-gutenberg-composition-contract']['required_scopes'] ?? array(), 'Gutenberg composition contract inspection advertises post and Site Editor read scopes' );
npcink_abilities_toolkit_assert_same( array( 'post_content', 'site_editor_template', 'site_editor_template_part', 'blocks_input' ), $package_abilities['npcink-abilities-toolkit/inspect-gutenberg-composition-contract']['input_schema']['properties']['surface_kind']['enum'] ?? array(), 'Gutenberg composition contract inspection exposes bounded surface kinds' );
npcink_abilities_toolkit_assert_same( array( 'none', 'breadcrumbs' ), $package_abilities['npcink-abilities-toolkit/inspect-gutenberg-composition-contract']['input_schema']['properties']['placement_check']['enum'] ?? array(), 'Gutenberg composition contract inspection exposes bounded placement checks' );
npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/inspect-gutenberg-composition-contract']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'Gutenberg composition contract inspection is classified as page governance' );
$gutenberg_block_catalog = $core_read_package->get_gutenberg_block_capability_catalog( array( 'surface' => 'all' ) );
npcink_abilities_toolkit_assert_same( true, $gutenberg_block_catalog['success'] ?? null, 'get-gutenberg-block-capability-catalog returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'gutenberg_block_capability_catalog', $gutenberg_block_catalog['data']['artifact_type'] ?? '', 'Gutenberg block capability catalog declares its artifact type' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $gutenberg_block_catalog['data']['catalog_id'] ?? '', 'Gutenberg block capability catalog exposes the native catalog id' );
npcink_abilities_toolkit_assert_same( 'bounded_block_composition', $gutenberg_block_catalog['data']['composition_model'] ?? '', 'Gutenberg block capability catalog describes bounded block composition' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_block_catalog['data']['direct_wordpress_write'] ?? null, 'Gutenberg block capability catalog never writes WordPress' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_block_catalog['data']['commit_execution'] ?? null, 'Gutenberg block capability catalog never commits execution' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_block_catalog['data']['core_html_allowed'] ?? true, 'Gutenberg block capability catalog forbids core/html' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_block_catalog['data']['non_core_blocks_allowed'] ?? true, 'Gutenberg block capability catalog forbids non-core blocks' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_block_catalog['data']['custom_css_allowed'] ?? true, 'Gutenberg block capability catalog forbids custom CSS as a base composition mechanism' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_block_composer_v1', $gutenberg_block_catalog['data']['composer_instruction']['instruction_id'] ?? '', 'Gutenberg block capability catalog exposes stable AI composer instructions' );
npcink_abilities_toolkit_assert_same( 'gutenberg_composer_profiles_v1', $gutenberg_block_catalog['data']['composer_profile_catalog_id'] ?? '', 'Gutenberg block capability catalog exposes the composer profile catalog id' );
npcink_abilities_toolkit_assert_true( isset( $gutenberg_block_catalog['data']['composer_profiles']['saas_landing'] ), 'Gutenberg block capability catalog includes the SaaS landing composer profile' );
npcink_abilities_toolkit_assert_true( isset( $gutenberg_block_catalog['data']['composer_profiles']['editorial_article'] ), 'Gutenberg block capability catalog includes the editorial article composer profile' );
npcink_abilities_toolkit_assert_true( isset( $gutenberg_block_catalog['data']['composer_profiles']['comparison_review'] ), 'Gutenberg block capability catalog includes the comparison review composer profile' );
npcink_abilities_toolkit_assert_true( isset( $gutenberg_block_catalog['data']['composer_profiles']['product_docs'] ), 'Gutenberg block capability catalog includes the product docs composer profile' );
npcink_abilities_toolkit_assert_true( isset( $gutenberg_block_catalog['data']['composer_profiles']['block_theme_template'] ), 'Gutenberg block capability catalog includes the block theme template composer profile' );
npcink_abilities_toolkit_assert_same( 'high', $gutenberg_block_catalog['data']['composer_profiles']['saas_landing']['quality_targets']['section_variance'] ?? '', 'SaaS landing profile encodes high section variance' );
npcink_abilities_toolkit_assert_true( in_array( 'core/html', $gutenberg_block_catalog['data']['composer_profiles']['saas_landing']['forbidden_outputs'] ?? array(), true ), 'SaaS landing profile forbids core/html output' );
npcink_abilities_toolkit_assert_true( in_array( 'core/html', $gutenberg_block_catalog['data']['composer_instruction']['ai_must_not_choose'] ?? array(), true ), 'Gutenberg block composer instructions forbid raw HTML blocks' );
npcink_abilities_toolkit_assert_same( 'inspect_catalog', $gutenberg_block_catalog['data']['recommended_composer_flow'][0]['step'] ?? '', 'Gutenberg block capability catalog tells composers to inspect the catalog first' );
npcink_abilities_toolkit_assert_same( 'bounded_template_anchor_placement', $gutenberg_block_catalog['data']['template_placement_standards']['breadcrumbs']['placement_model'] ?? '', 'Gutenberg block capability catalog exposes bounded template placement standards' );
npcink_abilities_toolkit_assert_true( in_array( 'core/post-title', $gutenberg_block_catalog['data']['template_placement_standards']['breadcrumbs']['preferred_anchor_blocks'] ?? array(), true ), 'Gutenberg block capability catalog allows post title anchors for breadcrumbs' );
npcink_abilities_toolkit_assert_true( in_array( 'core/query-title', $gutenberg_block_catalog['data']['template_placement_standards']['breadcrumbs']['preferred_anchor_blocks'] ?? array(), true ), 'Gutenberg block capability catalog allows query title anchors for breadcrumbs' );
npcink_abilities_toolkit_assert_true( in_array( 'core/group', $gutenberg_block_catalog['data']['allowed_block_names'] ?? array(), true ), 'Gutenberg block capability catalog allows core/group composition' );
npcink_abilities_toolkit_assert_true( in_array( 'core/image', $gutenberg_block_catalog['data']['allowed_block_names'] ?? array(), true ), 'Gutenberg block capability catalog allows core/image composition' );
npcink_abilities_toolkit_assert_true( in_array( 'core/media-text', $gutenberg_block_catalog['data']['allowed_block_names'] ?? array(), true ), 'Gutenberg block capability catalog allows core/media-text composition' );
npcink_abilities_toolkit_assert_true( in_array( 'core/html', $gutenberg_block_catalog['data']['forbidden_block_names'] ?? array(), true ), 'Gutenberg block capability catalog explicitly forbids core/html' );
npcink_abilities_toolkit_assert_same( true, $gutenberg_block_catalog['data']['responsive_rules']['columns_must_stack_on_mobile'] ?? null, 'Gutenberg block capability catalog requires mobile-stacked columns' );
npcink_abilities_toolkit_assert_same( 'fail_closed', $gutenberg_block_catalog['data']['repair_policy']['non_core_block'] ?? '', 'Gutenberg block capability catalog fails closed on non-core blocks' );
$valid_template_contract_inspection = $core_read_package->inspect_gutenberg_composition_contract(
	array(
		'surface_kind'    => 'site_editor_template',
		'post_type'       => 'wp_template',
		'slug'            => 'single',
		'placement_check' => 'breadcrumbs',
		'blocks'          => array(
			array(
				'blockName'   => 'core/template-part',
				'attrs'       => array( 'slug' => 'header' ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array( 'tagName' => 'main' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/group',
						'attrs'       => array( 'className' => 'openclaw-breadcrumbs' ),
						'innerBlocks' => array(
							array(
								'blockName'    => 'core/paragraph',
								'attrs'        => array(),
								'innerHTML'    => '<p><a href="/">Home</a> / Current item</p>',
								'innerContent' => array( '<p><a href="/">Home</a> / Current item</p>' ),
								'innerBlocks'  => array(),
							),
						),
					),
					array(
						'blockName'   => 'core/post-title',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/post-content',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				),
			),
			array(
				'blockName'   => 'core/template-part',
				'attrs'       => array( 'slug' => 'footer' ),
				'innerBlocks' => array(),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $valid_template_contract_inspection['success'] ?? null, 'inspect-gutenberg-composition-contract returns a success envelope for proposed template blocks' );
npcink_abilities_toolkit_assert_same( 'gutenberg_composition_contract_inspection', $valid_template_contract_inspection['data']['artifact_type'] ?? '', 'inspect-gutenberg-composition-contract declares its artifact type' );
npcink_abilities_toolkit_assert_same( 'site_editor_template', $valid_template_contract_inspection['data']['block_editor_surface']['surface_kind'] ?? '', 'composition contract inspection identifies Site Editor template surfaces' );
npcink_abilities_toolkit_assert_same( 'pass', $valid_template_contract_inspection['data']['contract_status'] ?? '', 'composition contract inspection passes valid template blocks' );
npcink_abilities_toolkit_assert_same( 'pass', $valid_template_contract_inspection['data']['composition_contract']['contract_status'] ?? '', 'composition contract inspection passes the block composition contract' );
npcink_abilities_toolkit_assert_same( 'pass', $valid_template_contract_inspection['data']['template_placement_contract']['contract_status'] ?? '', 'composition contract inspection passes valid breadcrumb placement' );
npcink_abilities_toolkit_assert_same( 'core/post-title', $valid_template_contract_inspection['data']['template_placement_contract']['placements'][0]['inserted_before'] ?? '', 'composition contract inspection records the matched title anchor' );
npcink_abilities_toolkit_assert_same( false, $valid_template_contract_inspection['data']['direct_wordpress_write'] ?? null, 'composition contract inspection never writes WordPress' );
npcink_abilities_toolkit_assert_same( false, $valid_template_contract_inspection['data']['commit_execution'] ?? null, 'composition contract inspection never commits execution' );
npcink_abilities_toolkit_assert_true( in_array( 'no_changes_required', $valid_template_contract_inspection['data']['recommended_next_actions'] ?? array(), true ), 'composition contract inspection reports no change when contracts pass' );
$article_title_stack_contract_inspection = $core_read_package->inspect_gutenberg_composition_contract(
	array(
		'surface_kind'    => 'site_editor_template',
		'post_type'       => 'wp_template',
		'slug'            => 'single',
		'placement_check' => 'breadcrumbs',
		'blocks'          => array(
			array(
				'blockName'   => 'core/template-part',
				'attrs'       => array( 'slug' => 'header' ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array( 'tagName' => 'main' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/group',
						'attrs'       => array( 'className' => 'openclaw-breadcrumbs' ),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/group',
						'attrs'       => array( 'className' => 'openclaw-template-title-stack' ),
						'innerBlocks' => array(
							array(
								'blockName'   => 'core/post-title',
								'attrs'       => array(),
								'innerBlocks' => array(),
							),
						),
					),
					array(
						'blockName'   => 'core/post-content',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				),
			),
			array(
				'blockName'   => 'core/template-part',
				'attrs'       => array( 'slug' => 'footer' ),
				'innerBlocks' => array(),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( 'pass', $article_title_stack_contract_inspection['data']['contract_status'] ?? '', 'composition contract inspection passes breadcrumbs before an article title stack' );
npcink_abilities_toolkit_assert_same( 'pass', $article_title_stack_contract_inspection['data']['template_placement_contract']['contract_status'] ?? '', 'composition contract inspection accepts title-stack breadcrumb placement' );
$homepage_contract_inspection = $core_read_package->inspect_gutenberg_composition_contract(
	array(
		'surface_kind'       => 'site_editor_template',
		'post_type'          => 'wp_template',
		'slug'               => 'front-page',
		'placement_check'    => 'breadcrumbs',
		'show_on_home_page'  => false,
		'blocks'             => array(
			array(
				'blockName'   => 'core/template-part',
				'attrs'       => array( 'slug' => 'header' ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array( 'tagName' => 'main' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/group',
						'attrs'       => array( 'className' => 'openclaw-breadcrumbs' ),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/post-title',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( 'needs_revision', $homepage_contract_inspection['data']['contract_status'] ?? '', 'composition contract inspection flags homepage breadcrumbs when home display is disabled' );
npcink_abilities_toolkit_assert_true( in_array( 'template_placement_contract_failed', $homepage_contract_inspection['data']['violation_codes'] ?? array(), true ), 'composition contract inspection reports homepage breadcrumb placement violations' );
npcink_abilities_toolkit_assert_true( in_array( 'build_block_theme_site_plan', $homepage_contract_inspection['data']['recommended_next_actions'] ?? array(), true ), 'composition contract inspection recommends the Site Editor planner for homepage placement fixes' );
$invalid_contract_inspection = $core_read_package->inspect_gutenberg_composition_contract(
	array(
		'surface_kind'    => 'post_content',
		'post_type'       => 'page',
		'placement_check' => 'none',
		'blocks'          => array(
			array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerHTML'    => '<div style="position:absolute">Unsafe raw section</div>',
				'innerContent' => array( '<div style="position:absolute">Unsafe raw section</div>' ),
				'innerBlocks'  => array(),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $invalid_contract_inspection['success'] ?? null, 'inspect-gutenberg-composition-contract returns a success envelope for invalid proposed blocks' );
npcink_abilities_toolkit_assert_same( 'needs_revision', $invalid_contract_inspection['data']['contract_status'] ?? '', 'composition contract inspection rejects invalid proposed blocks' );
npcink_abilities_toolkit_assert_true( in_array( 'core/html', $invalid_contract_inspection['data']['composition_contract']['forbidden_block_names'] ?? array(), true ), 'composition contract inspection reports forbidden core/html blocks' );
npcink_abilities_toolkit_assert_true( in_array( 'composition_contract_failed', $invalid_contract_inspection['data']['violation_codes'] ?? array(), true ), 'composition contract inspection reports a composition violation code' );
npcink_abilities_toolkit_assert_true( in_array( 'revise_block_composition', $invalid_contract_inspection['data']['recommended_next_actions'] ?? array(), true ), 'composition contract inspection recommends composition revision when blocks violate the catalog' );
npcink_abilities_toolkit_assert_same( array( 'site.read' ), $package_abilities['npcink-abilities-toolkit/inspect-block-theme-surface']['required_scopes'] ?? array(), 'block theme surface inspection advertises Site Editor read scope' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/inspect-block-theme-surface']['input_schema']['properties']['target_templates'] ), 'block theme surface inspection accepts target templates' );
npcink_abilities_toolkit_assert_same( 1, $package_abilities['npcink-abilities-toolkit/build-article-block-plan']['input_schema']['properties']['target_post_id']['minimum'] ?? null, 'article block plan accepts a bounded target post id for existing draft updates' );
npcink_abilities_toolkit_assert_same( array( 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['required_scopes'] ?? array(), 'pattern page plan remains a read-scope planning ability' );
npcink_abilities_toolkit_assert_same( array( 'pattern_id' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['required'] ?? array(), 'pattern page plan only requires the pattern id and can infer title from variables' );
npcink_abilities_toolkit_assert_same( 1, $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['target_post_id']['minimum'] ?? null, 'pattern page plan accepts a bounded target page id for existing draft updates' );
npcink_abilities_toolkit_assert_same( array( 'landing_standard' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['responsive_profile']['enum'] ?? array(), 'pattern page plan exposes a bounded responsive profile' );
npcink_abilities_toolkit_assert_same( array( 'minimal-dark-light', 'editorial-accent' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['color_story']['enum'] ?? array(), 'pattern page plan exposes bounded color stories' );
npcink_abilities_toolkit_assert_same( array( 'balanced' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['visual_density']['enum'] ?? array(), 'pattern page plan exposes a bounded visual density' );
npcink_abilities_toolkit_assert_same( array( 'mock_or_existing_media', 'existing_media_url' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['media_strategy']['enum'] ?? array(), 'pattern page plan exposes bounded media strategies' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['research_brief'] ), 'pattern page plan accepts an optional landing page research brief' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['review_feedback'] ), 'pattern page plan accepts optional review feedback for revision loops' );
	npcink_abilities_toolkit_assert_same( array( 'center-title-two-cards', 'left-title-two-cards' ), $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['input_schema']['properties']['section_variant_hints']['properties']['comparison']['enum'] ?? array(), 'pattern page plan exposes bounded section variant hints' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/review-pattern-page'] ), 'review-pattern-page is registered as a read-only page quality review ability' );
	npcink_abilities_toolkit_assert_same( array( 'post.read' ), $package_abilities['npcink-abilities-toolkit/review-pattern-page']['required_scopes'] ?? array(), 'pattern page review remains a read-scope ability' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/review-pattern-page']['input_schema']['properties']['post_id'] ), 'pattern page review accepts a post id' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/review-pattern-page']['input_schema']['properties']['blocks'] ), 'pattern page review accepts proposed blocks before write' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/review-block-editor-surface'] ), 'review-block-editor-surface is registered as a read-only block surface review ability' );
	npcink_abilities_toolkit_assert_same( array( 'post.read', 'site.read' ), $package_abilities['npcink-abilities-toolkit/review-block-editor-surface']['required_scopes'] ?? array(), 'block surface review advertises post and Site Editor read scopes' );
	npcink_abilities_toolkit_assert_same( array( 'post_content', 'site_editor_template', 'site_editor_template_part', 'blocks_input' ), $package_abilities['npcink-abilities-toolkit/review-block-editor-surface']['input_schema']['properties']['surface_kind']['enum'] ?? array(), 'block surface review exposes bounded surface kinds' );
	npcink_abilities_toolkit_assert_same( array( 'post', 'page', 'wp_template', 'wp_template_part' ), $package_abilities['npcink-abilities-toolkit/review-block-editor-surface']['input_schema']['properties']['post_type']['enum'] ?? array(), 'block surface review exposes bounded post types' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/get-block-theme-context'] ), 'get-block-theme-context is registered as a read-only Site Editor context ability' );
	npcink_abilities_toolkit_assert_same( array( 'site.read' ), $package_abilities['npcink-abilities-toolkit/get-block-theme-context']['required_scopes'] ?? array(), 'block theme context remains a site read ability' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan'] ), 'build-block-theme-site-plan is registered as a read-only planning ability' );
	npcink_abilities_toolkit_assert_same( array( 'site.read' ), $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan']['required_scopes'] ?? array(), 'block theme site plan remains a read-scope planning ability' );
	npcink_abilities_toolkit_assert_same( array( 'add_breadcrumbs', 'customize_template_layout' ), $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan']['input_schema']['properties']['intent']['enum'] ?? array(), 'block theme site plan exposes breadcrumbs and bounded template layout intents' );
	npcink_abilities_toolkit_assert_same( array( 'auto', 'article_standard', 'page_standard', 'homepage_landing' ), $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan']['input_schema']['properties']['layout_profile']['enum'] ?? array(), 'block theme site plan exposes bounded layout profiles' );
	npcink_abilities_toolkit_assert_same( array( 'single', 'page', 'front-page', 'home', 'index' ), $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan']['input_schema']['properties']['target_templates']['items']['enum'] ?? array(), 'block theme site plan accepts Core-compatible content templates including front-page' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/update-template-blocks'] ), 'update-template-blocks is registered as a governed write ability' );
	npcink_abilities_toolkit_assert_same( array( 'site.write' ), $package_abilities['npcink-abilities-toolkit/update-template-blocks']['required_scopes'] ?? array(), 'template block updates require site.write scope' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/upsert-template-blocks'] ), 'upsert-template-blocks is registered as a governed template override write ability' );
	npcink_abilities_toolkit_assert_same( array( 'site.write' ), $package_abilities['npcink-abilities-toolkit/upsert-template-blocks']['required_scopes'] ?? array(), 'template override upserts require site.write scope' );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/update-template-part-blocks'] ), 'update-template-part-blocks is registered as a governed write ability' );
npcink_abilities_toolkit_assert_same( array( 'owned', 'ai_generated', 'stock', 'external', 'test' ), $package_abilities['npcink-abilities-toolkit/update-media-details']['input_schema']['properties']['source_type']['enum'] ?? array(), 'update-media-details accepts canonical media source_type values' );
npcink_abilities_toolkit_assert_same( 'external', $package_abilities['npcink-abilities-toolkit/upload-media-from-url']['input_schema']['properties']['source_type']['default'] ?? '', 'upload-media-from-url defaults remote imports to external source type' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/upload-media-from-url']['input_schema']['properties']['file_name'] ), 'upload-media-from-url accepts an approved custom media file name' );
npcink_abilities_toolkit_assert_same( array( 'webp', 'jpeg', 'png' ), $package_abilities['npcink-abilities-toolkit/optimize-media-asset']['input_schema']['properties']['preferred_format']['enum'] ?? array(), 'optimize-media-asset exposes bounded derivative formats' );
npcink_abilities_toolkit_assert_same( 82, $package_abilities['npcink-abilities-toolkit/optimize-media-asset']['input_schema']['properties']['quality']['default'] ?? null, 'optimize-media-asset defaults to quality 82' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/optimize-media-asset']['output_schema']['properties']['derivative_url'] ), 'optimize-media-asset exposes a top-level derivative_url for batch output references' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/inspect-media-asset']['output_schema']['properties']['data']['properties']['storage'] ), 'inspect-media-asset exposes media storage preflight evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['output_schema']['properties']['data']['properties']['storage'] ), 'media derivative cloud request exposes storage preflight evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-optimization-plan']['input_schema']['properties']['storage_preflight'] ), 'media optimization plans accept reviewed storage preflight evidence' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/replace-media-file']['input_schema']['properties']['mode'] ), 'replace-media-file does not expose media restore modes' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-backup', $package_abilities['npcink-abilities-toolkit/replace-media-file']['input_schema']['properties']['backup_suffix']['default'] ?? '', 'replace-media-file defaults to explicit Npcink backup suffix' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/replace-media-file']['input_schema']['properties']['expected_storage_provider'] ), 'replace-media-file accepts storage provider drift guards' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/replace-media-file']['output_schema']['properties']['content_reference_repairs'] ), 'replace-media-file exposes post content reference repair preview evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/replace-media-file']['output_schema']['properties']['verification'] ), 'replace-media-file exposes execution verification summary' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/list-media-backups'] ), 'list-media-backups is registered as a read-only media history ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id' ), $package_abilities['npcink-abilities-toolkit/list-media-backups']['input_schema']['required'] ?? array(), 'list-media-backups requires attachment id' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/restore-media-backup'] ), 'restore-media-backup is registered as a governed write ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'backup_id' ), $package_abilities['npcink-abilities-toolkit/restore-media-backup']['input_schema']['required'] ?? array(), 'restore-media-backup requires attachment and backup id' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/restore-media-backup']['input_schema']['properties']['expected_storage_adapter'] ), 'restore-media-backup accepts storage adapter drift guards' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/restore-media-backup']['output_schema']['properties']['content_reference_repairs'] ), 'restore-media-backup exposes post content reference repair evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/restore-media-backup']['output_schema']['properties']['verification'] ), 'restore-media-backup exposes execution verification summary' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/rename-media-file'] ), 'rename-media-file is registered as a local write ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'target_file_name' ), $package_abilities['npcink-abilities-toolkit/rename-media-file']['input_schema']['required'] ?? array(), 'rename-media-file requires attachment and target filename' );
npcink_abilities_toolkit_assert_same( array( 'fail', 'unique' ), $package_abilities['npcink-abilities-toolkit/rename-media-file']['input_schema']['properties']['conflict_mode']['enum'] ?? array(), 'rename-media-file exposes bounded conflict modes' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-rename-backup', $package_abilities['npcink-abilities-toolkit/rename-media-file']['input_schema']['properties']['backup_suffix']['default'] ?? '', 'rename-media-file defaults to explicit rename backup suffix' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative'] ), 'adopt-cloud-media-derivative is registered as a local write ability' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-cloud-backup', $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['backup_suffix']['default'] ?? '', 'adopt-cloud-media-derivative defaults to explicit Cloud backup suffix' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['file_name'] ), 'adopt-cloud-media-derivative accepts an approved custom derivative file name' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['expected_content_reference_post_ids'] ), 'adopt-cloud-media-derivative accepts reviewed content reference post id expectations' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['expected_content_reference_replacement_count'] ), 'adopt-cloud-media-derivative accepts reviewed content reference replacement count expectations' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['storage_preflight'] ), 'adopt-cloud-media-derivative accepts reviewed storage preflight evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['output_schema']['properties']['proposed_filename'] ) && isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['output_schema']['properties']['filename_policy'] ), 'adopt-cloud-media-derivative exposes filename proposal evidence in its output schema' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['output_schema']['properties']['content_reference_repairs'] ), 'adopt-cloud-media-derivative exposes post content reference repair preview evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['output_schema']['properties']['verification'] ), 'adopt-cloud-media-derivative exposes execution verification summary' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'derivative_artifact' ), $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['required'] ?? array(), 'adopt-cloud-media-derivative requires attachment and artifact evidence' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-reference-repair-plan'] ), 'build-media-reference-repair-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id' ), $package_abilities['npcink-abilities-toolkit/build-media-reference-repair-plan']['input_schema']['required'] ?? array(), 'build-media-reference-repair-plan requires attachment id' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan'] ), 'build-media-adoption-enhancement-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read', 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['required_scopes'] ?? array(), 'media adoption enhancement plan reads media and post references' );
npcink_abilities_toolkit_assert_same( array( 'url' ), $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['input_schema']['required'] ?? array(), 'media adoption enhancement plan requires a reviewed remote URL' );
npcink_abilities_toolkit_assert_same( array( 'webp', 'jpeg', 'png' ), $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['input_schema']['properties']['preferred_format']['enum'] ?? array(), 'media adoption enhancement plan exposes bounded local derivative formats' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['input_schema']['properties']['commit'] ), 'media adoption enhancement plan does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-adoption-enhancement-plan']['input_schema']['properties']['dry_run'] ), 'media adoption enhancement plan does not expose write dry_run control' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-review-artifact'] ), 'build-image-candidate-review-artifact is registered as a read-only review ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-image-candidate-review-artifact']['required_scopes'] ?? array(), 'image candidate review artifact only reads media candidate evidence' );
npcink_abilities_toolkit_assert_same( 12, $package_abilities['npcink-abilities-toolkit/build-image-candidate-review-artifact']['input_schema']['properties']['image_candidates']['maxItems'] ?? null, 'image candidate review artifact bounds candidate evidence input' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-review-artifact']['input_schema']['properties']['commit'] ), 'image candidate review artifact does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-review-artifact']['input_schema']['properties']['provider'] ), 'image candidate review artifact does not expose provider runtime selection' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-alt-caption-review-set'] ), 'build-media-alt-caption-review-set is registered as a read-only review ability' );
npcink_abilities_toolkit_assert_same( 'media_governance', $package_abilities['npcink-abilities-toolkit/build-media-alt-caption-review-set']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'media ALT/caption review set is classified as media governance' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-media-alt-caption-review-set']['required_scopes'] ?? array(), 'media ALT/caption review set only reads supplied media evidence' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-alt-caption-review-set']['input_schema']['properties']['commit'] ), 'media ALT/caption review set does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-alt-caption-review-set']['input_schema']['properties']['provider'] ), 'media ALT/caption review set does not expose provider runtime selection' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-alt-apply-plan'] ), 'build-media-alt-apply-plan is registered as a read-only governed planner' );
npcink_abilities_toolkit_assert_same( 'media_governance', $package_abilities['npcink-abilities-toolkit/build-media-alt-apply-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'media ALT apply plan is classified as media governance' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id', 'alt', 'expected_current_alt', 'operator_visual_review_confirmed', 'review_set_contract' ), $package_abilities['npcink-abilities-toolkit/build-media-alt-apply-plan']['input_schema']['required'] ?? array(), 'media ALT apply plan requires reviewed value, old value, visual confirmation, and review contract' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/update-media-details']['input_schema']['properties']['expected_current_alt'] ), 'update-media-details exposes the optional ALT old-value guard' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/update-media-details']['input_schema']['properties']['operator_visual_review_confirmed'] ), 'update-media-details exposes guarded ALT visual confirmation' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan'] ), 'build-image-candidate-adoption-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read', 'post.read' ), $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan']['required_scopes'] ?? array(), 'image candidate adoption plan reads media and post references' );
npcink_abilities_toolkit_assert_same( array(), $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan']['input_schema']['required'] ?? array(), 'image candidate adoption plan accepts image_candidate or direct URL input' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan']['input_schema']['properties']['set_featured_image'] ), 'image candidate adoption plan exposes optional featured image planning' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan']['input_schema']['properties']['commit'] ), 'image candidate adoption plan does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-image-candidate-adoption-plan']['input_schema']['properties']['dry_run'] ), 'image candidate adoption plan does not expose write dry_run control' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-settings-reference-repair-plan'] ), 'build-media-settings-reference-repair-plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id' ), $package_abilities['npcink-abilities-toolkit/build-media-settings-reference-repair-plan']['input_schema']['required'] ?? array(), 'build-media-settings-reference-repair-plan requires attachment id' );
npcink_abilities_toolkit_assert_same( array( 'svg', 'gif', 'ico', 'pdf' ), $package_abilities['npcink-abilities-toolkit/build-media-settings-reference-repair-plan']['input_schema']['properties']['excluded_formats']['default'] ?? array(), 'media settings reference repair defaults excluded formats' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/patch-setting-value'] ), 'patch-setting-value is registered as a local write ability' );
npcink_abilities_toolkit_assert_same( array( 'target_type', 'target_name', 'operations' ), $package_abilities['npcink-abilities-toolkit/patch-setting-value']['input_schema']['required'] ?? array(), 'patch-setting-value requires a setting target and operations' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url'] ), 'resolve-media-attachment-by-url is registered as a read-only media resolver' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url']['required_scopes'] ?? array(), 'resolve-media-attachment-by-url remains a read-scope ability' );
npcink_abilities_toolkit_assert_same( array( 'url' ), $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url']['input_schema']['required'] ?? array(), 'resolve-media-attachment-by-url requires a URL' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url']['input_schema']['properties']['max_candidates']['maximum'] ?? null, 'resolve-media-attachment-by-url bounds candidates to 20' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url']['input_schema']['properties']['commit'] ), 'resolve-media-attachment-by-url does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/resolve-media-attachment-by-url']['input_schema']['properties']['dry_run'] ), 'resolve-media-attachment-by-url does not expose write dry_run control' );
npcink_abilities_toolkit_assert_same( 1920, $package_abilities['npcink-abilities-toolkit/inspect-media-asset']['input_schema']['properties']['target_max_width']['default'] ?? null, 'inspect-media-asset defaults to a 1920px max width target' );
npcink_abilities_toolkit_assert_same( array( 'webp', 'avif', 'original' ), $package_abilities['npcink-abilities-toolkit/inspect-media-asset']['input_schema']['properties']['preferred_format']['enum'] ?? array(), 'inspect-media-asset exposes bounded preferred output formats' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['required_scopes'] ?? array(), 'media derivative cloud request remains a read-scope planning ability' );
npcink_abilities_toolkit_assert_same( array( 'attachment_id' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['required'] ?? array(), 'media derivative cloud request requires an attachment id' );
npcink_abilities_toolkit_assert_same( array( 'webp', 'avif', 'jpeg', 'png', 'original' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['preferred_format']['enum'] ?? array(), 'media derivative cloud request exposes bounded preferred output formats' );
npcink_abilities_toolkit_assert_same( 82, $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['quality']['default'] ?? null, 'media derivative cloud request defaults to quality 82' );
npcink_abilities_toolkit_assert_same( array( 'aspect_ratio' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['crop']['properties']['type']['enum'] ?? array(), 'media derivative cloud request supports bounded aspect-ratio crop plans' );
npcink_abilities_toolkit_assert_same( array( 'top_left', 'top', 'top_right', 'left', 'center', 'right', 'bottom_left', 'bottom', 'bottom_right' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['crop']['properties']['position']['enum'] ?? array(), 'media derivative cloud request exposes bounded crop positions' );
npcink_abilities_toolkit_assert_same( array( 'image', 'text' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['type']['enum'] ?? array(), 'media derivative cloud request supports image and text watermark plans' );
npcink_abilities_toolkit_assert_same( array( 'top_left', 'top_right', 'bottom_left', 'bottom_right', 'center' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['position']['enum'] ?? array(), 'media derivative cloud request exposes bounded watermark positions' );
npcink_abilities_toolkit_assert_same( 0.75, $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['opacity']['default'] ?? null, 'media derivative cloud request defaults watermark opacity' );
npcink_abilities_toolkit_assert_same( 18, $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['scale_percent']['default'] ?? null, 'media derivative cloud request defaults watermark scale' );
npcink_abilities_toolkit_assert_same( 'AI', $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['text']['default'] ?? null, 'media derivative cloud request defaults text watermark content' );
npcink_abilities_toolkit_assert_same( 48, $package_abilities['npcink-abilities-toolkit/build-media-derivative-cloud-request']['input_schema']['properties']['watermark']['properties']['font_size']['default'] ?? null, 'media derivative cloud request defaults text watermark font size' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan'] ), 'media derivative batch plan is registered as a read-only planning ability' );
npcink_abilities_toolkit_assert_same( array( 'media.read' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['required_scopes'] ?? array(), 'media derivative batch plan remains a read-scope planning ability' );
npcink_abilities_toolkit_assert_same( array( 'auto_safe' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['optimization_mode']['enum'] ?? array(), 'media derivative batch plan exposes only the auto-safe mode' );
npcink_abilities_toolkit_assert_same( array( 'auto_safe.v1' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['optimization_profile']['enum'] ?? array(), 'media derivative batch plan pins the automatic policy version' );
npcink_abilities_toolkit_assert_same( array( 'jpeg', 'png', 'webp' ), $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['image_types']['items']['enum'] ?? array(), 'media derivative batch plan exposes only supported static image types' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['target_format'] ) && ! isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['crop'] ) && ! isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['watermark'] ), 'media derivative batch plan keeps manual format, crop, and watermark controls out of one-click input' );
npcink_abilities_toolkit_assert_same( 1000, $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['max_items']['maximum'] ?? null, 'media derivative batch plan bounds candidates to 1000 items' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['output_schema']['properties']['data']['properties']['eligibility_summary'] ), 'media derivative batch plan declares eligibility summary output' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['output_schema']['properties']['data']['properties']['blocked_items'] ), 'media derivative batch plan declares blocked items output' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['output_schema']['properties']['data']['properties']['retry_guidance'] ), 'media derivative batch plan declares retry guidance output' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['output_schema']['properties']['data']['properties']['operator_next_action'] ), 'media derivative batch plan declares operator next action output' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['commit'] ), 'media derivative batch plan does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-media-derivative-batch-plan']['input_schema']['properties']['dry_run'] ), 'media derivative batch plan does not expose write dry_run control' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-taxonomy-consolidation-suggestions']['input_schema']['properties']['per_page']['maximum'] ?? null, 'taxonomy consolidation suggestions scan is bounded to 100 terms per page' );
npcink_abilities_toolkit_assert_same( array( 'both', 'category', 'post_tag' ), $package_abilities['npcink-abilities-toolkit/suggest-post-taxonomy-terms']['input_schema']['properties']['taxonomy']['enum'] ?? array(), 'post taxonomy suggestions support both category and tag candidates' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/suggest-post-taxonomy-terms']['input_schema']['properties']['related_term_evidence']['maxItems'] ?? null, 'post taxonomy suggestions bound related term evidence' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/suggest-post-taxonomy-terms']['input_schema']['properties']['commit'] ), 'post taxonomy suggestions do not expose a commit control' );
npcink_abilities_toolkit_assert_true( isset( $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set'] ), 'build-taxonomy-tag-review-set is registered as a read-only review ability' );
npcink_abilities_toolkit_assert_same( 'taxonomy_governance', $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'taxonomy/tag review set is classified as taxonomy governance' );
npcink_abilities_toolkit_assert_same( array( 'post.read', 'taxonomy.read' ), $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['required_scopes'] ?? array(), 'taxonomy/tag review set reads post context and taxonomy evidence' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['input_schema']['properties']['review_set_limit']['maximum'] ?? null, 'taxonomy/tag review set bounds selected review rows' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['input_schema']['properties']['commit'] ), 'taxonomy/tag review set does not expose a commit control' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['input_schema']['properties']['provider'] ), 'taxonomy/tag review set does not expose provider runtime selection' );
npcink_abilities_toolkit_assert_same( array( 'post_id' ), $package_abilities['npcink-abilities-toolkit/propose-post-taxonomy-terms']['input_schema']['required'] ?? array(), 'post taxonomy proposal requires post_id' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/propose-post-taxonomy-terms']['input_schema']['properties']['candidate_terms']['maxItems'] ?? null, 'post taxonomy proposal bounds candidate term names' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-page-structure-health']['input_schema']['properties']['max_pages']['maximum'] ?? null, 'page structure health scan is bounded to 100 pages' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-seo-geo-gap-report']['input_schema']['properties']['per_page']['maximum'] ?? null, 'SEO/GEO gap report scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 5, $package_abilities['npcink-abilities-toolkit/get-site-style-baseline']['input_schema']['properties']['limit']['maximum'] ?? null, 'site style baseline is bounded to 5 samples' );
npcink_abilities_toolkit_assert_same( array( 'new_article', 'refresh', 'publish' ), $package_abilities['npcink-abilities-toolkit/build-article-workflow-context']['input_schema']['properties']['workflow']['enum'] ?? array(), 'article workflow context supports known workflow modes' );
npcink_abilities_toolkit_assert_same( 365, $package_abilities['npcink-abilities-toolkit/get-publishing-calendar-context']['input_schema']['properties']['window_days']['maximum'] ?? null, 'publishing calendar window is bounded to 365 days' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-media-inventory-health']['input_schema']['properties']['per_page']['maximum'] ?? null, 'media inventory health scan is bounded to 100 assets per page' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/get-media-inventory-health']['input_schema']['properties']['attachment_ids']['maxItems'] ?? null, 'media inventory attachment-id revalidation is bounded to 20 assets' );
npcink_abilities_toolkit_assert_same( array( 'date_desc', 'id_asc' ), $package_abilities['npcink-abilities-toolkit/get-media-inventory-health']['input_schema']['properties']['stable_order']['enum'] ?? array(), 'media inventory exposes only the bounded date or stable ID ordering modes' );
npcink_abilities_toolkit_assert_same( array( 'post_id' ), $package_abilities['npcink-abilities-toolkit/get-post-seo-geo-readiness']['input_schema']['required'] ?? array(), 'post SEO/GEO readiness requires post_id' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-site-topic-coverage-report']['input_schema']['properties']['per_page']['maximum'] ?? null, 'site topic coverage scan is bounded to 100 posts per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-taxonomy-inventory-health']['input_schema']['properties']['per_page']['maximum'] ?? null, 'taxonomy inventory health scan is bounded to 100 terms per page' );
npcink_abilities_toolkit_assert_same( array( 'post_id' ), $package_abilities['npcink-abilities-toolkit/get-revision-change-risk-report']['input_schema']['required'] ?? array(), 'revision change risk report requires post_id' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-comment-queue-health']['input_schema']['properties']['per_page']['maximum'] ?? null, 'comment queue health scan is bounded to 100 comments per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-comment-action-priority-queue']['input_schema']['properties']['per_page']['maximum'] ?? null, 'comment action priority queue scan is bounded to 100 comments per page' );
npcink_abilities_toolkit_assert_same( 100, $package_abilities['npcink-abilities-toolkit/get-comment-compliance-handoff']['input_schema']['properties']['per_page']['maximum'] ?? null, 'comment compliance handoff scan is bounded to 100 comments per page' );
npcink_abilities_toolkit_assert_same( array(), $package_abilities['npcink-abilities-toolkit/build-comment-mention-reply-suggest']['input_schema']['required'] ?? array(), 'comment mention reply suggestions accept either comment_id or supplied comment text' );
npcink_abilities_toolkit_assert_same( 1200, $package_abilities['npcink-abilities-toolkit/build-comment-mention-reply-suggest']['input_schema']['properties']['comment_text']['maxLength'] ?? null, 'comment mention reply suggestions bound supplied comment text' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-comments', $package_abilities['npcink-abilities-toolkit/build-comment-moderation-suggest']['category'], 'comment helper abilities use the standalone comments category' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-comments', $package_abilities['npcink-abilities-toolkit/get-comment-queue-health']['category'], 'comment queue health uses the standalone comments category' );
	npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-diagnostics-summary']['project_to_npcink_catalog'], 'standalone diagnostics ability does not project into Npcink AI by default' );
	npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['project_to_npcink_catalog'], 'standalone ops diagnostics ability does not project into Npcink AI by default' );
	npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/list-workflow-recipes']['project_to_npcink_catalog'], 'workflow recipe discovery ability does not project into Npcink AI by default' );
	npcink_abilities_toolkit_assert_same( 'wordpress_diagnostics', $package_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'ops diagnostics detail is classified as WordPress diagnostics' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-workflows', $package_abilities['npcink-abilities-toolkit/list-workflow-recipes']['category'], 'workflow recipe discovery uses standalone workflow category' );
	npcink_abilities_toolkit_assert_same( 'workflow_definitions', $package_abilities['npcink-abilities-toolkit/list-workflow-recipes']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'workflow recipe discovery is classified as workflow definitions' );
npcink_abilities_toolkit_assert_same( 'core_wordpress_read', $package_abilities['npcink-abilities-toolkit/site-info']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'site-info is classified as a core WordPress read ability' );
npcink_abilities_toolkit_assert_same( 'content_operations', $package_abilities['npcink-abilities-toolkit/get-site-operations-dashboard']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'site operations dashboard is classified outside core WordPress reads' );
npcink_abilities_toolkit_assert_same( 'content_operations', $package_abilities['npcink-abilities-toolkit/build-content-inventory-fix-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'content inventory fix plan is classified as content operations' );
npcink_abilities_toolkit_assert_same( 'media_governance', $package_abilities['npcink-abilities-toolkit/build-media-inventory-fix-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'media inventory fix plan is classified as media governance' );
npcink_abilities_toolkit_assert_same( 'taxonomy_governance', $package_abilities['npcink-abilities-toolkit/suggest-post-taxonomy-terms']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'post taxonomy suggestions are classified as taxonomy governance' );
npcink_abilities_toolkit_assert_same( 'taxonomy_governance', $package_abilities['npcink-abilities-toolkit/build-taxonomy-tag-review-set']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'taxonomy/tag review set is classified as taxonomy governance' );
npcink_abilities_toolkit_assert_same( 'taxonomy_governance', $package_abilities['npcink-abilities-toolkit/propose-post-taxonomy-terms']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'post taxonomy proposal is classified as taxonomy governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/build-pattern-page-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'pattern page plan is classified as page governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/route-content-intent']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'content intent router is classified as page governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'Gutenberg recipe evaluation is classified as page governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/inspect-block-theme-surface']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'block theme surface inspection is classified as page governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/review-pattern-page']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'pattern page review is classified as page governance' );
	npcink_abilities_toolkit_assert_same( 'page_governance', $package_abilities['npcink-abilities-toolkit/build-block-theme-site-plan']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'block theme site plan is classified as page governance' );
npcink_abilities_toolkit_assert_same( 'comment_queue_context', $package_abilities['npcink-abilities-toolkit/get-comment-queue-health']['meta']['npcink_abilities_toolkit']['pack'] ?? '', 'comment queue health is classified as a comment queue helper' );
	$expected_mcp_public_read_ability_ids = array(
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/list-post-types',
		'npcink-abilities-toolkit/list-taxonomies',
		'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/site-info',
	);
	$mcp_public_read_ability_ids = array();
	foreach ( $package_abilities as $ability_id => $definition ) {
		if ( 'read' === (string) ( $definition['risk_level'] ?? '' ) && true === (bool) ( $definition['meta']['mcp']['public'] ?? false ) ) {
			$mcp_public_read_ability_ids[] = (string) $ability_id;
		}
	}
	sort( $mcp_public_read_ability_ids );
	npcink_abilities_toolkit_assert_same( $expected_mcp_public_read_ability_ids, $mcp_public_read_ability_ids, 'default MCP-public read surface stays limited to approved entrypoint abilities' );
	foreach ( $expected_mcp_public_read_ability_ids as $ability_id ) {
		npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-read', $package_abilities[ $ability_id ]['meta']['mcp']['server'] ?? '', "{$ability_id} belongs on the read MCP server" );
	}
	npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/wp-diagnostics-summary']['meta']['mcp']['public'] ?? null, 'diagnostics summary stays out of default MCP discovery' );
	npcink_abilities_toolkit_assert_same( false, $package_abilities['npcink-abilities-toolkit/get-site-operations-dashboard']['meta']['mcp']['public'] ?? null, 'site operations dashboard stays out of default MCP discovery' );
	$core_read_definition_ids = array_keys( $core_read_package->definitions() );
	$media_governance_definition_ids = array(
		'npcink-abilities-toolkit/list-media',
		'npcink-abilities-toolkit/resolve-media-attachment-by-url',
		'npcink-abilities-toolkit/build-inline-image-blocks',
		'npcink-abilities-toolkit/build-media-seo-assets',
		'npcink-abilities-toolkit/optimize-media-metadata',
		'npcink-abilities-toolkit/position-inline-image-blocks',
		'npcink-abilities-toolkit/get-media-cleanup-opportunities',
		'npcink-abilities-toolkit/list-media-backups',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
		'npcink-abilities-toolkit/build-image-candidate-adoption-plan',
		'npcink-abilities-toolkit/build-image-candidate-review-artifact',
		'npcink-abilities-toolkit/build-media-alt-caption-review-set',
		'npcink-abilities-toolkit/build-media-alt-apply-plan',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-abilities-toolkit/get-media-inventory-health',
		'npcink-abilities-toolkit/inspect-media-asset',
		'npcink-abilities-toolkit/build-media-derivative-cloud-request',
		'npcink-abilities-toolkit/build-media-optimization-plan',
		'npcink-abilities-toolkit/build-media-adoption-preflight-summary',
		'npcink-abilities-toolkit/build-media-rename-plan',
		'npcink-abilities-toolkit/build-media-derivative-batch-plan',
	);
	$media_governance_definitions = Media_Governance_Read_Definitions::definitions( $core_read_package );
	npcink_abilities_toolkit_assert_same( $media_governance_definition_ids, array_keys( $media_governance_definitions ), 'media governance definition provider owns the expected 23 definitions in stable source order' );
	npcink_abilities_toolkit_assert_same( 116, count( $core_read_definition_ids ), 'core read package preserves the 116-definition public surface after media provider split' );
	npcink_abilities_toolkit_assert_same( array_keys( Core_Read_Pack_Classifier::known_pack_map() ), $core_read_definition_ids, 'core read package preserves the classifier map as the complete 116-definition public order' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $core_read_package_source, 'Media_Governance_Read_Definitions::definitions( $this )' ), 'core read package composes media governance definitions through the dedicated provider' );
	foreach ( $media_governance_definition_ids as $media_ability_id ) {
		$inline_definition_pattern = "/'" . preg_quote( $media_ability_id, '/' ) . "'\\s*=>\\s*array\\s*\\(/";
		npcink_abilities_toolkit_assert_same( 0, preg_match( $inline_definition_pattern, $core_read_package_source ), "core read package no longer inlines {$media_ability_id}" );
	}
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/site-info', $core_read_definition_ids[0] ?? '', 'core read definitions keep site-info first after provider split' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/wp-diagnostics-summary', $core_read_definition_ids[1] ?? '', 'core read definitions keep diagnostics second after provider split' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/wp-ops-diagnostics-detail', $core_read_definition_ids[2] ?? '', 'core read definitions keep ops diagnostics after diagnostics summary' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/list-workflow-recipes', $core_read_definition_ids[3] ?? '', 'core read definitions keep workflow list after diagnostics' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-workflow-recipe', $core_read_definition_ids[4] ?? '', 'core read definitions keep workflow get after workflow list' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/list-post-types', $core_read_definition_ids[5] ?? '', 'core read definitions keep post types after workflow definitions' );
		npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/list-media', $core_read_definition_ids[7] ?? '', 'core read definitions keep media governance order after provider split' );
		npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/resolve-media-attachment-by-url', $core_read_definition_ids[8] ?? '', 'core read definitions keep media URL resolver near media inventory' );
	$taxonomy_suggest_index = array_search( 'npcink-abilities-toolkit/suggest-post-taxonomy-terms', $core_read_definition_ids, true );
	$taxonomy_review_index = array_search( 'npcink-abilities-toolkit/build-taxonomy-tag-review-set', $core_read_definition_ids, true );
	$metadata_plan_index = array_search( 'npcink-abilities-toolkit/resolve-post-metadata-plan', $core_read_definition_ids, true );
	$route_content_intent_index = array_search( 'npcink-abilities-toolkit/route-content-intent', $core_read_definition_ids, true );
	$recipe_evaluation_index = array_search( 'npcink-abilities-toolkit/evaluate-gutenberg-recipe-suite', $core_read_definition_ids, true );
	$block_catalog_index = array_search( 'npcink-abilities-toolkit/get-gutenberg-block-capability-catalog', $core_read_definition_ids, true );
	$block_composer_index = array_search( 'npcink-abilities-toolkit/compose-gutenberg-block-plan', $core_read_definition_ids, true );
	$composition_contract_index = array_search( 'npcink-abilities-toolkit/inspect-gutenberg-composition-contract', $core_read_definition_ids, true );
	$block_theme_context_index = array_search( 'npcink-abilities-toolkit/get-block-theme-context', $core_read_definition_ids, true );
	$block_theme_surface_index = array_search( 'npcink-abilities-toolkit/inspect-block-theme-surface', $core_read_definition_ids, true );
	$block_theme_site_plan_index = array_search( 'npcink-abilities-toolkit/build-block-theme-site-plan', $core_read_definition_ids, true );
	$pattern_page_plan_index = array_search( 'npcink-abilities-toolkit/build-pattern-page-plan', $core_read_definition_ids, true );
	$pattern_page_review_index = array_search( 'npcink-abilities-toolkit/review-pattern-page', $core_read_definition_ids, true );
	$block_surface_review_index = array_search( 'npcink-abilities-toolkit/review-block-editor-surface', $core_read_definition_ids, true );
	$article_block_plan_index = array_search( 'npcink-abilities-toolkit/build-article-block-plan', $core_read_definition_ids, true );
	npcink_abilities_toolkit_assert_true( false !== $taxonomy_suggest_index && false !== $taxonomy_review_index && $taxonomy_suggest_index < $taxonomy_review_index, 'core read definitions keep taxonomy review-set builder after taxonomy suggestions' );
	npcink_abilities_toolkit_assert_true( false !== $taxonomy_review_index && false !== $metadata_plan_index && $taxonomy_review_index < $metadata_plan_index, 'core read definitions keep taxonomy review-set builder before metadata planning' );
	npcink_abilities_toolkit_assert_true( false !== $metadata_plan_index && false !== $route_content_intent_index && $metadata_plan_index < $route_content_intent_index, 'core read definitions keep content intent routing after metadata planning' );
	npcink_abilities_toolkit_assert_true( false !== $route_content_intent_index && false !== $recipe_evaluation_index && $route_content_intent_index < $recipe_evaluation_index, 'core read definitions keep recipe evaluation next to intent routing' );
	npcink_abilities_toolkit_assert_true( false !== $recipe_evaluation_index && false !== $block_catalog_index && $recipe_evaluation_index < $block_catalog_index, 'core read definitions keep the block composition catalog before concrete planners' );
	npcink_abilities_toolkit_assert_true( false !== $block_catalog_index && false !== $block_composer_index && $block_catalog_index < $block_composer_index, 'core read definitions keep the composer repair loop after the block catalog' );
	npcink_abilities_toolkit_assert_true( false !== $block_composer_index && false !== $composition_contract_index && $block_composer_index < $composition_contract_index, 'core read definitions keep composition contract inspection after the composer loop' );
	npcink_abilities_toolkit_assert_true( false !== $composition_contract_index && false !== $block_theme_context_index && $composition_contract_index < $block_theme_context_index, 'core read definitions keep block theme context near page planning' );
	npcink_abilities_toolkit_assert_true( false !== $block_theme_context_index && false !== $block_theme_surface_index && $block_theme_context_index < $block_theme_surface_index, 'core read definitions keep block theme inspection before site planning' );
	npcink_abilities_toolkit_assert_true( false !== $block_theme_surface_index && false !== $block_theme_site_plan_index && $block_theme_surface_index < $block_theme_site_plan_index, 'core read definitions keep block theme site planning before pattern page planning' );
	npcink_abilities_toolkit_assert_true( false !== $block_theme_site_plan_index && false !== $pattern_page_plan_index && $block_theme_site_plan_index < $pattern_page_plan_index, 'core read definitions keep pattern page planning near block theme planning' );
	npcink_abilities_toolkit_assert_true( false !== $pattern_page_plan_index && false !== $pattern_page_review_index && $pattern_page_plan_index < $pattern_page_review_index, 'core read definitions keep pattern page review near pattern page planning' );
	npcink_abilities_toolkit_assert_true( false !== $pattern_page_review_index && false !== $block_surface_review_index && $pattern_page_review_index < $block_surface_review_index, 'core read definitions keep block surface review near pattern page review' );
	npcink_abilities_toolkit_assert_true( false !== $block_surface_review_index && false !== $article_block_plan_index && $block_surface_review_index < $article_block_plan_index, 'core read definitions keep article block planning near pattern page planning' );
		npcink_abilities_toolkit_assert_true( false !== array_search( 'npcink-abilities-toolkit/list-media-backups', $core_read_definition_ids, true ), 'core read definitions include media backup history discovery' );
		$url_resolver_index = array_search( 'npcink-abilities-toolkit/resolve-url-to-post', $core_read_definition_ids, true );
		$revision_list_index = array_search( 'npcink-abilities-toolkit/list-post-revisions', $core_read_definition_ids, true );
		npcink_abilities_toolkit_assert_true( false !== $url_resolver_index, 'core read definitions include URL resolver after provider split' );
		npcink_abilities_toolkit_assert_true( false !== $revision_list_index, 'core read definitions include revision list after provider split' );
		npcink_abilities_toolkit_assert_true( false !== $url_resolver_index && false !== $revision_list_index && $url_resolver_index < $revision_list_index, 'core read definitions keep URL resolver before revision list after provider split' );
$core_comment_definition_ids = array_keys( $core_comment_package->definitions() );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-comment-moderation-suggest', $core_comment_definition_ids[0] ?? '', 'core comment definitions keep moderation suggestion first after provider split' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-comment-compliance-handoff', $core_comment_definition_ids[6] ?? '', 'core comment definitions keep compliance handoff order after provider split' );
foreach ( array_keys( $core_read_package->definitions() ) as $known_read_ability_id ) {
	npcink_abilities_toolkit_assert_true(
		isset( Core_Read_Pack_Classifier::known_pack_map()[ $known_read_ability_id ] ),
		"core read ability {$known_read_ability_id} has an explicit sub-pack map entry"
	);
}
foreach ( array_keys( $core_comment_package->definitions() ) as $known_comment_ability_id ) {
	npcink_abilities_toolkit_assert_true(
		isset( Core_Comment_Pack_Classifier::known_pack_map()[ $known_comment_ability_id ] ),
		"core comment ability {$known_comment_ability_id} has an explicit sub-pack map entry"
	);
}
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/create-page'] ), 'create-page is not migrated as a readonly ability' );
npcink_abilities_toolkit_assert_true( ! isset( $package_abilities['npcink-abilities-toolkit/update-page'] ), 'update-page is not migrated as a readonly ability' );

add_filter(
	'npcink_abilities_toolkit_enabled_read_packs',
	static function () {
		return array( 'core_wordpress_read' );
	}
);
$filtered_read_categories = new Category_Registrar();
$filtered_read_registrar = new Ability_Registrar( $filtered_read_categories, $contract_normalizer );
$filtered_read_package = new Core_Read_Package( $filtered_read_categories, $filtered_read_registrar );
$filtered_read_package->boot();
$filtered_read_abilities = $filtered_read_registrar->all();
	npcink_abilities_toolkit_assert_true( isset( $filtered_read_abilities['npcink-abilities-toolkit/site-info'] ), 'core read pack filter keeps generic site-info ability' );
	npcink_abilities_toolkit_assert_true( ! isset( $filtered_read_abilities['npcink-abilities-toolkit/get-site-operations-dashboard'] ), 'core read pack filter removes operations helper ability' );
	npcink_abilities_toolkit_assert_true( ! isset( $filtered_read_abilities['npcink-abilities-toolkit/wp-diagnostics-summary'] ), 'core read pack filter removes diagnostics helper ability' );
	npcink_abilities_toolkit_assert_true( ! isset( $filtered_read_abilities['npcink-abilities-toolkit/wp-ops-diagnostics-detail'] ), 'core read pack filter removes ops diagnostics helper ability' );
	npcink_abilities_toolkit_assert_true( ! isset( $filtered_read_abilities['npcink-abilities-toolkit/list-workflow-recipes'] ), 'core read pack filter removes workflow definition discovery ability' );
remove_all_filters( 'npcink_abilities_toolkit_enabled_read_packs' );

add_filter(
	'npcink_abilities_toolkit_enabled_comment_packs',
	static function () {
		return array( 'comment_queue_context' );
	}
);
$filtered_comment_categories = new Category_Registrar();
$filtered_comment_registrar = new Ability_Registrar( $filtered_comment_categories, $contract_normalizer );
$filtered_comment_package = new Core_Comment_Package( $filtered_comment_categories, $filtered_comment_registrar );
$filtered_comment_package->boot();
$filtered_comment_abilities = $filtered_comment_registrar->all();
npcink_abilities_toolkit_assert_true( isset( $filtered_comment_abilities['npcink-abilities-toolkit/get-comment-queue-health'] ), 'comment pack filter keeps queue helper ability' );
npcink_abilities_toolkit_assert_true( ! isset( $filtered_comment_abilities['npcink-abilities-toolkit/get-comment-compliance-handoff'] ), 'comment pack filter removes handoff helper ability' );
remove_all_filters( 'npcink_abilities_toolkit_enabled_comment_packs' );
foreach ( $migrated_write_ability_ids as $migrated_write_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $migrated_write_ability_id ] ), "core write package owns migrated {$migrated_write_ability_id} ability" );
	npcink_abilities_toolkit_assert_package_write_ability_contract( $migrated_write_ability_id, $package_abilities[ $migrated_write_ability_id ] );
}
foreach ( $priority_write_implementation_posture_ids as $posture_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $posture_ability_id ] ), "core write package owns priority implementation posture ability {$posture_ability_id}" );
	npcink_abilities_toolkit_assert_write_like_implementation_posture( $posture_ability_id, $package_abilities[ $posture_ability_id ] );
}
foreach ( $migrated_destructive_ability_ids as $migrated_destructive_ability_id ) {
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $migrated_destructive_ability_id ] ), "core destructive package owns migrated {$migrated_destructive_ability_id} ability" );
	npcink_abilities_toolkit_assert_package_destructive_ability_contract( $migrated_destructive_ability_id, $package_abilities[ $migrated_destructive_ability_id ] );
}
npcink_abilities_toolkit_assert_same(
	array( 'taxonomy', 'name' ),
	$package_abilities['npcink-abilities-toolkit/create-term']['input_schema']['required'] ?? array(),
	'create-term preserves migrated required taxonomy/name schema'
);
npcink_abilities_toolkit_assert_same(
	array( 'taxonomy', 'term_id' ),
	$package_abilities['npcink-abilities-toolkit/update-term']['input_schema']['required'] ?? array(),
	'update-term preserves migrated required taxonomy/term_id schema'
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
	501 => (object) array(
		'ID' => 501,
		'post_type' => 'post',
		'post_status' => 'draft',
		'post_title' => 'Original title',
		'post_content' => '<p>Original body marker.</p>',
		'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'original-title',
			'post_parent' => 0,
		),
	601 => (object) array(
		'ID' => 601,
		'post_type' => 'wp_template',
		'post_status' => 'publish',
		'post_title' => 'Single',
		'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
		'post_excerpt' => '',
		'post_author' => 7,
		'post_name' => 'single',
		'post_parent' => 0,
	),
	602 => (object) array(
		'ID' => 602,
		'post_type' => 'wp_template',
		'post_status' => 'publish',
		'post_title' => 'Page',
		'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
		'post_excerpt' => '',
		'post_author' => 7,
		'post_name' => 'page',
		'post_parent' => 0,
	),
	604 => (object) array(
		'ID' => 604,
		'post_type' => 'wp_template',
		'post_status' => 'publish',
		'post_title' => 'Front Page',
		'post_content' => '<!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"><!-- wp:paragraph {"className":"openclaw-breadcrumbs__trail"} --><p class="openclaw-breadcrumbs__trail">Home / Current item</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
		'post_excerpt' => '',
		'post_author' => 7,
		'post_name' => 'front-page',
		'post_parent' => 0,
	),
	603 => (object) array(
		'ID' => 603,
		'post_type' => 'wp_template_part',
		'post_status' => 'publish',
		'post_title' => 'Header',
		'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->',
		'post_excerpt' => '',
		'post_author' => 7,
		'post_name' => 'header',
		'post_parent' => 0,
	),
	);
	$GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'] = true;
	$GLOBALS['npcink_abilities_toolkit_unit_active_theme'] = array(
		'name'       => 'Unit Block Theme',
		'stylesheet' => 'unit-block-theme',
	);
npcink_abilities_toolkit_assert_same( 262144, $package_abilities['npcink-abilities-toolkit/create-draft']['input_schema']['properties']['content']['maxLength'] ?? null, 'create-draft bounds content input size' );
npcink_abilities_toolkit_assert_same( 262144, $package_abilities['npcink-abilities-toolkit/update-post']['input_schema']['properties']['content']['maxLength'] ?? null, 'update-post bounds content input size' );
npcink_abilities_toolkit_assert_same( 20, $package_abilities['npcink-abilities-toolkit/patch-post-content']['input_schema']['properties']['operations']['maxItems'] ?? null, 'patch-post-content bounds operation count' );
npcink_abilities_toolkit_assert_same( 262144, $package_abilities['npcink-abilities-toolkit/patch-post-content']['input_schema']['properties']['operations']['items']['properties']['replace']['maxLength'] ?? null, 'patch-post-content bounds replacement size' );
npcink_abilities_toolkit_assert_same( 200, $package_abilities['npcink-abilities-toolkit/update-post-blocks']['input_schema']['properties']['blocks']['maxItems'] ?? null, 'update-post-blocks bounds submitted block count' );
$create_preview = $core_write_package->create_draft(
	array(
		'title' => 'Preview title',
		'content' => 'Preview body',
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $create_preview['dry_run'] ?? null, 'create-draft defaults to governed dry-run preview when requested' );
npcink_abilities_toolkit_assert_same( 'create_draft', $create_preview['preview']['action'] ?? '', 'create-draft dry-run reports preview action' );
$oversized_create_preview = $core_write_package->create_draft(
	array(
		'title'   => 'Oversized preview',
		'content' => str_repeat( 'x', 262145 ),
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $oversized_create_preview ), 'create-draft rejects oversized content before preview generation' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_input_too_large', $oversized_create_preview->code ?? '', 'create-draft oversized content fails with stable code' );

	$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
	$posts_before_non_commit_write = count( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] );
	$non_commit_create = $core_write_package->create_draft(
		array(
			'title'   => 'Must Stay Preview',
			'content' => 'Explicitly disabling dry run cannot replace commit intent.',
			'dry_run' => false,
			'commit'  => false,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $non_commit_create['dry_run'] ?? null, 'create-draft keeps dry-run when commit is explicitly false' );
	npcink_abilities_toolkit_assert_same( $posts_before_non_commit_write, count( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ), 'create-draft does not mutate when commit is explicitly false' );
	$conflicting_write_controls = $core_write_package->create_draft(
		array(
			'title'   => 'Conflicting Controls',
			'content' => 'Dry-run wins when write controls conflict.',
			'dry_run' => true,
			'commit'  => true,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $conflicting_write_controls['dry_run'] ?? null, 'create-draft keeps dry-run when commit conflicts with dry_run=true' );
	npcink_abilities_toolkit_assert_same( $posts_before_non_commit_write, count( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ), 'create-draft does not mutate when write controls conflict' );
	$destructive_status_before_non_commit = (string) ( get_post( 501 )->post_status ?? '' );
	$non_commit_trash = $core_destructive_package->trash_post(
		array(
			'post_id' => 501,
			'dry_run' => false,
			'commit'  => false,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $non_commit_trash['dry_run'] ?? null, 'destructive package keeps dry-run when commit is explicitly false' );
	npcink_abilities_toolkit_assert_same( $destructive_status_before_non_commit, (string) ( get_post( 501 )->post_status ?? '' ), 'destructive package does not mutate when commit is explicitly false' );
	$created = $core_write_package->create_draft(
	array(
		'title' => 'Migrated Draft',
		'content' => "# Migrated Draft\n\n![Alt](https://example.test/image.jpg)\n\nBody text.",
		'content_format' => 'markdown',
		'commit' => true,
		'meta' => array( 'source' => 'unit' ),
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_same( false, $created['dry_run'] ?? null, 'create-draft commit returns a committed payload' );
npcink_abilities_toolkit_assert_same( 'markdown', $created['content_format'] ?? '', 'create-draft preserves migrated markdown content_format reporting' );
$created_post = get_post( (int) ( $created['post_id'] ?? 0 ) );
npcink_abilities_toolkit_assert_true( is_object( $created_post ), 'create-draft commit creates a draft post in the standalone package' );
npcink_abilities_toolkit_assert_true( false === strpos( (string) ( $created_post->post_content ?? '' ), '<h1>Migrated Draft</h1>' ), 'create-draft strips a duplicate leading title heading after migration' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $created_post->post_content ?? '' ), '<img src="https://example.test/image.jpg" alt="Alt" />' ), 'create-draft converts markdown image syntax after migration' );

$update_preview = $core_write_package->update_post(
	array(
		'post_id' => 501,
		'content' => "## Updated heading\n\nUpdated body.",
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $update_preview['dry_run'] ?? null, 'update-post returns a governed dry-run preview after migration' );
npcink_abilities_toolkit_assert_same( 'markdown', $update_preview['changes']['content']['content_format'] ?? '', 'update-post auto-detects markdown content after migration' );

$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_yoast_wpseo_title'] = 'Old SEO title';
$seo_preview = $core_write_package->set_post_seo_meta(
	array(
		'post_id' => 501,
		'seo_title' => 'New SEO title',
		'seo_description' => 'New SEO description',
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $seo_preview['dry_run'] ?? null, 'set-post-seo-meta returns a governed dry-run preview after migration' );
npcink_abilities_toolkit_assert_same( 'yoast', $seo_preview['provider'] ?? '', 'set-post-seo-meta detects existing Yoast-style SEO metadata after migration' );
$seo_missing_fields = $core_write_package->set_post_seo_meta(
	array(
		'post_id' => 501,
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $seo_missing_fields ), 'set-post-seo-meta rejects requests without explicit metadata fields' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_no_changes', $seo_missing_fields->code ?? '', 'set-post-seo-meta no-change request fails with a stable code' );
$seo_title_only_preview = $core_write_package->set_post_seo_meta(
	array(
		'post_id'   => 501,
		'seo_title' => 'Title-only preview',
		'dry_run'   => true,
	)
);
npcink_abilities_toolkit_assert_same( array( 'seo_title' ), $seo_title_only_preview['preview']['changed_fields'] ?? array(), 'set-post-seo-meta preview reports only explicit changed fields' );
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
$seo_written = $core_write_package->set_post_seo_meta(
	array(
		'post_id' => 501,
		'seo_title' => 'Committed SEO title',
		'seo_description' => 'Committed SEO description',
		'commit' => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_same( false, $seo_written['dry_run'] ?? null, 'set-post-seo-meta commit returns a committed payload after migration' );
npcink_abilities_toolkit_assert_same( 'Committed SEO title', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_yoast_wpseo_title'] ?? '', 'set-post-seo-meta writes SEO title through standalone fallback metadata keys' );
npcink_abilities_toolkit_assert_same( 'Committed SEO description', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_yoast_wpseo_metadesc'] ?? '', 'set-post-seo-meta writes SEO description through standalone fallback metadata keys' );
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
$seo_title_only_written = $core_write_package->set_post_seo_meta(
	array(
		'post_id'   => 501,
		'seo_title' => 'Only title changed',
		'commit'    => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_same( false, $seo_title_only_written['dry_run'] ?? null, 'set-post-seo-meta title-only commit returns committed payload' );
npcink_abilities_toolkit_assert_same( 'Only title changed', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_yoast_wpseo_title'] ?? '', 'set-post-seo-meta title-only commit writes title' );
npcink_abilities_toolkit_assert_same( 'Committed SEO description', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_yoast_wpseo_metadesc'] ?? '', 'set-post-seo-meta title-only commit preserves description' );
$article_audio_preview = $core_write_package->adopt_article_audio(
	array(
		'post_id'             => 501,
		'audio_url'           => 'https://cloud.example.test/audio/article-narration.mp3',
		'audio_title'         => 'Reviewed narration',
		'audio_kind'          => 'article_narration',
		'duration_seconds'    => 185.25,
		'mime_type'           => 'audio/mpeg',
		'source_content_hash' => 'sha256:article-source',
		'source_word_count'   => 1234,
		'source_generated_at' => '2026-06-24T09:30:00Z',
		'provider'            => 'minimax',
		'model'               => 'speech-2.8-turbo',
		'trace_id'            => 'trace_audio_456',
		'dry_run'             => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $article_audio_preview['dry_run'] ?? null, 'adopt-article-audio returns a governed dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $article_audio_preview['updated'] ?? null, 'adopt-article-audio dry-run does not update metadata' );
npcink_abilities_toolkit_assert_same( true, $article_audio_preview['preview']['no_content_write'] ?? null, 'adopt-article-audio preview records that content is untouched' );
npcink_abilities_toolkit_assert_same( true, in_array( '_npcink_toolbox_article_audio_url', $article_audio_preview['audio_meta_keys'] ?? array(), true ), 'adopt-article-audio previews the bounded article audio URL meta key' );
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
$article_audio_written = $core_write_package->adopt_article_audio(
	array(
		'post_id'             => 501,
		'audio_url'           => 'https://cloud.example.test/audio/article-narration.mp3',
		'audio_title'         => 'Reviewed narration',
		'audio_kind'          => 'article_narration',
		'duration_seconds'    => 185.25,
		'mime_type'           => 'audio/mpeg',
		'source_content_hash' => 'sha256:article-source',
		'source_word_count'   => 1234,
		'source_generated_at' => '2026-06-24T09:30:00Z',
		'provider'            => 'minimax',
		'model'               => 'speech-2.8-turbo',
		'trace_id'            => 'trace_audio_456',
		'commit'              => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_same( false, $article_audio_written['dry_run'] ?? null, 'adopt-article-audio commit returns a committed payload after approval' );
npcink_abilities_toolkit_assert_same( true, $article_audio_written['updated'] ?? null, 'adopt-article-audio commit reports metadata update' );
npcink_abilities_toolkit_assert_same( 'https://cloud.example.test/audio/article-narration.mp3', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_url'] ?? '', 'adopt-article-audio writes the article audio URL meta' );
npcink_abilities_toolkit_assert_same( 'article_narration', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_kind'] ?? '', 'adopt-article-audio writes the article audio kind meta' );
npcink_abilities_toolkit_assert_same( 'sha256:article-source', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_source_content_hash'] ?? '', 'adopt-article-audio writes freshness source hash meta' );
npcink_abilities_toolkit_assert_same( 'minimax', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_provider'] ?? '', 'adopt-article-audio writes provider evidence meta' );
$article_audio_upload_dir = sys_get_temp_dir() . '/npcink-abilities-toolkit-audio-import-' . getmypid();
$GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] = $article_audio_upload_dir;
$GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] = 'https://origin.example.test/wp-content/uploads';
$GLOBALS['npcink_abilities_toolkit_unit_http_responses'] = array(
	'https://cloud.example.test/audio/signed-runtime-url' => array(
		'status'       => 200,
		'content_type' => 'audio/mpeg',
		'body'         => str_repeat( 'a', 128 ),
	),
);
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
	$article_audio_imported = $core_write_package->adopt_article_audio(
	array(
		'post_id'             => 501,
		'audio_url'           => 'https://cloud.example.test/audio/signed-runtime-url',
		'audio_title'         => 'Imported narration',
		'audio_kind'          => 'article_narration',
		'duration_seconds'    => 45,
		'mime_type'           => 'audio/mpeg',
		'source_content_hash' => 'sha256:article-source-import',
		'source_word_count'   => 456,
		'source_generated_at' => '2026-06-24T10:00:00Z',
		'provider'            => 'minimax',
		'model'               => 'speech-2.8-turbo',
		'trace_id'            => 'trace_audio_import',
		'import_media'        => true,
		'media_file_name'     => 'reviewed-narration',
		'commit'              => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
unset( $GLOBALS['npcink_abilities_toolkit_unit_http_responses'] );
unset( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] );
unset( $GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $article_audio_imported ), 'adopt-article-audio imports media after approval' . ( is_wp_error( $article_audio_imported ) ? ': ' . $article_audio_imported->get_error_code() : '' ) );
npcink_abilities_toolkit_assert_same( 'wordpress_media_library', $article_audio_imported['storage_mode'] ?? '', 'adopt-article-audio import reports WordPress media library storage' );
npcink_abilities_toolkit_assert_true( (int) ( $article_audio_imported['attachment_id'] ?? 0 ) > 0, 'adopt-article-audio import creates an attachment' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $article_audio_imported['local_audio_url'] ?? '' ), 'reviewed-narration.mp3' ), 'adopt-article-audio import resolves a local audio URL with an audio extension' );
npcink_abilities_toolkit_assert_same( (string) ( $article_audio_imported['attachment_id'] ?? 0 ), (string) ( $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_attachment_id'] ?? '' ), 'adopt-article-audio import writes the local attachment id meta' );
npcink_abilities_toolkit_assert_same( $article_audio_imported['local_audio_url'] ?? '', $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][501]['_npcink_toolbox_article_audio_url'] ?? '', 'adopt-article-audio import switches playback URL to the local media URL' );
npcink_abilities_toolkit_assert_true( is_readable( $article_audio_upload_dir . '/reviewed-narration.mp3' ), 'adopt-article-audio import writes the audio file into uploads' );
$GLOBALS['npcink_abilities_toolkit_unit_comments'][11] = (object) array(
	'comment_ID'       => 11,
	'comment_post_ID'  => 77,
	'comment_author'   => 'Permission Fixture',
	'comment_approved' => 'hold',
	'comment_content'  => 'Pending moderation.',
);
$GLOBALS['npcink_abilities_toolkit_unit_current_user_caps'] = array( 'moderate_comments' => false );
$comment_permission_denied = $core_write_package->approve_comment(
	array(
		'comment_id' => 11,
		'dry_run'    => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_current_user_caps'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $comment_permission_denied ), 'approve-comment enforces moderate_comments before dry-run preview' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_permission_denied', $comment_permission_denied->code ?? '', 'approve-comment permission denial has stable error code' );

$patch_preview = $core_write_package->patch_post_content(
	array(
		'post_id' => 501,
		'operations' => array(
			array(
				'op' => 'replace',
				'find' => 'Original body marker',
				'replace' => 'Patched body marker',
			),
		),
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $patch_preview['dry_run'] ?? null, 'patch-post-content returns a governed dry-run preview after migration' );
npcink_abilities_toolkit_assert_same( 1, $patch_preview['patch_preview'][0]['applied'] ?? null, 'patch-post-content reports applied operation count after migration' );
$too_many_patch_operations = $core_write_package->patch_post_content(
	array(
		'post_id'    => 501,
		'operations' => array_fill(
			0,
			21,
			array(
				'op'      => 'replace',
				'find'    => 'Original body marker',
				'replace' => 'Patched body marker',
			)
		),
		'dry_run'    => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $too_many_patch_operations ), 'patch-post-content rejects oversized operation lists before diff generation' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_patch_operations_too_many', $too_many_patch_operations->code ?? '', 'patch-post-content oversized operation list fails with stable code' );
$original_patch_post_content = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][501]->post_content ?? '';
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][501]->post_content = str_repeat( 'x', 262145 );
$oversized_existing_post_patch = $core_write_package->patch_post_content(
	array(
		'post_id'    => 501,
		'operations' => array(
			array(
				'op'      => 'replace',
				'find'    => 'x',
				'replace' => 'y',
			),
		),
		'dry_run'    => true,
	)
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][501]->post_content = $original_patch_post_content;
npcink_abilities_toolkit_assert_true( is_wp_error( $oversized_existing_post_patch ), 'patch-post-content rejects oversized existing content before patching' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_input_too_large', $oversized_existing_post_patch->code ?? '', 'patch-post-content oversized existing content fails with stable code' );

$blocks_preview = $core_write_package->update_post_blocks(
	array(
		'post_id' => 501,
		'blocks' => array(
			array(
				'blockName' => 'core/paragraph',
				'innerHTML' => '<p>Block body.</p>',
			),
		),
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $blocks_preview['dry_run'] ?? null, 'update-post-blocks returns a governed dry-run preview after migration' );
npcink_abilities_toolkit_assert_same( true, $blocks_preview['validation']['valid'] ?? null, 'update-post-blocks validates serialized blocks after migration' );
$too_many_blocks = $core_write_package->update_post_blocks(
	array(
		'post_id' => 501,
		'blocks'  => array_fill(
			0,
			201,
			array(
				'blockName' => 'core/paragraph',
				'innerHTML' => '<p>Block body.</p>',
			)
		),
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $too_many_blocks ), 'update-post-blocks rejects block trees over the bounded block count' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_blocks_invalid', $too_many_blocks->code ?? '', 'update-post-blocks oversized block tree fails with stable code' );
npcink_abilities_toolkit_assert_same( 'block_count_exceeded', $too_many_blocks->data['errors'][0]['error'] ?? '', 'update-post-blocks reports block count overflow detail' );
$deep_block = array(
	'blockName'   => 'core/group',
	'innerHTML'   => '<div class="wp-block-group"></div>',
	'innerBlocks' => array(),
);
for ( $deep_index = 0; $deep_index < 9; ++$deep_index ) {
	$deep_block = array(
		'blockName'   => 'core/group',
		'innerHTML'   => '<div class="wp-block-group"></div>',
		'innerBlocks' => array( $deep_block ),
	);
}
$too_deep_blocks = $core_write_package->update_post_blocks(
	array(
		'post_id' => 501,
		'blocks'  => array( $deep_block ),
		'dry_run' => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $too_deep_blocks ), 'update-post-blocks rejects excessively deep block trees' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_blocks_invalid', $too_deep_blocks->code ?? '', 'update-post-blocks deep block tree fails with stable code' );

$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
$nested_blocks_written = $core_write_package->update_post_blocks(
	array(
		'post_id'            => 501,
		'validate_roundtrip' => false,
		'blocks'             => array(
			array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => array( '<div class="wp-block-group">', null, '</div>' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array(),
						'innerHTML'    => '<p>Nested body.</p>',
						'innerContent' => array( '<p>Nested body.</p>' ),
						'innerBlocks'  => array(),
					),
				),
			),
		),
		'commit'            => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_same( false, $nested_blocks_written['dry_run'] ?? null, 'update-post-blocks commit returns a committed payload for nested parsed blocks' );
	$nested_content = (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][501]->post_content ?? '' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $nested_content, '<div class="wp-block-group"><!-- wp:paragraph -->' ), 'update-post-blocks serializes innerBlocks at innerContent null markers' );
	npcink_abilities_toolkit_assert_true( false === strpos( $nested_content, '</div><!-- wp:paragraph -->' ), 'update-post-blocks does not append innerBlocks after the parent wrapper' );
	$block_theme_context = $core_read_package->get_block_theme_context( array() );
	npcink_abilities_toolkit_assert_same( true, $block_theme_context['is_block_theme'] ?? null, 'get-block-theme-context reports active block theme state' );
	npcink_abilities_toolkit_assert_same( 3, count( $block_theme_context['templates'] ?? array() ), 'get-block-theme-context lists available template entities including front-page' );
	npcink_abilities_toolkit_assert_same( 'posts', $block_theme_context['reading_settings']['show_on_front'] ?? '', 'get-block-theme-context exposes front page reading mode' );
	npcink_abilities_toolkit_assert_same( 'front-page', $block_theme_context['template_resolution']['front_page']['target_slug'] ?? '', 'get-block-theme-context exposes homepage template resolution' );
	npcink_abilities_toolkit_assert_true( ! empty( $block_theme_context['existing_overrides']['front-page']['content_hash'] ?? '' ), 'get-block-theme-context exposes existing template override hashes' );
	npcink_abilities_toolkit_assert_true( is_array( $block_theme_context['content_inventory']['candidate_cta_pages'] ?? null ), 'get-block-theme-context exposes CTA candidate inventory' );
	$template_blocks = $core_read_package->get_template_blocks( array( 'slug' => 'single' ) );
	npcink_abilities_toolkit_assert_same( 601, $template_blocks['post_id'] ?? 0, 'get-template-blocks resolves a template by slug' );
	npcink_abilities_toolkit_assert_true( ( $template_blocks['block_count'] ?? 0 ) > 0, 'get-template-blocks parses template blocks' );
	$template_surface_review = $core_read_package->review_block_editor_surface(
		array(
			'post_type' => 'wp_template',
			'slug'      => 'single',
		)
	);
	npcink_abilities_toolkit_assert_same( true, $template_surface_review['success'] ?? null, 'review-block-editor-surface reviews Site Editor templates by slug' );
	npcink_abilities_toolkit_assert_same( 'block_editor_surface_review', $template_surface_review['data']['artifact_type'] ?? '', 'review-block-editor-surface declares a generic block surface review artifact' );
	npcink_abilities_toolkit_assert_same( 'site_editor_template', $template_surface_review['data']['block_editor_surface']['surface_kind'] ?? '', 'review-block-editor-surface identifies template surfaces' );
	npcink_abilities_toolkit_assert_same( 'site_editor', $template_surface_review['data']['block_editor_surface']['editor'] ?? '', 'review-block-editor-surface reports Site Editor ownership' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-template-blocks', $template_surface_review['data']['block_editor_surface']['read_ability_id'] ?? '', 'review-block-editor-surface points template reads at get-template-blocks' );
	npcink_abilities_toolkit_assert_same( false, $template_surface_review['data']['direct_wordpress_write'] ?? null, 'review-block-editor-surface does not write Site Editor templates' );
	npcink_abilities_toolkit_assert_true( in_array( 'plan_site_editor_block_change', $template_surface_review['data']['next_actions'] ?? array(), true ), 'review-block-editor-surface recommends governed Site Editor planning for template changes' );
	$block_theme_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'single', 'page' ),
			'separator' => '>',
		)
	);
	npcink_abilities_toolkit_assert_same( true, $block_theme_plan['success'] ?? null, 'build-block-theme-site-plan returns a success envelope' );
	npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $block_theme_plan['data']['artifact_type'] ?? '', 'build-block-theme-site-plan declares block theme site artifact type' );
	npcink_abilities_toolkit_assert_same( 'site_editor_template', $block_theme_plan['data']['block_editor_surface']['surface_kind'] ?? '', 'build-block-theme-site-plan declares a Site Editor template surface' );
	npcink_abilities_toolkit_assert_same( 'site_editor', $block_theme_plan['data']['block_editor_surface']['editor'] ?? '', 'build-block-theme-site-plan declares the Site Editor owner' );
	npcink_abilities_toolkit_assert_same( array( 'wp_template' ), $block_theme_plan['data']['block_editor_surface']['post_types'] ?? array(), 'build-block-theme-site-plan declares template post types' );
	npcink_abilities_toolkit_assert_same( 'update_or_create_template_override', $block_theme_plan['data']['block_editor_surface']['target_mode'] ?? '', 'build-block-theme-site-plan reports template override target mode' );
	npcink_abilities_toolkit_assert_same( false, $block_theme_plan['data']['direct_wordpress_write'] ?? null, 'build-block-theme-site-plan does not directly write WordPress' );
	npcink_abilities_toolkit_assert_same( false, $block_theme_plan['data']['commit_execution'] ?? null, 'build-block-theme-site-plan keeps commit execution disabled' );
	npcink_abilities_toolkit_assert_same( 'site_editor_template_batch', $block_theme_plan['data']['block_editor_quality_gate']['profile'] ?? '', 'build-block-theme-site-plan exposes a Site Editor template batch quality gate' );
	npcink_abilities_toolkit_assert_same( true, $block_theme_plan['data']['block_editor_quality_gate']['ready_for_proposal'] ?? null, 'build-block-theme-site-plan marks reviewed template changes ready for proposal' );
	npcink_abilities_toolkit_assert_same( false, $block_theme_plan['data']['block_editor_quality_gate']['commit_execution'] ?? null, 'build-block-theme-site-plan quality gate does not execute commits' );
	npcink_abilities_toolkit_assert_same( 2, count( $block_theme_plan['data']['block_editor_reviews'] ?? array() ), 'build-block-theme-site-plan reviews each generated template block change' );
	$block_theme_actions = is_array( $block_theme_plan['data']['write_actions'] ?? null ) ? $block_theme_plan['data']['write_actions'] : array();
	npcink_abilities_toolkit_assert_same( 2, count( $block_theme_actions ), 'build-block-theme-site-plan emits one action per found template target' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-template-blocks', $block_theme_actions[0]['target_ability_id'] ?? '', 'block theme site plan targets template block writes' );
	npcink_abilities_toolkit_assert_same( 'core/template-part', $block_theme_actions[0]['input']['blocks'][0]['blockName'] ?? '', 'block theme site plan keeps the header template part first' );
	npcink_abilities_toolkit_assert_same( 'core/group', $block_theme_actions[0]['input']['blocks'][1]['blockName'] ?? '', 'block theme site plan keeps main content as the second top-level block' );
	npcink_abilities_toolkit_assert_same( 'main', $block_theme_actions[0]['input']['blocks'][1]['attrs']['tagName'] ?? '', 'block theme site plan targets the main content container' );
	npcink_abilities_toolkit_assert_same( 'openclaw-breadcrumbs', $block_theme_actions[0]['input']['blocks'][1]['innerBlocks'][0]['attrs']['className'] ?? '', 'block theme site plan inserts breadcrumbs inside main before the title' );
	npcink_abilities_toolkit_assert_same( 'core/post-title', $block_theme_actions[0]['input']['blocks'][1]['innerBlocks'][1]['blockName'] ?? '', 'block theme site plan keeps the post title after breadcrumbs' );
	npcink_abilities_toolkit_assert_same( 'before_post_title_in_main', $block_theme_plan['data']['preview'][0]['breadcrumb_placement']['strategy'] ?? '', 'block theme site plan reports semantic breadcrumb placement' );
	npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $block_theme_plan['data']['composition_contract']['catalog_id'] ?? '', 'build-block-theme-site-plan references the Gutenberg block capability catalog' );
	npcink_abilities_toolkit_assert_same( 'template', $block_theme_plan['data']['composition_contract']['surface'] ?? '', 'build-block-theme-site-plan scopes the block composition contract to templates' );
	npcink_abilities_toolkit_assert_same( 'pass', $block_theme_plan['data']['composition_contract']['contract_status'] ?? '', 'build-block-theme-site-plan passes the block composition contract' );
	npcink_abilities_toolkit_assert_same( 'bounded_template_anchor_placement', $block_theme_plan['data']['template_placement_contract']['placement_model'] ?? '', 'build-block-theme-site-plan uses bounded template placement standards' );
	npcink_abilities_toolkit_assert_same( 'pass', $block_theme_plan['data']['template_placement_contract']['contract_status'] ?? '', 'build-block-theme-site-plan passes the template placement contract' );
	npcink_abilities_toolkit_assert_same( 'core/post-title', $block_theme_plan['data']['template_placement_contract']['placements'][0]['inserted_before'] ?? '', 'build-block-theme-site-plan records the approved title anchor in the placement contract' );
	npcink_abilities_toolkit_assert_same( true, $block_theme_plan['data']['preview'][0]['block_editor_quality_gate']['ready_for_proposal'] ?? null, 'block theme site plan preview carries the per-template quality gate' );
	$template_layout_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'customize_template_layout',
			'target_templates' => array( 'single' ),
			'layout_profile'   => 'article_standard',
		)
	);
	$template_layout_actions = is_array( $template_layout_plan['data']['write_actions'] ?? null ) ? $template_layout_plan['data']['write_actions'] : array();
	$template_layout_blocks_json = wp_json_encode( $template_layout_actions[0]['input']['blocks'] ?? array() );
	$template_layout_blocks_json = is_string( $template_layout_blocks_json ) ? $template_layout_blocks_json : '';
	npcink_abilities_toolkit_assert_same( true, $template_layout_plan['success'] ?? null, 'build-block-theme-site-plan accepts bounded template layout intent' );
	npcink_abilities_toolkit_assert_same( 'customize_template_layout', $template_layout_plan['data']['intent'] ?? '', 'template layout plan reports its intent' );
	npcink_abilities_toolkit_assert_same( 'block_theme_template_layout_plan', $template_layout_plan['data']['composition_role'] ?? '', 'template layout plan declares a layout composition role' );
	npcink_abilities_toolkit_assert_same( 1, count( $template_layout_actions ), 'template layout plan emits one action for the requested template' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-template-blocks', $template_layout_actions[0]['target_ability_id'] ?? '', 'template layout plan targets template block writes' );
	npcink_abilities_toolkit_assert_same( 'core/template-part', $template_layout_actions[0]['input']['blocks'][0]['blockName'] ?? '', 'template layout plan keeps the header template part first' );
	npcink_abilities_toolkit_assert_same( 'main', $template_layout_actions[0]['input']['blocks'][1]['attrs']['tagName'] ?? '', 'template layout plan places the bounded profile inside main' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-title' ), 'template layout plan includes the post title block' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-author-name' ), 'template layout plan includes the author block' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-date' ), 'template layout plan includes the post date block' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-terms' ), 'template layout plan includes taxonomy term blocks' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-featured-image' ), 'template layout plan includes the featured image block' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-content' ), 'template layout plan includes the post content slot' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'post-navigation-link' ), 'template layout plan includes previous/next post navigation' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'core\\/comments' ), 'template layout plan includes the comments block' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'latest-posts' ), 'template layout plan includes related/latest posts' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, '#FBFAF3' ), 'template layout plan gives article title and navigation native background styles' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, '#F1F5F9' ), 'template layout plan gives related posts a distinct native background style' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $template_layout_blocks_json, 'var(--wp--preset--spacing--50)' ), 'template layout plan serializes preset spacing tokens as valid CSS variables in static markup' );
	npcink_abilities_toolkit_assert_true( false === strpos( $template_layout_blocks_json, 'core\\/html' ), 'template layout plan does not emit raw HTML blocks' );
	npcink_abilities_toolkit_assert_same( 'pass', $template_layout_plan['data']['composition_contract']['contract_status'] ?? '', 'template layout plan passes the block composition contract' );
	npcink_abilities_toolkit_assert_same( 'bounded_template_layout_profile', $template_layout_plan['data']['template_layout_contract']['placement_model'] ?? '', 'template layout plan reports bounded layout profile contract' );
	npcink_abilities_toolkit_assert_same( 'block_theme_profile_compiler@0.3', $template_layout_plan['data']['template_layout_contract']['compiler_version'] ?? '', 'template layout plan reports the bounded profile compiler version' );
	npcink_abilities_toolkit_assert_same( 'block_theme_safe_core_blocks@0.2', $template_layout_plan['data']['template_layout_contract']['forbidden_policy_version'] ?? '', 'template layout plan reports the safe core block policy version' );
	npcink_abilities_toolkit_assert_true( in_array( 'article_standard@0.4', $template_layout_plan['data']['template_layout_contract']['accepted_profile_versions'] ?? array(), true ), 'template layout contract accepts article_standard@0.4' );
	npcink_abilities_toolkit_assert_true( in_array( 'page_standard@0.2', $template_layout_plan['data']['template_layout_contract']['accepted_profile_versions'] ?? array(), true ), 'template layout contract accepts page_standard@0.2' );
	npcink_abilities_toolkit_assert_true( in_array( 'homepage_landing@0.3', $template_layout_plan['data']['template_layout_contract']['accepted_profile_versions'] ?? array(), true ), 'template layout contract accepts homepage_landing@0.3' );
	npcink_abilities_toolkit_assert_same( 'pass', $template_layout_plan['data']['template_layout_contract']['contract_status'] ?? '', 'template layout plan passes the layout profile contract' );
	npcink_abilities_toolkit_assert_same( 'article_standard@0.4', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['profile_version'] ?? '', 'template layout profile row records article_standard@0.4' );
	npcink_abilities_toolkit_assert_same( 'replace_template_layout_with_preserved_template_parts', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['operation'] ?? '', 'template layout profile row declares the accepted Core intake operation' );
	npcink_abilities_toolkit_assert_true( in_array( 'post_navigation', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['modules'] ?? array(), true ), 'template layout profile row declares post navigation as a module' );
	npcink_abilities_toolkit_assert_true( in_array( 'comments', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['modules'] ?? array(), true ), 'template layout profile row declares comments as a module' );
	npcink_abilities_toolkit_assert_true( in_array( 'core/post-navigation-link', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['allowed_blocks'] ?? array(), true ), 'template layout profile row allows post navigation blocks' );
	npcink_abilities_toolkit_assert_true( in_array( 'core/comments', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['allowed_blocks'] ?? array(), true ), 'template layout profile row allows comments blocks' );
	npcink_abilities_toolkit_assert_true( in_array( 'theme_json', $template_layout_plan['data']['template_layout_contract']['profiles'][0]['forbidden_outputs'] ?? array(), true ), 'template layout profile row preserves forbidden output policy' );
	npcink_abilities_toolkit_assert_same( 'article_standard', $template_layout_plan['data']['preview'][0]['layout_profile'] ?? '', 'template layout plan preview records the article profile' );
	$template_layout_finding_codes = $template_layout_plan['data']['preview'][0]['block_editor_quality_gate']['finding_codes'] ?? array();
	npcink_abilities_toolkit_assert_true( ! in_array( 'hero_media_missing', $template_layout_finding_codes, true ), 'article template quality gate omits landing-only hero media finding' );
	npcink_abilities_toolkit_assert_true( ! in_array( 'bento_grid_missing', $template_layout_finding_codes, true ), 'article template quality gate omits landing-only bento finding' );
	npcink_abilities_toolkit_assert_true( ! in_array( 'faq_missing', $template_layout_finding_codes, true ), 'article template quality gate omits landing-only FAQ finding' );
	npcink_abilities_toolkit_assert_true( ! in_array( 'final_cta_missing', $template_layout_finding_codes, true ), 'article template quality gate omits landing-only final CTA finding' );
	npcink_abilities_toolkit_assert_same( true, $template_layout_plan['data']['preview'][0]['block_editor_quality_gate']['ready_for_proposal'] ?? null, 'template layout plan preview carries a passing per-template quality gate' );
	$page_layout_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'customize_template_layout',
			'target_templates' => array( 'page' ),
			'layout_profile'   => 'page_standard',
		)
	);
	$page_layout_actions = is_array( $page_layout_plan['data']['write_actions'] ?? null ) ? $page_layout_plan['data']['write_actions'] : array();
	$page_layout_blocks_json = wp_json_encode( $page_layout_actions[0]['input']['blocks'] ?? array() );
	$page_layout_blocks_json = is_string( $page_layout_blocks_json ) ? $page_layout_blocks_json : '';
	npcink_abilities_toolkit_assert_same( true, $page_layout_plan['success'] ?? null, 'page standard template layout plan returns a success envelope' );
	npcink_abilities_toolkit_assert_same( 'page_standard@0.2', $page_layout_plan['data']['template_layout_contract']['profiles'][0]['profile_version'] ?? '', 'page standard profile row records page_standard@0.2' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $page_layout_blocks_json, 'openclaw-template-page-title-band' ), 'page standard layout includes a title band class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $page_layout_blocks_json, 'openclaw-template-media-band' ), 'page standard layout includes a media band class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $page_layout_blocks_json, 'openclaw-template-page-content-panel' ), 'page standard layout includes a content panel class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $page_layout_blocks_json, '#F7F8EF' ), 'page standard layout gives the title band a native background style' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $page_layout_blocks_json, '#111827' ), 'page standard layout gives the media band a contrast background style' );
	$homepage_unresolved_cta_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'customize_template_layout',
			'target_templates' => array( 'front-page' ),
			'layout_profile'   => 'homepage_landing',
			'include_cta'      => true,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $homepage_unresolved_cta_plan['success'] ?? null, 'homepage layout plan still returns a reviewable envelope when CTA cannot be resolved' );
	npcink_abilities_toolkit_assert_same( 0, count( $homepage_unresolved_cta_plan['data']['write_actions'] ?? array() ), 'homepage layout plan emits no write action when CTA link is unresolved' );
	npcink_abilities_toolkit_assert_same( 'cta_link_unresolved', $homepage_unresolved_cta_plan['data']['warnings'][0]['reason'] ?? '', 'homepage layout plan reports unresolved CTA links' );
	npcink_abilities_toolkit_assert_same( 'cta_link_unresolved', $homepage_unresolved_cta_plan['data']['preview'][0]['no_change_reason'] ?? '', 'homepage layout preview explains unresolved CTA blocking' );
	npcink_abilities_toolkit_assert_same( false, $homepage_unresolved_cta_plan['data']['preview'][0]['block_editor_quality_gate']['ready_for_proposal'] ?? true, 'homepage layout preview is not proposal-ready without a CTA URL' );
	npcink_abilities_toolkit_assert_same( 'resolve_cta_link_before_proposal', $homepage_unresolved_cta_plan['data']['preview'][0]['block_editor_quality_gate']['recommended_next_step'] ?? '', 'homepage layout preview asks for CTA resolution before proposal' );
	$homepage_layout_fixture_posts  = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'];
	$homepage_layout_fixture_options = $GLOBALS['npcink_abilities_toolkit_unit_options'] ?? array();
	$GLOBALS['npcink_abilities_toolkit_unit_options'] = array(
		'show_on_front'  => 'posts',
		'page_for_posts' => 702,
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		701 => (object) array(
			'ID' => 701,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Front Page',
			'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'front-page',
			'post_parent' => 0,
		),
		702 => (object) array(
			'ID' => 702,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Blog',
			'post_content' => 'Blog page.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'blog',
			'post_parent' => 0,
		),
		703 => (object) array(
			'ID' => 703,
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => 'Published Post',
			'post_content' => 'Published post.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'published-post',
			'post_parent' => 0,
		),
	);
	$homepage_posts_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'customize_template_layout',
			'target_templates' => array( 'front-page' ),
			'layout_profile'   => 'homepage_landing',
		)
	);
	$homepage_posts_actions = is_array( $homepage_posts_plan['data']['write_actions'] ?? null ) ? $homepage_posts_plan['data']['write_actions'] : array();
	$homepage_posts_blocks_json = wp_json_encode( $homepage_posts_actions[0]['input']['blocks'] ?? array() );
	$homepage_posts_blocks_json = is_string( $homepage_posts_blocks_json ) ? $homepage_posts_blocks_json : '';
	npcink_abilities_toolkit_assert_same( 1, count( $homepage_posts_actions ), 'homepage posts-front layout emits one write action when CTA resolves to the posts page' );
	npcink_abilities_toolkit_assert_same( 'resolved', $homepage_posts_plan['data']['preview'][0]['cta_resolution']['status'] ?? '', 'homepage posts-front layout resolves CTA from site context' );
	npcink_abilities_toolkit_assert_same( 'posts_page', $homepage_posts_plan['data']['preview'][0]['cta_resolution']['source'] ?? '', 'homepage posts-front layout prefers the configured posts page for CTA' );
	npcink_abilities_toolkit_assert_same( false, $homepage_posts_plan['data']['preview'][0]['page_content_enabled'] ?? true, 'homepage posts-front layout does not include static page content' );
	npcink_abilities_toolkit_assert_true( ! in_array( 'post_content', $homepage_posts_plan['data']['preview'][0]['layout_sections'] ?? array(), true ), 'homepage posts-front layout sections omit post_content' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_posts_blocks_json, 'blog' ), 'homepage posts-front layout points CTA at the resolved blog page' );
	npcink_abilities_toolkit_assert_true( false === strpos( $homepage_posts_blocks_json, 'core\\/post-content' ), 'homepage posts-front layout omits core/post-content' );
	$GLOBALS['npcink_abilities_toolkit_unit_options'] = array(
		'show_on_front' => 'page',
		'page_on_front' => 704,
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		701 => $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][701],
		704 => (object) array(
			'ID' => 704,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Front Page Fixture',
			'post_content' => 'Static front page.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'front-page-fixture',
			'post_parent' => 0,
		),
		705 => (object) array(
			'ID' => 705,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Contact',
			'post_content' => 'Contact page.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'contact',
			'post_parent' => 0,
		),
	);
	$homepage_static_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'customize_template_layout',
			'target_templates' => array( 'front-page' ),
			'layout_profile'   => 'homepage_landing',
		)
	);
	$homepage_static_actions = is_array( $homepage_static_plan['data']['write_actions'] ?? null ) ? $homepage_static_plan['data']['write_actions'] : array();
	$homepage_static_blocks_json = wp_json_encode( $homepage_static_actions[0]['input']['blocks'] ?? array() );
	$homepage_static_blocks_json = is_string( $homepage_static_blocks_json ) ? $homepage_static_blocks_json : '';
	npcink_abilities_toolkit_assert_same( 1, count( $homepage_static_actions ), 'homepage static-front layout emits one write action when CTA resolves to a candidate page' );
	npcink_abilities_toolkit_assert_same( true, $homepage_static_plan['data']['preview'][0]['page_content_enabled'] ?? false, 'homepage static-front layout includes static page content' );
	npcink_abilities_toolkit_assert_true( in_array( 'post_content', $homepage_static_plan['data']['preview'][0]['layout_sections'] ?? array(), true ), 'homepage static-front layout sections include post_content' );
	npcink_abilities_toolkit_assert_same( 'slug_match_contact', $homepage_static_plan['data']['preview'][0]['cta_resolution']['source'] ?? '', 'homepage static-front layout resolves CTA to the contact page' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, 'core\\/post-content' ), 'homepage static-front layout includes core/post-content' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, 'contact' ), 'homepage static-front layout points CTA at the contact page' );
	npcink_abilities_toolkit_assert_same( 'homepage_landing@0.3', $homepage_static_plan['data']['template_layout_contract']['profiles'][0]['profile_version'] ?? '', 'homepage landing profile row records homepage_landing@0.3' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, 'openclaw-home-hero' ), 'homepage landing layout preserves hero section class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, 'openclaw-home-latest-posts' ), 'homepage landing layout preserves latest posts section class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, 'openclaw-home-categories' ), 'homepage landing layout preserves categories section class for visual review' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, '#0F172A' ), 'homepage landing layout gives the hero a contrast background style' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, '#EEF6F1' ), 'homepage landing layout gives category links a distinct native background style' );
	npcink_abilities_toolkit_assert_true( false !== strpos( $homepage_static_blocks_json, '#FFF7ED' ), 'homepage landing layout gives page content a distinct native background style' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $homepage_layout_fixture_posts;
	$GLOBALS['npcink_abilities_toolkit_unit_options']     = $homepage_layout_fixture_options;
	$valid_page_template_posts_fixture = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'];
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		608 => (object) array(
			'ID' => 608,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Page',
			'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph /--><!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"></div><!-- /wp:group --><!-- wp:post-title /--><!-- wp:post-content /--></div><!-- /wp:group --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'page',
			'post_parent' => 0,
		),
	);
	$valid_page_template_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'page' ),
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $valid_page_template_posts_fixture;
	npcink_abilities_toolkit_assert_same( true, $valid_page_template_plan['success'] ?? null, 'build-block-theme-site-plan accepts already-valid nested page breadcrumb placement' );
	npcink_abilities_toolkit_assert_same( 0, count( $valid_page_template_plan['data']['write_actions'] ?? array() ), 'block theme site plan emits no action when page breadcrumbs are already before the title in main' );
	npcink_abilities_toolkit_assert_same( false, $valid_page_template_plan['data']['preview'][0]['requires_write'] ?? true, 'block theme site plan marks already-valid page templates as no-write previews' );
	npcink_abilities_toolkit_assert_same( 'already_valid', $valid_page_template_plan['data']['preview'][0]['breadcrumb_placement']['status'] ?? '', 'block theme site plan reports already-valid page breadcrumb placement' );
	npcink_abilities_toolkit_assert_same( 'breadcrumbs_already_before_post_title', $valid_page_template_plan['data']['preview'][0]['no_change_reason'] ?? '', 'block theme site plan explains no-write page plans' );
	npcink_abilities_toolkit_assert_same( 'no_changes_required', $valid_page_template_plan['data']['block_editor_quality_gate']['recommended_next_step'] ?? '', 'block theme site plan batch gate reports no changes when every target is already valid' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		608 => (object) array(
			'ID' => 608,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Page',
			'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph /--><!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"></div><!-- /wp:group --><!-- wp:post-title /--><!-- wp:post-content /--></div><!-- /wp:group --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'page',
			'post_parent' => 0,
		),
	);
	$valid_page_surface_inspection = $core_read_package->inspect_block_theme_surface(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'page' ),
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		609 => (object) array(
			'ID' => 609,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Page',
			'post_content' => '<!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"></div><!-- /wp:group --><!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'page',
			'post_parent' => 0,
		),
	);
	$misplaced_page_surface_inspection = $core_read_package->inspect_block_theme_surface(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'page' ),
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $valid_page_template_posts_fixture;
	npcink_abilities_toolkit_assert_same( true, $valid_page_surface_inspection['success'] ?? null, 'inspect-block-theme-surface returns a success envelope for valid templates' );
	npcink_abilities_toolkit_assert_same( 'block_theme_surface_inspection', $valid_page_surface_inspection['data']['artifact_type'] ?? '', 'block theme surface inspection declares its artifact type' );
	npcink_abilities_toolkit_assert_same( 2, $valid_page_surface_inspection['data']['review_contract']['reviewer_count'] ?? 0, 'block theme surface inspection exposes a two-reviewer contract' );
	npcink_abilities_toolkit_assert_same( array(), $valid_page_surface_inspection['data']['templates'][0]['issue_codes'] ?? array( 'unexpected' ), 'block theme surface inspection reports no issues for valid breadcrumb placement' );
	npcink_abilities_toolkit_assert_same( 'no_changes_required', $valid_page_surface_inspection['data']['dual_review']['consensus']['recommended_next_step'] ?? '', 'block theme surface inspection consensus reports no changes for valid templates' );
	npcink_abilities_toolkit_assert_same( array(), $valid_page_surface_inspection['data']['recommended_plan_input'] ?? array( 'unexpected' ), 'block theme surface inspection does not recommend a plan for valid templates' );
	npcink_abilities_toolkit_assert_same( true, $misplaced_page_surface_inspection['success'] ?? null, 'inspect-block-theme-surface returns a success envelope for misplaced templates' );
	npcink_abilities_toolkit_assert_true( in_array( 'breadcrumb_above_header', $misplaced_page_surface_inspection['data']['templates'][0]['issue_codes'] ?? array(), true ), 'block theme surface inspection detects breadcrumbs above the header' );
	npcink_abilities_toolkit_assert_true( in_array( 'breadcrumb_not_before_title', $misplaced_page_surface_inspection['data']['templates'][0]['issue_codes'] ?? array(), true ), 'block theme surface inspection detects breadcrumbs not before the post title' );
	npcink_abilities_toolkit_assert_same( 'build_block_theme_site_plan', $misplaced_page_surface_inspection['data']['dual_review']['consensus']['recommended_next_step'] ?? '', 'block theme surface inspection consensus recommends a minimal plan for fixable issues' );
	npcink_abilities_toolkit_assert_same( array( 'page' ), $misplaced_page_surface_inspection['data']['recommended_plan_input']['target_templates'] ?? array(), 'block theme surface inspection recommends only affected templates for planning' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		610 => (object) array(
			'ID' => 610,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Archive',
			'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:query /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'archive',
			'post_parent' => 0,
		),
	);
	$archive_surface_inspection = $core_read_package->inspect_block_theme_surface(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'archive' ),
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $valid_page_template_posts_fixture;
	npcink_abilities_toolkit_assert_same( true, $archive_surface_inspection['success'] ?? null, 'inspect-block-theme-surface can inspect archive templates' );
	npcink_abilities_toolkit_assert_true( in_array( 'breadcrumb_missing', $archive_surface_inspection['data']['templates'][0]['issue_codes'] ?? array(), true ), 'block theme surface inspection reports archive breadcrumb issues' );
	npcink_abilities_toolkit_assert_same( 'blocked', $archive_surface_inspection['data']['templates'][0]['status'] ?? '', 'block theme surface inspection blocks archive template planning handoff' );
	npcink_abilities_toolkit_assert_same( array(), $archive_surface_inspection['data']['templates'][0]['fixable_issue_codes'] ?? array( 'unexpected' ), 'block theme surface inspection does not mark archive issues as planner-fixable' );
	npcink_abilities_toolkit_assert_same( '', $archive_surface_inspection['data']['recommended_plan_ability_id'] ?? 'unexpected', 'block theme surface inspection does not recommend a plan ability for archive targets' );
	npcink_abilities_toolkit_assert_same( array(), $archive_surface_inspection['data']['recommended_plan_input'] ?? array( 'unexpected' ), 'block theme surface inspection does not recommend archive plan input' );
	npcink_abilities_toolkit_assert_same( 'template_plan_target_not_supported', $archive_surface_inspection['data']['warnings'][0]['reason'] ?? '', 'block theme surface inspection explains archive planning is unsupported' );
	$front_page_breadcrumb_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'front-page' ),
			'show_on_home_page' => false,
		)
	);
	$front_page_breadcrumb_actions = is_array( $front_page_breadcrumb_plan['data']['write_actions'] ?? null ) ? $front_page_breadcrumb_plan['data']['write_actions'] : array();
	npcink_abilities_toolkit_assert_same( true, $front_page_breadcrumb_plan['success'] ?? null, 'build-block-theme-site-plan accepts front-page templates' );
	npcink_abilities_toolkit_assert_same( 'removed', $front_page_breadcrumb_plan['data']['preview'][0]['breadcrumb_placement']['status'] ?? '', 'block theme site plan removes existing breadcrumbs from front-page when homepage breadcrumbs are disabled' );
	npcink_abilities_toolkit_assert_same( 'home_page_disabled', $front_page_breadcrumb_plan['data']['preview'][0]['breadcrumb_placement']['strategy'] ?? '', 'block theme site plan reports disabled homepage breadcrumb placement' );
	npcink_abilities_toolkit_assert_same( 'core/template-part', $front_page_breadcrumb_actions[0]['input']['blocks'][0]['blockName'] ?? '', 'block theme site plan removes misplaced front-page breadcrumbs before the header' );
	npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $front_page_breadcrumb_actions[0]['input']['blocks'] ?? array() ), 'openclaw-breadcrumbs' ), 'block theme site plan does not leave breadcrumbs in front-page blocks when disabled' );
	$home_template_style_posts_fixture = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'];
	$home_template_options_fixture     = $GLOBALS['npcink_abilities_toolkit_unit_options'] ?? array();
	$GLOBALS['npcink_abilities_toolkit_unit_options']['show_on_front'] = 'page';
	$GLOBALS['npcink_abilities_toolkit_unit_options']['page_on_front'] = 700;
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		607 => (object) array(
			'ID' => 607,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Page',
			'post_content' => '<!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"><!-- wp:paragraph {"className":"openclaw-breadcrumbs__trail"} --><p class="openclaw-breadcrumbs__trail">Home / Current item</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'page',
			'post_parent' => 0,
		),
		700 => (object) array(
			'ID' => 700,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Front Page Fixture',
			'post_content' => 'Static front page content.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'front-page-fixture',
			'post_parent' => 0,
		),
	);
	$front_page_fallback_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'front-page' ),
			'show_on_home_page' => false,
		)
	);
	$front_page_fallback_actions = is_array( $front_page_fallback_plan['data']['write_actions'] ?? null ) ? $front_page_fallback_plan['data']['write_actions'] : array();
	npcink_abilities_toolkit_assert_same( true, $front_page_fallback_plan['success'] ?? null, 'build-block-theme-site-plan resolves a missing front-page template from the actual static homepage template stack' );
	npcink_abilities_toolkit_assert_same( 1, count( $front_page_fallback_actions ), 'block theme site plan emits one action for resolved front-page fallback templates' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/upsert-template-blocks', $front_page_fallback_actions[0]['target_ability_id'] ?? '', 'block theme site plan creates a front-page override when the concrete front-page template is missing' );
	npcink_abilities_toolkit_assert_same( 'front-page', $front_page_fallback_actions[0]['input']['slug'] ?? '', 'block theme site plan keeps the requested front-page target slug for fallback plans' );
	npcink_abilities_toolkit_assert_same( 'unit-block-theme//page', $front_page_fallback_actions[0]['input']['source_template_id'] ?? '', 'block theme site plan records the source page template id for front-page fallback plans' );
	npcink_abilities_toolkit_assert_same( 'front-page', $front_page_fallback_plan['data']['preview'][0]['template_resolution']['requested_slug'] ?? '', 'block theme site plan reports the requested homepage target slug' );
	npcink_abilities_toolkit_assert_same( 'page', $front_page_fallback_plan['data']['preview'][0]['template_resolution']['source_slug'] ?? '', 'block theme site plan reports the source template slug used for homepage fallback' );
	npcink_abilities_toolkit_assert_same( 'static_front_page_page_template_fallback', $front_page_fallback_plan['data']['preview'][0]['template_resolution']['strategy'] ?? '', 'block theme site plan reports the static homepage page-template fallback strategy' );
	npcink_abilities_toolkit_assert_same( true, $front_page_fallback_plan['data']['preview'][0]['template_resolution']['creates_template_override'] ?? null, 'block theme site plan reports that homepage fallback creates a template override' );
	npcink_abilities_toolkit_assert_same( 'removed', $front_page_fallback_plan['data']['preview'][0]['breadcrumb_placement']['status'] ?? '', 'block theme site plan removes inherited breadcrumbs from disabled homepage fallback plans' );
	npcink_abilities_toolkit_assert_true( false === strpos( wp_json_encode( $front_page_fallback_actions[0]['input']['blocks'] ?? array() ), 'openclaw-breadcrumbs' ), 'block theme site plan does not carry breadcrumbs into disabled homepage fallback blocks' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		607 => (object) array(
			'ID' => 607,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Page',
			'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'page',
			'post_parent' => 0,
		),
		700 => (object) array(
			'ID' => 700,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Front Page Fixture',
			'post_content' => 'Static front page content.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'front-page-fixture',
			'post_parent' => 0,
		),
	);
	$front_page_fallback_noop_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'front-page' ),
			'show_on_home_page' => false,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $front_page_fallback_noop_plan['success'] ?? null, 'build-block-theme-site-plan resolves no-op front-page fallback plans' );
	npcink_abilities_toolkit_assert_same( 0, count( $front_page_fallback_noop_plan['data']['write_actions'] ?? array() ), 'block theme site plan does not create a front-page override when homepage breadcrumbs are already absent' );
	npcink_abilities_toolkit_assert_same( false, $front_page_fallback_noop_plan['data']['preview'][0]['requires_write'] ?? true, 'block theme site plan marks absent homepage breadcrumbs as a no-write preview' );
	npcink_abilities_toolkit_assert_same( 'homepage_breadcrumbs_already_absent', $front_page_fallback_noop_plan['data']['preview'][0]['no_change_reason'] ?? '', 'block theme site plan explains no-write front-page fallback plans' );
	npcink_abilities_toolkit_assert_same( false, $front_page_fallback_noop_plan['data']['preview'][0]['creates_template_override'] ?? true, 'block theme site plan does not claim to create a front-page override for no-write fallback plans' );
	npcink_abilities_toolkit_assert_same( 'no_changes_required', $front_page_fallback_noop_plan['data']['block_editor_quality_gate']['recommended_next_step'] ?? '', 'block theme site plan batch gate reports no changes for no-op homepage fallback plans' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		700 => (object) array(
			'ID' => 700,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Front Page Fixture',
			'post_content' => 'Static front page content.',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'front-page-fixture',
			'post_parent' => 0,
		),
	);
	$front_page_unresolved_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'front-page' ),
			'show_on_home_page' => false,
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $home_template_style_posts_fixture;
	$GLOBALS['npcink_abilities_toolkit_unit_options']     = $home_template_options_fixture;
	npcink_abilities_toolkit_assert_same( true, $front_page_unresolved_plan['success'] ?? null, 'build-block-theme-site-plan still returns a plan envelope when homepage template resolution fails' );
	npcink_abilities_toolkit_assert_same( 0, count( $front_page_unresolved_plan['data']['write_actions'] ?? array() ), 'block theme site plan emits no write actions when no homepage template fallback exists' );
	npcink_abilities_toolkit_assert_same( 'template_not_found', $front_page_unresolved_plan['data']['warnings'][0]['reason'] ?? '', 'block theme site plan reports template_not_found when homepage template resolution has no source' );
	npcink_abilities_toolkit_assert_same( 'home_template_unresolved', $front_page_unresolved_plan['data']['warnings'][0]['template_resolution']['strategy'] ?? '', 'block theme site plan exposes unresolved homepage template resolution metadata' );
	$block_theme_style_posts_fixture = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'];
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		606 => (object) array(
			'ID' => 606,
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'post_title' => 'Single',
			'post_content' => '<!-- wp:group {"className":"openclaw-breadcrumbs"} --><div class="wp-block-group openclaw-breadcrumbs"><!-- wp:paragraph {"className":"openclaw-breadcrumbs__trail"} --><p class="openclaw-breadcrumbs__trail">Home / Current item</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group -->',
			'post_excerpt' => '',
			'post_author' => 7,
			'post_name' => 'single',
		),
	);
	$relocated_breadcrumb_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent' => 'add_breadcrumbs',
			'target_templates' => array( 'single' ),
		)
	);
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $block_theme_style_posts_fixture;
	$relocated_breadcrumb_actions = is_array( $relocated_breadcrumb_plan['data']['write_actions'] ?? null ) ? $relocated_breadcrumb_plan['data']['write_actions'] : array();
	npcink_abilities_toolkit_assert_same( true, $relocated_breadcrumb_plan['success'] ?? null, 'build-block-theme-site-plan accepts a template with misplaced breadcrumbs' );
	npcink_abilities_toolkit_assert_same( 'relocated', $relocated_breadcrumb_plan['data']['preview'][0]['breadcrumb_placement']['status'] ?? '', 'block theme site plan reports misplaced breadcrumbs as relocated' );
	npcink_abilities_toolkit_assert_same( 'before_post_title_in_main', $relocated_breadcrumb_plan['data']['preview'][0]['breadcrumb_placement']['strategy'] ?? '', 'block theme site plan relocates misplaced breadcrumbs before the title in main' );
	npcink_abilities_toolkit_assert_same( 'core/template-part', $relocated_breadcrumb_actions[0]['input']['blocks'][0]['blockName'] ?? '', 'block theme site plan removes misplaced top-level breadcrumbs before the header' );
	npcink_abilities_toolkit_assert_same( 'openclaw-breadcrumbs', $relocated_breadcrumb_actions[0]['input']['blocks'][1]['innerBlocks'][0]['attrs']['className'] ?? '', 'block theme site plan moves existing breadcrumbs inside main before the title' );
	npcink_abilities_toolkit_assert_same( 'core/post-title', $relocated_breadcrumb_actions[0]['input']['blocks'][1]['innerBlocks'][1]['blockName'] ?? '', 'block theme site plan preserves post title after relocated breadcrumbs' );
	$template_update_output_schema = $package_abilities['npcink-abilities-toolkit/update-template-blocks']['output_schema'] ?? array();
	$template_preview = $core_write_package->update_template_blocks(
		array(
			'post_id' => 601,
			'blocks'  => $block_theme_actions[0]['input']['blocks'] ?? array(),
			'dry_run' => true,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $template_preview['dry_run'] ?? null, 'update-template-blocks returns a governed dry-run preview' );
	npcink_abilities_toolkit_assert_same( 'wp_template', $template_preview['post_type'] ?? '', 'update-template-blocks reports the template post type' );
	npcink_abilities_toolkit_assert_output_schema_declares_payload_keys( $block_document, $template_update_output_schema, $template_preview, 'update-template-blocks dry-run' );
	$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
	$template_written = $core_write_package->update_template_blocks(
		array(
			'post_id'            => 601,
			'validate_roundtrip' => false,
			'blocks'             => $block_theme_actions[0]['input']['blocks'] ?? array(),
			'commit'             => true,
		)
	);
	$unsafe_media_upload = $core_write_package->upload_media_from_url(
		array(
			'url'       => 'https://cloud.example.test/audio/signed-runtime-url',
			'file_name' => 'unsafe.php',
			'commit'    => true,
		)
	);
	npcink_abilities_toolkit_assert_true( is_wp_error( $unsafe_media_upload ), 'upload-media-from-url rejects an unapproved media extension before it reaches uploads' );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_media_type_blocked', $unsafe_media_upload->get_error_code() ?? '', 'upload-media-from-url reports the blocked media type' );
	npcink_abilities_toolkit_assert_true( ! file_exists( $article_audio_upload_dir . '/unsafe.php' ), 'upload-media-from-url never copies an invalid remote file into uploads' );
	unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
	npcink_abilities_toolkit_assert_same( false, $template_written['dry_run'] ?? null, 'update-template-blocks commits after host approval' );
	npcink_abilities_toolkit_assert_output_schema_declares_payload_keys( $block_document, $template_update_output_schema, $template_written, 'update-template-blocks commit' );
	$template_written_content = (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][601]->post_content ?? '' );
	$template_written_header_position = strpos( $template_written_content, 'wp:template-part {"slug":"header"}' );
	$template_written_breadcrumb_position = strpos( $template_written_content, 'openclaw-breadcrumbs' );
	$template_written_title_position = strpos( $template_written_content, 'wp:post-title' );
	npcink_abilities_toolkit_assert_true( false !== $template_written_breadcrumb_position, 'update-template-blocks writes breadcrumb scaffold markup' );
	npcink_abilities_toolkit_assert_true( false !== $template_written_header_position, 'update-template-blocks writes the header template part marker' );
	npcink_abilities_toolkit_assert_true( false !== $template_written_title_position, 'update-template-blocks writes the post title marker' );
	npcink_abilities_toolkit_assert_true( $template_written_breadcrumb_position > $template_written_header_position, 'update-template-blocks does not write breadcrumbs before the header template part' );
	npcink_abilities_toolkit_assert_true( $template_written_breadcrumb_position < $template_written_title_position, 'update-template-blocks writes breadcrumbs before the post title' );
	$template_part_preview = $core_write_package->update_template_part_blocks(
		array(
			'post_id' => 603,
			'blocks' => array(
				array(
					'blockName' => 'core/group',
					'innerHTML' => '<div class="wp-block-group"></div>',
				),
			),
			'dry_run' => true,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $template_part_preview['dry_run'] ?? null, 'update-template-part-blocks returns a governed dry-run preview' );
	npcink_abilities_toolkit_assert_same( 'wp_template_part', $template_part_preview['post_type'] ?? '', 'update-template-part-blocks reports the template part post type' );
	$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
		501 => $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][501],
	);
	$GLOBALS['npcink_abilities_toolkit_unit_file_templates'] = array(
		array(
			'type'    => 'wp_template',
			'id'      => 'unit-block-theme//single',
			'theme'   => 'unit-block-theme',
			'slug'    => 'single',
			'source'  => 'theme',
			'title'   => 'Single',
			'content' => "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:post-title /--><!-- wp:post-content /--></div><!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n",
		),
	);
	$file_template_context = $core_read_package->get_block_theme_context( array() );
	npcink_abilities_toolkit_assert_same( 1, count( $file_template_context['templates'] ?? array() ), 'get-block-theme-context lists file-backed block templates' );
	npcink_abilities_toolkit_assert_same( 'theme', $file_template_context['templates'][0]['source'] ?? '', 'get-block-theme-context reports file-backed template source' );
	npcink_abilities_toolkit_assert_same( 0, $file_template_context['templates'][0]['post_id'] ?? -1, 'file-backed templates do not pretend to have a post id' );
	$file_template_plan = $core_read_package->build_block_theme_site_plan(
		array(
			'intent'           => 'add_breadcrumbs',
			'target_templates' => array( 'single' ),
		)
	);
	$file_template_actions = is_array( $file_template_plan['data']['write_actions'] ?? null ) ? $file_template_plan['data']['write_actions'] : array();
	npcink_abilities_toolkit_assert_same( 1, count( $file_template_actions ), 'build-block-theme-site-plan emits an action for a file-backed template' );
	npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/upsert-template-blocks', $file_template_actions[0]['target_ability_id'] ?? '', 'file-backed block theme plan targets template override upsert' );
	npcink_abilities_toolkit_assert_same( 'single', $file_template_actions[0]['input']['slug'] ?? '', 'file-backed block theme plan carries template slug into upsert input' );
	foreach ( (array) ( $file_template_actions[0]['input']['blocks'] ?? array() ) as $file_template_action_block ) {
		npcink_abilities_toolkit_assert_true( is_array( $file_template_action_block ) && ! empty( $file_template_action_block['blockName'] ), 'file-backed block theme plan omits whitespace-only freeform spacer blocks' );
	}
	$upsert_preview = $core_write_package->upsert_template_blocks(
		array(
			'slug'   => 'single',
			'theme'  => 'unit-block-theme',
			'title'  => 'Single',
			'blocks' => $file_template_actions[0]['input']['blocks'] ?? array(),
			'dry_run' => true,
		)
	);
	npcink_abilities_toolkit_assert_same( true, $upsert_preview['dry_run'] ?? null, 'upsert-template-blocks returns a governed dry-run preview' );
	npcink_abilities_toolkit_assert_same( true, $upsert_preview['created'] ?? null, 'upsert-template-blocks previews template override creation when no post exists' );
	$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
	$upsert_written = $core_write_package->upsert_template_blocks(
		array(
			'slug'               => 'single',
			'theme'              => 'unit-block-theme',
			'title'              => 'Single',
			'validate_roundtrip' => false,
			'blocks'             => $file_template_actions[0]['input']['blocks'] ?? array(),
			'commit'             => true,
		)
	);
	unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
	npcink_abilities_toolkit_assert_same( false, $upsert_written['dry_run'] ?? null, 'upsert-template-blocks commits after host approval' );
	npcink_abilities_toolkit_assert_same( true, $upsert_written['created'] ?? null, 'upsert-template-blocks creates a custom template override' );
	npcink_abilities_toolkit_assert_true( ! empty( $upsert_written['post_id'] ), 'upsert-template-blocks returns the created template post id' );
	npcink_abilities_toolkit_assert_same( 'single', (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][ $upsert_written['post_id'] ]->post_name ?? '' ), 'upsert-template-blocks stores the template slug as the post name' );
	npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][ $upsert_written['post_id'] ]->post_content ?? '' ), 'openclaw-breadcrumbs' ), 'upsert-template-blocks writes breadcrumb scaffold markup' );
	unset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'], $GLOBALS['npcink_abilities_toolkit_unit_post_meta'], $GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'], $GLOBALS['npcink_abilities_toolkit_unit_active_theme'], $GLOBALS['npcink_abilities_toolkit_unit_file_templates'] );
$inspect_page_structure = $package_abilities['npcink-abilities-toolkit/inspect-page-structure'];
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-pages', $inspect_page_structure['category'], 'inspect-page-structure uses page category' );
npcink_abilities_toolkit_assert_same( 1, $inspect_page_structure['input_schema']['properties']['max_pages']['minimum'] ?? null, 'inspect-page-structure max_pages minimum is 1' );
npcink_abilities_toolkit_assert_same( 100, $inspect_page_structure['input_schema']['properties']['max_pages']['maximum'] ?? null, 'inspect-page-structure max_pages maximum is 100' );
npcink_abilities_toolkit_assert_same( 50, $inspect_page_structure['input_schema']['properties']['max_pages']['default'] ?? null, 'inspect-page-structure max_pages default is 50' );
$proposal_excerpt = $package_abilities['npcink-abilities-toolkit/propose-post-excerpt'];
npcink_abilities_toolkit_assert_same( true, $proposal_excerpt['annotations']['readonly'], 'propose-post-excerpt remains proposal-only and readonly' );
npcink_abilities_toolkit_assert_same( false, $proposal_excerpt['requires_confirm'], 'propose-post-excerpt does not perform a final write' );
$GLOBALS['npcink_abilities_toolkit_unit_comments'] = array(
	11 => (object) array(
		'comment_ID' => 11,
		'comment_post_ID' => 77,
		'comment_author' => 'Promo Bot',
		'comment_approved' => 'hold',
		'comment_content' => 'Buy now discount coupon https://example.test https://promo.example.test',
	),
	12 => (object) array(
		'comment_ID' => 12,
		'comment_post_ID' => 77,
		'comment_author' => 'Reader',
		'comment_approved' => 'hold',
		'comment_content' => '@admin 请问这个报错怎么处理？',
	),
);
$comment_suggest = $core_comment_package->build_comment_moderation_suggest(
	array(
		'comment_id' => 11,
	)
);
npcink_abilities_toolkit_assert_same( true, $comment_suggest['success'] ?? null, 'build-comment-moderation-suggest returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'spam', $comment_suggest['data']['recommended_action'] ?? '', 'build-comment-moderation-suggest flags promotional comments as spam' );
npcink_abilities_toolkit_assert_true( in_array( 'commercial_promo', $comment_suggest['data']['risk_flags'] ?? array(), true ), 'build-comment-moderation-suggest exposes commercial promo risk flag' );
$GLOBALS['npcink_abilities_toolkit_unit_comments'][13] = (object) array(
	'comment_ID'      => 13,
	'comment_post_ID' => 77,
	'comment_author'  => 'Pharmacy Bot',
	'comment_approved' => 'hold',
	'comment_content' => 'Buy cheap pills now',
);
$pharmacy_comment_suggest = $core_comment_package->build_comment_moderation_suggest(
	array(
		'comment_id' => 13,
	)
);
npcink_abilities_toolkit_assert_same( 'spam', $pharmacy_comment_suggest['data']['recommended_action'] ?? '', 'build-comment-moderation-suggest flags pharmacy spam without relying on links' );
$comment_result = $core_comment_package->compose_comment_moderation_result(
	array(
		'comment_id' => 11,
		'mode' => 'suggest',
		'suggest_result' => $comment_suggest['data'],
	)
);
npcink_abilities_toolkit_assert_same( true, $comment_result['success'] ?? null, 'compose-comment-moderation-result returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'spam', $comment_result['data']['recommended_action'] ?? '', 'compose-comment-moderation-result keeps recommended action' );
$mention_suggest = $core_comment_package->build_comment_mention_reply_suggest(
	array(
		'comment_id' => 12,
		'trigger_type' => 'mention',
	)
);
npcink_abilities_toolkit_assert_same( true, $mention_suggest['success'] ?? null, 'build-comment-mention-reply-suggest returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $mention_suggest['data']['trigger']['trigger_detected'] ?? null, 'build-comment-mention-reply-suggest detects mention trigger' );
npcink_abilities_toolkit_assert_true( ! empty( $mention_suggest['data']['reply_options'] ), 'build-comment-mention-reply-suggest returns review-only reply options' );
npcink_abilities_toolkit_assert_same( 'acknowledge_and_answer', $mention_suggest['data']['reply_options'][0]['id'] ?? '', 'build-comment-mention-reply-suggest keeps stable reply option ids' );
npcink_abilities_toolkit_assert_true( '' !== (string) ( $mention_suggest['data']['reply_options'][0]['label'] ?? '' ), 'build-comment-mention-reply-suggest exposes reply option labels' );
npcink_abilities_toolkit_assert_true( '' !== (string) ( $mention_suggest['data']['reply_options'][0]['reason'] ?? '' ), 'build-comment-mention-reply-suggest exposes reply option reasons' );
$text_reply_suggest = $core_comment_package->build_comment_mention_reply_suggest(
	array(
		'post_id'        => 77,
		'post_title'    => 'Comment Reply Context',
		'comment_text'  => 'Could you share more detail about how this workflow handles review?',
		'comment_author' => 'Reader',
		'trigger_type'  => 'support_request',
		'always_suggest' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $text_reply_suggest['success'] ?? null, 'build-comment-mention-reply-suggest accepts operator-supplied comment text' );
npcink_abilities_toolkit_assert_same( 'operator_supplied_comment_text', $text_reply_suggest['data']['comment']['source'] ?? '', 'operator-supplied comment reply suggestions preserve source boundary' );
npcink_abilities_toolkit_assert_same( false, $text_reply_suggest['data']['direct_wordpress_write'] ?? true, 'operator-supplied comment reply suggestions remain no-write' );
npcink_abilities_toolkit_assert_same( 'preview_reply', $text_reply_suggest['data']['mention_summary']['next_action'] ?? '', 'operator-supplied comment reply suggestions summarize preview reply as the next action' );
$trigger_queue = $core_comment_package->read_comment_trigger_queue(
	array(
		'post_id' => 77,
		'trigger_type' => 'mention',
		'status' => 'hold',
	)
);
npcink_abilities_toolkit_assert_same( 1, $trigger_queue['data']['summary']['candidate_count'] ?? null, 'read-comment-trigger-queue returns detected mention candidates' );
$comment_queue_health = $core_comment_package->get_comment_queue_health(
	array(
		'post_id'  => 77,
		'status'   => 'hold',
		'per_page' => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $comment_queue_health['success'] ?? null, 'get-comment-queue-health returns a success envelope' );
npcink_abilities_toolkit_assert_same( 3, $comment_queue_health['data']['summary']['counts']['total'] ?? null, 'get-comment-queue-health counts queued comments' );
npcink_abilities_toolkit_assert_true( (int) ( $comment_queue_health['data']['summary']['counts']['spam_risk'] ?? 0 ) >= 1, 'get-comment-queue-health counts spam-risk comments' );
npcink_abilities_toolkit_assert_true( (int) ( $comment_queue_health['data']['summary']['counts']['reply_needed'] ?? 0 ) >= 1, 'get-comment-queue-health counts reply-needed comments' );
$comment_action_queue = $core_comment_package->get_comment_action_priority_queue(
	array(
		'post_id'  => 77,
		'status'   => 'hold',
		'per_page' => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $comment_action_queue['success'] ?? null, 'get-comment-action-priority-queue returns a success envelope' );
npcink_abilities_toolkit_assert_same( 3, $comment_action_queue['data']['summary']['counts']['total'] ?? null, 'get-comment-action-priority-queue counts queued comments' );
npcink_abilities_toolkit_assert_true( (int) ( $comment_action_queue['data']['items'][0]['priority_score'] ?? 0 ) >= (int) ( $comment_action_queue['data']['items'][1]['priority_score'] ?? 0 ), 'get-comment-action-priority-queue sorts high-priority items first' );
$comment_handoff = $core_comment_package->get_comment_compliance_handoff(
	array(
		'post_id'             => 77,
		'status'              => 'hold',
		'per_page'            => 10,
		'selected_comment_id' => 12,
	)
);
npcink_abilities_toolkit_assert_same( true, $comment_handoff['success'] ?? null, 'get-comment-compliance-handoff returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/recipes/comment-compliance-handoff', $comment_handoff['data']['recipe'] ?? '', 'get-comment-compliance-handoff declares its recipe id' );
npcink_abilities_toolkit_assert_true( in_array( 'selected_moderation_suggestion', $comment_handoff['data']['sections'] ?? array(), true ), 'get-comment-compliance-handoff includes selected moderation suggestion' );
$batch_suggest = $core_comment_package->build_comment_moderation_batch_suggest(
	array(
		'comment_ids' => array( 11, 12 ),
	)
);
npcink_abilities_toolkit_assert_same( true, $batch_suggest['success'] ?? null, 'build-comment-moderation-batch-suggest returns a success envelope' );
npcink_abilities_toolkit_assert_same( 2, $batch_suggest['data']['batch_summary']['counts']['total'] ?? null, 'build-comment-moderation-batch-suggest counts batch items' );
$batch_result = $core_comment_package->compose_comment_moderation_batch_result(
	array(
		'batch_result' => $batch_suggest['data'],
	)
);
npcink_abilities_toolkit_assert_same( 'review_individual_items', $batch_result['data']['next_action'] ?? '', 'compose-comment-moderation-batch-result keeps single-item handoff' );
$resolved_metadata_plan = $core_read_package->resolve_post_metadata_plan(
	array(
		'post_metadata_plan' => array(
			'excerpt_mode' => 'explicit',
			'excerpt' => '这是新的摘要。',
			'slug_mode' => 'explicit',
			'slug' => 'New Canonical Slug!',
			'categories' => array( '3', '5', '5', 'bad' ),
			'tags' => array(),
			'publish_at' => '2030-01-02 03:04:05',
			'author_id' => 12,
			'template' => 'landing.php',
			'format' => 'image',
		),
		'taxonomy_plan' => array(
			'categories' => array( '1' ),
			'tags' => array( '8', '13' ),
		),
		'generated_excerpt' => '备用摘要',
		'generated_slug' => 'fallback-slug',
	)
);
npcink_abilities_toolkit_assert_same( true, $resolved_metadata_plan['success'] ?? null, 'resolve-post-metadata-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( '这是新的摘要。', $resolved_metadata_plan['data']['excerpt'] ?? '', 'resolve-post-metadata-plan preserves explicit excerpt' );
npcink_abilities_toolkit_assert_same( 'new-canonical-slug', $resolved_metadata_plan['data']['slug'] ?? '', 'resolve-post-metadata-plan sanitizes explicit slug' );
npcink_abilities_toolkit_assert_same( array( 3, 5 ), $resolved_metadata_plan['data']['categories'] ?? array(), 'resolve-post-metadata-plan prefers metadata categories' );
npcink_abilities_toolkit_assert_same( array( 8, 13 ), $resolved_metadata_plan['data']['tags'] ?? array(), 'resolve-post-metadata-plan falls back to taxonomy tags' );
npcink_abilities_toolkit_assert_same( '2030-01-02 03:04:05', $resolved_metadata_plan['data']['publish_at'] ?? '', 'resolve-post-metadata-plan preserves publish_at handoff' );
npcink_abilities_toolkit_assert_same( 12, $resolved_metadata_plan['data']['author_id'] ?? 0, 'resolve-post-metadata-plan normalizes author_id handoff' );
npcink_abilities_toolkit_assert_same( 'landing.php', $resolved_metadata_plan['data']['template'] ?? '', 'resolve-post-metadata-plan preserves template handoff' );
npcink_abilities_toolkit_assert_same( 'image', $resolved_metadata_plan['data']['format'] ?? '', 'resolve-post-metadata-plan normalizes format handoff' );
$inline_blocks = $core_read_package->build_inline_image_blocks(
	array(
		'uploaded_inline_media' => array(
			array(
				'attachment_id' => 44,
				'url' => 'https://example.test/alpha.jpg',
				'alt' => 'Fallback alt',
			),
		),
		'inline_plan' => array(
			array(
				'alt' => 'Inline alt',
				'caption' => 'Inline caption',
				'placement_key' => 'alpha-hero',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $inline_blocks['success'] ?? null, 'build-inline-image-blocks returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $inline_blocks['data']['summary']['count'] ?? 0, 'build-inline-image-blocks counts generated blocks' );
npcink_abilities_toolkit_assert_same( 'core/image', $inline_blocks['data']['blocks'][0]['blockName'] ?? '', 'build-inline-image-blocks emits Gutenberg image blocks' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit-inline-image alpha-hero', $inline_blocks['data']['blocks'][0]['attrs']['className'] ?? '', 'build-inline-image-blocks preserves placement class key' );
$media_assets = $core_read_package->build_media_seo_assets(
	array(
		'article' => array( 'title' => 'Article title' ),
		'resolved_image_source' => array(
			'featured' => array(
				'image_origin' => 'ai_generated',
				'prompt' => 'Generated hero prompt',
				'role' => 'featured',
			),
			'inline' => array(
				array(
					'provider_hint' => 'pexels',
					'provider_title' => 'Provider title',
					'section_heading' => 'Inline section',
				),
			),
		),
		'generated_featured_upload' => array(
			'attachment_id' => 88,
			'url' => 'https://example.test/generated.jpg',
			'file_name' => 'generated.jpg',
		),
		'inline_uploads' => array(
			array(
				'attachment_id' => 89,
				'url' => 'https://example.test/inline.jpg',
				'file_name' => 'inline.jpg',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_assets['success'] ?? null, 'build-media-seo-assets returns a success envelope' );
npcink_abilities_toolkit_assert_same( 2, $media_assets['data']['summary']['asset_count'] ?? 0, 'build-media-seo-assets counts featured plus inline assets' );
npcink_abilities_toolkit_assert_same( 'ai_generated', $media_assets['data']['items'][0]['image_origin'] ?? '', 'build-media-seo-assets preserves generated image origin' );
npcink_abilities_toolkit_assert_same( 'ai_generated', $media_assets['data']['items'][0]['source_type'] ?? '', 'build-media-seo-assets maps generated images to ai_generated source type' );
npcink_abilities_toolkit_assert_same( 'AI-generated by site operator', $media_assets['data']['items'][0]['attribution_text'] ?? '', 'build-media-seo-assets adds generated image attribution default' );
npcink_abilities_toolkit_assert_same( 'Generated asset for this site', $media_assets['data']['items'][0]['copyright_notice'] ?? '', 'build-media-seo-assets adds generated image copyright default' );
npcink_abilities_toolkit_assert_same( 'public_free', $media_assets['data']['items'][1]['image_origin'] ?? '', 'build-media-seo-assets infers public-free provider origin' );
npcink_abilities_toolkit_assert_same( 'stock', $media_assets['data']['items'][1]['source_type'] ?? '', 'build-media-seo-assets maps public-free provider images to stock source type' );
$geo_analysis = $core_read_package->geo_analyze(
	array(
		'title' => 'WordPress AI visibility',
		'content' => 'WordPress AI visibility 是什么？It helps answer boxes reuse concise article sections. Teams should add FAQ blocks and direct summaries.',
		'excerpt' => '',
		'focus_keyword' => 'AI visibility',
	)
);
npcink_abilities_toolkit_assert_same( true, $geo_analysis['success'] ?? null, 'geo-analyze returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $geo_analysis['data']['summary']['faq_candidate_count'] ?? 0, 'geo-analyze extracts question candidates' );
$optimized_media = $core_read_package->optimize_media_metadata(
	array(
		'article_title' => 'Article title',
		'article_excerpt' => 'Article excerpt',
		'focus_keyword' => 'media SEO',
		'media_assets' => array(
			array(
				'attachment_id' => 90,
				'url' => 'https://example.test/manual.jpg',
				'mime_type' => 'image/jpeg',
				'file_name' => 'manual.jpg',
				'vision_fallback_allowed' => true,
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $optimized_media['success'] ?? null, 'optimize-media-metadata returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $optimized_media['data']['summary']['missing_alt_count'] ?? 0, 'optimize-media-metadata counts missing alt text' );
npcink_abilities_toolkit_assert_same( 'owned', $optimized_media['data']['assets'][0]['suggestions']['source_type'] ?? '', 'optimize-media-metadata includes source_type in media detail suggestions' );
npcink_abilities_toolkit_assert_same( 'Owned asset for this site', $optimized_media['data']['assets'][0]['suggestions']['copyright_notice'] ?? '', 'optimize-media-metadata adds owned media copyright default' );
$positioned_blocks = $core_read_package->position_inline_image_blocks(
	array(
		'existing_blocks' => array(
			array(
				'blockName' => 'core/paragraph',
				'attrs' => array(),
				'innerHTML' => '<p>Intro</p>',
				'innerBlocks' => array(),
			),
			array(
				'blockName' => 'core/heading',
				'attrs' => array( 'anchor' => 'target-heading' ),
				'innerHTML' => '<h2>Target heading</h2>',
				'innerBlocks' => array(),
			),
		),
		'inline_blocks' => $inline_blocks['data']['blocks'] ?? array(),
		'inline_plan' => array(
			array(
				'placement_key' => 'alpha-hero',
				'placement' => 'after_heading',
				'section_anchor' => 'target-heading',
				'section_heading' => 'Target heading',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $positioned_blocks['success'] ?? null, 'position-inline-image-blocks returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $positioned_blocks['data']['summary']['positioned_count'] ?? 0, 'position-inline-image-blocks positions matching inline blocks' );
npcink_abilities_toolkit_assert_same( 'core/image', $positioned_blocks['data']['blocks'][2]['blockName'] ?? '', 'position-inline-image-blocks inserts after matching heading' );
$article_production_fingerprint = $core_read_package->build_article_production_fingerprint(
	array(
		'topic'              => 'Local article production',
		'image_mode'         => 'featured_only',
		'publish_mode'       => 'draft',
		'content_format'     => 'html',
		'voice_profile'      => 'practical_editorial',
		'opening_style'      => 'scene',
		'structure_style'    => 'asymmetric',
		'reference_post_ids' => array( 9, 3 ),
	)
);
$article_production_fingerprint_reordered = $core_read_package->build_article_production_fingerprint(
	array(
		'topic'              => 'Local article production',
		'image_mode'         => 'featured_only',
		'publish_mode'       => 'draft',
		'content_format'     => 'html',
		'voice_profile'      => 'practical_editorial',
		'opening_style'      => 'scene',
		'structure_style'    => 'asymmetric',
		'reference_post_ids' => array( 3, 9 ),
	)
);
npcink_abilities_toolkit_assert_same( true, $article_production_fingerprint['success'] ?? null, 'build-article-production-fingerprint returns a success envelope' );
npcink_abilities_toolkit_assert_same( 16, strlen( (string) ( $article_production_fingerprint['data']['production_fingerprint'] ?? '' ) ), 'build-article-production-fingerprint emits a compact 16-character hash' );
npcink_abilities_toolkit_assert_same(
	$article_production_fingerprint['data']['production_fingerprint'] ?? '',
	$article_production_fingerprint_reordered['data']['production_fingerprint'] ?? null,
	'build-article-production-fingerprint sorts reference post ids for stable dedupe'
);
$article_duplicate_check = $core_read_package->check_article_production_duplicate(
	array(
		'production_fingerprint' => (string) ( $article_production_fingerprint['data']['production_fingerprint'] ?? '' ),
		'write_guard_mode'       => 'preserve_manual_edits',
	)
);
npcink_abilities_toolkit_assert_same( true, $article_duplicate_check['success'] ?? null, 'check-article-production-duplicate returns a success envelope' );
npcink_abilities_toolkit_assert_same( false, $article_duplicate_check['data']['duplicate_found'] ?? null, 'check-article-production-duplicate stays readonly when no WordPress lookup is available' );
npcink_abilities_toolkit_assert_same( false, $article_duplicate_check['data']['skip_recommended'] ?? null, 'check-article-production-duplicate does not recommend skipping without a duplicate' );
$article_review_light = $core_read_package->review_article_output_light(
	array(
		'article' => array(
			'content' => implode(
				"\n\n",
				array(
					'<p>首先，AI 写作正在快速发展，因此我们需要全面认识它的优势与不足。</p>',
					'<p>其次，AI 写作可以提高效率，因此企业应该结合实际情况进行合理应用。</p>',
					'<p>最后，AI 写作未来前景广阔，因此团队需要持续优化流程与质量控制。</p>',
					'<p>综上所述，AI 写作具有重要价值，因此我们应当积极拥抱并持续探索。</p>',
				)
			),
		),
		'style_profile' => array(
			'resolved_opening_style' => 'scene',
			'resolved_voice_profile' => 'experiential_editorial',
		),
		'platform_profile' => 'wechat',
		'human_signals' => array(),
		'image_mode' => 'featured_only',
		'media' => array(
			'featured_attached' => true,
		),
	)
);
$article_review_data = is_array( $article_review_light['data'] ?? null ) ? $article_review_light['data'] : array();
$article_ai_risk_review = is_array( $article_review_data['ai_risk_review'] ?? null ) ? $article_review_data['ai_risk_review'] : array();
$article_ai_risk_keys = array();
foreach ( is_array( $article_ai_risk_review['items'] ?? null ) ? $article_ai_risk_review['items'] : array() as $risk_item ) {
	$risk_item = is_array( $risk_item ) ? $risk_item : array();
	$article_ai_risk_keys[] = sanitize_key( (string) ( $risk_item['key'] ?? '' ) );
}
npcink_abilities_toolkit_assert_same( true, $article_review_light['success'] ?? null, 'review-article-output-light returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $article_review_data['needs_human_review'] ?? null, 'review-article-output-light flags template-heavy output for review' );
npcink_abilities_toolkit_assert_same( 'high', $article_review_data['template_risk_level'] ?? '', 'review-article-output-light preserves high template risk semantics' );
npcink_abilities_toolkit_assert_true( in_array( 'human_signal_gap', $article_ai_risk_keys, true ), 'review-article-output-light reports missing human signal risk' );
npcink_abilities_toolkit_assert_true( in_array( 'evidence_gap', $article_ai_risk_keys, true ), 'review-article-output-light reports missing evidence risk' );
$reference_style_review = $core_read_package->review_article_output_light(
	array(
		'article' => array(
			'content' => "<p>最近我们在一次编辑复盘里突然意识到，文章一旦写得太顺，反而像机器。</p>\n\n<p>我后来会故意先把现场判断扔出来，再补背景，因为团队实操和踩坑本来就不是教科书顺序。</p>\n\n<p>我们照着这个节奏改完以后，内容没那么完美，但至少终于像人写的了。</p>",
		),
		'style_profile' => array(
			'resolved_opening_style' => 'scene',
			'resolved_voice_profile' => 'experiential_editorial',
		),
		'image_mode' => 'featured_only',
		'media' => array(
			'featured_attached' => true,
		),
	)
);
npcink_abilities_toolkit_assert_same( false, $reference_style_review['data']['needs_human_review'] ?? null, 'review-article-output-light relaxes missing evidence risks when reference style strongly matches' );
npcink_abilities_toolkit_assert_same( 'ready_for_editorial_review', $reference_style_review['data']['next_action'] ?? '', 'review-article-output-light keeps matching reference-style output reviewable' );
$production_result = $core_read_package->compose_article_production_result(
	array(
		'input' => array(
			'topic'              => 'Local article production',
			'image_mode'         => 'featured_and_inline',
			'publish_mode'       => 'draft',
			'reference_post_ids' => array( 9, 3 ),
			'write_guard_mode'   => 'preserve_manual_edits',
		),
		'draft' => array(
			'post_id'      => 99,
			'edit_link'    => 'https://example.test/edit/99',
			'preview_link' => 'https://example.test/preview/99',
			'public_link'  => 'https://example.test/article/99',
		),
		'media' => array(
			'featured_attached' => true,
			'position_inline_image_blocks' => array(
				'summary' => array(
					'positioned_count' => 1,
					'appended_count'   => 1,
				),
			),
		),
		'review' => array(
			'writing_naturalness'   => 70,
			'style_match_score'     => 94,
			'image_relevance_score' => 82,
			'needs_human_review'    => false,
			'next_action'           => 'needs_layout_review',
			'template_risk_level'   => 'medium',
			'signals'               => array(
				'anti_template_findings' => array( '段落层次偏少，容易显得像模板摘要。' ),
				'style_findings'         => array(),
			),
		),
		'duplicate_guard' => array(
			'production_fingerprint' => 'abc123def4567890',
			'duplicate_found'        => true,
			'skip_recommended'       => true,
			'summary_text'           => '检测到同指纹文章 #88《已有草稿》，建议先复核现有稿件。',
			'duplicate_candidate'    => array(
				'post_id' => 88,
				'title'   => '已有草稿',
			),
		),
	)
);
$production_data = is_array( $production_result['data'] ?? null ) ? $production_result['data'] : array();
npcink_abilities_toolkit_assert_same( true, $production_result['success'] ?? null, 'compose-article-production-result returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'degraded', $production_data['result_mode'] ?? '', 'compose-article-production-result exposes degraded result semantics' );
npcink_abilities_toolkit_assert_true( in_array( 'inline_position_partial_fallback', (array) ( $production_data['degraded_reasons'] ?? array() ), true ), 'compose-article-production-result preserves inline fallback degradation' );
npcink_abilities_toolkit_assert_true( in_array( 'duplicate_production_candidate', (array) ( $production_data['degraded_reasons'] ?? array() ), true ), 'compose-article-production-result preserves duplicate guard degradation' );
npcink_abilities_toolkit_assert_same( 'abc123def4567890', $production_data['production_fingerprint'] ?? '', 'compose-article-production-result preserves duplicate fingerprint' );
npcink_abilities_toolkit_assert_same( true, $production_data['skip_recommended'] ?? null, 'compose-article-production-result preserves duplicate skip recommendation' );
npcink_abilities_toolkit_assert_true( in_array( 'duplicate_candidate_detected', (array) ( $production_data['completed_stages'] ?? array() ), true ), 'compose-article-production-result records duplicate candidate stage' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $production_data['summary_text'] ?? '' ), '检测到可复用旧稿' ), 'compose-article-production-result summarizes duplicate reuse handoff' );
$production_result_fallback = $core_read_package->compose_article_production_result(
	array(
		'input' => array(
			'topic'              => 'Local article production',
			'image_mode'         => 'featured_only',
			'publish_mode'       => 'draft',
			'content_format'     => 'html',
			'voice_profile'      => 'practical_editorial',
			'opening_style'      => 'scene',
			'structure_style'    => 'asymmetric',
			'reference_post_ids' => array( 3, 9 ),
		),
	)
);
npcink_abilities_toolkit_assert_same(
	(string) ( $article_production_fingerprint['data']['production_fingerprint'] ?? '' ),
	(string) ( $production_result_fallback['data']['production_fingerprint'] ?? '' ),
	'compose-article-production-result fills a stable fingerprint when duplicate guard omits one'
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
	11 => (object) array(
		'ID'           => 11,
		'post_author'  => 7,
		'post_title'   => '现场复盘',
		'post_content' => "<p>最近我们在一次编辑复盘里发现，开头先放现场判断更自然。</p>\n\n<p>我和团队实操时会保留一点踩坑细节，让文章不像模板。</p>\n\n<p>下一步建议继续保留这种判断节奏。</p>",
	),
	12 => (object) array(
		'ID'           => 12,
		'post_author'  => 7,
		'post_title'   => '团队笔记',
		'post_content' => "<p>在一次上线后，我们把数据指标和人工判断放在一起看。</p>\n\n<p>团队实操中最有价值的是先说取舍，再补背景。</p>\n\n<p>可以把这种写法沉淀成编辑基线。</p>",
	),
	13 => (object) array(
		'ID'           => 13,
		'post_author'  => 3,
		'post_title'   => '站点样本',
		'post_content' => "<p>如果只看单篇文章，很容易误判站点风格。</p>\n\n<p>把最近内容合起来看，结构和语气会更稳定。</p>\n\n<p>总的来说，基线应该轻量而可复用。</p>",
	),
);
$GLOBALS['npcink_abilities_toolkit_unit_transients'] = array();
$reference_style = $core_read_package->extract_reference_post_style(
	array(
		'reference_post_ids' => array( 11, 12 ),
	)
);
npcink_abilities_toolkit_assert_same( true, $reference_style['success'] ?? null, 'extract-reference-post-style returns a success envelope' );
npcink_abilities_toolkit_assert_same( 2, $reference_style['data']['profile']['sample_count'] ?? null, 'extract-reference-post-style profiles two reference samples' );
npcink_abilities_toolkit_assert_same( 'scene', $reference_style['data']['profile']['dominant_opening_style'] ?? '', 'extract-reference-post-style detects scene-led openings' );
npcink_abilities_toolkit_assert_same( 'action', $reference_style['data']['profile']['dominant_ending_style'] ?? '', 'extract-reference-post-style detects action endings' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $reference_style['data']['profile']['style_brief'] ?? '' ), '段落平均长度约' ), 'extract-reference-post-style returns a compact style brief' );
$GLOBALS['npcink_abilities_toolkit_unit_transients'] = array();
$author_baseline = $core_read_package->extract_style_baseline(
	array(
		'mode'      => 'author_recent',
		'author_id' => 7,
		'limit'     => 4,
	)
);
npcink_abilities_toolkit_assert_same( true, $author_baseline['success'] ?? null, 'extract-style-baseline returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'author_recent', $author_baseline['data']['source'] ?? '', 'extract-style-baseline keeps author source when author samples exist' );
npcink_abilities_toolkit_assert_same( 2, $author_baseline['data']['profile']['sample_count'] ?? null, 'extract-style-baseline profiles author samples' );
$GLOBALS['npcink_abilities_toolkit_unit_transients'] = array();
$site_baseline = $core_read_package->extract_style_baseline(
	array(
		'mode'  => 'site_recent',
		'limit' => 3,
	)
);
npcink_abilities_toolkit_assert_same( 'site_recent', $site_baseline['data']['source'] ?? '', 'extract-style-baseline keeps site source for site baselines' );
npcink_abilities_toolkit_assert_same( 3, $site_baseline['data']['profile']['sample_count'] ?? null, 'extract-style-baseline profiles site samples' );
$optimization_report = $core_read_package->build_article_optimization_report(
	array(
		'post'           => array(
			'id'        => 42,
			'title'     => 'Optimization target',
			'status'    => 'publish',
			'edit_link' => 'https://example.test/wp-admin/post.php?post=42&action=edit',
		),
		'seo'            => array(
			'recommendations' => array(
				array(
					'type'     => 'keyword',
					'priority' => 'high',
					'title'    => '补焦点关键词',
					'detail'   => '首段补齐关键词。',
				),
			),
		),
		'geo'            => array(
			'recommendations' => array(
				array(
					'type'     => 'answerability',
					'priority' => 'medium',
					'title'    => '补答案摘要',
					'detail'   => '增加 answer-first 段落。',
				),
			),
		),
		'internal_links' => array(
			'placement_plan' => array(
				array(
					'anchor_text' => '内部链接锚点',
					'reason'      => '连接相关旧文。',
				),
			),
		),
		'media'          => array(
			'assets' => array(
				array(
					'issues' => array( '缺少 alt', '缺少 caption' ),
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $optimization_report['success'] ?? null, 'build-article-optimization-report returns a success envelope' );
npcink_abilities_toolkit_assert_same( 42, $optimization_report['data']['post']['post_id'] ?? null, 'build-article-optimization-report normalizes post id' );
npcink_abilities_toolkit_assert_same( 'needs_attention', $optimization_report['data']['summary']['status'] ?? '', 'build-article-optimization-report marks high-priority reports for attention' );
npcink_abilities_toolkit_assert_same( 4, $optimization_report['data']['summary']['total_recommendations'] ?? null, 'build-article-optimization-report merges SEO, GEO, internal link, and media recommendations' );
$seo_report_context = $core_read_package->build_seo_report_context(
	array(
		'input'         => '<p>这是一段偏短的正文，用来触发内容深度建议。</p>',
		'focus_keyword' => '本地能力包',
	)
);
npcink_abilities_toolkit_assert_same( true, $seo_report_context['success'] ?? null, 'seo-report-context returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'local_seo_report_context', $seo_report_context['meta']['source'] ?? '', 'seo-report-context records deterministic source metadata' );
npcink_abilities_toolkit_assert_same( 1, $seo_report_context['data']['summary']['high_priority_count'] ?? null, 'seo-report-context counts missing focus keyword as high priority' );
npcink_abilities_toolkit_assert_true( 88 > (int) ( $seo_report_context['data']['score'] ?? 88 ), 'seo-report-context lowers score when checks fail' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][77] = (object) array(
	'ID'           => 77,
	'post_title'   => 'Optimization Context Post',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => 'Optimization context content.',
	'post_name'    => 'optimization-context-post',
);
$post_context = $core_read_package->read_post_optimization_context(
	array(
		'post_id' => 77,
	)
);
npcink_abilities_toolkit_assert_same( true, $post_context['success'] ?? null, 'read-post-optimization-context returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $post_context['data']['id'] ?? null, 'read-post-optimization-context reads the requested post id' );
npcink_abilities_toolkit_assert_same( 'optimization-context-post', $post_context['data']['slug'] ?? '', 'read-post-optimization-context exposes slug for optimization workflows' );
npcink_abilities_toolkit_assert_same( 'standard', $post_context['data']['format'] ?? '', 'read-post-optimization-context defaults empty post format to standard' );
$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][77]['_yoast_wpseo_title'] = 'Optimization SEO Title';
$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][77]['_yoast_wpseo_metadesc'] = 'Optimization SEO Description';
$agent_post_context = $core_read_package->get_post_context(
	array(
		'post_id'      => 77,
		'include_meta' => true,
		'meta_keys'    => array( '_yoast_wpseo_title' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $agent_post_context['success'] ?? null, 'get-post-context returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $agent_post_context['data']['post']['id'] ?? null, 'get-post-context reads the requested post id' );
npcink_abilities_toolkit_assert_same( 'Optimization context content.', $agent_post_context['data']['post']['content'] ?? '', 'get-post-context includes post content by default' );
npcink_abilities_toolkit_assert_same( 1, $agent_post_context['data']['stats']['block_count'] ?? null, 'get-post-context falls back to a freeform block for plain content' );
	npcink_abilities_toolkit_assert_same( 'Optimization SEO Title', $agent_post_context['data']['meta']['_yoast_wpseo_title'] ?? '', 'get-post-context supports scoped metadata reads' );
	$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][77]['_vendor_api_token'] = 'tok-super-secret';
	$unscoped_agent_post_context = $core_read_package->get_post_context(
		array(
			'post_id'      => 77,
			'include_meta' => true,
		)
	);
	npcink_abilities_toolkit_assert_true( ! isset( $unscoped_agent_post_context['data']['meta']['_vendor_api_token'] ), 'get-post-context never returns all metadata when no safe keys are supplied' );
	$blocked_post_meta = $core_read_package->get_post_meta(
		array(
			'post_id'  => 77,
			'meta_key' => '_vendor_api_token',
		)
	);
	npcink_abilities_toolkit_assert_true( is_wp_error( $blocked_post_meta ), 'get-post-meta blocks sensitive metadata keys' );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_meta_key_blocked', $blocked_post_meta->get_error_code() ?? '', 'get-post-meta uses a stable sensitive-key error code' );
	$missing_post_meta_key = $core_read_package->get_post_meta( array( 'post_id' => 77 ) );
	npcink_abilities_toolkit_assert_true( is_wp_error( $missing_post_meta_key ), 'get-post-meta requires one explicit safe key' );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_meta_key_required', $missing_post_meta_key->get_error_code() ?? '', 'get-post-meta uses a stable missing-key error code' );
	$safe_post_meta = $core_read_package->get_post_meta(
		array(
			'post_id'  => 77,
			'meta_key' => '_yoast_wpseo_title',
		)
	);
	npcink_abilities_toolkit_assert_same( 'Optimization SEO Title', $safe_post_meta['value'] ?? '', 'get-post-meta returns an explicitly requested safe key' );
$publishing_checklist = $core_read_package->get_content_publishing_checklist(
	array(
		'post_id' => 77,
	)
);
npcink_abilities_toolkit_assert_same( true, $publishing_checklist['success'] ?? null, 'get-content-publishing-checklist returns a success envelope' );
npcink_abilities_toolkit_assert_same( false, $publishing_checklist['data']['ready'] ?? null, 'get-content-publishing-checklist blocks thin content from ready state' );
npcink_abilities_toolkit_assert_true( in_array( 'content', $publishing_checklist['data']['missing'] ?? array(), true ), 'get-content-publishing-checklist reports missing content depth' );
npcink_abilities_toolkit_assert_true( in_array( 'excerpt', $publishing_checklist['data']['warnings'] ?? array(), true ), 'get-content-publishing-checklist reports missing excerpt as warning' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][78] = (object) array(
	'ID'           => 78,
	'post_title'   => 'Internal Link Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => 'Candidate excerpt.',
	'post_content' => 'Optimization workflow candidate content with enough body text to support an internal link opportunity report for related workflow articles.',
	'post_name'    => 'internal-link-candidate',
	'post_author'  => 7,
	'post_modified' => '2024-01-02 03:04:05',
);
$inventory_health = $core_read_package->get_content_inventory_health(
	array(
		'post_type' => 'post',
		'status'    => 'any',
		'per_page'  => 5,
		'page'      => 1,
	)
);
npcink_abilities_toolkit_assert_same( true, $inventory_health['success'] ?? null, 'get-content-inventory-health returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $inventory_health['data']['summary']['scanned_count'] ?? 0 ) > 0, 'get-content-inventory-health scans bounded inventory rows' );
npcink_abilities_toolkit_assert_true( isset( $inventory_health['data']['health_score'] ), 'get-content-inventory-health returns a health score' );
$inventory_health_cached = $core_read_package->get_content_inventory_health(
	array(
		'post_type' => 'post',
		'status'    => 'any',
		'per_page'  => 5,
		'page'      => 1,
	)
);
npcink_abilities_toolkit_assert_same( true, $inventory_health_cached['meta']['cache_hit'] ?? null, 'get-content-inventory-health uses the bounded read cache on repeated calls' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][79] = (object) array(
	'ID'           => 79,
	'post_title'   => 'Core Governance Comment Smoke',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => 'Temporary smoke content for local validation.',
	'post_name'    => 'core-governance-comment-smoke',
	'post_author'  => 7,
	'post_modified' => '2026-05-30 10:00:00',
);
$test_inventory = $core_read_package->get_nonproduction_content_inventory(
	array(
		'patterns' => array( 'Core Governance' ),
		'per_page' => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $test_inventory['success'] ?? null, 'get-nonproduction-content-inventory returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $test_inventory['data']['detected'] ?? null, 'get-nonproduction-content-inventory detects matching smoke content' );
npcink_abilities_toolkit_assert_same( 'Core Governance', $test_inventory['data']['posts']['items'][0]['matched_pattern'] ?? '', 'get-nonproduction-content-inventory returns matched pattern' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][81] = (object) array(
	'ID'           => 81,
	'post_title'   => 'Core Plan Bridge Content Candidate',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => 'Temporary plan bridge fixture content for local validation.',
	'post_name'    => 'core-plan-bridge-content-candidate',
	'post_author'  => 7,
	'post_modified' => '2026-05-30 10:30:00',
);
$default_test_inventory = $core_read_package->get_nonproduction_content_inventory(
	array(
		'per_page' => 10,
	)
);
$default_plan_bridge_match = null;
foreach ( (array) ( $default_test_inventory['data']['posts']['items'] ?? array() ) as $default_test_inventory_item ) {
	if ( 81 === (int) ( $default_test_inventory_item['post_id'] ?? 0 ) ) {
		$default_plan_bridge_match = $default_test_inventory_item;
		break;
	}
}
npcink_abilities_toolkit_assert_same( true, $default_test_inventory['data']['detected'] ?? null, 'get-nonproduction-content-inventory detects Core Plan Bridge fixtures by default' );
npcink_abilities_toolkit_assert_same( 'core plan bridge content candidate', $default_plan_bridge_match['matched_pattern'] ?? '', 'get-nonproduction-content-inventory includes Core Plan Bridge fixture patterns by default' );
$cleanup_plan = $core_read_package->build_nonproduction_content_cleanup_plan(
	array(
		'patterns'    => array( 'Core Governance' ),
		'max_actions' => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $cleanup_plan['success'] ?? null, 'build-nonproduction-content-cleanup-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'batch', $cleanup_plan['data']['proposal_mode'] ?? '', 'nonproduction content cleanup plan requests batch proposal intake' );
npcink_abilities_toolkit_assert_same( true, $cleanup_plan['data']['batch_approval'] ?? null, 'nonproduction content cleanup plan requests one approval for the generated action batch' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/trash-post', $cleanup_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'nonproduction content cleanup plan reuses trash-post' );
npcink_abilities_toolkit_assert_same( false, $cleanup_plan['data']['write_actions'][0]['commit_execution'] ?? null, 'nonproduction content cleanup plan does not execute commits' );
$default_cleanup_plan = $core_read_package->build_nonproduction_content_cleanup_plan(
	array(
		'max_actions' => 5,
	)
);
$default_cleanup_plan_bridge_action = null;
foreach ( (array) ( $default_cleanup_plan['data']['write_actions'] ?? array() ) as $default_cleanup_action ) {
	if ( 81 === (int) ( $default_cleanup_action['input']['post_id'] ?? 0 ) ) {
		$default_cleanup_plan_bridge_action = $default_cleanup_action;
		break;
	}
}
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/trash-post', $default_cleanup_plan_bridge_action['target_ability_id'] ?? '', 'nonproduction content cleanup plan includes Core Plan Bridge fixture posts by default' );
$terms_only_cleanup_plan = $core_read_package->build_nonproduction_content_cleanup_plan(
	array(
		'include_posts'    => false,
		'include_terms'    => false,
		'include_comments' => false,
		'max_actions'      => 5,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $terms_only_cleanup_plan['data']['preview']['posts'] ?? array() ) ), 'nonproduction content cleanup plan honors include_posts=false' );
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $terms_only_cleanup_plan['data']['write_actions'] ?? array() ) ), 'nonproduction content cleanup plan does not generate post actions when include_posts=false' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][80] = (object) array(
	'ID'           => 80,
	'post_title'   => 'Inventory Fix Candidate',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => 'Inventory fix candidate content with enough text to generate a deterministic excerpt and metadata description for review planning.',
	'post_name'    => '',
	'post_author'  => 7,
	'post_modified' => '2026-05-30 11:00:00',
);
$content_fix_plan = $core_read_package->build_content_inventory_fix_plan(
	array(
		'post_ids'    => array( 80 ),
		'issue_types' => array( 'seo_title', 'seo_description', 'slug', 'excerpt' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $content_fix_plan['success'] ?? null, 'build-content-inventory-fix-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $content_fix_plan['data']['requires_approval'] ?? null, 'content inventory fix plan requires approval' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/set-post-seo-meta', $content_fix_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'content inventory fix plan reuses SEO write ability' );
npcink_abilities_toolkit_assert_same( false, $content_fix_plan['data']['write_actions'][0]['commit_execution'] ?? null, 'content inventory fix plan does not execute commits' );
npcink_abilities_toolkit_assert_true( isset( $content_fix_plan['data']['preview'][0]['before']['seo_title'] ), 'content inventory fix plan returns before preview' );
npcink_abilities_toolkit_assert_true( isset( $content_fix_plan['data']['preview'][0]['after_suggestion']['seo_title'] ), 'content inventory fix plan returns after suggestion preview' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][81] = (object) array(
	'ID'           => 81,
	'post_title'   => '',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_excerpt' => 'Has excerpt.',
	'post_content' => 'Inventory fix title candidate content with enough words to avoid unrelated body content warnings during planning.',
	'post_name'    => 'inventory-fix-title-candidate',
	'post_author'  => 7,
	'post_modified' => '2026-05-30 11:10:00',
);
$title_fix_plan = $core_read_package->build_content_inventory_fix_plan(
	array(
		'post_ids'    => array( 81 ),
		'issue_types' => array( 'title' ),
	)
);
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post', $title_fix_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'content inventory fix plan maps missing title to update-post' );
npcink_abilities_toolkit_assert_same( array( 'title' ), $title_fix_plan['data']['write_actions'][0]['requires_input'] ?? array(), 'content inventory title plan requires a reviewed title input' );
$bulk_checklist = $core_read_package->get_bulk_publishing_checklist(
	array(
		'post_ids' => array( 77, 78, 77 ),
	)
);
npcink_abilities_toolkit_assert_same( true, $bulk_checklist['success'] ?? null, 'get-bulk-publishing-checklist returns a success envelope' );
npcink_abilities_toolkit_assert_same( 2, $bulk_checklist['data']['total'] ?? null, 'get-bulk-publishing-checklist deduplicates post ids' );
npcink_abilities_toolkit_assert_true( (int) ( $bulk_checklist['data']['blocked_count'] ?? 0 ) >= 1, 'get-bulk-publishing-checklist counts blocked posts' );
$internal_link_report = $core_read_package->get_internal_link_opportunity_report(
	array(
		'post_id'       => 77,
		'focus_keyword' => 'workflow',
		'max_targets'   => 3,
	)
);
npcink_abilities_toolkit_assert_same( true, $internal_link_report['success'] ?? null, 'get-internal-link-opportunity-report returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $internal_link_report['data']['source_post']['post_id'] ?? null, 'get-internal-link-opportunity-report keeps source post id' );
npcink_abilities_toolkit_assert_true( (int) ( $internal_link_report['data']['summary']['candidate_count'] ?? 0 ) >= 1, 'get-internal-link-opportunity-report finds local candidate posts in isolated tests' );
$internal_link_candidates = $core_read_package->resolve_internal_link_targets(
	array(
		'current_post_id'           => 77,
		'post_type'                 => 'post',
		'title'                     => 'Workflow optimization guide',
		'content_text'              => 'Workflow optimization article body that needs related internal reading.',
		'query'                     => 'workflow',
		'candidate_limit'           => 4,
		'max_targets'               => 3,
		'related_content_evidence'  => array(
			array(
				'post_id'      => 77,
				'title'        => 'Current post must be excluded',
				'url'          => 'https://example.test/current',
				'evidence_ref' => 'site_knowledge:current',
			),
			array(
				'post_id'      => 280976,
				'title'        => 'Supplied related workflow target',
				'url'          => 'https://example.test/supplied-workflow',
				'score'        => 0.82,
				'evidence_ref' => 'site_knowledge:supplied_workflow',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $internal_link_candidates['success'] ?? null, 'resolve-internal-link-targets returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'internal_link_candidates.v1', $internal_link_candidates['data']['internal_link_candidates']['artifact_type'] ?? '', 'resolve-internal-link-targets returns reusable internal-link candidate artifact' );
npcink_abilities_toolkit_assert_same( 'operator_review_only_no_insert', $internal_link_candidates['data']['internal_link_candidates']['final_write_path'] ?? '', 'resolve-internal-link-targets keeps manual insertion boundary' );
npcink_abilities_toolkit_assert_same( false, $internal_link_candidates['data']['internal_link_candidates']['direct_wordpress_write'] ?? null, 'resolve-internal-link-targets does not perform WordPress writes' );
npcink_abilities_toolkit_assert_true( (int) ( $internal_link_candidates['data']['summary']['candidate_count'] ?? 0 ) >= 1, 'resolve-internal-link-targets builds bounded candidate rows' );
npcink_abilities_toolkit_assert_true( in_array( 'supplied_related_content_evidence', array_column( $internal_link_candidates['data']['internal_link_candidates']['items'] ?? array(), 'source' ), true ), 'resolve-internal-link-targets can include host-supplied related content evidence without owning that provider' );
npcink_abilities_toolkit_assert_true( ! in_array( 77, array_column( $internal_link_candidates['data']['internal_link_candidates']['items'] ?? array(), 'target_post_id' ), true ), 'resolve-internal-link-targets excludes the current post from candidates' );
$GLOBALS['npcink_abilities_toolkit_unit_comments'][21] = (object) array(
	'comment_ID'       => 21,
	'comment_post_ID'  => 77,
	'comment_author'   => 'Ops Reader',
	'comment_approved' => 'hold',
	'comment_content'  => 'Please review this operations comment.',
);
$GLOBALS['npcink_abilities_toolkit_unit_terms'] = array(
	'category' => array(
		(object) array(
			'term_id'     => 301,
			'name'        => 'Workflow',
			'slug'        => 'workflow',
			'description' => '',
			'count'       => 0,
			'parent'      => 0,
		),
	),
);
$site_operations = $core_read_package->get_site_operations_dashboard(
	array(
		'post_type' => 'post',
		'per_page'  => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $site_operations['success'] ?? null, 'get-site-operations-dashboard returns a success envelope' );
npcink_abilities_toolkit_assert_true( isset( $site_operations['data']['status_counts']['draft'] ), 'get-site-operations-dashboard returns status counts' );
npcink_abilities_toolkit_assert_true( (int) ( $site_operations['data']['comments']['pending_count'] ?? 0 ) >= 1, 'get-site-operations-dashboard counts pending comments' );
$publish_risk = $core_read_package->get_post_publish_risk_report(
	array(
		'post_id'       => 77,
		'focus_keyword' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $publish_risk['success'] ?? null, 'get-post-publish-risk-report returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $publish_risk['data']['post']['post_id'] ?? null, 'get-post-publish-risk-report keeps post id' );
npcink_abilities_toolkit_assert_true( (int) ( $publish_risk['data']['risk_score'] ?? 0 ) > 0, 'get-post-publish-risk-report returns a positive risk score for incomplete drafts' );
$article_publish_preflight = $core_read_package->get_article_publish_preflight_context(
	array(
		'post_id'       => 77,
		'focus_keyword' => 'workflow',
		'window_days'   => 30,
	)
);
npcink_abilities_toolkit_assert_same( true, $article_publish_preflight['success'] ?? null, 'get-article-publish-preflight-context returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/recipes/article-publish-preflight', $article_publish_preflight['data']['recipe'] ?? '', 'get-article-publish-preflight-context declares its recipe id' );
npcink_abilities_toolkit_assert_true( in_array( 'publish_risk', $article_publish_preflight['data']['sections'] ?? array(), true ), 'get-article-publish-preflight-context includes publish risk' );
$refresh_opportunities = $core_read_package->get_content_refresh_opportunities(
	array(
		'post_type'      => 'post',
		'status'         => 'any',
		'per_page'       => 5,
		'stale_days'     => 30,
		'min_word_count' => 200,
	)
);
npcink_abilities_toolkit_assert_same( true, $refresh_opportunities['success'] ?? null, 'get-content-refresh-opportunities returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $refresh_opportunities['data']['summary']['opportunity_count'] ?? 0 ) >= 1, 'get-content-refresh-opportunities finds refresh candidates' );
npcink_abilities_toolkit_assert_true( isset( $refresh_opportunities['data']['issue_counts']['thin_content'] ), 'get-content-refresh-opportunities counts thin content' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][77]->post_content = 'Optimization context content with <a href="https://example.test/?p=78">workflow candidate</a>.';
$internal_link_graph = $core_read_package->get_internal_link_graph_health(
	array(
		'post_type' => 'post',
		'status'    => 'any',
		'per_page'  => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $internal_link_graph['success'] ?? null, 'get-internal-link-graph-health returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $internal_link_graph['data']['summary']['scanned_count'] ?? 0 ) >= 1, 'get-internal-link-graph-health scans local posts' );
npcink_abilities_toolkit_assert_true( isset( $internal_link_graph['data']['issue_counts']['orphan_post'] ), 'get-internal-link-graph-health counts orphan posts' );
$old_article_refresh = $core_read_package->get_old_article_refresh_context(
	array(
		'post_type'     => 'post',
		'status'        => 'any',
		'per_page'      => 5,
		'topic_seed'    => 'workflow',
		'post_id'       => 77,
		'focus_keyword' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $old_article_refresh['success'] ?? null, 'get-old-article-refresh-context returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/recipes/old-article-refresh-discovery', $old_article_refresh['data']['recipe'] ?? '', 'get-old-article-refresh-context declares its recipe id' );
npcink_abilities_toolkit_assert_true( in_array( 'seo_geo_gap_report', $old_article_refresh['data']['sections'] ?? array(), true ), 'get-old-article-refresh-context includes SEO/GEO gaps' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][79] = (object) array(
	'ID'             => 79,
	'post_title'     => 'Workflow diagram image',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'workflow-diagram-image',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/jpeg',
);
update_post_meta(
	79,
	'_wp_attachment_metadata',
	array(
		'width'          => 2600,
		'height'         => 1400,
		'file'           => '2026/06/workflow-diagram-image.jpg',
		'filesize'       => 900000,
		'original_image' => 'workflow-diagram-image-original.jpg',
		'sizes'          => array(
			'medium' => array(
				'file'   => 'workflow-diagram-image-300x162.jpg',
				'width'  => 300,
				'height' => 162,
			),
		),
	)
);
update_post_meta( 79, '_wp_attached_file', '2026/06/workflow-diagram-image.jpg' );
$cloud_meta_cas_method = new ReflectionMethod( Core_Write_Package::class, 'cloud_media_atomic_compare_and_swap_post_meta' );
$cloud_meta_cas_method->setAccessible( true );

$empty_meta_key = '_npcink_ai_media_latest_file_replacement';
update_post_meta( 79, $empty_meta_key, '' );
$empty_meta_race_injected = false;
$GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] = static function ( $post_id, $meta_key ) use ( &$empty_meta_race_injected, $empty_meta_key ) {
	if ( ! $empty_meta_race_injected && 79 === (int) $post_id && $empty_meta_key === (string) $meta_key ) {
		$empty_meta_race_injected = true;
		$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79][ $empty_meta_key ] = 'external-empty-value-winner';
	}
	return null;
};
$empty_meta_race = $cloud_meta_cas_method->invoke(
	$core_write_package,
	79,
	$empty_meta_key,
	array( 'exists' => true, 'value' => '' ),
	array( 'exists' => true, 'value' => 'batch-empty-replacement' )
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $empty_meta_race ) && 'npcink_abilities_toolkit_cloud_adoption_mutation_conflict' === $empty_meta_race->get_error_code(), 'locked raw postmeta CAS rejects an external write when the reviewed previous value was empty' );
npcink_abilities_toolkit_assert_same( 'external-empty-value-winner', get_post_meta( 79, $empty_meta_key, true ), 'empty-value postmeta race preserves the external winner byte-for-byte' );
delete_post_meta( 79, $empty_meta_key );

$case_meta_key = '_npcink_ai_media_latest_optimized_derivative';
update_post_meta( 79, $case_meta_key, 'lowercase-value' );
$case_meta_race_injected = false;
$GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] = static function ( $post_id, $meta_key ) use ( &$case_meta_race_injected, $case_meta_key ) {
	if ( ! $case_meta_race_injected && 79 === (int) $post_id && $case_meta_key === (string) $meta_key ) {
		$case_meta_race_injected = true;
		$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79][ $case_meta_key ] = 'LOWERCASE-VALUE';
	}
	return null;
};
$case_meta_race = $cloud_meta_cas_method->invoke(
	$core_write_package,
	79,
	$case_meta_key,
	array( 'exists' => true, 'value' => 'lowercase-value' ),
	array( 'exists' => true, 'value' => 'batch-case-replacement' )
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $case_meta_race ), 'locked raw postmeta CAS rejects case-only external value drift despite case-insensitive database collations' );
npcink_abilities_toolkit_assert_same( 'LOWERCASE-VALUE', get_post_meta( 79, $case_meta_key, true ), 'case-only postmeta race preserves the external winner byte-for-byte' );
delete_post_meta( 79, $case_meta_key );

$missing_meta_key = '_npcink_ai_media_optimized_derivatives';
delete_post_meta( 79, $missing_meta_key );
$missing_meta_race_injected = false;
$GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] = static function ( $post_id, $meta_key ) use ( &$missing_meta_race_injected, $missing_meta_key ) {
	if ( ! $missing_meta_race_injected && 79 === (int) $post_id && $missing_meta_key === (string) $meta_key ) {
		$missing_meta_race_injected = true;
		$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79][ $missing_meta_key ] = 'external-second-batch';
		npcink_abilities_toolkit_unit_meta_id( 79, $missing_meta_key );
	}
	return null;
};
$missing_meta_race = $cloud_meta_cas_method->invoke(
	$core_write_package,
	79,
	$missing_meta_key,
	array( 'exists' => false, 'value' => null ),
	array( 'exists' => true, 'value' => 'losing-first-batch' )
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wpdb_postmeta_select_for_update_callback'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $missing_meta_race ), 'attachment parent-row serialization forces a missing-key contender to re-read and reject the committed second-batch value' );
npcink_abilities_toolkit_assert_same( 'external-second-batch', get_post_meta( 79, $missing_meta_key, true ), 'missing-key postmeta race never creates or overwrites a second logical row' );
delete_post_meta( 79, $missing_meta_key );

$reentrant_meta_key = '_npcink_ai_media_latest_optimized_derivative';
update_post_meta( 79, $reentrant_meta_key, 'outer-before' );
$reentrant_meta_result = null;
$GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'] = static function ( $post_id, $meta_key ) use ( &$reentrant_meta_result, $reentrant_meta_key, $cloud_meta_cas_method, $core_write_package ) {
	if ( null === $reentrant_meta_result && 79 === (int) $post_id && $reentrant_meta_key === (string) $meta_key ) {
		$reentrant_meta_result = $cloud_meta_cas_method->invoke(
			$core_write_package,
			79,
			$reentrant_meta_key,
			array( 'exists' => true, 'value' => 'outer-before' ),
			array( 'exists' => true, 'value' => 'nested-write' )
		);
	}
	return null;
};
$outer_meta_result = $cloud_meta_cas_method->invoke(
	$core_write_package,
	79,
	$reentrant_meta_key,
	array( 'exists' => true, 'value' => 'outer-before' ),
	array( 'exists' => true, 'value' => 'outer-after' )
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'] );
$reentrant_meta_data = is_wp_error( $reentrant_meta_result ) ? $reentrant_meta_result->get_error_data() : array();
npcink_abilities_toolkit_assert_true( true === $outer_meta_result && is_wp_error( $reentrant_meta_result ), 'WordPress meta hooks cannot open a nested Cloud media transaction on the same package instance' );
npcink_abilities_toolkit_assert_same( 'transaction_reentrant', $reentrant_meta_data['stage'] ?? '', 'nested Cloud media transaction attempts return a bounded reentrancy conflict before START TRANSACTION' );
npcink_abilities_toolkit_assert_same( 'outer-after', get_post_meta( 79, $reentrant_meta_key, true ), 'reentrancy guard preserves the outer locked WordPress meta lifecycle' );
delete_post_meta( 79, $reentrant_meta_key );

$cloud_post_field_cas_method = new ReflectionMethod( Core_Write_Package::class, 'cloud_media_atomic_compare_and_swap_post_field' );
$cloud_post_field_cas_method->setAccessible( true );
$reentrant_post_result = null;
$GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'] = static function ( array $postarr ) use ( &$reentrant_post_result, $cloud_post_field_cas_method, $core_write_package ) {
	if ( null === $reentrant_post_result && 79 === (int) ( $postarr['ID'] ?? 0 ) && array_key_exists( 'post_mime_type', $postarr ) ) {
		$reentrant_post_result = $cloud_post_field_cas_method->invoke( $core_write_package, 79, 'post_mime_type', 'image/jpeg', 'image/png' );
	}
	return null;
};
$outer_post_result = $cloud_post_field_cas_method->invoke( $core_write_package, 79, 'post_mime_type', 'image/jpeg', 'image/webp' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'] );
$reentrant_post_data = is_wp_error( $reentrant_post_result ) ? $reentrant_post_result->get_error_data() : array();
npcink_abilities_toolkit_assert_true( true === $outer_post_result && is_wp_error( $reentrant_post_result ), 'wp_update_post hooks cannot open a nested Cloud media transaction on the same package instance' );
npcink_abilities_toolkit_assert_same( 'transaction_reentrant', $reentrant_post_data['stage'] ?? '', 'wp_update_post reentrancy is rejected before nested START TRANSACTION can implicitly commit the outer transaction' );
npcink_abilities_toolkit_assert_same( 'image/webp', get_post_mime_type( 79 ), 'wp_update_post reentrancy guard preserves the outer locked post-field lifecycle' );
$restore_post_result = $cloud_post_field_cas_method->invoke( $core_write_package, 79, 'post_mime_type', 'image/webp', 'image/jpeg' );
npcink_abilities_toolkit_assert_same( true, $restore_post_result, 'post-field transaction fixture restores the attachment MIME after reentrancy coverage' );

$GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] = sys_get_temp_dir() . '/npcink-abilities-toolkit-media-' . getmypid();
$workflow_media_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/workflow-diagram-image.jpg';
if ( ! is_dir( dirname( $workflow_media_path ) ) ) {
	mkdir( dirname( $workflow_media_path ), 0755, true );
}
file_put_contents( $workflow_media_path, 'original-jpeg-bytes' );
$rollback_unknown_relative = '2026/06/rollback-unknown.webp';
$rollback_unknown_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $rollback_unknown_relative;
file_put_contents( $rollback_unknown_path, 'rollback-unknown-bytes' );
$rollback_unknown_stat = lstat( $rollback_unknown_path );
$rollback_unknown_original_pointer = (string) get_post_meta( 79, '_wp_attached_file', true );
update_post_meta( 79, '_wp_attached_file', $rollback_unknown_relative );
$rollback_unknown_manifest = (object) array(
	'created_files' => array(
		array(
			'path'                  => $rollback_unknown_path,
			'device'                => (int) ( $rollback_unknown_stat['dev'] ?? -1 ),
			'inode'                 => (int) ( $rollback_unknown_stat['ino'] ?? -1 ),
			'created_by_this_batch' => true,
		),
	),
	'mutations' => array(
		'meta:79:_wp_attached_file' => array(
			'kind'     => 'meta',
			'post_id'  => 79,
			'meta_key' => '_wp_attached_file',
			'before'   => array( 'exists' => true, 'value' => $rollback_unknown_original_pointer ),
			'after'    => array( 'exists' => true, 'value' => $rollback_unknown_relative ),
		),
	),
);
$rollback_unknown_cleanup_method = new ReflectionMethod( Core_Write_Package::class, 'cleanup_failed_cloud_media_adoption' );
$rollback_unknown_cleanup_method->setAccessible( true );
$rollback_unknown_package = new Core_Write_Package( $package_categories, $package_registrar );
$GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'] = static function ( $post_id, $meta_key ) {
	return 79 === (int) $post_id && '_wp_attached_file' === (string) $meta_key ? false : null;
};
$GLOBALS['npcink_abilities_toolkit_unit_wpdb_query_callback'] = static function ( $sql ) {
	return 'ROLLBACK' === strtoupper( trim( (string) $sql ) ) ? false : null;
};
$rollback_unknown_cleanup = $rollback_unknown_cleanup_method->invoke(
	$rollback_unknown_package,
	79,
	array( '_cloud_batch_manifest' => $rollback_unknown_manifest ),
	array(),
	array(
		'attachment_mime_type' => 'image/jpeg',
		'post_contents'         => array(),
		'meta'                  => array(
			'_wp_attached_file' => array( 'exists' => true, 'value' => $rollback_unknown_original_pointer ),
		),
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'], $GLOBALS['npcink_abilities_toolkit_unit_wpdb_query_callback'] );
$GLOBALS['wpdb']->query( 'ROLLBACK' );
$rollback_unknown_data = is_wp_error( $rollback_unknown_cleanup ) ? $rollback_unknown_cleanup->get_error_data() : array();
npcink_abilities_toolkit_assert_true( is_wp_error( $rollback_unknown_cleanup ) && 'npcink_abilities_toolkit_cloud_adoption_cleanup_conflict' === $rollback_unknown_cleanup->get_error_code(), 'cleanup stops immediately when a locked postmeta rollback cannot be confirmed' );
npcink_abilities_toolkit_assert_same( array( 'wordpress_transaction_state_unknown' ), $rollback_unknown_data['conflicts'] ?? array(), 'unknown cleanup transaction state returns one bounded conflict reason' );
npcink_abilities_toolkit_assert_same( $rollback_unknown_relative, get_post_meta( 79, '_wp_attached_file', true ), 'unknown cleanup transaction state preserves the current pointer for operator diagnosis' );
npcink_abilities_toolkit_assert_true( is_file( $rollback_unknown_path ), 'unknown cleanup transaction state skips all manifest file deletion' );
update_post_meta( 79, '_wp_attached_file', $rollback_unknown_original_pointer );
wp_delete_file( $rollback_unknown_path );
$media_url_resolution = $core_read_package->resolve_media_attachment_by_url(
	array(
		'url' => 'https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_url_resolution['success'] ?? null, 'resolve-media-attachment-by-url returns a success envelope for exact uploads URLs' );
npcink_abilities_toolkit_assert_same( true, $media_url_resolution['data']['readonly'] ?? null, 'resolve-media-attachment-by-url is read-only' );
npcink_abilities_toolkit_assert_same( 'resolved', $media_url_resolution['data']['match_status'] ?? '', 'resolve-media-attachment-by-url resolves one exact attachment match' );
npcink_abilities_toolkit_assert_same( 79, $media_url_resolution['data']['attachment_id'] ?? 0, 'resolve-media-attachment-by-url returns the matched attachment id' );
npcink_abilities_toolkit_assert_same( false, $media_url_resolution['data']['boundary']['wordpress_write_included'] ?? null, 'resolve-media-attachment-by-url does not write WordPress data' );
$media_size_url_resolution = $core_read_package->resolve_media_attachment_by_url(
	array(
		'url' => 'https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_size_url_resolution['success'] ?? null, 'resolve-media-attachment-by-url returns a success envelope for metadata size URLs' );
npcink_abilities_toolkit_assert_same( 79, $media_size_url_resolution['data']['attachment_id'] ?? 0, 'resolve-media-attachment-by-url resolves a metadata size URL to the parent attachment' );
npcink_abilities_toolkit_assert_same( 'metadata_size_file', $media_size_url_resolution['data']['candidates'][0]['match_type'] ?? '', 'resolve-media-attachment-by-url records size variant evidence' );
$external_media_url_resolution = $core_read_package->resolve_media_attachment_by_url(
	array(
		'url' => 'https://cdn.example.invalid/wp-content/uploads/2026/06/workflow-diagram-image.jpg',
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $external_media_url_resolution ) && 'npcink_abilities_toolkit_media_url_external' === $external_media_url_resolution->get_error_code(), 'resolve-media-attachment-by-url rejects external uploads-looking URLs' );
$media_inspection = $core_read_package->inspect_media_asset(
	array(
		'attachment_id'              => 79,
		'target_max_width'           => 1920,
		'large_file_threshold_bytes' => 524288,
		'preferred_format'           => 'webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_inspection['success'] ?? null, 'inspect-media-asset returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'jpeg', $media_inspection['data']['source_format'] ?? '', 'inspect-media-asset resolves JPEG source format' );
npcink_abilities_toolkit_assert_same( true, $media_inspection['data']['format_plan']['should_convert'] ?? null, 'inspect-media-asset recommends conversion for legacy JPEG' );
npcink_abilities_toolkit_assert_same( true, $media_inspection['data']['format_plan']['should_resize'] ?? null, 'inspect-media-asset recommends resizing over-wide images' );
npcink_abilities_toolkit_assert_same( true, $media_inspection['data']['format_plan']['should_compress'] ?? null, 'inspect-media-asset recommends compression for large images' );
npcink_abilities_toolkit_assert_same( 'webp', $media_inspection['data']['format_plan']['recommended_format'] ?? '', 'inspect-media-asset recommends WebP by default' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', $media_inspection['data']['current_relative_file'] ?? '', 'inspect-media-asset returns current relative file for guarded writes' );
npcink_abilities_toolkit_assert_same( true, $media_inspection['data']['content_hashes']['available'] ?? null, 'inspect-media-asset returns available content hashes when the file is readable' );
npcink_abilities_toolkit_assert_same( md5( 'original-jpeg-bytes' ), $media_inspection['data']['content_hashes']['md5'] ?? '', 'inspect-media-asset returns current file MD5' );
npcink_abilities_toolkit_assert_same( hash( 'sha256', 'original-jpeg-bytes' ), $media_inspection['data']['content_hashes']['sha256'] ?? '', 'inspect-media-asset returns current file SHA-256' );
npcink_abilities_toolkit_assert_same( 'local_uploads', $media_inspection['data']['storage']['provider'] ?? '', 'inspect-media-asset reports local uploads storage by default' );
npcink_abilities_toolkit_assert_same( 'local_file', $media_inspection['data']['storage']['source_read_mode'] ?? '', 'inspect-media-asset reports local file source read mode when readable' );
npcink_abilities_toolkit_assert_same( '', $media_inspection['data']['storage']['blocked_reason'] ?? '', 'inspect-media-asset leaves local readable media unblocked' );
$GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] = 'https://origin.example.test/wp-content/uploads';
$remote_storage_inspection = $core_read_package->inspect_media_asset(
	array(
		'attachment_id' => 79,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] );
npcink_abilities_toolkit_assert_same( true, $remote_storage_inspection['success'] ?? null, 'inspect-media-asset returns success for remote-storage-looking media' );
npcink_abilities_toolkit_assert_same( 'remote_object_storage', $remote_storage_inspection['data']['storage']['provider'] ?? '', 'inspect-media-asset detects attachment URLs outside the uploads base as remote object storage' );
npcink_abilities_toolkit_assert_same( 'remote_storage_write_requires_adapter', $remote_storage_inspection['data']['storage']['blocked_reason'] ?? '', 'inspect-media-asset blocks remote storage writes until a host adapter is present' );
$media_cloud_request = $core_read_package->build_media_derivative_cloud_request(
	array(
		'attachment_id'              => 79,
		'target_max_width'           => 1920,
		'large_file_threshold_bytes' => 524288,
		'preferred_format'           => 'webp',
		'quality'                    => 82,
		'crop'                       => array(
			'type'         => 'aspect_ratio',
			'aspect_ratio' => '16:9',
			'position'     => 'center',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_cloud_request['success'] ?? null, 'build-media-derivative-cloud-request returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $media_cloud_request['data']['readonly'] ?? null, 'media derivative cloud request is read-only' );
npcink_abilities_toolkit_assert_same( 'media_derivative_cloud_request.v1', $media_cloud_request['data']['request_contract_version'] ?? '', 'media derivative cloud request exposes a versioned contract' );
npcink_abilities_toolkit_assert_same( 'local_uploads', $media_cloud_request['data']['storage']['provider'] ?? '', 'media derivative cloud request carries storage preflight evidence' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request['data']['blocked'] ?? null, 'media derivative cloud request is not blocked for local readable media' );
npcink_abilities_toolkit_assert_same( 'local_file', $media_cloud_request['data']['cloud_execution']['source_read_mode'] ?? '', 'media derivative cloud request carries source read mode for host transport' );
npcink_abilities_toolkit_assert_same( 'generate_optimized_media_derivative', $media_cloud_request['data']['cloud_job_payload']['job_type'] ?? '', 'media derivative cloud request targets derivative generation' );
npcink_abilities_toolkit_assert_same( 'webp', $media_cloud_request['data']['cloud_job_payload']['target_format'] ?? '', 'media derivative cloud request exposes Cloud target format' );
npcink_abilities_toolkit_assert_same( 1920, $media_cloud_request['data']['cloud_job_payload']['max_width'] ?? 0, 'media derivative cloud request exposes Cloud max width' );
npcink_abilities_toolkit_assert_same( 82, $media_cloud_request['data']['cloud_job_payload']['quality'] ?? 0, 'media derivative cloud request exposes Cloud quality' );
npcink_abilities_toolkit_assert_same( 'aspect_ratio', $media_cloud_request['data']['cloud_job_payload']['crop']['type'] ?? '', 'media derivative cloud request preserves crop type' );
npcink_abilities_toolkit_assert_same( '16:9', $media_cloud_request['data']['cloud_job_payload']['crop']['aspect_ratio'] ?? '', 'media derivative cloud request preserves crop aspect ratio' );
npcink_abilities_toolkit_assert_same( 'center', $media_cloud_request['data']['cloud_job_payload']['crop']['position'] ?? '', 'media derivative cloud request preserves crop position' );
npcink_abilities_toolkit_assert_same( 'webp', $media_cloud_request['data']['cloud_job_payload']['requested_derivative']['format'] ?? '', 'media derivative cloud request preserves preferred format' );
npcink_abilities_toolkit_assert_same( 1920, $media_cloud_request['data']['cloud_job_payload']['requested_derivative']['max_width'] ?? 0, 'media derivative cloud request preserves target max width' );
npcink_abilities_toolkit_assert_same( true, $media_cloud_request['data']['cloud_execution']['source_upload_required'] ?? null, 'media derivative cloud request requires host-provided source upload' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request['data']['cloud_execution']['credentials_included'] ?? null, 'media derivative cloud request does not include credentials' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request['data']['cloud_execution']['authorization_included'] ?? null, 'media derivative cloud request does not include authorization headers' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request['data']['cloud_execution']['signed_headers_included'] ?? null, 'media derivative cloud request does not include signed headers' );
npcink_abilities_toolkit_assert_same( 'local_wordpress_host', $media_cloud_request['data']['local_adoption']['final_write_owner'] ?? '', 'media derivative cloud request leaves final writes local' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request['data']['local_adoption']['wordpress_write_included'] ?? null, 'media derivative cloud request does not write WordPress' );
$GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] = 'https://origin.example.test/wp-content/uploads';
$remote_storage_cloud_request = $core_read_package->build_media_derivative_cloud_request(
	array(
		'attachment_id' => 79,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] );
npcink_abilities_toolkit_assert_same( true, $remote_storage_cloud_request['success'] ?? null, 'media derivative cloud request returns success for remote-storage-looking media' );
npcink_abilities_toolkit_assert_same( true, $remote_storage_cloud_request['data']['blocked'] ?? null, 'media derivative cloud request blocks remote storage without a host adapter' );
npcink_abilities_toolkit_assert_same( 'remote_storage_write_requires_adapter', $remote_storage_cloud_request['data']['blocked_reason'] ?? '', 'media derivative cloud request explains missing remote storage adapter' );
add_filter(
	'npcink_abilities_toolkit_media_storage_inspection',
	static function ( $storage, $attachment_id ) {
		if ( 79 !== (int) $attachment_id ) {
			return $storage;
		}
		$storage['provider']             = 'remote_object_storage';
		$storage['adapter']              = 'aliyun_oss_mock';
		$storage['source_read_mode']     = 'signed_url';
		$storage['write_mode']           = 'provider_api';
		$storage['restore_mode']         = 'provider_backup';
		$storage['cache_purge_required'] = true;
		$storage['blocked_reason']       = '';
		return $storage;
	},
	10,
	2
);
$GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] = 'https://origin.example.test/wp-content/uploads';
$mock_oss_cloud_request = $core_read_package->build_media_derivative_cloud_request(
	array(
		'attachment_id' => 79,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_upload_baseurl'] );
remove_all_filters( 'npcink_abilities_toolkit_media_storage_inspection' );
npcink_abilities_toolkit_assert_same( true, $mock_oss_cloud_request['success'] ?? null, 'media derivative cloud request accepts mock OSS storage adapter readiness' );
npcink_abilities_toolkit_assert_same( false, $mock_oss_cloud_request['data']['blocked'] ?? null, 'mock OSS storage adapter clears remote storage block for review planning' );
npcink_abilities_toolkit_assert_same( 'aliyun_oss_mock', $mock_oss_cloud_request['data']['storage']['adapter'] ?? '', 'mock OSS storage adapter identity is preserved in storage evidence' );
$mock_oss_optimization_plan = $core_read_package->build_media_optimization_plan(
	array(
		'attachment_id'       => 79,
		'media_details_input' => array(
			'title'       => 'Mock OSS optimized workflow diagram',
			'alt'         => 'Mock OSS optimized workflow diagram alt text.',
			'caption'     => 'Mock OSS optimized workflow diagram caption.',
			'description' => 'Mock OSS optimized workflow diagram description.',
			'source_type' => 'owned',
		),
		'derivative_artifact' => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000001',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1600,
			'height'         => 862,
			'filesize_bytes' => 12345,
			'sha256'         => hash( 'sha256', 'mock-oss-webp' ),
		) ),
		'storage_preflight'  => $mock_oss_cloud_request['data']['storage'],
	)
);
npcink_abilities_toolkit_assert_same( true, $mock_oss_optimization_plan['success'] ?? null, 'media optimization plan accepts mock OSS storage preflight evidence' );
npcink_abilities_toolkit_assert_same( 'remote_object_storage', $mock_oss_optimization_plan['data']['write_actions'][1]['input']['expected_storage_provider'] ?? '', 'media optimization plan carries expected storage provider into derivative adoption action' );
npcink_abilities_toolkit_assert_same( 'aliyun_oss_mock', $mock_oss_optimization_plan['data']['write_actions'][1]['input']['expected_storage_adapter'] ?? '', 'media optimization plan carries expected storage adapter into derivative adoption action' );
npcink_abilities_toolkit_assert_same( 'provider_api', $mock_oss_optimization_plan['data']['write_actions'][1]['input']['storage_preflight']['write_mode'] ?? '', 'media optimization plan preserves reviewed provider write mode evidence' );
$media_cloud_request_with_watermark = $core_read_package->build_media_derivative_cloud_request(
	array(
		'attachment_id'              => 79,
		'target_max_width'           => 1600,
		'large_file_threshold_bytes' => 524288,
		'preferred_format'           => 'png',
		'quality'                    => 90,
		'watermark'                  => array(
			'type'          => 'image',
			'artifact_id'   => 'art_00000000000000000000000000000002',
			'position'      => 'top_right',
			'opacity'       => 0.5,
			'scale_percent' => 22,
			'margin_px'     => 16,
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_cloud_request_with_watermark['success'] ?? null, 'build-media-derivative-cloud-request accepts optional image watermark plans' );
npcink_abilities_toolkit_assert_same( 'png', $media_cloud_request_with_watermark['data']['cloud_job_payload']['target_format'] ?? '', 'watermarked media derivative request exposes Cloud target format' );
npcink_abilities_toolkit_assert_same( 'image', $media_cloud_request_with_watermark['data']['cloud_job_payload']['watermark']['type'] ?? '', 'watermarked media derivative request preserves watermark type' );
npcink_abilities_toolkit_assert_same( 'art_00000000000000000000000000000002', $media_cloud_request_with_watermark['data']['cloud_job_payload']['watermark']['artifact_id'] ?? '', 'watermarked media derivative request preserves watermark artifact reference' );
npcink_abilities_toolkit_assert_same( 'top_right', $media_cloud_request_with_watermark['data']['cloud_job_payload']['watermark']['position'] ?? '', 'watermarked media derivative request preserves watermark position' );
npcink_abilities_toolkit_assert_same( 0.5, $media_cloud_request_with_watermark['data']['cloud_job_payload']['watermark']['opacity'] ?? null, 'watermarked media derivative request preserves watermark opacity' );
npcink_abilities_toolkit_assert_same( 22, $media_cloud_request_with_watermark['data']['cloud_job_payload']['watermark']['scale_percent'] ?? 0, 'watermarked media derivative request preserves watermark scale' );
npcink_abilities_toolkit_assert_same( false, $media_cloud_request_with_watermark['data']['local_adoption']['wordpress_write_included'] ?? null, 'watermarked media derivative request still does not write WordPress' );
$media_cloud_request_with_text_watermark = $core_read_package->build_media_derivative_cloud_request(
	array(
		'attachment_id'    => 79,
		'preferred_format' => 'webp',
		'watermark'        => array(
			'type'       => 'text',
			'text'       => '<strong>AI</strong>',
			'position'   => 'top_right',
			'opacity'    => 0.75,
			'font_size'  => 48,
			'color'      => '#ffffff',
			'background' => 'rgba(0,0,0,0.35)',
			'margin_px'  => 24,
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_cloud_request_with_text_watermark['success'] ?? null, 'build-media-derivative-cloud-request accepts optional text watermark plans' );
npcink_abilities_toolkit_assert_same( 'text', $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['type'] ?? '', 'text watermarked media derivative request preserves watermark type' );
npcink_abilities_toolkit_assert_same( 'AI', $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['text'] ?? '', 'text watermarked media derivative request normalizes plain text content' );
npcink_abilities_toolkit_assert_same( 'top_right', $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['position'] ?? '', 'text watermarked media derivative request preserves watermark position' );
npcink_abilities_toolkit_assert_same( 48, $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['font_size'] ?? 0, 'text watermarked media derivative request preserves bounded font size' );
npcink_abilities_toolkit_assert_same( '#FFFFFF', $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['color'] ?? '', 'text watermarked media derivative request normalizes hex color' );
npcink_abilities_toolkit_assert_same( 'rgba(0,0,0,0.35)', $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['background'] ?? '', 'text watermarked media derivative request preserves bounded rgba background' );
npcink_abilities_toolkit_assert_true( ! isset( $media_cloud_request_with_text_watermark['data']['cloud_job_payload']['watermark']['artifact_id'] ), 'text watermarked media derivative request does not require a watermark artifact id' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][90] = (object) array(
	'ID'           => 90,
	'post_title'   => 'Media Optimization Reference Preview Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<!-- wp:image {"id":79,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg" srcset="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg 300w, https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg 2600w" alt="Workflow diagram" class="wp-image-79" /></figure><!-- /wp:image -->',
	'post_name'    => 'media-optimization-reference-preview-candidate',
	'post_author'  => 7,
);
$invalid_integrity_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000003',
		'expires_at'     => gmdate( 'c', time() + 600 ),
		'mime_type'      => 'image/webp',
		'format'         => 'webp',
		'width'          => 1600,
		'height'         => 862,
		'filesize_bytes' => 210000,
		'sha256'         => 'not-a-sha256',
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $invalid_integrity_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_sha256_required' === $invalid_integrity_artifact->get_error_code(), 'shared derivative artifact contract rejects missing or malformed SHA-256 evidence' );
$valid_transform_facts_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( npcink_abilities_toolkit_cloud_artifact_fixture() );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $valid_transform_facts_artifact ), 'shared derivative artifact contract accepts complete Cloud v3 scalar transformation facts' );
npcink_abilities_toolkit_assert_same( npcink_abilities_toolkit_cloud_artifact_fixture()['transform_facts'], $valid_transform_facts_artifact['transform_facts'] ?? array(), 'shared derivative artifact normalization preserves Cloud v3 transformation facts' );
$invalid_transform_fact_type = npcink_abilities_toolkit_cloud_artifact_fixture();
$invalid_transform_fact_type['transform_facts']['source_checksum'] = array();
$invalid_transform_fact_type = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( $invalid_transform_fact_type );
npcink_abilities_toolkit_assert_true( is_wp_error( $invalid_transform_fact_type ) && 'npcink_abilities_toolkit_cloud_artifact_transform_facts_checksum_invalid' === $invalid_transform_fact_type->get_error_code(), 'shared derivative artifact contract rejects non-scalar Cloud v2 checksum facts' );
$unknown_transform_fact = npcink_abilities_toolkit_cloud_artifact_fixture();
$unknown_transform_fact['transform_facts']['provider_detail'] = 'forbidden';
$unknown_transform_fact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( $unknown_transform_fact );
npcink_abilities_toolkit_assert_true( is_wp_error( $unknown_transform_fact ) && 'npcink_abilities_toolkit_cloud_artifact_transform_facts_fields_invalid' === $unknown_transform_fact->get_error_code(), 'shared derivative artifact contract rejects undeclared Cloud v2 transformation facts' );
$mismatched_transform_fact = npcink_abilities_toolkit_cloud_artifact_fixture();
$mismatched_transform_fact['transform_facts']['output_width'] = 1599;
$mismatched_transform_fact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( $mismatched_transform_fact );
npcink_abilities_toolkit_assert_true( is_wp_error( $mismatched_transform_fact ) && 'npcink_abilities_toolkit_cloud_artifact_transform_facts_output_mismatch' === $mismatched_transform_fact->get_error_code(), 'shared derivative artifact contract rejects transformation facts that disagree with the reviewed Artifact' );
$relative_expiry_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000004',
		'expires_at'     => 'tomorrow',
		'mime_type'      => 'image/webp',
		'format'         => 'webp',
		'width'          => 1600,
		'height'         => 862,
		'filesize_bytes' => 210000,
		'sha256'         => hash( 'sha256', 'relative-expiry' ),
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $relative_expiry_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_expiry_required' === $relative_expiry_artifact->get_error_code(), 'shared derivative artifact contract rejects relative timestamps outside the RFC3339 schema' );
$utc_z_expiry_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_0000000000000000000000000000001f',
			'expires_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ),
		)
	)
);
npcink_abilities_toolkit_assert_true( ! is_wp_error( $utc_z_expiry_artifact ), 'shared derivative artifact contract accepts canonical UTC Z timestamps' );
$microsecond_expiry = gmdate( 'Y-m-d\TH:i:s', time() + 600 ) . '.123456+00:00';
$microsecond_expiry_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_00000000000000000000000000000022',
			'expires_at'  => $microsecond_expiry,
		)
	)
);
npcink_abilities_toolkit_assert_same( $microsecond_expiry, $microsecond_expiry_artifact['expires_at'] ?? '', 'shared derivative artifact contract preserves the exact validated UTC microsecond expiry for delivery ACK binding' );
$non_utc_expiry_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_00000000000000000000000000000020',
			'expires_at'  => gmdate( 'Y-m-d\TH:i:s', time() + 600 ) . '+08:00',
		)
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $non_utc_expiry_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_expiry_required' === $non_utc_expiry_artifact->get_error_code(), 'shared derivative artifact contract rejects non-UTC offsets' );
$invalid_calendar_expiry_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_00000000000000000000000000000021',
			'expires_at'  => '2026-02-30T12:00:00+00:00',
		)
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $invalid_calendar_expiry_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_expiry_required' === $invalid_calendar_expiry_artifact->get_error_code(), 'shared derivative artifact contract rejects impossible UTC calendar timestamps' );
$negative_dimension_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000005',
		'expires_at'     => gmdate( 'c', time() + 600 ),
		'mime_type'      => 'image/webp',
		'format'         => 'webp',
		'width'          => -1,
		'height'         => 862,
		'filesize_bytes' => 210000,
		'sha256'         => hash( 'sha256', 'negative-dimension' ),
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $negative_dimension_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_dimensions_invalid' === $negative_dimension_artifact->get_error_code(), 'shared derivative artifact contract rejects negative dimensions even through the direct PHP seam' );
$non_integer_dimension_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000006',
		'expires_at'     => gmdate( 'c', time() + 600 ),
		'mime_type'      => 'image/webp',
		'format'         => 'webp',
		'width'          => true,
		'height'         => 862.5,
		'filesize_bytes' => 210000,
		'sha256'         => hash( 'sha256', 'non-integer-dimension' ),
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $non_integer_dimension_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_dimensions_invalid' === $non_integer_dimension_artifact->get_error_code(), 'shared derivative artifact contract rejects booleans and floats instead of coercing them into dimensions' );
$max_axis_dimension_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_00000000000000000000000000000019',
			'width'       => 8192,
			'height'      => 1,
		)
	)
);
npcink_abilities_toolkit_assert_true( ! is_wp_error( $max_axis_dimension_artifact ), 'shared derivative artifact contract accepts the 8192-pixel axis boundary when decoded pixel area is legal' );
$oversized_axis_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_0000000000000000000000000000001a',
			'width'       => 8193,
			'height'      => 1,
		)
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $oversized_axis_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_dimensions_invalid' === $oversized_axis_artifact->get_error_code(), 'shared derivative artifact contract rejects an 8193-pixel axis' );
$oversized_area_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'artifact_id' => 'art_0000000000000000000000000000001b',
			'width'       => 4096,
			'height'      => 4097,
		)
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $oversized_area_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_dimensions_invalid' === $oversized_area_artifact->get_error_code(), 'shared derivative artifact contract rejects decoded pixel area above 16,777,216 even when each axis is legal' );
$non_integer_filesize_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000007',
		'expires_at'     => gmdate( 'c', time() + 600 ),
		'mime_type'      => 'image/webp',
		'format'         => 'webp',
		'width'          => 1600,
		'height'         => 862,
		'filesize_bytes' => true,
		'sha256'         => hash( 'sha256', 'non-integer-filesize' ),
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $non_integer_filesize_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_filesize_invalid' === $non_integer_filesize_artifact->get_error_code(), 'shared derivative artifact contract rejects non-integer byte counts through the direct PHP seam' );
$mismatched_format_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array(
		'artifact_id'    => 'art_00000000000000000000000000000008',
		'expires_at'     => gmdate( 'c', time() + 600 ),
		'mime_type'      => 'image/webp',
		'format'         => 'png',
		'width'          => 1600,
		'height'         => 862,
		'filesize_bytes' => 210000,
		'sha256'         => hash( 'sha256', 'mismatched-format' ),
	) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $mismatched_format_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_format_mismatch' === $mismatched_format_artifact->get_error_code(), 'shared derivative artifact contract rejects MIME and format contradictions' );
$legacy_url_artifact = npcink_abilities_toolkit_cloud_artifact_fixture( array( 'download_url' => 'https://cloud.invalid/legacy' ) );
$legacy_url_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( $legacy_url_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $legacy_url_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_descriptor_fields_invalid' === $legacy_url_artifact->get_error_code(), 'shared derivative artifact contract rejects legacy URL fields instead of normalizing them' );
$noncanonical_id_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array( 'artifact_id' => 'art_not_canonical' ) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $noncanonical_id_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_id_invalid' === $noncanonical_id_artifact->get_error_code(), 'shared derivative artifact contract requires canonical art-prefixed 32-hex ids' );
$missing_filename_basis_artifact = npcink_abilities_toolkit_cloud_artifact_fixture();
unset( $missing_filename_basis_artifact['filename_basis'] );
$missing_filename_basis_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize( $missing_filename_basis_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $missing_filename_basis_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_descriptor_fields_invalid' === $missing_filename_basis_artifact->get_error_code(), 'shared derivative artifact contract requires all exact 11 descriptor fields' );
$invalid_filename_basis_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture(
		array(
			'filename_basis' => array(
				'owner'                          => 'cloud',
				'strategy'                       => 'cloud_decides_filename',
				'final_sanitize_unique_required' => false,
			),
		)
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $invalid_filename_basis_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_filename_basis_value_invalid' === $invalid_filename_basis_artifact->get_error_code(), 'shared derivative artifact contract preserves final filename authority in WordPress' );
$sanitized_filename_artifact = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::normalize(
	npcink_abilities_toolkit_cloud_artifact_fixture( array( 'suggested_filename' => '../cloud-artifact.webp' ) )
);
npcink_abilities_toolkit_assert_true( is_wp_error( $sanitized_filename_artifact ) && 'npcink_abilities_toolkit_cloud_artifact_suggested_filename_invalid' === $sanitized_filename_artifact->get_error_code(), 'shared derivative artifact contract rejects suggested filenames that would change during sanitization' );
$received_fixture_bytes = base64_decode( 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true );
$received_fixture_artifact = npcink_abilities_toolkit_cloud_artifact_fixture(
	array(
		'artifact_id'    => 'art_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'width'          => 1,
		'height'         => 1,
		'filesize_bytes' => strlen( $received_fixture_bytes ),
		'sha256'         => hash( 'sha256', $received_fixture_bytes ),
	)
);
$received_fixture_payload = npcink_abilities_toolkit_cloud_receive_fixture( $received_fixture_artifact, $received_fixture_bytes );
$late_ack_now = time() - 899;
$late_ack_deadline = gmdate( 'c', $late_ack_now + 900 );
$late_acknowledged_at = gmdate( 'c', $late_ack_now + 899 );
$late_ack_artifact_expires_at = gmdate( 'c', $late_ack_now + 1800 );
$late_ack_proposal = npcink_abilities_toolkit_cloud_artifact_fixture(
	array(
		'artifact_id'    => 'art_00000000000000000000000000000022',
		'expires_at'     => gmdate( 'c', $late_ack_now + 1800 ),
		'width'          => 1,
		'height'         => 1,
		'filesize_bytes' => strlen( $received_fixture_bytes ),
		'sha256'         => hash( 'sha256', $received_fixture_bytes ),
	)
);
$late_ack_payload = npcink_abilities_toolkit_cloud_receive_fixture( $late_ack_proposal, $received_fixture_bytes );
$late_ack_payload['expires_at'] = $late_ack_artifact_expires_at;
$late_ack_payload['transfer_evidence']['ack_deadline_at'] = $late_ack_deadline;
$late_ack_payload['delivery_ack']['acknowledged_at'] = $late_acknowledged_at;
$late_ack_payload['delivery_ack']['artifact_expires_at'] = $late_ack_artifact_expires_at;
$late_ack_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $late_ack_payload, $late_ack_proposal );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $late_ack_result ), 'receive validation accepts an ACK just before its deadline while retaining the unchanged proposal expiry beyond that deadline' );
$aged_ack_now = time();
$aged_ack_proposal = npcink_abilities_toolkit_cloud_artifact_fixture(
	array(
		'artifact_id'    => 'art_00000000000000000000000000000023',
		'expires_at'     => gmdate( 'c', $aged_ack_now + 600 ),
		'width'          => 1,
		'height'         => 1,
		'filesize_bytes' => strlen( $received_fixture_bytes ),
		'sha256'         => hash( 'sha256', $received_fixture_bytes ),
	)
);
$aged_ack_payload = npcink_abilities_toolkit_cloud_receive_fixture( $aged_ack_proposal, $received_fixture_bytes );
$aged_ack_payload['delivery_ack']['acknowledged_at'] = gmdate( 'c', $aged_ack_now - 360 );
$aged_ack_payload['transfer_evidence']['ack_deadline_at'] = gmdate( 'c', $aged_ack_now - 350 );
$aged_ack_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $aged_ack_payload, $aged_ack_proposal );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $aged_ack_result ), 'the same local11 artifact remains receivable six minutes after ACK because ACK does not shorten its original TTL' );
$expired_original_ttl_proposal = $aged_ack_proposal;
$expired_original_ttl_proposal['expires_at'] = gmdate( 'c', $aged_ack_now - 1 );
$expired_original_ttl_payload = $aged_ack_payload;
$expired_original_ttl_payload['expires_at'] = $expired_original_ttl_proposal['expires_at'];
$expired_original_ttl_payload['delivery_ack']['artifact_expires_at'] = $expired_original_ttl_proposal['expires_at'];
$expired_original_ttl_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $expired_original_ttl_payload, $expired_original_ttl_proposal );
npcink_abilities_toolkit_assert_true( is_wp_error( $expired_original_ttl_result ) && 'npcink_abilities_toolkit_cloud_artifact_expired' === $expired_original_ttl_result->get_error_code(), 'the unchanged local11 artifact fails closed at its original expiry' );
$expiry_mismatch_payload = $received_fixture_payload;
$expiry_mismatch_payload['expires_at'] = gmdate( 'c', time() + 240 );
$expiry_mismatch_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $expiry_mismatch_payload, $received_fixture_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $expiry_mismatch_result ) && 'npcink_abilities_toolkit_cloud_artifact_received_expiry_invalid' === $expiry_mismatch_result->get_error_code(), 'receive validation rejects a received expiry that shortens the original local11 lifetime' );
$incomplete_transfer_payload = $received_fixture_payload;
$incomplete_transfer_payload['transfer_evidence']['image_decoded'] = false;
$incomplete_transfer_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $incomplete_transfer_payload, $received_fixture_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $incomplete_transfer_result ) && 'npcink_abilities_toolkit_cloud_artifact_transfer_verification_incomplete' === $incomplete_transfer_result->get_error_code(), 'receive validation requires every local transfer verification flag to be true' );
$invalid_ack_scope_payload = $received_fixture_payload;
$invalid_ack_scope_payload['delivery_ack']['acknowledgement_scope'] = 'apply_proof';
$invalid_ack_scope_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $invalid_ack_scope_payload, $received_fixture_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $invalid_ack_scope_result ) && 'npcink_abilities_toolkit_cloud_artifact_delivery_ack_contract_invalid' === $invalid_ack_scope_result->get_error_code(), 'receive validation rejects treating Cloud ACK as local apply proof' );
$extra_receive_field_payload = $received_fixture_payload;
$extra_receive_field_payload['download_url'] = 'https://cloud.invalid/legacy';
$extra_receive_field_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload( $extra_receive_field_payload, $received_fixture_artifact );
npcink_abilities_toolkit_assert_true( is_wp_error( $extra_receive_field_result ) && 'npcink_abilities_toolkit_cloud_artifact_received_payload_fields_invalid' === $extra_receive_field_result->get_error_code(), 'receive validation rejects undeclared legacy delivery fields' );
$non_image_bytes = 'not-an-image';
$non_image_artifact = npcink_abilities_toolkit_cloud_artifact_fixture(
	array(
		'artifact_id'    => 'art_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
		'width'          => 1,
		'height'         => 1,
		'filesize_bytes' => strlen( $non_image_bytes ),
		'sha256'         => hash( 'sha256', $non_image_bytes ),
	)
);
$non_image_result = \Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact::verify_received_payload(
	npcink_abilities_toolkit_cloud_receive_fixture( $non_image_artifact, $non_image_bytes ),
	$non_image_artifact
);
npcink_abilities_toolkit_assert_true( is_wp_error( $non_image_result ) && 'npcink_abilities_toolkit_cloud_artifact_image_decode_failed' === $non_image_result->get_error_code(), 'receive validation independently rejects bytes that getimagesize cannot decode' );
$media_optimization_plan = $core_read_package->build_media_optimization_plan(
	array(
		'attachment_id'                 => 79,
		'media_details_input'           => array(
			'title'       => 'Optimized workflow diagram',
			'alt'         => 'AI generated workflow diagram',
			'caption'     => 'AI generated workflow diagram.',
			'description' => 'Optimized media metadata for a workflow diagram.',
			'source_type' => 'ai_generated',
		),
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'        => 'art_00000000000000000000000000000009',
			'expires_at'         => gmdate( 'c', time() + 600 ),
			'mime_type'          => 'image/webp',
			'format'             => 'webp',
			'width'              => 1600,
			'height'             => 862,
			'filesize_bytes'     => 210000,
			'sha256'             => hash( 'sha256', 'media-optimization-plan-artifact' ),
			'suggested_filename' => 'workflow-diagram-cloud-plan.webp',
		) ),
		'file_name'                     => 'f553110d20d666349676892b1b0fbeb7.webp',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_optimization_plan['success'] ?? null, 'build-media-optimization-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_optimization_plan', $media_optimization_plan['data']['artifact_type'] ?? '', 'media optimization plan declares Core media optimization artifact type' );
npcink_abilities_toolkit_assert_same( 'batch', $media_optimization_plan['data']['proposal_mode'] ?? '', 'media optimization plan requests batch proposal mode' );
npcink_abilities_toolkit_assert_same( true, $media_optimization_plan['data']['batch_approval'] ?? null, 'media optimization plan requests one Core approval' );
npcink_abilities_toolkit_assert_same( 2, count( (array) ( $media_optimization_plan['data']['write_actions'] ?? array() ) ), 'media optimization plan includes metadata and derivative actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-media-details', $media_optimization_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media optimization plan starts with metadata action' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/adopt-cloud-media-derivative', $media_optimization_plan['data']['write_actions'][1]['target_ability_id'] ?? '', 'media optimization plan includes Cloud derivative adoption action' );
npcink_abilities_toolkit_assert_same( hash( 'sha256', 'media-optimization-plan-artifact' ), $media_optimization_plan['data']['write_actions'][1]['input']['derivative_artifact']['sha256'] ?? '', 'media optimization plan binds the governed adoption action to the reviewed artifact SHA-256' );
$planned_derivative_artifact = $media_optimization_plan['data']['write_actions'][1]['input']['derivative_artifact'] ?? array();
$write_derivative_schema = $package_abilities['npcink-abilities-toolkit/adopt-cloud-media-derivative']['input_schema']['properties']['derivative_artifact'] ?? array();
npcink_abilities_toolkit_assert_same( array(), array_values( array_diff( array_keys( $planned_derivative_artifact ), array_keys( (array) ( $write_derivative_schema['properties'] ?? array() ) ) ) ), 'media optimization plan emits no derivative artifact fields rejected by the target write ability schema' );
npcink_abilities_toolkit_assert_same( array(), array_values( array_diff( (array) ( $write_derivative_schema['required'] ?? array() ), array_keys( $planned_derivative_artifact ) ) ), 'media optimization plan emits every derivative artifact field required by the target write ability schema' );
npcink_abilities_toolkit_assert_true( ! isset( $planned_derivative_artifact['expires_ts'] ), 'media optimization plan keeps parsed expiry timestamps internal instead of leaking undeclared action input' );
npcink_abilities_toolkit_assert_same( 'f553110d20d666349676892b1b0fbeb7.webp', $media_optimization_plan['data']['write_actions'][1]['input']['file_name'] ?? '', 'media optimization plan passes reviewed derivative file_name into adoption action' );
npcink_abilities_toolkit_assert_same( array( 90 ), $media_optimization_plan['data']['write_actions'][1]['input']['expected_content_reference_post_ids'] ?? array(), 'media optimization plan carries reviewed content reference post targets into adoption input' );
npcink_abilities_toolkit_assert_same( 1, $media_optimization_plan['data']['write_actions'][1]['input']['expected_content_reference_post_count'] ?? null, 'media optimization plan carries reviewed content reference post count into adoption input' );
npcink_abilities_toolkit_assert_same( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['replacement_count'] ?? null, $media_optimization_plan['data']['write_actions'][1]['input']['expected_content_reference_replacement_count'] ?? null, 'media optimization plan carries reviewed content reference replacement count into adoption input' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_optimization_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/patch-post-content' ), 'media optimization plan keeps post-content repair inside derivative adoption evidence' );
npcink_abilities_toolkit_assert_same( false, $media_optimization_plan['data']['commit_execution'] ?? null, 'media optimization plan does not execute commits' );
npcink_abilities_toolkit_assert_same( true, $media_optimization_plan['meta']['readonly'] ?? null, 'media optimization plan remains read-only' );
npcink_abilities_toolkit_assert_same( 1, $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['post_count'] ?? 0, 'media optimization plan previews post content reference repairs inside derivative evidence' );
npcink_abilities_toolkit_assert_same( 90, $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['repairs'][0]['post_id'] ?? 0, 'media optimization reference preview targets the referencing post' );
npcink_abilities_toolkit_assert_true( (int) ( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['replacement_count'] ?? 0 ) >= 2, 'media optimization reference preview includes old main and sized image references' );
npcink_abilities_toolkit_assert_true( (int) ( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['replacement_rule_count'] ?? 0 ) >= 2, 'media optimization reference preview distinguishes replacement rule count' );
npcink_abilities_toolkit_assert_true( (int) ( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['actual_replacement_count'] ?? 0 ) >= 1, 'media optimization reference preview reports actual replacement count' );
npcink_abilities_toolkit_assert_true( isset( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['unmatched_rules'] ), 'media optimization reference preview exposes unmatched rules' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs']['repairs'][0]['operations'][0]['replace'] ?? '' ), 'f553110d20d666349676892b1b0fbeb7.webp' ), 'media optimization reference preview replaces inline URLs with the reviewed derivative filename' );
npcink_abilities_toolkit_assert_same( $media_optimization_plan['data']['derivative_preview']['content_reference_repairs'], $media_optimization_plan['data']['content_reference_repairs_preview'] ?? array(), 'media optimization plan exposes content reference repairs at top level for Core review summaries' );
$media_adoption_preflight_summary = $core_read_package->build_media_adoption_preflight_summary(
	array(
		'attachment_id'        => 79,
		'derivative_artifact'  => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'        => 'art_0000000000000000000000000000000a',
			'expires_at'         => gmdate( 'c', time() + 600 ),
			'mime_type'          => 'image/webp',
			'format'             => 'webp',
			'width'              => 1600,
			'height'             => 862,
			'filesize_bytes'     => 210000,
			'sha256'             => hash( 'sha256', 'media-preflight-artifact' ),
			'processing_warnings' => array( 'source_resized' ),
		) ),
		'file_name'            => 'f553110d20d666349676892b1b0fbeb7.webp',
		'include_settings_scan' => true,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_adoption_preflight_summary['success'] ?? null, 'media adoption preflight summary returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_adoption_preflight_summary', $media_adoption_preflight_summary['data']['artifact_type'] ?? '', 'media adoption preflight summary declares its artifact type' );
npcink_abilities_toolkit_assert_same( true, $media_adoption_preflight_summary['data']['readonly'] ?? null, 'media adoption preflight summary is read-only' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_preflight_summary['data']['direct_wordpress_write'] ?? null, 'media adoption preflight summary declares no direct WordPress write' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_preflight_summary['data']['proposal_created'] ?? null, 'media adoption preflight summary does not create Core proposals' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_preflight_summary['data']['cloud_call_included'] ?? null, 'media adoption preflight summary does not call Cloud' );
npcink_abilities_toolkit_assert_true( ! isset( $media_adoption_preflight_summary['data']['write_actions'] ), 'media adoption preflight summary does not expose write actions' );
npcink_abilities_toolkit_assert_same( 'art_0000000000000000000000000000000a', $media_adoption_preflight_summary['data']['derivative']['artifact_id'] ?? '', 'media adoption preflight summary includes derivative artifact evidence' );
npcink_abilities_toolkit_assert_same( true, $media_adoption_preflight_summary['data']['readiness']['can_submit_core_proposal'] ?? null, 'media adoption preflight summary marks artifact-backed adoption as proposal-ready' );
npcink_abilities_toolkit_assert_same( 1, $media_adoption_preflight_summary['data']['content_reference_summary']['post_count'] ?? 0, 'media adoption preflight summary includes bounded content reference impact' );
npcink_abilities_toolkit_assert_true( in_array( 'settings_reference_scan_deferred', (array) ( $media_adoption_preflight_summary['data']['warnings'] ?? array() ), true ), 'media adoption preflight summary defers settings scans to the dedicated repair plan ability' );
npcink_abilities_toolkit_assert_true( in_array( 'submit_media_optimization_proposal', (array) ( $media_adoption_preflight_summary['data']['next_steps'] ?? array() ), true ), 'media adoption preflight summary points to governed Core proposal submission' );
$media_adoption_preflight_missing_artifact = $core_read_package->build_media_adoption_preflight_summary(
	array(
		'attachment_id' => 79,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_adoption_preflight_missing_artifact['success'] ?? null, 'media adoption preflight summary succeeds without derivative artifact' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_preflight_missing_artifact['data']['derivative']['available'] ?? null, 'media adoption preflight summary reports a missing derivative artifact' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_preflight_missing_artifact['data']['readiness']['can_submit_core_proposal'] ?? null, 'media adoption preflight summary blocks proposal readiness without artifact evidence' );
npcink_abilities_toolkit_assert_true( in_array( 'generate_derivative_preview', (array) ( $media_adoption_preflight_missing_artifact['data']['next_steps'] ?? array() ), true ), 'media adoption preflight summary points missing artifacts to preview generation' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][90] );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][790] = (object) array(
	'ID'             => 790,
	'post_title'     => 'Scaled unique suffix source image',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'photo-scaled-1',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/jpeg',
);
update_post_meta(
	790,
	'_wp_attachment_metadata',
	array(
		'width'          => 2600,
		'height'         => 1463,
		'file'           => '2026/06/photo-scaled-1.jpg',
		'filesize'       => 940000,
		'original_image' => 'photo-scaled.jpg',
		'sizes'          => array(),
	)
);
update_post_meta( 790, '_wp_attached_file', '2026/06/photo-scaled-1.jpg' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][791] = (object) array(
	'ID'           => 791,
	'post_title'   => 'Media Optimization Original Reference Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<figure><img src="https://example.test/wp-content/uploads/2026/06/photo-scaled.jpg" srcset="https://example.test/wp-content/uploads/2026/06/photo-scaled.jpg 2600w" class="wp-image-790" /></figure>',
	'post_name'    => 'media-optimization-original-reference-candidate',
	'post_author'  => 7,
);
$media_optimization_original_plan = $core_read_package->build_media_optimization_plan(
	array(
		'attachment_id'                 => 790,
		'media_details_input'           => array(
			'title'       => 'Optimized original reference photo',
			'alt'         => 'Optimized original reference photo',
			'caption'     => 'Optimized original reference photo.',
			'description' => 'Optimized media metadata for an original reference photo.',
			'source_type' => 'ai_generated',
		),
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'        => 'art_0000000000000000000000000000000b',
			'expires_at'         => gmdate( 'c', time() + 600 ),
			'mime_type'          => 'image/webp',
			'format'             => 'webp',
			'width'              => 1920,
			'height'             => 1080,
			'filesize_bytes'     => 126482,
			'sha256'             => hash( 'sha256', 'media-original-reference-artifact' ),
			'suggested_filename' => 'photo-optimized.webp',
		) ),
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_optimization_original_plan['success'] ?? null, 'media optimization plan succeeds for unique-suffixed current files' );
npcink_abilities_toolkit_assert_same( 1, $media_optimization_original_plan['data']['derivative_preview']['content_reference_repairs']['post_count'] ?? 0, 'media optimization plan previews repairs for metadata original_image references' );
npcink_abilities_toolkit_assert_same( 791, $media_optimization_original_plan['data']['derivative_preview']['content_reference_repairs']['repairs'][0]['post_id'] ?? 0, 'media optimization original reference preview targets the post using the pre-unique filename' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_optimization_original_plan['data']['derivative_preview']['content_reference_repairs']['repairs'][0]['operations'][0]['find'] ?? '' ), 'photo-scaled.jpg' ), 'media optimization original reference preview finds the pre-unique filename' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_optimization_original_plan['data']['derivative_preview']['content_reference_repairs']['repairs'][0]['operations'][0]['replace'] ?? '' ), 'photo-optimized.webp' ), 'media optimization original reference preview replaces with the derivative filename' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][790], $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][791] );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'npcink_cloud_addon_receive_media_derivative_artifact' ), 'adopt-cloud-media-derivative uses the verified Cloud Addon receive seam' );
npcink_abilities_toolkit_assert_true( false === strpos( $core_write_package_source, 'npcink_cloud_addon_download_media_derivative_artifact' ) && false === strpos( $core_write_package_source, 'npcink_abilities_toolkit_cloud_media_derivative_artifact_download' ), 'adopt-cloud-media-derivative removes legacy download helper and filter seams' );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'npcink_abilities_toolkit_media_file_write_blocked' ), 'media write execution exposes a bounded failure-injection hook for verification tests' );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'npcink_abilities_toolkit_media_file_copy_blocked' ), 'media copy execution exposes a bounded failure-injection hook for verification tests' );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'is_media_uploads_path_allowed' ), 'media file helpers enforce uploads path containment' );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'generate_attachment_metadata_for_file' ), 'media uploads share an explicit attachment metadata persistence helper' );
npcink_abilities_toolkit_assert_true( false !== strpos( $core_write_package_source, 'minimal_attachment_metadata_for_file' ), 'media uploads persist minimal dimensions when full WordPress image helpers are unavailable' );
$write_media_file_reflection = new ReflectionMethod( $core_write_package, 'write_media_file' );
$write_media_file_reflection->setAccessible( true );
$outside_uploads_path = sys_get_temp_dir() . '/npcink-abilities-toolkit-outside-uploads-' . getmypid() . '.jpg';
npcink_abilities_toolkit_assert_same( false, $write_media_file_reflection->invoke( $core_write_package, $outside_uploads_path, 'outside', array() ), 'media write helper rejects targets outside uploads basedir' );
npcink_abilities_toolkit_assert_true( ! is_readable( $outside_uploads_path ), 'outside uploads path containment test does not create a file' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][88] = (object) array(
	'ID'           => 88,
	'post_title'   => 'Rename Reference Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<!-- wp:image {"id":79,"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg" alt="Workflow diagram" class="wp-image-79" /></figure><!-- /wp:image -->',
	'post_name'    => 'rename-reference-candidate',
	'post_author'  => 7,
);
$media_rename_plan = $core_read_package->build_media_rename_plan(
	array(
		'attachment_id'        => 79,
		'target_file_name'     => 'workflow-diagram-image-reviewed',
		'expected_current_md5' => md5( 'original-jpeg-bytes' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_rename_plan['success'] ?? null, 'build-media-rename-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_rename_plan', $media_rename_plan['data']['artifact_type'] ?? '', 'media rename plan declares Core media rename artifact type' );
npcink_abilities_toolkit_assert_same( 'batch', $media_rename_plan['data']['proposal_mode'] ?? '', 'media rename plan batches rename with exact content reference updates' );
npcink_abilities_toolkit_assert_same( true, $media_rename_plan['data']['batch_approval'] ?? null, 'media rename plan requests one approval for rename and reference updates' );
npcink_abilities_toolkit_assert_same( 2, count( (array) ( $media_rename_plan['data']['write_actions'] ?? array() ) ), 'media rename plan includes rename and post reference patch actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/rename-media-file', $media_rename_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media rename plan targets rename-media-file' );
npcink_abilities_toolkit_assert_same( 'workflow-diagram-image-reviewed.jpg', $media_rename_plan['data']['write_actions'][0]['input']['target_file_name'] ?? '', 'media rename plan appends the current extension when omitted' );
npcink_abilities_toolkit_assert_same( md5( 'original-jpeg-bytes' ), $media_rename_plan['data']['write_actions'][0]['input']['expected_current_md5'] ?? '', 'media rename plan carries MD5 guard into write action' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/patch-post-content', $media_rename_plan['data']['write_actions'][1]['target_ability_id'] ?? '', 'media rename plan patches post content references after rename' );
npcink_abilities_toolkit_assert_same( 88, $media_rename_plan['data']['write_actions'][1]['input']['post_id'] ?? 0, 'media rename plan targets the post that embeds the renamed image URL' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_rename_plan['data']['write_actions'][1]['input']['operations'][0]['find'] ?? '' ), 'workflow-diagram-image.jpg' ), 'media rename plan finds the old image URL in post content' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_rename_plan['data']['write_actions'][1]['input']['operations'][0]['replace'] ?? '' ), 'workflow-diagram-image-reviewed.jpg' ), 'media rename plan replaces post content with the renamed image URL' );
npcink_abilities_toolkit_assert_same( 1, $media_rename_plan['data']['reference_repair']['action_count'] ?? 0, 'media rename plan reports one exact reference repair action' );
npcink_abilities_toolkit_assert_same( false, $media_rename_plan['data']['commit_execution'] ?? null, 'media rename plan does not execute commits' );
npcink_abilities_toolkit_assert_same( true, $media_rename_plan['meta']['readonly'] ?? null, 'media rename plan remains read-only' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][88] );
$media_rename_plan_invalid_hash = $core_read_package->build_media_rename_plan(
	array(
		'attachment_id'        => 79,
		'target_file_name'     => 'workflow-diagram-image-reviewed.jpg',
		'expected_current_md5' => 'not-a-valid-md5',
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $media_rename_plan_invalid_hash ) && 'npcink_abilities_toolkit_expected_md5_invalid' === $media_rename_plan_invalid_hash->get_error_code(), 'media rename plan rejects invalid expected MD5 values' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][84] = (object) array(
	'ID'             => 84,
	'post_title'     => 'April Campaign JPEG',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'april-campaign-jpeg',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/jpeg',
	'post_date'      => '2026-04-12 10:00:00',
);
update_post_meta(
	84,
	'_wp_attachment_metadata',
	array(
		'width'    => 1800,
		'height'   => 1000,
		'file'     => '2026/04/april-campaign-jpeg.jpg',
		'filesize' => 700000,
	)
);
update_post_meta( 84, '_wp_attached_file', '2026/04/april-campaign-jpeg.jpg' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][85] = (object) array(
	'ID'             => 85,
	'post_title'     => 'April Existing PNG',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'april-existing-png',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/png',
	'post_date'      => '2026-04-18 09:00:00',
);
update_post_meta(
	85,
	'_wp_attachment_metadata',
	array(
		'width'    => 1600,
		'height'   => 900,
		'file'     => '2026/04/april-existing-png.png',
		'filesize' => 600000,
	)
);
update_post_meta( 85, '_wp_attached_file', '2026/04/april-existing-png.png' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][86] = (object) array(
	'ID'             => 86,
	'post_title'     => 'May Campaign JPEG',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'may-campaign-jpeg',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/jpeg',
	'post_date'      => '2026-05-02 10:00:00',
);
update_post_meta(
	86,
	'_wp_attachment_metadata',
	array(
		'width'    => 1700,
		'height'   => 950,
		'file'     => '2026/05/may-campaign-jpeg.jpg',
		'filesize' => 650000,
	)
);
update_post_meta( 86, '_wp_attached_file', '2026/05/may-campaign-jpeg.jpg' );
$batch_media_files = array(
	84 => array( '2026/04/april-campaign-jpeg.jpg', 'april-jpeg-bytes' ),
	85 => array( '2026/04/april-existing-png.png', 'april-png-bytes' ),
	86 => array( '2026/05/may-campaign-jpeg.jpg', 'may-jpeg-bytes' ),
);
foreach ( $batch_media_files as $batch_attachment_id => $batch_media_file ) {
	$batch_media_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $batch_media_file[0];
	if ( ! is_dir( dirname( $batch_media_path ) ) ) {
		mkdir( dirname( $batch_media_path ), 0755, true );
	}
	file_put_contents( $batch_media_path, $batch_media_file[1] );
	update_post_meta( $batch_attachment_id, '_wp_attached_file', $batch_media_path );
}
$media_derivative_batch_plan = $core_read_package->build_media_derivative_batch_plan(
	array(
		'date_from'  => '2026-04-01',
		'date_to'    => '2026-04-30 23:59:59',
		'image_types' => array( 'jpeg' ),
		'max_items'  => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_derivative_batch_plan['success'] ?? null, 'media derivative batch plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $media_derivative_batch_plan['data']['readonly'] ?? null, 'media derivative batch plan is read-only' );
npcink_abilities_toolkit_assert_same( 'dry_run', $media_derivative_batch_plan['data']['plan_mode'] ?? '', 'media derivative batch plan returns a dry-run plan mode' );
npcink_abilities_toolkit_assert_same( false, $media_derivative_batch_plan['data']['commit_execution'] ?? null, 'media derivative batch plan does not execute commits' );
npcink_abilities_toolkit_assert_same( true, $media_derivative_batch_plan['data']['requires_approval'] ?? null, 'media derivative batch plan requires approval before adoption' );
npcink_abilities_toolkit_assert_same( 'toolbox_media_optimization_manifest.v1', $media_derivative_batch_plan['data']['plan_contract_version'] ?? '', 'media derivative batch plan emits the exact one-click optimization manifest contract' );
npcink_abilities_toolkit_assert_same( 1, $media_derivative_batch_plan['data']['summary']['candidate_count'] ?? 0, 'media derivative batch plan selects one April JPEG candidate from the requested types' );
npcink_abilities_toolkit_assert_same( 1, $media_derivative_batch_plan['data']['eligibility_summary']['eligible_count'] ?? 0, 'media derivative batch plan reports eligible count in eligibility summary' );
npcink_abilities_toolkit_assert_same( 1, $media_derivative_batch_plan['data']['eligibility_summary']['blocked_count'] ?? 0, 'media derivative batch plan reports the filtered PNG in the eligibility summary' );
npcink_abilities_toolkit_assert_same( true, $media_derivative_batch_plan['data']['retryable'] ?? null, 'media derivative batch plan is retryable as a rebuildable review set' );
npcink_abilities_toolkit_assert_true( is_string( $media_derivative_batch_plan['data']['operator_next_action'] ?? null ) && '' !== $media_derivative_batch_plan['data']['operator_next_action'], 'media derivative batch plan provides operator next action guidance' );
npcink_abilities_toolkit_assert_same( 84, $media_derivative_batch_plan['data']['candidates'][0]['attachment_id'] ?? 0, 'media derivative batch plan candidate comes from the April date range' );
npcink_abilities_toolkit_assert_same( 'eligible', $media_derivative_batch_plan['data']['candidates'][0]['status'] ?? '', 'media derivative batch plan candidate carries eligible status' );
npcink_abilities_toolkit_assert_same( 'attachment:84', $media_derivative_batch_plan['data']['candidates'][0]['result_ref'] ?? '', 'media derivative batch plan candidate carries a stable result reference' );
npcink_abilities_toolkit_assert_same( 'webp', $media_derivative_batch_plan['data']['candidates'][0]['cloud_request_input']['preferred_format'] ?? '', 'media derivative batch plan fixes one-click output to WebP' );
npcink_abilities_toolkit_assert_same( 'auto_safe', $media_derivative_batch_plan['data']['candidates'][0]['cloud_request_input']['optimization_mode'] ?? '', 'media derivative batch plan uses the Cloud auto-safe mode' );
npcink_abilities_toolkit_assert_same( 'auto_safe.v1', $media_derivative_batch_plan['data']['candidates'][0]['cloud_request_input']['optimization_profile'] ?? '', 'media derivative batch plan pins the auto-safe policy version' );
npcink_abilities_toolkit_assert_same( 'sha256:' . hash( 'sha256', 'april-jpeg-bytes' ), $media_derivative_batch_plan['data']['candidates'][0]['media_fingerprint'] ?? '', 'media derivative batch plan freezes the current file SHA-256' );
npcink_abilities_toolkit_assert_array_omits_keys( $media_derivative_batch_plan['data']['candidates'][0]['cloud_request_input'] ?? array(), array( 'quality', 'crop', 'watermark' ), 'media derivative batch auto-safe request input' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-media-derivative-cloud-request', $media_derivative_batch_plan['data']['candidates'][0]['cloud_request_ability'] ?? '', 'media derivative batch plan points to the existing single-image cloud request ability' );
$media_derivative_execution_plan = implode( ' ', (array) ( $media_derivative_batch_plan['data']['execution_plan']['steps'] ?? array() ) );
npcink_abilities_toolkit_assert_true( false !== strpos( $media_derivative_execution_plan, 'source SHA-256 fingerprints' ) && false !== strpos( $media_derivative_execution_plan, 'browser foreground' ), 'media derivative batch plan freezes file versions and remains foreground-driven' );
npcink_abilities_toolkit_assert_true( false === strpos( $media_derivative_execution_plan, 'media-derivative-runs' ) && false === strpos( $media_derivative_execution_plan, 'media-derivative-proposal-payload' ) && false === strpos( $media_derivative_execution_plan, 'Adapter POST' ), 'media derivative batch plan omits retired Adapter execution seams' );
npcink_abilities_toolkit_assert_same( 'source_format_filtered', $media_derivative_batch_plan['data']['skipped'][0]['reason'] ?? '', 'media derivative batch plan explains image type filtering' );
npcink_abilities_toolkit_assert_same( 'source_format_filtered', $media_derivative_batch_plan['data']['blocked_items'][0]['blocked_reason'] ?? '', 'media derivative batch plan exposes filtered media as blocked items' );
npcink_abilities_toolkit_assert_same( 10, $media_derivative_batch_plan['data']['execution_plan']['batch_size_recommendation'] ?? 0, 'media derivative batch plan recommends ten-item execution chunks' );
npcink_abilities_toolkit_assert_array_omits_keys( $media_derivative_batch_plan['data'], array( 'write_actions', 'wordpress_write_decision', 'approval_decision', 'commit' ), 'media derivative batch plan output' );
$media_derivative_batch_plan_bounded = $core_read_package->build_media_derivative_batch_plan(
	array(
		'attachment_ids' => array( 84, 86 ),
		'max_items'      => 1,
	)
);
npcink_abilities_toolkit_assert_same( 1, $media_derivative_batch_plan_bounded['data']['summary']['candidate_count'] ?? 0, 'media derivative batch plan enforces max_items' );
$media_derivative_batch_plan_resize = $core_read_package->build_media_derivative_batch_plan(
	array(
		'attachment_ids' => array( 84 ),
		'resize_mode'    => 'fit',
	)
);
npcink_abilities_toolkit_assert_same( 'fit', $media_derivative_batch_plan_resize['data']['filters']['resize_mode'] ?? '', 'media derivative batch plan records the optional 1920-pixel resize choice' );
npcink_abilities_toolkit_assert_same( 'fit', $media_derivative_batch_plan_resize['data']['candidates'][0]['cloud_request_input']['resize_mode'] ?? '', 'media derivative batch plan carries the optional resize choice to Cloud' );
$media_optimization_preview = $core_write_package->optimize_media_asset(
	array(
		'attachment_id'     => 79,
		'target_max_width'  => 1920,
		'preferred_format'  => 'webp',
		'quality'           => 82,
		'derivative_suffix' => 'optimized',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_optimization_preview['dry_run'] ?? null, 'optimize-media-asset defaults to dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $media_optimization_preview['optimized'] ?? null, 'optimize-media-asset dry-run does not generate a file' );
npcink_abilities_toolkit_assert_same( true, $media_optimization_preview['original_preserved'] ?? null, 'optimize-media-asset preserves original asset' );
npcink_abilities_toolkit_assert_same( false, $media_optimization_preview['replace_original'] ?? null, 'optimize-media-asset never replaces the original file' );
npcink_abilities_toolkit_assert_same( 'webp', $media_optimization_preview['derivative']['format'] ?? '', 'optimize-media-asset plans WebP derivative by default' );
npcink_abilities_toolkit_assert_same( 1920, $media_optimization_preview['derivative']['width'] ?? 0, 'optimize-media-asset plans bounded derivative width' );
npcink_abilities_toolkit_assert_true( ! isset( $media_optimization_preview['media_fingerprint'] ) && ( ! isset( $media_optimization_preview['history'] ) || ! is_array( $media_optimization_preview['history'] ) || ! isset( $media_optimization_preview['history']['new_media_fingerprint'] ) ), 'media optimization preview writes no current fingerprint or replacement lineage before an explicit commit' );
update_post_meta(
	79,
	'_npcink_ai_media_optimized_derivatives',
	array(
		array(
			'format'           => 'webp',
			'mime_type'        => 'image/webp',
			'file_basename'    => 'workflow-diagram-image-optimized.webp',
			'relative_file'    => '2026/06/workflow-diagram-image-optimized.webp',
			'url'              => 'https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-optimized.webp',
			'width'            => 1920,
			'height'           => 1034,
			'quality'          => 82,
			'filesize_bytes'   => 300000,
			'generated_at_gmt' => '2026-06-02T00:00:00+00:00',
		),
	)
);
$media_replace_preview = $core_write_package->replace_media_file(
	array(
		'attachment_id'                 => 79,
		'derivative_relative_file'      => '2026/06/workflow-diagram-image-optimized.webp',
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_replace_preview['dry_run'] ?? null, 'replace-media-file defaults to dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $media_replace_preview['replaced'] ?? null, 'replace-media-file dry-run does not switch files' );
npcink_abilities_toolkit_assert_same( true, $media_replace_preview['original_preserved'] ?? null, 'replace-media-file keeps original backup intent in dry-run' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image-optimized.webp', $media_replace_preview['after']['relative_file'] ?? '', 'replace-media-file uses recorded optimized derivative as target' );
npcink_abilities_toolkit_assert_true( 0 === strpos( (string) ( $media_replace_preview['backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-backups/2026/06/' ), 'replace-media-file plans backups in the dedicated Npcink uploads backup directory' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_replace_preview['backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-backup' ), 'replace-media-file plans a Npcink backup file' );
$media_replace_derivative_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/workflow-diagram-image-optimized.webp';
file_put_contents( $media_replace_derivative_path, 'optimized-webp-bytes' );
$media_replace_plan_method = new ReflectionMethod( Core_Write_Package::class, 'build_media_file_replacement_plan' );
$media_replace_execute_method = new ReflectionMethod( Core_Write_Package::class, 'execute_media_file_replacement' );
if ( PHP_VERSION_ID < 80100 ) {
	$media_replace_plan_method->setAccessible( true );
	$media_replace_execute_method->setAccessible( true );
}
$media_replace_drift_plan = $media_replace_plan_method->invoke( $core_write_package, 79, array( 'derivative_relative_file' => '2026/06/workflow-diagram-image-optimized.webp' ) );
file_put_contents( $workflow_media_path, 'third-party-replacement-after-preview' );
$media_replace_drift_result = $media_replace_execute_method->invoke( $core_write_package, 79, $media_replace_drift_plan );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_replace_drift_result ) && 'npcink_abilities_toolkit_media_replace_precommit_drift' === $media_replace_drift_result->get_error_code(), 'replace-media-file rejects source-byte drift immediately before the file switch' );
file_put_contents( $workflow_media_path, 'original-jpeg-bytes' );
$cloud_artifact_contents = base64_decode( 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true );
npcink_abilities_toolkit_assert_true( is_string( $cloud_artifact_contents ), 'Cloud derivative fixture decodes into real WebP bytes' );
$cloud_artifact_sha256 = hash( 'sha256', $cloud_artifact_contents );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89] = (object) array(
	'ID'           => 89,
	'post_title'   => 'Cloud Derivative Inline Reference',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<!-- wp:image {"id":79,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg" srcset="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg 300w, https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg 2600w, https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-original.jpg 2600w" alt="Workflow diagram" class="wp-image-79" /></figure><!-- /wp:image -->',
	'post_name'    => 'cloud-derivative-inline-reference',
	'post_author'  => 7,
);
$cloud_adoption_preview = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_0000000000000000000000000000000c',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_preview['dry_run'] ?? null, 'adopt-cloud-media-derivative defaults to dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $cloud_adoption_preview['replaced'] ?? null, 'adopt-cloud-media-derivative dry-run does not switch files' );
npcink_abilities_toolkit_assert_same( 'art_0000000000000000000000000000000c', $cloud_adoption_preview['artifact']['artifact_id'] ?? '', 'adopt-cloud-media-derivative preserves artifact evidence' );
npcink_abilities_toolkit_assert_same( '2026/06/cloud-artifact.webp', $cloud_adoption_preview['after']['relative_file'] ?? '', 'adopt-cloud-media-derivative plans the canonical Cloud suggestion for final local review' );
npcink_abilities_toolkit_assert_same( 1, $cloud_adoption_preview['content_reference_repairs']['post_count'] ?? 0, 'adopt-cloud-media-derivative previews post content reference repairs for embedded attachment URLs' );
npcink_abilities_toolkit_assert_true( (int) ( $cloud_adoption_preview['content_reference_repairs']['replacement_count'] ?? 0 ) >= 3, 'adopt-cloud-media-derivative preview includes old main, sized, and original image references' );
$cloud_adoption_expected_post_ids = array_values( array_map( 'absint', array_column( (array) ( $cloud_adoption_preview['content_reference_repairs']['repairs'] ?? array() ), 'post_id' ) ) );
$cloud_adoption_expected_post_count = absint( $cloud_adoption_preview['content_reference_repairs']['post_count'] ?? 0 );
$cloud_adoption_expected_replacement_count = absint( $cloud_adoption_preview['content_reference_repairs']['replacement_count'] ?? 0 );
$cloud_suggested_filename_preview = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'         => 'art_0000000000000000000000000000000d',
			'expires_at'          => gmdate( 'c', time() + 600 ),
			'mime_type'           => 'image/webp',
			'format'              => 'webp',
			'width'               => 1,
			'height'              => 1,
			'filesize_bytes'      => strlen( $cloud_artifact_contents ),
			'sha256'              => $cloud_artifact_sha256,
			'suggested_filename'  => 'cloud-suggested-file.webp',
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
	)
);
npcink_abilities_toolkit_assert_same( 'cloud-suggested-file.webp', $cloud_suggested_filename_preview['proposed_filename'] ?? '', 'adopt-cloud-media-derivative adopts a sanitized Cloud suggested filename as local proposal evidence' );
npcink_abilities_toolkit_assert_same( 'cloud_artifact_suggestion', $cloud_suggested_filename_preview['filename_policy']['source'] ?? '', 'adopt-cloud-media-derivative marks Cloud filenames as suggestions, not write decisions' );
npcink_abilities_toolkit_assert_same( true, $cloud_suggested_filename_preview['filename_policy']['final_sanitize_unique_required'] ?? null, 'adopt-cloud-media-derivative requires final WordPress-side filename finalization' );
npcink_abilities_toolkit_assert_same( 'format_checksum', $cloud_suggested_filename_preview['artifact']['filename_basis']['strategy'] ?? '', 'direct PHP artifact normalization preserves the exact local filename basis' );
$expired_cloud_adoption = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'       => 79,
		'derivative_artifact' => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id' => 'art_0000000000000000000000000000000e',
			'expires_at'  => gmdate( 'c', time() - 60 ),
			'mime_type'   => 'image/webp',
			'format'      => 'webp',
		) ),
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $expired_cloud_adoption ) && 'npcink_abilities_toolkit_cloud_artifact_expired' === $expired_cloud_adoption->get_error_code(), 'adopt-cloud-media-derivative rejects expired artifacts' );
$GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] = sys_get_temp_dir() . '/npcink-abilities-toolkit-cloud-adoption-' . getmypid();
$current_media_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/workflow-diagram-image.jpg';
mkdir( dirname( $current_media_path ), 0755, true );
file_put_contents( $current_media_path, 'original-jpeg-bytes' );
$cloud_artifact_receive_count = 0;
$GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] = static function ( array $artifact ) use ( $cloud_artifact_contents, &$cloud_artifact_receive_count ) {
	++$cloud_artifact_receive_count;
	return npcink_abilities_toolkit_cloud_receive_fixture( $artifact, $cloud_artifact_contents );
};
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-cloud-media-adoption',
);
$valid_cloud_artifact_receive_callback = $GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'];
$GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] = static function ( array $artifact ) use ( $cloud_artifact_contents, &$cloud_artifact_receive_count ) {
	++$cloud_artifact_receive_count;
	return npcink_abilities_toolkit_cloud_receive_fixture( $artifact, $cloud_artifact_contents, array( 'sha256' => 'invalid-transport-digest' ) );
};
$cloud_adoption_invalid_transport_digest = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_0000000000000000000000000000000f',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_invalid_transport_digest ) && 'npcink_abilities_toolkit_cloud_artifact_received_facts_invalid' === $cloud_adoption_invalid_transport_digest->get_error_code(), 'adopt-cloud-media-derivative rejects malformed receive facts' );
$GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] = $valid_cloud_artifact_receive_callback;
$cloud_artifact_receive_count = 0;
$cloud_adoption_size_mismatch = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000010',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ) + 1,
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_size_mismatch ) && 'npcink_abilities_toolkit_cloud_artifact_filesize_mismatch' === $cloud_adoption_size_mismatch->get_error_code(), 'adopt-cloud-media-derivative rejects received bytes whose size differs from approved artifact evidence' );
npcink_abilities_toolkit_assert_same( 1, $cloud_artifact_receive_count, 'artifact size integrity is checked immediately after verified receiving' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'artifact size mismatch leaves the attachment pointer unchanged' );
$cloud_artifact_receive_count = 0;
$cloud_adoption_drift = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000011',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'expected_content_reference_post_ids' => array( 999 ),
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_drift ) && 'npcink_abilities_toolkit_media_reference_repair_expectation_mismatch' === $cloud_adoption_drift->get_error_code(), 'adopt-cloud-media-derivative blocks commit when reviewed content reference targets drift' );
npcink_abilities_toolkit_assert_same( 0, $cloud_artifact_receive_count, 'adopt-cloud-media-derivative checks content reference drift before receiving the Cloud artifact' );
$precommit_original_relative_file = (string) get_post_meta( 79, '_wp_attached_file', true );
$precommit_original_metadata = wp_get_attachment_metadata( 79 );
$precommit_original_mime = (string) get_post_mime_type( 79 );
$precommit_original_post_content = (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' );
$precommit_history_before = $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null;
$precommit_derivatives_before = $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_optimized_derivatives'] ?? null;
$precommit_latest_derivative_before = $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_latest_optimized_derivative'] ?? null;
$precommit_backup_files_before = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$precommit_backup_files_before = is_array( $precommit_backup_files_before ) ? $precommit_backup_files_before : array();
$concurrent_relative_file = '2026/06/concurrent-external-state.jpg';
$concurrent_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $concurrent_relative_file;
$concurrent_contents = 'concurrent-external-state-bytes';
$concurrent_post_content = '<p>Concurrent editor state must survive the losing Cloud adoption.</p>';
$cloud_artifact_receive_count = 0;
$GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] = static function ( array $artifact ) use ( $cloud_artifact_contents, &$cloud_artifact_receive_count, $concurrent_relative_file, $concurrent_path, $concurrent_contents, $concurrent_post_content ) {
	++$cloud_artifact_receive_count;
	file_put_contents( $concurrent_path, $concurrent_contents );
	update_post_meta( 79, '_wp_attached_file', $concurrent_relative_file );
	update_post_meta(
		79,
		'_wp_attachment_metadata',
		array(
			'file'     => $concurrent_relative_file,
			'width'    => 777,
			'height'   => 555,
			'filesize' => strlen( $concurrent_contents ),
			'sizes'    => array(),
		)
	);
	wp_update_post( array( 'ID' => 79, 'post_mime_type' => 'image/png' ), true );
	wp_update_post( array( 'ID' => 89, 'post_content' => $concurrent_post_content ), true );
	return npcink_abilities_toolkit_cloud_receive_fixture( $artifact, $cloud_artifact_contents );
};
$cloud_adoption_precommit_drift = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_00000000000000000000000000000017',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'precommit-drift.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
$precommit_drift_data = is_wp_error( $cloud_adoption_precommit_drift ) ? $cloud_adoption_precommit_drift->get_error_data() : array();
$precommit_drift_fields = is_array( $precommit_drift_data ) ? (array) ( $precommit_drift_data['drift_fields'] ?? array() ) : array();
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_precommit_drift ) && 'npcink_abilities_toolkit_cloud_adoption_precommit_drift' === $cloud_adoption_precommit_drift->get_error_code(), 'adopt-cloud-media-derivative fails closed when attachment truth drifts during verified receive' );
npcink_abilities_toolkit_assert_same( 409, $precommit_drift_data['status'] ?? 0, 'receive-time attachment drift returns a conflict instead of overwriting concurrent state' );
npcink_abilities_toolkit_assert_true( in_array( 'attachment_meta', $precommit_drift_fields, true ) && in_array( 'attachment_mime_type', $precommit_drift_fields, true ) && in_array( 'post_contents', $precommit_drift_fields, true ), 'precommit drift evidence identifies attachment meta, MIME, and post-content changes' );
npcink_abilities_toolkit_assert_same( 1, $cloud_artifact_receive_count, 'precommit drift is detected after verified Cloud receive' );
npcink_abilities_toolkit_assert_same( $concurrent_relative_file, get_post_meta( 79, '_wp_attached_file', true ), 'precommit drift cleanup preserves the concurrent attachment pointer' );
npcink_abilities_toolkit_assert_same( 'image/png', get_post_mime_type( 79 ), 'precommit drift cleanup preserves the concurrent attachment MIME type' );
npcink_abilities_toolkit_assert_same( $concurrent_post_content, (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'precommit drift cleanup preserves concurrent post content' );
npcink_abilities_toolkit_assert_same( $concurrent_contents, is_readable( $concurrent_path ) ? file_get_contents( $concurrent_path ) : '', 'precommit drift cleanup preserves the concurrent attachment bytes' );
npcink_abilities_toolkit_assert_true( ! is_file( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/precommit-drift.webp' ), 'precommit drift cleanup deletes only the derivative created by the losing batch' );
npcink_abilities_toolkit_assert_same( $precommit_history_before, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null, 'precommit drift creates no replacement history' );
npcink_abilities_toolkit_assert_same( $precommit_derivatives_before, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_optimized_derivatives'] ?? null, 'precommit drift creates no optimized derivative metadata' );
npcink_abilities_toolkit_assert_same( $precommit_latest_derivative_before, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_latest_optimized_derivative'] ?? null, 'precommit drift creates no latest derivative projection' );
$precommit_backup_files_after = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$precommit_backup_files_after = is_array( $precommit_backup_files_after ) ? $precommit_backup_files_after : array();
npcink_abilities_toolkit_assert_same( $precommit_backup_files_before, $precommit_backup_files_after, 'precommit drift creates no local backup' );
update_post_meta( 79, '_wp_attached_file', $precommit_original_relative_file );
update_post_meta( 79, '_wp_attachment_metadata', $precommit_original_metadata );
wp_update_post( array( 'ID' => 79, 'post_mime_type' => $precommit_original_mime ), true );
wp_update_post( array( 'ID' => 89, 'post_content' => $precommit_original_post_content ), true );
wp_delete_file( $concurrent_path );
$GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] = $valid_cloud_artifact_receive_callback;

$exclusive_race_contents = 'external-exclusive-create-winner';
$exclusive_race_created = false;
add_filter(
	'npcink_abilities_toolkit_media_file_write_blocked',
	static function ( $blocked, $target_path, $bytes, $context ) use ( &$exclusive_race_created, $exclusive_race_contents ) {
		unset( $bytes );
		if (
			! $exclusive_race_created
			&& is_array( $context )
			&& 'adopt_cloud_media_derivative' === (string) ( $context['operation'] ?? '' )
			&& 'write_derivative' === (string) ( $context['step'] ?? '' )
			&& 'exclusive-race.webp' === basename( (string) $target_path )
		) {
			$exclusive_race_created = true;
			file_put_contents( (string) $target_path, $exclusive_race_contents );
		}
		return $blocked;
	},
	10,
	4
);
$cloud_adoption_exclusive_race = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_00000000000000000000000000000018',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'exclusive-race.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_write_blocked' );
$exclusive_race_error_data = is_wp_error( $cloud_adoption_exclusive_race ) ? $cloud_adoption_exclusive_race->get_error_data() : array();
$exclusive_race_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/exclusive-race.webp';
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_exclusive_race ) && 'npcink_abilities_toolkit_cloud_derivative_target_conflict' === $cloud_adoption_exclusive_race->get_error_code(), 'Cloud derivative exclusive create fails safely when another request wins after filename selection' );
npcink_abilities_toolkit_assert_same( 409, $exclusive_race_error_data['status'] ?? 0, 'exclusive derivative target races return a conflict' );
npcink_abilities_toolkit_assert_same( $exclusive_race_contents, is_readable( $exclusive_race_path ) ? file_get_contents( $exclusive_race_path ) : '', 'exclusive derivative target races never overwrite or delete the competing file' );
npcink_abilities_toolkit_assert_same( $precommit_history_before, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null, 'exclusive derivative target races create no replacement history' );
wp_delete_file( $exclusive_race_path );
add_filter(
	'npcink_abilities_toolkit_media_file_write_blocked',
	static function ( $blocked, $target_path, $bytes, $context ) {
		unset( $target_path, $bytes );
		return true === $blocked || (
			is_array( $context ) &&
			'adopt_cloud_media_derivative' === (string) ( $context['operation'] ?? '' ) &&
			'write_derivative' === (string) ( $context['step'] ?? '' )
		);
	},
	10,
	4
);
$cloud_adoption_write_failure = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000012',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'write-failure.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_write_blocked' );
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_write_failure ) && 'npcink_abilities_toolkit_cloud_derivative_write_failed' === $cloud_adoption_write_failure->get_error_code(), 'adopt-cloud-media-derivative commit reports local derivative write failures' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'adopt-cloud-media-derivative write failure leaves the attachment pointer unchanged' );
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) {
		unset( $source_path, $target_path );
		return true === $blocked || (
			is_array( $context ) &&
			'replace_media_file' === (string) ( $context['operation'] ?? '' ) &&
			'backup_current' === (string) ( $context['step'] ?? '' )
		);
	},
	10,
	4
);
$cloud_adoption_backup_failure = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000013',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'backup-failure.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_backup_failure ) && 'npcink_abilities_toolkit_media_backup_failed' === $cloud_adoption_backup_failure->get_error_code(), 'adopt-cloud-media-derivative commit reports current media backup failures' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'adopt-cloud-media-derivative backup failure leaves the attachment pointer unchanged' );

$late_drift_relative_file = '2026/06/late-concurrent-external.jpg';
$late_drift_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $late_drift_relative_file;
$late_drift_contents = 'late-concurrent-external-bytes';
$late_drift_post_content = '<p>Late concurrent editor state must survive backup-time drift.</p>';
$late_drift_injected = false;
$late_drift_backups_before = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$late_drift_backups_before = is_array( $late_drift_backups_before ) ? $late_drift_backups_before : array();
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) use ( &$late_drift_injected, $late_drift_relative_file, $late_drift_path, $late_drift_contents, $late_drift_post_content ) {
		unset( $source_path, $target_path );
		if (
			! $late_drift_injected
			&& is_array( $context )
			&& 'replace_media_file' === (string) ( $context['operation'] ?? '' )
			&& 'backup_current' === (string) ( $context['step'] ?? '' )
		) {
			$late_drift_injected = true;
			file_put_contents( $late_drift_path, $late_drift_contents );
			update_post_meta( 79, '_wp_attached_file', $late_drift_relative_file );
			update_post_meta(
				79,
				'_wp_attachment_metadata',
				array(
					'file'     => $late_drift_relative_file,
					'width'    => 640,
					'height'   => 480,
					'filesize' => strlen( $late_drift_contents ),
					'sizes'    => array(),
				)
			);
			wp_update_post( array( 'ID' => 79, 'post_mime_type' => 'image/png' ), true );
			wp_update_post( array( 'ID' => 89, 'post_content' => $late_drift_post_content ), true );
		}
		return $blocked;
	},
	10,
	4
);
$cloud_adoption_late_drift = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_0000000000000000000000000000001c',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'late-precommit-drift.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
$late_drift_error_data = is_wp_error( $cloud_adoption_late_drift ) ? $cloud_adoption_late_drift->get_error_data() : array();
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_late_drift ) && 'npcink_abilities_toolkit_cloud_adoption_precommit_drift' === $cloud_adoption_late_drift->get_error_code(), 'adopt-cloud-media-derivative reruns its state CAS after backup and before the first pointer write' );
npcink_abilities_toolkit_assert_same( 409, $late_drift_error_data['status'] ?? 0, 'backup-window drift returns a conflict' );
npcink_abilities_toolkit_assert_same( $late_drift_relative_file, get_post_meta( 79, '_wp_attached_file', true ), 'late drift cleanup preserves the concurrent attachment pointer' );
npcink_abilities_toolkit_assert_same( 'image/png', get_post_mime_type( 79 ), 'late drift cleanup preserves the concurrent attachment MIME type' );
npcink_abilities_toolkit_assert_same( $late_drift_post_content, (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'late drift cleanup preserves concurrent post content' );
npcink_abilities_toolkit_assert_same( $late_drift_contents, is_readable( $late_drift_path ) ? file_get_contents( $late_drift_path ) : '', 'late drift cleanup preserves concurrent attachment bytes' );
npcink_abilities_toolkit_assert_true( ! is_file( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/late-precommit-drift.webp' ), 'late drift cleanup removes the losing batch derivative' );
$late_drift_backups_after = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$late_drift_backups_after = is_array( $late_drift_backups_after ) ? $late_drift_backups_after : array();
npcink_abilities_toolkit_assert_same( $late_drift_backups_before, $late_drift_backups_after, 'late drift cleanup removes the losing batch backup without touching existing backups' );
npcink_abilities_toolkit_assert_same( $precommit_history_before, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null, 'late drift creates no replacement history' );
update_post_meta( 79, '_wp_attached_file', $precommit_original_relative_file );
update_post_meta( 79, '_wp_attachment_metadata', $precommit_original_metadata );
wp_update_post( array( 'ID' => 79, 'post_mime_type' => $precommit_original_mime ), true );
wp_update_post( array( 'ID' => 89, 'post_content' => $precommit_original_post_content ), true );
wp_delete_file( $late_drift_path );

$source_open_race_hold_path = $current_media_path . '.source-open-race-hold';
$source_open_race_injected = false;
$source_open_race_backups_before = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$source_open_race_backups_before = is_array( $source_open_race_backups_before ) ? $source_open_race_backups_before : array();
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) use ( &$source_open_race_injected, $source_open_race_hold_path ) {
		unset( $target_path );
		if (
			! $source_open_race_injected
			&& is_array( $context )
			&& 'replace_media_file' === (string) ( $context['operation'] ?? '' )
			&& 'backup_current' === (string) ( $context['step'] ?? '' )
		) {
			$source_open_race_injected = true;
			rename( (string) $source_path, $source_open_race_hold_path );
		}
		return $blocked;
	},
	10,
	4
);
$cloud_adoption_source_open_race = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_0000000000000000000000000000001d',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'source-open-race.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
$source_open_race_backups_after = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$source_open_race_backups_after = is_array( $source_open_race_backups_after ) ? $source_open_race_backups_after : array();
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_source_open_race ) && 'npcink_abilities_toolkit_media_backup_failed' === $cloud_adoption_source_open_race->get_error_code(), 'exclusive backup fails safely when the source disappears before fopen' );
npcink_abilities_toolkit_assert_true( is_file( $source_open_race_hold_path ), 'source-open race test preserves the externally moved source bytes' );
npcink_abilities_toolkit_assert_same( $source_open_race_backups_before, $source_open_race_backups_after, 'source-open failure creates no empty or untracked backup target' );
npcink_abilities_toolkit_assert_true( ! is_file( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/source-open-race.webp' ), 'source-open failure removes the materialized derivative through the batch manifest' );
rename( $source_open_race_hold_path, $current_media_path );

$atomic_conflict_relative_file = '2026/06/atomic-conflict.webp';
$atomic_conflict_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $atomic_conflict_relative_file;
$atomic_conflict_mime = 'IMAGE/JPEG';
$atomic_conflict_post_content = '<p>Concurrent post content inserted before the row lock.</p>';
$atomic_conflict_metadata = array(
	'file'     => $atomic_conflict_relative_file,
	'width'    => 1,
	'height'   => 1,
	'filesize' => strlen( $cloud_artifact_contents ),
	'sizes'    => array(),
);
$atomic_conflict_injected = false;
$atomic_conflict_backups_before = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$atomic_conflict_backups_before = is_array( $atomic_conflict_backups_before ) ? $atomic_conflict_backups_before : array();
$GLOBALS['npcink_abilities_toolkit_unit_wpdb_select_for_update_callback'] = static function ( $query ) use ( &$atomic_conflict_injected, $atomic_conflict_mime, $atomic_conflict_post_content, $atomic_conflict_metadata ) {
	if ( ! $atomic_conflict_injected && false !== strpos( (string) $query, 'post_mime_type' ) && false !== strpos( (string) $query, 'ID = 79' ) ) {
		$atomic_conflict_injected = true;
		$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][79]->post_mime_type = $atomic_conflict_mime;
		$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content = $atomic_conflict_post_content;
		$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_wp_attachment_metadata'] = $atomic_conflict_metadata;
	}
	return null;
};
$cloud_adoption_atomic_conflict = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_0000000000000000000000000000001e',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'atomic-conflict.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wpdb_select_for_update_callback'] );
$atomic_conflict_error_data = is_wp_error( $cloud_adoption_atomic_conflict ) ? $cloud_adoption_atomic_conflict->get_error_data() : array();
$atomic_conflict_backups_after = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$atomic_conflict_backups_after = is_array( $atomic_conflict_backups_after ) ? $atomic_conflict_backups_after : array();
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_atomic_conflict ) && 'npcink_abilities_toolkit_cloud_adoption_cleanup_conflict' === $cloud_adoption_atomic_conflict->get_error_code(), 'Cloud adoption reports bounded cleanup conflict when a concurrent write wins before the wp_posts row lock' );
npcink_abilities_toolkit_assert_same( 409, $atomic_conflict_error_data['status'] ?? 0, 'atomic mutation cleanup conflicts return HTTP 409' );
npcink_abilities_toolkit_assert_same( $atomic_conflict_mime, get_post_mime_type( 79 ), 'strict row-lock compare preserves a case-only concurrent MIME change that a case-insensitive SQL WHERE could miss' );
npcink_abilities_toolkit_assert_same( $atomic_conflict_metadata, wp_get_attachment_metadata( 79 ), 'conditional compensation never overwrites concurrent attachment metadata' );
npcink_abilities_toolkit_assert_same( $atomic_conflict_post_content, (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'conditional compensation never overwrites concurrent post content' );
npcink_abilities_toolkit_assert_same( $precommit_original_relative_file, get_post_meta( 79, '_wp_attached_file', true ), 'conditional compensation restores a batch-owned pointer through meta CAS' );
npcink_abilities_toolkit_assert_same( $cloud_artifact_contents, is_readable( $atomic_conflict_path ) ? file_get_contents( $atomic_conflict_path ) : '', 'any logical compensation conflict preserves the complete manifest instead of deleting potentially referenced derivative bytes' );
npcink_abilities_toolkit_assert_true( count( $atomic_conflict_backups_after ) > count( $atomic_conflict_backups_before ), 'logical compensation conflict preserves the bounded batch backup manifest for diagnosis' );
update_post_meta( 79, '_wp_attachment_metadata', $precommit_original_metadata );
wp_update_post( array( 'ID' => 79, 'post_mime_type' => $precommit_original_mime ), true );
wp_update_post( array( 'ID' => 89, 'post_content' => $precommit_original_post_content ), true );
wp_delete_file( $atomic_conflict_path );
foreach ( array_diff( $atomic_conflict_backups_after, $atomic_conflict_backups_before ) as $atomic_conflict_backup_path ) {
	wp_delete_file( $atomic_conflict_backup_path );
}

$cloud_adoption_original_content = (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' );
$cloud_adoption_history_before_failure = $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null;
$cloud_adoption_derivatives_before_failure = $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_optimized_derivatives'] ?? null;
$cloud_adoption_backup_files_before_failure = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$cloud_adoption_backup_files_before_failure = is_array( $cloud_adoption_backup_files_before_failure ) ? $cloud_adoption_backup_files_before_failure : array();
$reference_repair_failed_once = false;
$GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'] = static function ( array $postarr ) use ( &$reference_repair_failed_once ) {
	if ( 89 === (int) ( $postarr['ID'] ?? 0 ) && array_key_exists( 'post_content', $postarr ) && ! $reference_repair_failed_once ) {
		$reference_repair_failed_once = true;
		return new WP_Error( 'unit_wordpress_lifecycle_write_failed', 'Injected WordPress lifecycle write failure.' );
	}
	return null;
};
$cloud_adoption_reference_failure = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_00000000000000000000000000000015',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'reference-repair-failure.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_reference_failure ) && 'npcink_abilities_toolkit_cloud_adoption_mutation_conflict' === $cloud_adoption_reference_failure->get_error_code(), 'adopt-cloud-media-derivative preserves the atomic reference repair conflict after successful cleanup' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'reference repair failure restores the original attachment pointer' );
npcink_abilities_toolkit_assert_same( 'image/jpeg', get_post_mime_type( 79 ), 'reference repair failure restores the original attachment MIME type' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_original_content, (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'reference repair failure restores all touched post content' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_history_before_failure, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null, 'reference repair failure preserves existing replacement history' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_derivatives_before_failure, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_optimized_derivatives'] ?? null, 'reference repair failure preserves existing derivative metadata' );
npcink_abilities_toolkit_assert_true( ! is_file( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/reference-repair-failure.webp' ), 'reference repair failure removes the newly materialized derivative' );
$cloud_adoption_backup_files_after_failure = glob( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/*' );
$cloud_adoption_backup_files_after_failure = is_array( $cloud_adoption_backup_files_after_failure ) ? $cloud_adoption_backup_files_after_failure : array();
npcink_abilities_toolkit_assert_same( $cloud_adoption_backup_files_before_failure, $cloud_adoption_backup_files_after_failure, 'reference repair failure removes the new backup without disturbing existing backups' );

$side_effect_free_metadata_before = get_post_meta( 79, '_wp_attachment_metadata', true );
$side_effect_free_generator_called = false;
$side_effect_free_file_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/side-effect-free-generation.webp';
file_put_contents( $side_effect_free_file_path, $cloud_artifact_contents );
$GLOBALS['npcink_abilities_toolkit_unit_generate_attachment_metadata_callback'] = static function () use ( &$side_effect_free_generator_called ) {
	$side_effect_free_generator_called = true;
	return array();
};
$side_effect_free_generation_method = new ReflectionMethod( Core_Write_Package::class, 'generate_attachment_metadata_for_file' );
$side_effect_free_generation_method->setAccessible( true );
$side_effect_free_result = $side_effect_free_generation_method->invoke(
	$core_write_package,
	79,
	$side_effect_free_file_path,
	false
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_generate_attachment_metadata_callback'] );
npcink_abilities_toolkit_assert_same( false, $side_effect_free_generator_called, 'non-persisting attachment metadata generation never enters WordPress Core persistent sub-size generation' );
npcink_abilities_toolkit_assert_same( '2026/06/side-effect-free-generation.webp', $side_effect_free_result['file'] ?? '', 'non-persisting attachment metadata generation returns the verified main file path' );
npcink_abilities_toolkit_assert_same( array(), $side_effect_free_result['sizes'] ?? null, 'non-persisting attachment metadata generation defers sub-size regeneration' );
npcink_abilities_toolkit_assert_same( $side_effect_free_metadata_before, get_post_meta( 79, '_wp_attachment_metadata', true ), 'non-persisting attachment metadata generation leaves reviewed attachment metadata unchanged before locked CAS' );
wp_delete_file( $side_effect_free_file_path );

$attachment_metadata_failures_remaining = 2;
$generated_thumb_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/metadata-failure-150x150.webp';
$generated_original_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/metadata-failure-original.webp';
$preexisting_generated_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/preexisting-generated-size.webp';
$preexisting_generated_contents = 'preexisting-generated-size-bytes';
file_put_contents( $preexisting_generated_path, $preexisting_generated_contents );
$GLOBALS['npcink_abilities_toolkit_unit_generate_attachment_metadata_callback'] = static function ( $attachment_id, $file_path ) use ( $generated_thumb_path, $generated_original_path, $preexisting_generated_path ) {
	if ( 79 !== (int) $attachment_id || 'metadata-failure.webp' !== basename( (string) $file_path ) ) {
		return array();
	}
	file_put_contents( $generated_thumb_path, 'generated-thumb-bytes' );
	file_put_contents( $generated_original_path, 'generated-original-bytes' );
	return array(
		'file'           => '2026/06/metadata-failure.webp',
		'width'          => 1,
		'height'         => 1,
		'filesize'       => filesize( $file_path ),
		'original_image' => basename( $generated_original_path ),
		'sizes'          => array(
			'thumbnail' => array(
				'file'      => basename( $generated_thumb_path ),
				'width'     => 1,
				'height'    => 1,
				'mime-type' => 'image/webp',
			),
			'preexisting' => array(
				'file'      => basename( $preexisting_generated_path ),
				'width'     => 1,
				'height'    => 1,
				'mime-type' => 'image/webp',
			),
		),
	);
};
$GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'] = static function ( $post_id, $meta_key ) use ( &$attachment_metadata_failures_remaining ) {
	if ( 79 === (int) $post_id && '_wp_attachment_metadata' === (string) $meta_key && $attachment_metadata_failures_remaining > 0 ) {
		--$attachment_metadata_failures_remaining;
		return false;
	}
	return null;
};
$cloud_adoption_metadata_failure = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture(
			array(
				'artifact_id'    => 'art_00000000000000000000000000000016',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $cloud_artifact_contents ),
				'sha256'         => $cloud_artifact_sha256,
			)
		),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'metadata-failure.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'commit'                       => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'], $GLOBALS['npcink_abilities_toolkit_unit_generate_attachment_metadata_callback'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $cloud_adoption_metadata_failure ) && 'npcink_abilities_toolkit_cloud_adoption_mutation_conflict' === $cloud_adoption_metadata_failure->get_error_code(), 'adopt-cloud-media-derivative detects attachment metadata persistence failure inside the locked WordPress meta lifecycle' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'metadata failure restores the original attachment pointer' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_original_content, (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'metadata failure restores repaired post content' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_history_before_failure, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_file_replacement_history'] ?? null, 'metadata failure preserves existing replacement history' );
npcink_abilities_toolkit_assert_same( $cloud_adoption_derivatives_before_failure, $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][79]['_npcink_ai_media_optimized_derivatives'] ?? null, 'metadata failure preserves existing derivative metadata' );
npcink_abilities_toolkit_assert_true( ! is_file( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/metadata-failure.webp' ), 'metadata failure removes the newly materialized derivative' );
npcink_abilities_toolkit_assert_true( ! is_file( $generated_thumb_path ), 'metadata failure removes generated thumbnail files from the in-memory batch manifest' );
npcink_abilities_toolkit_assert_true( ! is_file( $generated_original_path ), 'metadata failure removes generated original_image files even when metadata never persisted' );
npcink_abilities_toolkit_assert_same( $preexisting_generated_contents, is_readable( $preexisting_generated_path ) ? file_get_contents( $preexisting_generated_path ) : '', 'metadata failure cleanup never claims or deletes a generated-size path that predated the batch' );
wp_delete_file( $preexisting_generated_path );
$cloud_lifecycle_hooks = array( 'post_updated' => 0, 'save_post' => 0, 'wp_after_insert_post' => 0 );
foreach ( array_keys( $cloud_lifecycle_hooks ) as $cloud_lifecycle_hook ) {
	add_action(
		$cloud_lifecycle_hook,
		static function ( $post_id ) use ( &$cloud_lifecycle_hooks, $cloud_lifecycle_hook ) {
			if ( in_array( absint( $post_id ), array( 79, 89 ), true ) ) {
				++$cloud_lifecycle_hooks[ $cloud_lifecycle_hook ];
			}
		}
	);
}
$cloud_adoption_commit = $core_write_package->adopt_cloud_media_derivative(
	array(
		'attachment_id'                 => 79,
		'derivative_artifact'           => npcink_abilities_toolkit_cloud_artifact_fixture( array(
			'artifact_id'    => 'art_00000000000000000000000000000014',
			'expires_at'     => gmdate( 'c', time() + 600 ),
			'mime_type'      => 'image/webp',
			'format'         => 'webp',
			'width'          => 1,
			'height'         => 1,
			'filesize_bytes' => strlen( $cloud_artifact_contents ),
			'sha256'         => $cloud_artifact_sha256,
		) ),
		'expected_current_relative_file' => '2026/06/workflow-diagram-image.jpg',
		'expected_current_mime_type'    => 'image/jpeg',
		'expected_derivative_mime_type' => 'image/webp',
		'file_name'                    => 'customer-approved-diagram.webp',
		'expected_content_reference_post_ids' => $cloud_adoption_expected_post_ids,
		'expected_content_reference_post_count' => $cloud_adoption_expected_post_count,
		'expected_content_reference_replacement_count' => $cloud_adoption_expected_replacement_count,
		'batch_id'                     => 'media_optimization_retention_test',
		'optimization_profile'         => 'auto_safe.v1',
		'batch_confirmation_digest'    => 'sha256:' . str_repeat( 'a', 64 ),
		'commit'                       => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'], $GLOBALS['npcink_abilities_toolkit_unit_cloud_artifact_receive_callback'] );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $cloud_adoption_commit ), 'adopt-cloud-media-derivative commit succeeds after approval' . ( is_wp_error( $cloud_adoption_commit ) ? ': ' . $cloud_adoption_commit->get_error_code() : '' ) );
npcink_abilities_toolkit_assert_same( false, $cloud_adoption_commit['dry_run'] ?? null, 'adopt-cloud-media-derivative commit exits dry-run' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['replaced'] ?? null, 'adopt-cloud-media-derivative commit replaces the attachment pointer after approval' );
npcink_abilities_toolkit_assert_same( 'media_artifact_verified_transfer.v1', $cloud_adoption_commit['transfer_evidence']['contract_version'] ?? '', 'adopt-cloud-media-derivative returns exact verified transfer evidence for Core audit intake' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['transfer_evidence']['image_decoded'] ?? null, 'adopt-cloud-media-derivative reports independent image decode verification' );
npcink_abilities_toolkit_assert_same( 'media_artifact_delivery_ack.v1', $cloud_adoption_commit['delivery_ack']['contract_version'] ?? '', 'adopt-cloud-media-derivative returns the exact Cloud ACK projection' );
npcink_abilities_toolkit_assert_same( 'verified_transfer_only', $cloud_adoption_commit['delivery_ack']['acknowledgement_scope'] ?? '', 'adopt-cloud-media-derivative keeps ACK limited to transfer proof rather than local apply proof' );
npcink_abilities_toolkit_assert_same( 'image/webp', get_post_mime_type( 79 ), 'adopt-cloud-media-derivative commit updates attachment MIME type' );
npcink_abilities_toolkit_assert_same( '2026/06/customer-approved-diagram.webp', get_post_meta( 79, '_wp_attached_file', true ), 'adopt-cloud-media-derivative commit accepts an approved custom derivative file name' );
$cloud_adoption_latest_history = get_post_meta( 79, '_npcink_ai_media_latest_file_replacement', true );
npcink_abilities_toolkit_assert_true( 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', (string) ( $cloud_adoption_latest_history['new_media_fingerprint'] ?? '' ) ) && 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', (string) ( $cloud_adoption_latest_history['derived_from_media_fingerprint'] ?? '' ) ), 'adopt-cloud-media-derivative records canonical source and target fingerprints in replacement lineage' );
npcink_abilities_toolkit_assert_true( in_array( (string) ( $cloud_adoption_latest_history['visual_reuse_policy'] ?? '' ), array( 'reuse', 'reuse_with_human_check', 'requires_reidentification' ), true ) && is_array( $cloud_adoption_latest_history['transform_facts'] ?? null ), 'adopt-cloud-media-derivative records transform facts and a bounded visual reuse policy' );
npcink_abilities_toolkit_assert_same( 'manual_confirmation_required', $cloud_adoption_latest_history['backup_cleanup_policy'] ?? '', 'exact-manifest media optimization keeps its backup until explicit cleanup confirmation' );
npcink_abilities_toolkit_assert_true( $cloud_lifecycle_hooks['post_updated'] >= 2 && $cloud_lifecycle_hooks['save_post'] >= 2 && $cloud_lifecycle_hooks['wp_after_insert_post'] >= 2, 'Cloud MIME and post-content writes retain the WordPress update lifecycle while each row lock is held' );
npcink_abilities_toolkit_assert_same( 1, $cloud_adoption_commit['content_reference_repairs']['updated_count'] ?? 0, 'adopt-cloud-media-derivative commit updates posts that embed the attachment URL' );
npcink_abilities_toolkit_assert_same( '2026/06/customer-approved-diagram.webp', $cloud_adoption_commit['verification']['media_current_file'] ?? '', 'adopt-cloud-media-derivative verification reports current media file' );
npcink_abilities_toolkit_assert_same( 'image/webp', $cloud_adoption_commit['verification']['media_mime_type'] ?? '', 'adopt-cloud-media-derivative verification reports current mime type' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['verification']['backup_available'] ?? null, 'adopt-cloud-media-derivative verification confirms backup availability' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['verification']['rollback_available'] ?? null, 'adopt-cloud-media-derivative verification confirms rollback availability' );
npcink_abilities_toolkit_assert_same( 89, $cloud_adoption_commit['verification']['post_references_verified'][0]['post_id'] ?? 0, 'adopt-cloud-media-derivative verification records the repaired post reference' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['verification']['post_references_verified'][0]['old_url_absent'] ?? null, 'adopt-cloud-media-derivative verification confirms old post URLs are absent' );
npcink_abilities_toolkit_assert_same( true, $cloud_adoption_commit['verification']['post_references_verified'][0]['new_url_present'] ?? null, 'adopt-cloud-media-derivative verification confirms new post URLs are present' );
npcink_abilities_toolkit_assert_true( (int) ( $cloud_adoption_commit['verification']['content_reference_actual_replacement_count'] ?? 0 ) >= 3, 'adopt-cloud-media-derivative verification records actual post content replacement count' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'customer-approved-diagram.webp' ), 'adopt-cloud-media-derivative commit rewrites inline image references to the adopted WebP' );
npcink_abilities_toolkit_assert_true( false === strpos( (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'workflow-diagram-image-300x162.jpg' ), 'adopt-cloud-media-derivative commit removes old sized image references from post content' );
npcink_abilities_toolkit_assert_true( false === strpos( (string) ( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][89]->post_content ?? '' ), 'workflow-diagram-image-original.jpg' ), 'adopt-cloud-media-derivative commit removes metadata original image references from post content' );
npcink_abilities_toolkit_assert_same( 'customer-approved-diagram.webp', $cloud_adoption_commit['proposed_filename'] ?? '', 'adopt-cloud-media-derivative commit records the reviewed filename proposal' );
npcink_abilities_toolkit_assert_same( 'reviewed_input', $cloud_adoption_commit['filename_policy']['source'] ?? '', 'adopt-cloud-media-derivative commit treats explicit file_name as reviewed local input' );
npcink_abilities_toolkit_assert_true( is_readable( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . get_post_meta( 79, '_wp_attached_file', true ) ), 'adopt-cloud-media-derivative commit writes the local derivative file' );
npcink_abilities_toolkit_assert_true( 0 === strpos( (string) ( $cloud_adoption_commit['backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-backups/2026/06/' ), 'adopt-cloud-media-derivative commit stores backup outside the public month media directory' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $cloud_adoption_commit['backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-cloud-backup' ), 'adopt-cloud-media-derivative commit records a backup file' );
npcink_abilities_toolkit_assert_true( is_readable( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . (string) ( $cloud_adoption_commit['backup']['relative_file'] ?? '' ) ), 'adopt-cloud-media-derivative commit writes the local backup file' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][87] = (object) array(
	'ID'             => 87,
	'post_title'     => 'Rename Media Fixture',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'rename-media-fixture',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/jpeg',
	'post_date'      => '2026-06-02 10:00:00',
);
$rename_media_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/rename-media-fixture.jpg';
if ( ! is_dir( dirname( $rename_media_path ) ) ) {
	mkdir( dirname( $rename_media_path ), 0755, true );
}
file_put_contents( $rename_media_path, 'rename-jpeg-bytes' );
update_post_meta(
	87,
	'_wp_attachment_metadata',
	array(
		'width'    => 1200,
		'height'   => 800,
		'file'     => '2026/06/rename-media-fixture.jpg',
		'filesize' => strlen( 'rename-jpeg-bytes' ),
		'sizes'    => array(
			'medium' => array(
				'file'   => 'rename-media-fixture-300x200.jpg',
				'width'  => 300,
				'height' => 200,
			),
		),
	)
);
update_post_meta( 87, '_wp_attached_file', '2026/06/rename-media-fixture.jpg' );
$rename_preview = $core_write_package->rename_media_file(
	array(
		'attachment_id'                  => 87,
		'target_file_name'               => 'rename-media-fixture-reviewed.jpg',
		'expected_current_relative_file' => '2026/06/rename-media-fixture.jpg',
		'expected_current_md5'           => md5( 'rename-jpeg-bytes' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $rename_preview['dry_run'] ?? null, 'rename-media-file defaults to dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $rename_preview['renamed'] ?? null, 'rename-media-file dry-run does not move files' );
npcink_abilities_toolkit_assert_same( '2026/06/rename-media-fixture-reviewed.jpg', $rename_preview['after']['relative_file'] ?? '', 'rename-media-file dry-run plans target relative file' );
npcink_abilities_toolkit_assert_same( md5( 'rename-jpeg-bytes' ), $rename_preview['before']['content_hashes']['md5'] ?? '', 'rename-media-file dry-run includes current MD5 evidence' );
$rename_mismatch = $core_write_package->rename_media_file(
	array(
		'attachment_id'        => 87,
		'target_file_name'     => 'rename-media-fixture-reviewed.jpg',
		'expected_current_md5' => str_repeat( '0', 32 ),
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $rename_mismatch ) && 'npcink_abilities_toolkit_current_md5_mismatch' === $rename_mismatch->get_error_code(), 'rename-media-file rejects current hash mismatches' );
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-rename',
);
$rename_commit = $core_write_package->rename_media_file(
	array(
		'attachment_id'                  => 87,
		'target_file_name'               => 'rename-media-fixture-reviewed.jpg',
		'expected_current_relative_file' => '2026/06/rename-media-fixture.jpg',
		'expected_current_md5'           => md5( 'rename-jpeg-bytes' ),
		'commit'                         => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( ! is_wp_error( $rename_commit ), 'rename-media-file commit succeeds after approval' . ( is_wp_error( $rename_commit ) ? ': ' . $rename_commit->get_error_code() : '' ) );
npcink_abilities_toolkit_assert_same( false, $rename_commit['dry_run'] ?? null, 'rename-media-file commit exits dry-run' );
npcink_abilities_toolkit_assert_same( true, $rename_commit['renamed'] ?? null, 'rename-media-file commit renames the attachment main file' );
npcink_abilities_toolkit_assert_same( '2026/06/rename-media-fixture-reviewed.jpg', get_post_meta( 87, '_wp_attached_file', true ), 'rename-media-file commit updates attached file pointer' );
npcink_abilities_toolkit_assert_true( ! is_readable( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/rename-media-fixture.jpg' ), 'rename-media-file commit moves the original main file' );
npcink_abilities_toolkit_assert_true( is_readable( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/rename-media-fixture-reviewed.jpg' ), 'rename-media-file commit writes the renamed main file' );
npcink_abilities_toolkit_assert_true( 0 === strpos( (string) ( $rename_commit['backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-backups/2026/06/' ), 'rename-media-file commit stores backup outside the public month media directory' );
npcink_abilities_toolkit_assert_true( is_readable( $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . (string) ( $rename_commit['backup']['relative_file'] ?? '' ) ), 'rename-media-file commit writes a rollback backup file' );
$renamed_metadata = wp_get_attachment_metadata( 87 );
npcink_abilities_toolkit_assert_same( '2026/06/rename-media-fixture-reviewed.jpg', $renamed_metadata['file'] ?? '', 'rename-media-file commit updates attachment metadata file' );
npcink_abilities_toolkit_assert_same( 'rename-media-fixture-300x200.jpg', $renamed_metadata['sizes']['medium']['file'] ?? '', 'rename-media-file commit preserves existing size metadata' );
update_post_meta( 79, '_wp_attached_file', '2026/06/workflow-diagram-image-optimized.webp' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][79]->post_mime_type = 'image/webp';
update_post_meta(
	79,
	'_wp_attachment_metadata',
	array(
		'width'    => 1920,
		'height'   => 1034,
		'file'     => '2026/06/workflow-diagram-image-optimized.webp',
		'filesize' => 300000,
	)
);
update_post_meta(
	79,
	'_npcink_ai_media_file_replacement_history',
	array(
		array(
			'replacement_id'     => 'media_replace_unit',
			'status'             => 'active',
			'replaced_at_gmt'    => '2026-06-02T00:00:00+00:00',
			'rolled_back_at_gmt' => '',
			'backup_cleanup_policy' => 'manual_confirmation_required',
			'before'             => array(
				'relative_file' => '2026/06/workflow-diagram-image.jpg',
				'mime_type'     => 'image/jpeg',
				'width'         => 2600,
				'height'        => 1400,
				'media_fingerprint' => 'sha256:' . hash( 'sha256', 'original-jpeg-bytes' ),
			),
			'after'              => array(
				'relative_file' => '2026/06/workflow-diagram-image-optimized.webp',
				'mime_type'     => 'image/webp',
				'width'         => 1920,
				'height'        => 1034,
				'media_fingerprint' => 'sha256:' . hash( 'sha256', 'optimized-webp-bytes' ),
			),
			'backup'             => array(
				'relative_file'  => 'npcink-abilities-toolkit-backups/2026/06/workflow-diagram-image-npcink-abilities-toolkit-backup-media_replace_unit.jpg',
				'mime_type'      => 'image/jpeg',
				'width'          => 2600,
				'height'         => 1400,
				'filesize_bytes' => 900000,
			),
		),
	)
);
$media_restore_backup_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/npcink-abilities-toolkit-backups/2026/06/workflow-diagram-image-npcink-abilities-toolkit-backup-media_replace_unit.jpg';
if ( ! is_dir( dirname( $media_restore_backup_path ) ) ) {
	mkdir( dirname( $media_restore_backup_path ), 0755, true );
}
file_put_contents( $media_restore_backup_path, 'original-jpeg-bytes' );
$media_restore_current_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/workflow-diagram-image-optimized.webp';
if ( ! is_dir( dirname( $media_restore_current_path ) ) ) {
	mkdir( dirname( $media_restore_current_path ), 0755, true );
}
file_put_contents( $media_restore_current_path, 'optimized-webp-bytes' );
$media_backups = $core_read_package->list_media_backups(
	array(
		'attachment_id' => 79,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_backups['success'] ?? null, 'list-media-backups returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $media_backups['data']['summary']['backup_count'] ?? 0, 'list-media-backups returns recorded backup count' );
npcink_abilities_toolkit_assert_same( 'media_replace_unit', $media_backups['data']['backups'][0]['backup_id'] ?? '', 'list-media-backups exposes backup id for restore' );
npcink_abilities_toolkit_assert_same( true, $media_backups['data']['backups'][0]['file_exists'] ?? null, 'list-media-backups checks backup file availability' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/restore-media-backup', $media_backups['data']['backups'][0]['restore_action']['target_ability_id'] ?? '', 'list-media-backups returns restore-media-backup action metadata' );
$media_restore_preview = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => '2026/06/workflow-diagram-image-optimized.webp',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_restore_preview['dry_run'] ?? null, 'restore-media-backup defaults to dry-run preview' );
npcink_abilities_toolkit_assert_same( false, $media_restore_preview['restored'] ?? null, 'restore-media-backup dry-run does not switch files' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', $media_restore_preview['after']['relative_file'] ?? '', 'restore-media-backup targets the original public media path' );
npcink_abilities_toolkit_assert_true( isset( $media_restore_preview['content_reference_repairs']['replacement_rule_count'] ), 'restore-media-backup previews post content reference repairs for rollback' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_restore_preview['current_backup']['relative_file'] ?? '' ), 'npcink-abilities-toolkit-restore-backup' ), 'restore-media-backup plans a backup of the current main file before restore' );
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-failure',
);
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) {
		unset( $source_path, $target_path );
		return true === $blocked || (
			is_array( $context ) &&
			'restore_media_backup' === (string) ( $context['operation'] ?? '' ) &&
			'backup_current' === (string) ( $context['step'] ?? '' )
		);
	},
	10,
	4
);
$media_restore_backup_failure = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => '2026/06/workflow-diagram-image-optimized.webp',
		'commit'                         => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_backup_failure ) && 'npcink_abilities_toolkit_media_backup_failed' === $media_restore_backup_failure->get_error_code(), 'restore-media-backup commit reports current optimized file backup failures' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image-optimized.webp', get_post_meta( 79, '_wp_attached_file', true ), 'restore-media-backup backup failure leaves the optimized attachment pointer unchanged' );
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) {
		unset( $source_path, $target_path );
		return true === $blocked || (
			is_array( $context ) &&
			'restore_media_backup' === (string) ( $context['operation'] ?? '' ) &&
			'restore_backup' === (string) ( $context['step'] ?? '' )
		);
	},
	10,
	4
);
$media_restore_copy_failure = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => '2026/06/workflow-diagram-image-optimized.webp',
		'commit'                         => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_copy_failure ) && 'npcink_abilities_toolkit_media_restore_failed' === $media_restore_copy_failure->get_error_code(), 'restore-media-backup commit reports original backup restore copy failures' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image-optimized.webp', get_post_meta( 79, '_wp_attached_file', true ), 'restore-media-backup copy failure leaves the optimized attachment pointer unchanged' );

$media_restore_original_pointer = (string) get_post_meta( 79, '_wp_attached_file', true );
$media_restore_original_mime = (string) get_post_mime_type( 79 );
$media_restore_original_metadata = wp_get_attachment_metadata( 79 );
$media_restore_original_history = get_post_meta( 79, '_npcink_ai_media_file_replacement_history', true );
$media_restore_original_latest = get_post_meta( 79, '_npcink_ai_media_latest_file_replacement', true );
$media_restore_target_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/workflow-diagram-image.jpg';
file_put_contents( $media_restore_target_path, 'preexisting-target-bytes' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83] = (object) array(
	'ID'           => 83,
	'post_title'   => 'Media Restore Compensation Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<p><img src="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image-optimized.webp" /></p>',
	'post_name'    => 'media-restore-compensation-candidate',
	'post_author'  => 7,
);
$media_restore_original_content = (string) $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83]->post_content;
$restore_reference_failed_once = false;
$GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'] = static function ( array $postarr ) use ( &$restore_reference_failed_once ) {
	if ( 83 === (int) ( $postarr['ID'] ?? 0 ) && array_key_exists( 'post_content', $postarr ) && ! $restore_reference_failed_once ) {
		$restore_reference_failed_once = true;
		return new WP_Error( 'unit_media_restore_reference_failure', 'Injected restore reference failure.' );
	}
	return null;
};
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-compensation',
);
$media_restore_reference_failure = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_wp_update_post_callback'], $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_reference_failure ) && 'npcink_abilities_toolkit_cloud_adoption_mutation_conflict' === $media_restore_reference_failure->get_error_code(), 'restore-media-backup preserves the reference repair failure after complete compensation' );
npcink_abilities_toolkit_assert_same( $media_restore_original_pointer, get_post_meta( 79, '_wp_attached_file', true ), 'restore reference failure restores the attachment pointer' );
npcink_abilities_toolkit_assert_same( $media_restore_original_mime, get_post_mime_type( 79 ), 'restore reference failure restores the attachment MIME' );
npcink_abilities_toolkit_assert_same( $media_restore_original_metadata, wp_get_attachment_metadata( 79 ), 'restore reference failure restores attachment metadata' );
npcink_abilities_toolkit_assert_same( $media_restore_original_content, (string) $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83]->post_content, 'restore reference failure restores affected post content' );
npcink_abilities_toolkit_assert_same( $media_restore_original_history, get_post_meta( 79, '_npcink_ai_media_file_replacement_history', true ), 'restore reference failure preserves replacement history' );
npcink_abilities_toolkit_assert_same( $media_restore_original_latest, get_post_meta( 79, '_npcink_ai_media_latest_file_replacement', true ), 'restore reference failure preserves latest replacement projection' );
npcink_abilities_toolkit_assert_same( 'preexisting-target-bytes', file_get_contents( $media_restore_target_path ), 'restore reference failure restores overwritten target bytes' );

$restore_late_drift_injected = false;
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) use ( &$restore_late_drift_injected ) {
		unset( $source_path, $target_path );
		if ( ! $restore_late_drift_injected && is_array( $context ) && 'restore_media_backup' === (string) ( $context['operation'] ?? '' ) && 'backup_current' === (string) ( $context['step'] ?? '' ) ) {
			$restore_late_drift_injected = true;
			$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83]->post_content .= '<p>Concurrent edit.</p>';
		}
		return $blocked;
	},
	10,
	4
);
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-drift',
);
$media_restore_late_drift = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true(
	is_wp_error( $media_restore_late_drift ) && 'npcink_abilities_toolkit_media_restore_precommit_drift' === $media_restore_late_drift->get_error_code(),
	'restore-media-backup blocks a concurrent post-content change at the late precommit gate; got ' . ( is_wp_error( $media_restore_late_drift ) ? $media_restore_late_drift->get_error_code() : gettype( $media_restore_late_drift ) )
);
npcink_abilities_toolkit_assert_same( $media_restore_original_pointer, get_post_meta( 79, '_wp_attached_file', true ), 'late restore drift leaves the attachment pointer unchanged' );
npcink_abilities_toolkit_assert_same( 'preexisting-target-bytes', file_get_contents( $media_restore_target_path ), 'late restore drift leaves target bytes unchanged' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83]->post_content = $media_restore_original_content;

$restore_history_failed_once = false;
$GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'] = static function ( $post_id, $meta_key ) use ( &$restore_history_failed_once ) {
	if ( 79 === (int) $post_id && '_npcink_ai_media_file_replacement_history' === (string) $meta_key && ! $restore_history_failed_once ) {
		$restore_history_failed_once = true;
		return false;
	}
	return null;
};
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-history-failure',
);
$media_restore_history_failure = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_update_post_meta_callback'], $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_history_failure ) && 'npcink_abilities_toolkit_cloud_adoption_mutation_conflict' === $media_restore_history_failure->get_error_code(), 'restore-media-backup preserves a history CAS failure after complete compensation' );
npcink_abilities_toolkit_assert_same( $media_restore_original_pointer, get_post_meta( 79, '_wp_attached_file', true ), 'restore history failure restores the attachment pointer' );
npcink_abilities_toolkit_assert_same( $media_restore_original_mime, get_post_mime_type( 79 ), 'restore history failure restores the attachment MIME' );
npcink_abilities_toolkit_assert_same( $media_restore_original_metadata, wp_get_attachment_metadata( 79 ), 'restore history failure restores attachment metadata' );
npcink_abilities_toolkit_assert_same( $media_restore_original_content, (string) $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83]->post_content, 'restore history failure restores affected post content' );
npcink_abilities_toolkit_assert_same( $media_restore_original_history, get_post_meta( 79, '_npcink_ai_media_file_replacement_history', true ), 'restore history failure preserves replacement history' );
npcink_abilities_toolkit_assert_same( $media_restore_original_latest, get_post_meta( 79, '_npcink_ai_media_latest_file_replacement', true ), 'restore history failure preserves latest replacement projection' );
npcink_abilities_toolkit_assert_same( 'preexisting-target-bytes', file_get_contents( $media_restore_target_path ), 'restore history failure restores overwritten target bytes' );

$media_restore_source_backup_relative = '';
foreach ( $media_restore_original_history as $media_restore_history_record ) {
	if ( 'media_replace_unit' === (string) ( $media_restore_history_record['replacement_id'] ?? '' ) ) {
		$media_restore_source_backup_relative = (string) ( $media_restore_history_record['backup']['relative_file'] ?? '' );
		break;
	}
}
$media_restore_source_backup_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $media_restore_source_backup_relative;
$media_restore_source_backup_bytes = file_get_contents( $media_restore_source_backup_path );
$restore_backup_drift_injected = false;
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) use ( &$restore_backup_drift_injected, $media_restore_source_backup_path ) {
		unset( $source_path, $target_path );
		if ( ! $restore_backup_drift_injected && is_array( $context ) && 'restore_media_backup' === (string) ( $context['operation'] ?? '' ) && 'backup_current' === (string) ( $context['step'] ?? '' ) ) {
			$restore_backup_drift_injected = true;
			file_put_contents( $media_restore_source_backup_path, 'concurrent-backup-drift' );
		}
		return $blocked;
	},
	10,
	4
);
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-backup-drift',
);
$media_restore_backup_drift = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_backup_drift ) && 'npcink_abilities_toolkit_media_restore_precommit_drift' === $media_restore_backup_drift->get_error_code(), 'restore-media-backup blocks source backup drift at the late precommit gate' );
npcink_abilities_toolkit_assert_same( $media_restore_original_pointer, get_post_meta( 79, '_wp_attached_file', true ), 'source backup drift leaves the attachment pointer unchanged' );
npcink_abilities_toolkit_assert_same( 'preexisting-target-bytes', file_get_contents( $media_restore_target_path ), 'source backup drift leaves target bytes unchanged' );
file_put_contents( $media_restore_source_backup_path, $media_restore_source_backup_bytes );

$restore_target_drift_injected = false;
add_filter(
	'npcink_abilities_toolkit_media_file_copy_blocked',
	static function ( $blocked, $source_path, $target_path, $context ) use ( &$restore_target_drift_injected, $media_restore_target_path ) {
		unset( $source_path, $target_path );
		if ( ! $restore_target_drift_injected && is_array( $context ) && 'restore_media_backup' === (string) ( $context['operation'] ?? '' ) && 'backup_restore_target' === (string) ( $context['step'] ?? '' ) ) {
			$restore_target_drift_injected = true;
			file_put_contents( $media_restore_target_path, 'concurrent-target-winner' );
		}
		return $blocked;
	},
	10,
	4
);
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-target-drift',
);
$media_restore_target_drift = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
remove_all_filters( 'npcink_abilities_toolkit_media_file_copy_blocked' );
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_restore_target_drift ) && 'npcink_abilities_toolkit_media_restore_precommit_drift' === $media_restore_target_drift->get_error_code(), 'restore-media-backup blocks target drift during compensation copy' );
npcink_abilities_toolkit_assert_same( $media_restore_original_pointer, get_post_meta( 79, '_wp_attached_file', true ), 'target drift during compensation copy leaves the attachment pointer unchanged' );
npcink_abilities_toolkit_assert_same( 'concurrent-target-winner', file_get_contents( $media_restore_target_path ), 'target drift during compensation copy preserves the concurrent winner bytes' );
file_put_contents( $media_restore_target_path, 'preexisting-target-bytes' );

$restore_compensation_pattern = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/2026/06/*npcink-abilities-toolkit-restore-compensation*';
$restore_compensation_before = glob( $restore_compensation_pattern );
$GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] = array(
	'approval_commit_authorized' => true,
	'approval_id'                => 'approval-media-restore-success',
);
$media_restore_success = $core_write_package->restore_media_backup(
	array(
		'attachment_id'                  => 79,
		'backup_id'                      => 'media_replace_unit',
		'expected_current_relative_file' => $media_restore_original_pointer,
		'target_conflict_mode'           => 'overwrite',
		'commit'                         => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
$media_restore_success_history = get_post_meta( 79, '_npcink_ai_media_file_replacement_history', true );
$media_restore_success_original = array_values( array_filter( $media_restore_success_history, static function ( $record ) { return 'media_replace_unit' === (string) ( $record['replacement_id'] ?? '' ); } ) );
$media_restore_success_latest = get_post_meta( 79, '_npcink_ai_media_latest_file_replacement', true );
$media_restore_current_backup_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . (string) ( $media_restore_success['current_backup']['relative_file'] ?? '' );
npcink_abilities_toolkit_assert_same( true, $media_restore_success['restored'] ?? null, 'restore-media-backup success reports restored=true' );
npcink_abilities_toolkit_assert_same( true, $media_restore_success['rolled_back'] ?? null, 'restore-media-backup success reports rolled_back=true' );
npcink_abilities_toolkit_assert_same( '2026/06/workflow-diagram-image.jpg', get_post_meta( 79, '_wp_attached_file', true ), 'restore-media-backup success switches to the original attachment pointer' );
npcink_abilities_toolkit_assert_same( $media_restore_source_backup_bytes, file_get_contents( $media_restore_target_path ), 'restore-media-backup success restores the selected backup bytes' );
npcink_abilities_toolkit_assert_true( is_file( $media_restore_current_backup_path ), 'restore-media-backup success retains the new rollback backup' );
npcink_abilities_toolkit_assert_same( 'rolled_back', $media_restore_success_original[0]['status'] ?? '', 'restore-media-backup success marks the selected replacement rolled back' );
npcink_abilities_toolkit_assert_same( 'active', $media_restore_success_latest['status'] ?? '', 'restore-media-backup success records an active restore history projection' );
npcink_abilities_toolkit_assert_same( 'media_replace_unit', $media_restore_success_latest['restored_from'] ?? '', 'restore-media-backup success links the restore record to its selected backup' );
npcink_abilities_toolkit_assert_true( 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', (string) ( $media_restore_success_latest['new_media_fingerprint'] ?? '' ) ) && 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', (string) ( $media_restore_success_latest['derived_from_media_fingerprint'] ?? '' ) ), 'restore-media-backup success records canonical source and target fingerprints in restore lineage' );
npcink_abilities_toolkit_assert_same( 'requires_reidentification', $media_restore_success_latest['visual_reuse_policy'] ?? '', 'restore-media-backup marks restored media as requiring fresh visual identification' );
npcink_abilities_toolkit_assert_same( 'manual_confirmation_required', $media_restore_success_latest['backup_cleanup_policy'] ?? '', 'restore history inherits the exact-manifest manual cleanup policy' );
npcink_abilities_toolkit_assert_same( $restore_compensation_before, glob( $restore_compensation_pattern ), 'restore-media-backup success removes its transient overwrite compensation file' );

$manual_retention_relative = 'npcink-abilities-toolkit-backups/2026/06/manual-retention.jpg';
$automatic_retention_relative = 'npcink-abilities-toolkit-backups/2026/06/automatic-retention.jpg';
$manual_retention_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $manual_retention_relative;
$automatic_retention_path = $GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] . '/' . $automatic_retention_relative;
file_put_contents( $manual_retention_path, 'manual-retention-bytes' );
file_put_contents( $automatic_retention_path, 'automatic-retention-bytes' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][790] = (object) array(
	'ID'             => 790,
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image/jpeg',
);
update_post_meta(
	790,
	'_npcink_ai_media_file_replacement_history',
	array(
		array(
			'replacement_id'       => 'manual-retention',
			'status'               => 'active',
			'replaced_at_gmt'      => gmdate( 'c', time() - ( 31 * 86400 ) ),
			'backup_cleanup_policy' => 'manual_confirmation_required',
			'backup'               => array( 'relative_file' => $manual_retention_relative, 'file_exists' => true ),
		),
		array(
			'replacement_id'  => 'automatic-retention',
			'status'          => 'active',
			'replaced_at_gmt' => gmdate( 'c', time() - ( 31 * 86400 ) ),
			'backup'          => array( 'relative_file' => $automatic_retention_relative, 'file_exists' => true ),
		),
	)
);
$media_backup_cleanup = $core_write_package->cleanup_expired_media_backups();
$media_backup_cleanup_history = get_post_meta( 790, '_npcink_ai_media_file_replacement_history', true );
npcink_abilities_toolkit_assert_true( is_file( $manual_retention_path ), 'expired exact-manifest backup remains available without explicit cleanup confirmation' );
npcink_abilities_toolkit_assert_same( 'active', $media_backup_cleanup_history[0]['status'] ?? '', 'expired exact-manifest history remains restorable' );
npcink_abilities_toolkit_assert_true( ! is_file( $automatic_retention_path ), 'legacy backup without a manual policy is removed after retention' );
npcink_abilities_toolkit_assert_same( 'backup_expired', $media_backup_cleanup_history[1]['status'] ?? '', 'legacy backup history records automatic expiry' );
npcink_abilities_toolkit_assert_true( (int) ( $media_backup_cleanup['removed'] ?? 0 ) >= 1, 'backup cleanup reports automatically removed legacy files' );

update_post_meta( 79, '_wp_attached_file', '2026/06/workflow-diagram-image.jpg' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][79]->post_mime_type = 'image/jpeg';
update_post_meta(
	79,
	'_wp_attachment_metadata',
	array(
		'width'    => 2600,
		'height'   => 1400,
		'file'     => '2026/06/workflow-diagram-image.jpg',
		'filesize' => 900000,
		'sizes'    => array(
			'medium' => array(
				'file'   => 'workflow-diagram-image-300x162.jpg',
				'width'  => 300,
				'height' => 162,
			),
		),
	)
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][83] = (object) array(
	'ID'           => 83,
	'post_title'   => 'Media Reference Repair Candidate',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_excerpt' => '',
	'post_content' => '<p><img src="https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg" /></p><p><a href="/wp-content/uploads/2026/06/workflow-diagram-image.jpg">download</a></p><p><img src="/wp-content/uploads/2026/06/workflow-diagram-image-300x162.jpg" /></p>',
	'post_name'    => 'media-reference-repair-candidate',
	'post_author'  => 7,
);
$media_reference_repair_plan = $core_read_package->build_media_reference_repair_plan(
	array(
		'attachment_id'  => 79,
		'replacement_id' => 'media_replace_unit',
		'max_posts'      => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_reference_repair_plan['success'] ?? null, 'build-media-reference-repair-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( false, $media_reference_repair_plan['data']['commit_execution'] ?? null, 'media reference repair plan does not execute commits' );
npcink_abilities_toolkit_assert_same( 1, $media_reference_repair_plan['data']['action_count'] ?? 0, 'media reference repair plan builds one post patch action' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/patch-post-content', $media_reference_repair_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media reference repair plan reuses patch-post-content' );
npcink_abilities_toolkit_assert_same( 83, $media_reference_repair_plan['data']['write_actions'][0]['input']['post_id'] ?? 0, 'media reference repair action targets the referencing post' );
npcink_abilities_toolkit_assert_same( 'replace', $media_reference_repair_plan['data']['write_actions'][0]['input']['operations'][0]['op'] ?? '', 'media reference repair action uses replace operations' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_reference_repair_plan['data']['write_actions'][0]['input']['operations'][0]['find'] ?? '' ), 'workflow-diagram-image.jpg' ), 'media reference repair action finds old media URL' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $media_reference_repair_plan['data']['write_actions'][0]['input']['operations'][0]['replace'] ?? '' ), 'workflow-diagram-image-optimized.webp' ), 'media reference repair action replaces with new media URL' );
npcink_abilities_toolkit_assert_same( 'old_sized_variant_reference_detected', $media_reference_repair_plan['data']['manual_review'][0]['reason'] ?? '', 'media reference repair plan sends old size variants to manual review' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][82] = (object) array(
	'ID'           => 82,
	'post_title'   => 'Media Adoption Enhancement Candidate',
	'post_status'  => 'draft',
	'post_type'    => 'page',
	'post_excerpt' => '',
	'post_content' => '<!-- wp:media-text {"mediaUrl":"https://example.test/wp-content/uploads/2026/06/raw-dashboard.png"} --><div><img src="https://example.test/wp-content/uploads/2026/06/raw-dashboard.png" /></div><!-- /wp:media-text -->',
	'post_name'    => 'media-adoption-enhancement-candidate',
	'post_author'  => 7,
);
$media_adoption_enhancement_plan = $core_read_package->build_media_adoption_enhancement_plan(
	array(
		'url'               => 'https://cdn.example.test/generated/raw-dashboard.png',
		'post_id'           => 82,
		'old_url'           => 'https://example.test/wp-content/uploads/2026/06/raw-dashboard.png',
		'file_name'         => 'raw-dashboard.png',
		'title'             => 'Dashboard visual',
		'alt'               => 'Dashboard visual showing proposal approval.',
		'description'       => 'Reviewed page visual.',
		'source_type'       => 'ai_generated',
		'preferred_format'  => 'webp',
		'target_max_width'  => 1024,
		'quality'           => 82,
		'derivative_suffix' => 'optimized',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_adoption_enhancement_plan['success'] ?? null, 'build-media-adoption-enhancement-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_adoption_enhancement_plan', $media_adoption_enhancement_plan['data']['artifact_type'] ?? '', 'media adoption enhancement plan declares artifact type' );
npcink_abilities_toolkit_assert_same( 'batch', $media_adoption_enhancement_plan['data']['proposal_mode'] ?? '', 'media adoption enhancement plan requests batch proposal mode' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_enhancement_plan['data']['direct_wordpress_write'] ?? null, 'media adoption enhancement plan does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $media_adoption_enhancement_plan['data']['commit_execution'] ?? null, 'media adoption enhancement plan keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( true, $media_adoption_enhancement_plan['meta']['readonly'] ?? null, 'media adoption enhancement plan remains read-only' );
$media_adoption_actions = (array) ( $media_adoption_enhancement_plan['data']['write_actions'] ?? array() );
npcink_abilities_toolkit_assert_same( 3, count( $media_adoption_actions ), 'media adoption enhancement plan emits upload, optimize, and patch actions when old URL is present' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/upload-media-from-url', $media_adoption_actions[0]['target_ability_id'] ?? '', 'media adoption enhancement plan starts with media upload' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/optimize-media-asset', $media_adoption_actions[1]['target_ability_id'] ?? '', 'media adoption enhancement plan optimizes the uploaded media' );
npcink_abilities_toolkit_assert_same( '$outputs.upload-media-asset.attachment_id', $media_adoption_actions[1]['input']['attachment_id'] ?? '', 'media adoption enhancement plan optimizes the uploaded attachment output' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/patch-post-content', $media_adoption_actions[2]['target_ability_id'] ?? '', 'media adoption enhancement plan patches reviewed page references' );
npcink_abilities_toolkit_assert_same( array( 'optimize-media-asset' ), $media_adoption_actions[2]['depends_on'] ?? array(), 'media adoption enhancement patch waits for optimized derivative output' );
npcink_abilities_toolkit_assert_same( '$outputs.optimize-media-asset.derivative_url', $media_adoption_actions[2]['input']['operations'][0]['replace'] ?? '', 'media adoption enhancement patch uses a whole-field derivative URL output reference' );
npcink_abilities_toolkit_assert_same( 2, $media_adoption_actions[2]['input']['operations'][0]['limit'] ?? 0, 'media adoption enhancement patch limits replacements to reviewed matches' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan', $media_adoption_enhancement_plan['data']['handoff']['plan_ability_id'] ?? '', 'media adoption enhancement plan identifies itself for Core from-plan intake' );
unset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][82] );

$image_candidate_review_artifact = $core_read_package->build_image_candidate_review_artifact(
	array(
		'target_field'     => 'featured_image',
		'candidate_limit'  => 4,
		'image_candidates' => array(
			array(
				'id'                    => 'reviewed-featured',
				'contract_version'      => 'image_candidate.v1',
				'download_url'          => 'https://cdn.example.test/images/reviewed-featured.png',
				'thumbnail_url'         => 'https://cdn.example.test/images/reviewed-featured-thumb.png',
				'source_url'            => 'https://source.example.test/reviewed-featured',
				'source_type'           => 'stock',
				'provider'              => 'unsplash',
				'provider_origin'       => 'toolbox',
				'title'                 => 'Reviewed featured image',
				'description'           => 'Reviewed source image for the article.',
				'alt_description'       => 'Dashboard operator reviewing Core proposal.',
				'attribution'           => 'Photo by Example',
				'photographer'          => 'Example Photographer',
				'download_location'     => 'https://api.unsplash.example.test/download-location',
				'suggested_filename'    => 'reviewed-featured-image.png',
				'license_review_status' => 'reviewed',
				'match_score'           => 0.84,
			),
			array(
				'id'                    => 'weak-no-url',
				'title'                 => 'Weak image candidate',
				'provider'              => 'external',
				'license_review_status' => 'required',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $image_candidate_review_artifact['success'] ?? null, 'build-image-candidate-review-artifact returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'image_candidate_review.v1', $image_candidate_review_artifact['data']['artifact_type'] ?? '', 'image candidate review artifact declares artifact type' );
npcink_abilities_toolkit_assert_same( 'image_candidate.v1', $image_candidate_review_artifact['data']['candidate_contract'] ?? '', 'image candidate review artifact preserves authoritative candidate contract' );
npcink_abilities_toolkit_assert_same( 'recommendation_candidate.v1', $image_candidate_review_artifact['data']['projection_contract'] ?? '', 'image candidate review artifact exposes recommendation projection contract' );
npcink_abilities_toolkit_assert_same( false, $image_candidate_review_artifact['data']['direct_wordpress_write'] ?? null, 'image candidate review artifact does not directly write WordPress' );
npcink_abilities_toolkit_assert_true( ! isset( $image_candidate_review_artifact['data']['write_actions'] ), 'image candidate review artifact does not create adoption write actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-image-candidate-adoption-plan', $image_candidate_review_artifact['data']['handoff']['plan_ability_id'] ?? '', 'image candidate review artifact points selected candidates to the adoption planner' );
npcink_abilities_toolkit_assert_same( 'image_candidate.v1', $image_candidate_review_artifact['data']['items'][0]['contract_version'] ?? '', 'image candidate review artifact normalizes candidates to image_candidate.v1' );
npcink_abilities_toolkit_assert_same( 'https://api.unsplash.example.test/download-location', $image_candidate_review_artifact['data']['items'][0]['download_location'] ?? '', 'image candidate review artifact preserves source download tracking metadata' );
npcink_abilities_toolkit_assert_same( 'review', $image_candidate_review_artifact['data']['recommendation_candidates'][0]['quality_status'] ?? '', 'image candidate review artifact projects strong candidates for review' );
npcink_abilities_toolkit_assert_same( 'weak', $image_candidate_review_artifact['data']['recommendation_candidates'][1]['quality_status'] ?? '', 'image candidate review artifact downgrades candidates missing usable URLs' );

$media_alt_caption_review_set = $core_read_package->build_media_alt_caption_review_set(
	array(
		'review_set_limit'       => 2,
		'media_snapshot'         => array(
			'snapshot_policy' => 'current_article_media_metadata_only',
			'media_scope'     => 'current_article_used_images',
			'post_context'    => array(
				'post_id' => 82,
				'title'   => 'AI workflow operations',
			),
			'items'           => array(
				array(
					'attachment_id' => 711,
					'title'         => 'Operator reviewing workflow dashboard',
					'filename'      => 'workflow-dashboard-hero.jpg',
					'alt'           => '',
					'caption'       => '',
					'description'   => 'Generated with model: keep this provenance out of ALT.',
					'thumbnail_url' => 'https://cdn.example.test/workflow-dashboard-thumb.jpg',
					'url'           => 'https://cdn.example.test/workflow-dashboard.jpg',
					'mime_type'     => 'image/jpeg',
				),
				array(
					'attachment_id' => 712,
					'title'         => 'IMG_1234',
					'filename'      => 'IMG_1234.jpg',
					'alt'           => 'IMG_1234',
					'caption'       => '',
					'description'   => '',
					'thumbnail_url' => 'https://cdn.example.test/img-1234-thumb.jpg',
					'url'           => 'https://cdn.example.test/img-1234.jpg',
					'mime_type'     => 'image/jpeg',
				),
			),
		),
		'image_context_evidence' => array(
			'contract_version'       => 'image_context_evidence.v1',
			'write_posture'          => 'suggestion_only',
			'direct_wordpress_write' => false,
			'items'                  => array(
				array(
					'attachment_id'  => 711,
					'visual_summary' => 'WordPress operator reviewing an AI workflow dashboard',
					'objects'        => array( 'dashboard', 'workflow cards' ),
					'confidence'     => 'high',
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_alt_caption_review_set['success'] ?? null, 'build-media-alt-caption-review-set returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_alt_caption_review_set.v1', $media_alt_caption_review_set['data']['contract_version'] ?? '', 'media ALT/caption review set declares the reusable contract' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-media-alt-caption-review-set', $media_alt_caption_review_set['data']['source_ability_id'] ?? '', 'media ALT/caption review set records Toolkit source ability' );
npcink_abilities_toolkit_assert_same( 'current_article_media_metadata_only_no_pixel_vision', $media_alt_caption_review_set['data']['source_policy'] ?? '', 'media ALT/caption review set keeps no-pixel-vision metadata policy' );
npcink_abilities_toolkit_assert_same( false, $media_alt_caption_review_set['data']['direct_wordpress_write'] ?? null, 'media ALT/caption review set does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $media_alt_caption_review_set['data']['proposal_created'] ?? null, 'media ALT/caption review set does not create proposals' );
npcink_abilities_toolkit_assert_same( false, $media_alt_caption_review_set['data']['safety']['media_derivative_run_created'] ?? null, 'media ALT/caption review set does not create derivative runs' );
npcink_abilities_toolkit_assert_same( true, $media_alt_caption_review_set['data']['selected_items'][0]['needs_human_visual_check'] ?? null, 'media ALT/caption review set requires human visual review' );
npcink_abilities_toolkit_assert_same( 'visual_fact', $media_alt_caption_review_set['data']['selected_items'][0]['candidate_fact_types'][0] ?? '', 'media ALT/caption review set can use supplied reviewed image context as visual fact evidence' );
npcink_abilities_toolkit_assert_same( 'ready', $media_alt_caption_review_set['data']['selected_items'][0]['candidate_quality']['tier'] ?? '', 'media ALT/caption review set marks visual-evidence rows as ready only after human visual review' );
npcink_abilities_toolkit_assert_same( 90, $media_alt_caption_review_set['data']['selected_items'][0]['candidate_quality_score'] ?? null, 'media ALT/caption review set assigns a deterministic quality score for visual-evidence candidates' );
npcink_abilities_toolkit_assert_same( 'eligible_for_local_preview_after_visual_check', $media_alt_caption_review_set['data']['selected_items'][0]['automation_recommendation'] ?? '', 'media ALT/caption review set exposes machine-readable local preview guidance without write authority' );
npcink_abilities_toolkit_assert_same( 1, $media_alt_caption_review_set['data']['eligibility_summary']['local_preview_candidate_count'] ?? null, 'media ALT/caption review set summarizes local preview rows for UI triage' );
npcink_abilities_toolkit_assert_true( false === array_key_exists( 'ready_for_handoff_count', $media_alt_caption_review_set['data']['eligibility_summary'] ?? array() ), 'media ALT/caption review set does not emit deprecated ready-for-handoff counts in P0' );
npcink_abilities_toolkit_assert_same( 1, $media_alt_caption_review_set['data']['eligibility_summary']['visual_evidence_request_count'] ?? null, 'media ALT/caption review set summarizes rows that need visual evidence' );
npcink_abilities_toolkit_assert_same( 'insufficient', $media_alt_caption_review_set['data']['blocked_items'][0]['candidate_quality']['tier'] ?? '', 'media ALT/caption review set marks metadata-only failures as insufficient quality' );
npcink_abilities_toolkit_assert_true( false === strpos( implode( ' ', $media_alt_caption_review_set['data']['selected_items'][0]['alt_candidates'] ?? array() ), 'Generated with model' ), 'media ALT/caption review set filters runtime provenance from candidates' );
npcink_abilities_toolkit_assert_same( 'image_context_evidence_request.v1', $media_alt_caption_review_set['data']['image_context_evidence_request']['contract_version'] ?? '', 'media ALT/caption review set can request bounded external visual evidence for weak metadata rows' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-media-alt-apply-plan', $media_alt_caption_review_set['data']['handoff']['accepted_selection_target'] ?? '', 'media ALT/caption review set points accepted rows to the ALT-only apply planner' );

$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][713] = (object) array(
	'ID'             => 713,
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_title'     => 'Reviewed workflow dashboard',
	'post_content'   => '',
	'post_excerpt'   => '',
	'post_mime_type' => 'image/jpeg',
	'guid'           => 'https://example.test/wp-content/uploads/workflow-dashboard.jpg',
);
$GLOBALS['npcink_abilities_toolkit_unit_post_meta'][713]['_wp_attachment_image_alt'] = '';
$media_alt_apply_plan_input = array(
	'attachment_id'                     => 713,
	'alt'                               => 'Operator reviewing the workflow dashboard',
	'expected_current_alt'              => '',
	'operator_visual_review_confirmed' => true,
	'review_set_contract'               => 'media_alt_caption_review_set.v1',
	'source_item_id'                    => 'media-alt-caption:713',
	'evidence_refs'                     => array( 'image-context:713', 'article-context:82' ),
);
$media_alt_apply_plan = $core_read_package->build_media_alt_apply_plan( $media_alt_apply_plan_input );
npcink_abilities_toolkit_assert_same( true, $media_alt_apply_plan['success'] ?? null, 'build-media-alt-apply-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'media_alt_apply_plan.v1', $media_alt_apply_plan['data']['contract_version'] ?? '', 'media ALT apply plan declares the shared contract' );
npcink_abilities_toolkit_assert_same( 'core_proposal_required', $media_alt_apply_plan['data']['authorization']['classification'] ?? '', 'media ALT apply plan requires Core governance' );
npcink_abilities_toolkit_assert_same( 1, count( $media_alt_apply_plan['data']['write_actions'] ?? array() ), 'media ALT apply plan emits exactly one write action' );
$media_alt_apply_action = $media_alt_apply_plan['data']['write_actions'][0] ?? array();
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-media-details', $media_alt_apply_action['target_ability_id'] ?? '', 'media ALT apply plan reuses update-media-details' );
npcink_abilities_toolkit_assert_same( '', $media_alt_apply_action['input']['expected_current_alt'] ?? null, 'media ALT apply plan preserves the reviewed empty old value' );
npcink_abilities_toolkit_assert_same( true, $media_alt_apply_action['input']['operator_visual_review_confirmed'] ?? null, 'media ALT apply plan preserves visual confirmation' );
npcink_abilities_toolkit_assert_true( 0 === strpos( (string) ( $media_alt_apply_action['input']['idempotency_key'] ?? '' ), 'media-alt-missing-713-' ), 'media ALT apply plan emits a stable bounded idempotency key' );
npcink_abilities_toolkit_assert_same( 'media_alt_apply_plan_item', $media_alt_apply_action['preview']['artifact_type'] ?? '', 'media ALT apply action carries Core-readable review evidence' );

$media_alt_dry_run = $core_write_package->update_media_details( $media_alt_apply_action['input'] ?? array() );
npcink_abilities_toolkit_assert_same( true, $media_alt_dry_run['dry_run'] ?? null, 'guarded update-media-details returns a dry-run preview' );
npcink_abilities_toolkit_assert_same( 'media_alt_only_write.v1', $media_alt_dry_run['preview']['contract_version'] ?? '', 'guarded media ALT dry run declares its write contract' );
npcink_abilities_toolkit_assert_same( '', get_post_meta( 713, '_wp_attachment_image_alt', true ), 'guarded media ALT dry run does not mutate attachment metadata' );

$media_alt_unconfirmed_input = $media_alt_apply_plan_input;
$media_alt_unconfirmed_input['operator_visual_review_confirmed'] = false;
$media_alt_unconfirmed = $core_read_package->build_media_alt_apply_plan( $media_alt_unconfirmed_input );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_alt_unconfirmed ), 'media ALT apply plan rejects missing visual confirmation' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_media_alt_visual_confirmation_required', $media_alt_unconfirmed->get_error_code(), 'media ALT visual confirmation failure is machine-readable' );

update_post_meta( 713, '_wp_attachment_image_alt', 'Changed after review' );
$media_alt_stale = $core_read_package->build_media_alt_apply_plan( $media_alt_apply_plan_input );
npcink_abilities_toolkit_assert_true( is_wp_error( $media_alt_stale ), 'media ALT apply plan rejects a stale old value' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_media_alt_stale', $media_alt_stale->get_error_code(), 'media ALT stale-value failure is machine-readable' );
update_post_meta( 713, '_wp_attachment_image_alt', '' );

$image_candidate_adoption_plan = $core_read_package->build_image_candidate_adoption_plan(
	array(
		'post_id'            => 82,
		'set_featured_image' => true,
		'image_candidate'    => array(
			'contract_version'      => 'image_candidate.v1',
			'download_url'          => 'https://cdn.example.test/images/reviewed-featured.png',
			'thumbnail_url'         => 'https://cdn.example.test/images/reviewed-featured-thumb.png',
			'source_url'            => 'https://source.example.test/reviewed-featured',
			'source_type'           => 'stock',
			'provider'              => 'unsplash',
			'provider_origin'       => 'toolbox',
			'title'                 => 'Reviewed featured image',
			'description'           => 'Reviewed source image for the article.',
			'alt_description'       => 'Dashboard operator reviewing Core proposal.',
			'attribution'           => 'Photo by Example',
			'photographer'          => 'Example Photographer',
			'download_location'     => 'https://api.unsplash.example.test/download-location',
			'suggested_filename'    => 'reviewed-featured-image.png',
			'license_review_status' => 'reviewed',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $image_candidate_adoption_plan['success'] ?? null, 'build-image-candidate-adoption-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'image_candidate_adoption_plan', $image_candidate_adoption_plan['data']['artifact_type'] ?? '', 'image candidate adoption plan declares artifact type' );
npcink_abilities_toolkit_assert_same( false, $image_candidate_adoption_plan['data']['direct_wordpress_write'] ?? null, 'image candidate adoption plan does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $image_candidate_adoption_plan['data']['commit_execution'] ?? null, 'image candidate adoption plan keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( true, $image_candidate_adoption_plan['meta']['readonly'] ?? null, 'image candidate adoption plan remains read-only' );
$image_candidate_actions = (array) ( $image_candidate_adoption_plan['data']['write_actions'] ?? array() );
npcink_abilities_toolkit_assert_same( 3, count( $image_candidate_actions ), 'image candidate adoption plan emits upload, metadata, and featured-image actions when requested' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/upload-media-from-url', $image_candidate_actions[0]['target_ability_id'] ?? '', 'image candidate adoption plan starts with media upload' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-media-details', $image_candidate_actions[1]['target_ability_id'] ?? '', 'image candidate adoption plan updates media details after upload' );
npcink_abilities_toolkit_assert_same( '$outputs.upload_image_candidate.attachment_id', $image_candidate_actions[1]['input']['attachment_id'] ?? '', 'image candidate metadata action uses the upload output reference' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/set-post-featured-image', $image_candidate_actions[2]['target_ability_id'] ?? '', 'image candidate adoption plan can include featured image assignment' );
npcink_abilities_toolkit_assert_same( 'image_candidate.v1', $image_candidate_adoption_plan['data']['selected_image_candidate']['contract_version'] ?? '', 'image candidate adoption plan preserves image_candidate.v1 evidence' );
npcink_abilities_toolkit_assert_same( 'https://api.unsplash.example.test/download-location', $image_candidate_adoption_plan['data']['selected_image_candidate']['download_location'] ?? '', 'image candidate adoption plan preserves source download tracking metadata' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-image-candidate-adoption-plan', $image_candidate_adoption_plan['data']['handoff']['plan_ability_id'] ?? '', 'image candidate adoption plan identifies the Toolkit Core handoff ability' );

$GLOBALS['npcink_abilities_toolkit_unit_options']['theme_builder_media_setting'] = array(
	'hero' => array(
		'image' => 'https://example.test/wp-content/uploads/2026/06/workflow-diagram-image.jpg',
	),
);
$GLOBALS['npcink_abilities_toolkit_unit_theme_mods']['header_image'] = '/wp-content/uploads/2026/06/workflow-diagram-image.jpg';
$media_settings_reference_plan = $core_read_package->build_media_settings_reference_repair_plan(
	array(
		'attachment_id'    => 79,
		'replacement_id'   => 'media_replace_unit',
		'option_names'     => array( 'theme_builder_media_setting' ),
		'theme_mod_names'  => array( 'header_image' ),
		'min_width'        => 64,
		'min_height'       => 64,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_settings_reference_plan['success'] ?? null, 'build-media-settings-reference-repair-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( false, $media_settings_reference_plan['data']['commit_execution'] ?? null, 'media settings reference repair plan does not execute commits' );
npcink_abilities_toolkit_assert_same( 2, $media_settings_reference_plan['data']['action_count'] ?? 0, 'media settings reference repair plan builds option and theme mod patch actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/patch-setting-value', $media_settings_reference_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media settings reference repair plan reuses patch-setting-value' );
npcink_abilities_toolkit_assert_same( 'theme_builder_media_setting', $media_settings_reference_plan['data']['write_actions'][0]['input']['target_name'] ?? '', 'media settings reference repair targets the option name' );
npcink_abilities_toolkit_assert_same( 'header_image', $media_settings_reference_plan['data']['write_actions'][1]['input']['target_name'] ?? '', 'media settings reference repair targets the theme mod name' );
$media_settings_excluded_plan = $core_read_package->build_media_settings_reference_repair_plan(
	array(
		'attachment_id'     => 79,
		'replacement_id'    => 'media_replace_unit',
		'option_names'      => array( 'theme_builder_media_setting' ),
		'include_theme_mods' => false,
		'excluded_formats'  => array( 'jpg' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $media_settings_excluded_plan['success'] ?? null, 'media settings reference repair accepts excluded format policy' );
npcink_abilities_toolkit_assert_same( 0, $media_settings_excluded_plan['data']['action_count'] ?? 1, 'media settings reference repair does not build actions for excluded source formats' );
npcink_abilities_toolkit_assert_same( 'source_format_excluded', $media_settings_excluded_plan['data']['manual_review'][0]['reason'] ?? '', 'media settings reference repair sends excluded formats to manual review' );
	$patch_setting_preview = $core_write_package->patch_setting_value(
		array(
			'target_type' => 'option',
			'target_name' => 'theme_builder_media_setting',
			'operations'  => $media_settings_reference_plan['data']['write_actions'][0]['input']['operations'] ?? array(),
			'dry_run'     => true,
		)
	);
	npcink_abilities_toolkit_assert_true( is_wp_error( $patch_setting_preview ), 'patch-setting-value rejects settings that the host has not allowlisted' );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_setting_target_not_allowed', $patch_setting_preview->get_error_code() ?? '', 'patch-setting-value uses a stable not-allowlisted error code' );
	add_filter(
		'npcink_abilities_toolkit_patchable_setting_targets',
		static function ( $targets ) {
			$targets = is_array( $targets ) ? $targets : array();
			$targets['option'] = array( 'theme_builder_media_setting', 'oversized_setting_value' );
			$targets['theme_mod'] = array( 'header_image' );
			return $targets;
		}
	);
	$blocked_sensitive_setting = $core_write_package->patch_setting_value(
		array(
			'target_type' => 'option',
			'target_name' => 'vendor_api_token',
			'operations'  => array( array( 'op' => 'replace', 'find' => 'old', 'replace' => 'new' ) ),
			'dry_run'     => true,
		)
	);
	npcink_abilities_toolkit_assert_true( is_wp_error( $blocked_sensitive_setting ), 'patch-setting-value blocks sensitive setting names even when a host filter allows them' );
	npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_setting_target_blocked', $blocked_sensitive_setting->get_error_code() ?? '', 'patch-setting-value uses a stable sensitive-setting error code' );
	$patch_setting_preview = $core_write_package->patch_setting_value(
		array(
		'target_type' => 'option',
		'target_name' => 'theme_builder_media_setting',
		'operations'  => $media_settings_reference_plan['data']['write_actions'][0]['input']['operations'] ?? array(),
		'dry_run'     => true,
	)
);
	npcink_abilities_toolkit_assert_same( true, $patch_setting_preview['dry_run'] ?? null, 'patch-setting-value returns a governed dry-run preview' );
	npcink_abilities_toolkit_assert_same( 1, $patch_setting_preview['patch_preview'][0]['applied'] ?? null, 'patch-setting-value reports applied operation count' );
	npcink_abilities_toolkit_assert_true( ! isset( $patch_setting_preview['diff_preview']['before_fragment'] ) && ! isset( $patch_setting_preview['diff_preview']['after_fragment'] ), 'patch-setting-value does not expose stored setting fragments in its diff' );
	npcink_abilities_toolkit_assert_true( ! isset( $patch_setting_preview['impact_ranges'][0]['before_preview'] ) && ! isset( $patch_setting_preview['impact_ranges'][0]['after_preview'] ), 'patch-setting-value does not expose stored setting fragments in impact ranges' );
$GLOBALS['npcink_abilities_toolkit_unit_options']['oversized_setting_value'] = str_repeat( 'x', 262145 );
$oversized_patch_setting = $core_write_package->patch_setting_value(
	array(
		'target_type' => 'option',
		'target_name' => 'oversized_setting_value',
		'operations'  => array(
			array(
				'op'      => 'replace',
				'find'    => 'x',
				'replace' => 'y',
			),
		),
		'dry_run'     => true,
	)
);
unset( $GLOBALS['npcink_abilities_toolkit_unit_options']['oversized_setting_value'] );
npcink_abilities_toolkit_assert_true( is_wp_error( $oversized_patch_setting ), 'patch-setting-value rejects oversized setting values before recursive patching' );
npcink_abilities_toolkit_assert_same( 'npcink_abilities_toolkit_input_too_large', $oversized_patch_setting->code ?? '', 'patch-setting-value oversized setting value fails with stable code' );
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'approval_commit_authorized' => true ) );
$patch_setting_commit = $core_write_package->patch_setting_value(
	array(
		'target_type' => 'theme_mod',
		'target_name' => 'header_image',
		'operations'  => $media_settings_reference_plan['data']['write_actions'][1]['input']['operations'] ?? array(),
		'commit'      => true,
	)
);
unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
	npcink_abilities_toolkit_assert_same( false, $patch_setting_commit['dry_run'] ?? null, 'patch-setting-value commit exits dry-run after approval' );
	npcink_abilities_toolkit_assert_true( false !== strpos( (string) get_theme_mod( 'header_image', '' ), 'workflow-diagram-image-optimized.webp' ), 'patch-setting-value commits exact theme mod URL replacement' );
	remove_all_filters( 'npcink_abilities_toolkit_patchable_setting_targets' );
$media_health = $core_read_package->get_media_inventory_health(
	array(
		'mime_type' => 'image',
		'per_page'  => 5,
		'stable_order' => 'id_asc',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_health['success'] ?? null, 'get-media-inventory-health returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $media_health['data']['summary']['scanned_count'] ?? 0 ) >= 1, 'get-media-inventory-health scans local media rows' );
npcink_abilities_toolkit_assert_true( isset( $media_health['data']['issue_counts']['missing_alt'] ), 'get-media-inventory-health counts missing alt text' );
npcink_abilities_toolkit_assert_same( 'id_asc', $media_health['data']['summary']['stable_order'] ?? '', 'get-media-inventory-health reports the stable order used for resumable scans' );
$media_health_row = npcink_abilities_toolkit_find_row_by_key( (array) ( $media_health['data']['items'] ?? array() ), 'attachment_id', 79 );
npcink_abilities_toolkit_assert_same( true, $media_health_row['format_inspection']['format_plan']['needs_attention'] ?? null, 'get-media-inventory-health includes format inspection attention state' );
npcink_abilities_toolkit_assert_true( in_array( 'legacy_image_format', (array) ( $media_health_row['format_inspection']['warnings'] ?? array() ), true ), 'get-media-inventory-health includes legacy format warning' );
$media_projection_rows = $core_read_package->get_media_inventory_health(
	array(
		'mime_type'      => 'image',
		'attachment_ids' => array( 79 ),
		'per_page'       => 20,
	)
);
npcink_abilities_toolkit_assert_same( 1, count( (array) ( $media_projection_rows['data']['items'] ?? array() ) ), 'get-media-inventory-health can revalidate a bounded attachment-id selection' );
npcink_abilities_toolkit_assert_same( 79, $media_projection_rows['data']['items'][0]['attachment_id'] ?? 0, 'media inventory attachment-id selection preserves the requested local identity' );
npcink_abilities_toolkit_assert_true( 71 === strlen( (string) ( $media_projection_rows['data']['items'][0]['media_fingerprint'] ?? '' ) ) && 0 === strpos( (string) ( $media_projection_rows['data']['items'][0]['media_fingerprint'] ?? '' ), 'sha256:' ), 'media inventory rows expose a canonical SHA-256 revision fingerprint for rebuildable Cloud projections' );
$media_cleanup = $core_read_package->get_media_cleanup_opportunities(
	array(
		'mime_type' => 'image',
		'per_page'  => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $media_cleanup['success'] ?? null, 'get-media-cleanup-opportunities returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $media_cleanup['data']['summary']['opportunity_count'] ?? 0 ) >= 1, 'get-media-cleanup-opportunities finds cleanup opportunities' );
npcink_abilities_toolkit_assert_true( isset( $media_cleanup['data']['issue_counts']['possibly_unattached'] ), 'get-media-cleanup-opportunities counts unattached media' );
$media_fix_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'  => array( 79 ),
		'issue_types'     => array( 'missing_alt', 'missing_caption', 'missing_description', 'format_attention', 'possibly_unattached' ),
		'article_title'   => 'Workflow automation',
		'article_excerpt' => 'Workflow automation improves repeatable editorial operations.',
		'focus_keyword'   => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $media_fix_plan['success'] ?? null, 'build-media-inventory-fix-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $media_fix_plan['data']['requires_approval'] ?? null, 'media inventory fix plan requires approval' );
npcink_abilities_toolkit_assert_same( false, $media_fix_plan['data']['commit_execution'] ?? null, 'media inventory fix plan does not execute commits' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-media-details', $media_fix_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media inventory fix plan reuses update-media-details' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_fix_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/delete-media-permanently' ), 'media inventory fix plan does not map parentless media to delete actions by default' );
npcink_abilities_toolkit_assert_same( false, $media_fix_plan['data']['write_actions'][0]['commit_execution'] ?? null, 'media metadata plan action does not execute commits' );
npcink_abilities_toolkit_assert_true( isset( $media_fix_plan['data']['preview'][0]['before']['alt'] ), 'media inventory fix plan returns before preview' );
npcink_abilities_toolkit_assert_true( isset( $media_fix_plan['data']['preview'][0]['after_suggestion']['alt'] ), 'media inventory fix plan returns after suggestion preview' );
npcink_abilities_toolkit_assert_same( true, $media_fix_plan['data']['manual_review'][0]['format_plan']['should_convert'] ?? null, 'media inventory fix plan carries format inspection recommendations into manual review' );
npcink_abilities_toolkit_assert_same( 'legacy_image_format', $media_fix_plan['data']['manual_review'][0]['format_governance']['detected_reason'] ?? '', 'media inventory fix plan records a format attention detected reason' );
npcink_abilities_toolkit_assert_same( 'generate_optimized_derivative', $media_fix_plan['data']['manual_review'][0]['format_governance']['suggested_operation'] ?? '', 'media inventory fix plan suggests a lightweight future operation for format attention' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-media-derivative-cloud-request', $media_fix_plan['data']['manual_review'][0]['format_governance']['target_future_ability'] ?? '', 'media inventory fix plan points format attention at the read-only Cloud request planner without mapping it' );
npcink_abilities_toolkit_assert_same( false, $media_fix_plan['data']['manual_review'][0]['format_governance']['write_action_generated'] ?? null, 'media inventory fix plan keeps format attention read-only' );
npcink_abilities_toolkit_assert_same( 'high', $media_fix_plan['data']['manual_review'][0]['format_governance']['estimated_risk'] ?? '', 'media inventory fix plan marks format asset work as high risk' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_fix_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/build-media-derivative-cloud-request' ), 'media inventory fix plan does not map format attention to the Cloud request planner as a write action' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_fix_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/optimize-media-asset' ), 'media inventory fix plan does not map format attention to optimize-media-asset' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_fix_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/convert-media-format' ), 'media inventory fix plan does not map format attention to convert-media-format' );
npcink_abilities_toolkit_assert_same( 0, npcink_abilities_toolkit_count_plan_actions_for_ability( (array) ( $media_fix_plan['data']['write_actions'] ?? array() ), 'npcink-abilities-toolkit/replace-media-file' ), 'media inventory fix plan does not map format attention to replace-media-file' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/delete-media-permanently', $media_fix_plan['data']['skipped_destructive_candidates'][0]['target_ability_id'] ?? '', 'media inventory fix plan skips destructive candidates by default' );
npcink_abilities_toolkit_assert_same( 'delete_candidates_not_enabled', $media_fix_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan explains default destructive skip reason' );
$media_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'              => array( 79 ),
		'issue_types'                 => array( 'possibly_unattached' ),
		'include_delete_candidates'   => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $media_delete_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan does not map delete candidates without unattached nonproduction-media opt-in' );
npcink_abilities_toolkit_assert_same( 'unattached_nonproduction_media_not_enabled', $media_delete_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan requires explicit parentless nonproduction-media opt-in for destructive media deletes' );
$media_parentless_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'              => array( 79 ),
		'issue_types'                 => array( 'possibly_unattached' ),
		'include_delete_candidates'   => true,
		'include_trash_parent_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $media_parentless_delete_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan does not map parentless media to delete actions' );
npcink_abilities_toolkit_assert_same( 'unattached_nonproduction_media_not_enabled', $media_parentless_delete_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan requires explicit unattached nonproduction-media opt-in for parentless destructive media deletes' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][96] = (object) array(
	'ID'             => 96,
	'post_title'     => 'Playwright Native Media ALT 1776483949900',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'playwright-native-media-alt-1776483949900',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/png',
);
$media_parentless_test_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'                 => array( 96 ),
		'issue_types'                    => array( 'possibly_unattached' ),
		'include_delete_candidates'      => true,
		'include_unattached_nonproduction_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/delete-media-permanently', $media_parentless_test_delete_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media inventory fix plan maps eligible parentless nonproduction media to delete action only with explicit opt-in' );
npcink_abilities_toolkit_assert_same( 'high', $media_parentless_test_delete_plan['data']['write_actions'][0]['risk'] ?? '', 'eligible parentless nonproduction media delete candidate is marked high risk' );
npcink_abilities_toolkit_assert_same( false, $media_parentless_test_delete_plan['data']['write_actions'][0]['commit_execution'] ?? null, 'eligible parentless nonproduction media delete candidate remains proposal-only' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][97] = (object) array(
	'ID'             => 97,
	'post_title'     => 'Content Assistant Test Image 1776483949901',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'content-assistant-test-image-1776483949901',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/png',
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][98] = (object) array(
	'ID'           => 98,
	'post_title'   => 'Editorial Draft With Parentless Test Media',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_content' => '<!-- wp:image {"id":97} --><figure class="wp-block-image"><img class="wp-image-97" /></figure><!-- /wp:image -->',
	'post_name'    => 'editorial-draft-with-parentless-nonproduction-media',
	'post_author'  => 7,
	'post_parent'  => 0,
);
$referenced_parentless_test_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'                 => array( 97 ),
		'issue_types'                    => array( 'possibly_unattached' ),
		'include_delete_candidates'      => true,
		'include_unattached_nonproduction_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $referenced_parentless_test_delete_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan blocks parentless nonproduction media referenced by live content' );
npcink_abilities_toolkit_assert_same( 'referenced_by_live_content', $referenced_parentless_test_delete_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan reports parentless live reference policy failure' );
npcink_abilities_toolkit_assert_true( (int) ( $referenced_parentless_test_delete_plan['data']['skipped_destructive_candidates'][0]['policy_checks']['live_reference_count'] ?? 0 ) >= 1, 'media inventory fix plan records parentless live reference count for blocked media delete' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][99] = (object) array(
	'ID'             => 99,
	'post_title'     => 'Production Launch Diagram',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'production-launch-diagram',
	'post_author'    => 7,
	'post_parent'    => 0,
	'post_mime_type' => 'image/png',
);
$parentless_production_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'                 => array( 99 ),
		'issue_types'                    => array( 'possibly_unattached' ),
		'include_delete_candidates'      => true,
		'include_unattached_nonproduction_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $parentless_production_delete_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan blocks parentless media whose title is not nonproduction content' );
npcink_abilities_toolkit_assert_same( 'media_not_nonproduction_content', $parentless_production_delete_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan reports parentless media nonproduction-pattern policy failure' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][91] = (object) array(
	'ID'           => 91,
	'post_title'   => 'Runtime Smoke Media Parent',
	'post_status'  => 'trash',
	'post_type'    => 'post',
	'post_content' => 'Runtime smoke parent post for media cleanup policy.',
	'post_name'    => 'runtime-smoke-media-parent',
	'post_author'  => 7,
	'post_parent'  => 0,
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][92] = (object) array(
	'ID'             => 92,
	'post_title'     => 'Runtime Smoke Media Image',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'runtime-smoke-media-image',
	'post_author'    => 7,
	'post_parent'    => 91,
	'post_mime_type' => 'image/jpeg',
);
$eligible_media_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'              => array( 92 ),
		'issue_types'                 => array( 'possibly_unattached' ),
		'include_delete_candidates'   => true,
		'include_trash_parent_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/delete-media-permanently', $eligible_media_delete_plan['data']['write_actions'][0]['target_ability_id'] ?? '', 'media inventory fix plan maps eligible trash-parent nonproduction media to delete action' );
npcink_abilities_toolkit_assert_same( 'high', $eligible_media_delete_plan['data']['write_actions'][0]['risk'] ?? '', 'eligible media delete candidate is marked high risk' );
npcink_abilities_toolkit_assert_same( 'trash', $eligible_media_delete_plan['data']['preview'][0]['parent_post_status'] ?? '', 'eligible media delete policy records trashed parent status in preview' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][93] = (object) array(
	'ID'             => 93,
	'post_title'     => 'Production Diagram Image',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'production-diagram-image',
	'post_author'    => 7,
	'post_parent'    => 91,
	'post_mime_type' => 'image/jpeg',
);
$blocked_media_title_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'              => array( 93 ),
		'issue_types'                 => array( 'possibly_unattached' ),
		'include_delete_candidates'   => true,
		'include_trash_parent_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $blocked_media_title_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan blocks trash-parent media whose own title is not nonproduction content' );
npcink_abilities_toolkit_assert_same( 'media_not_nonproduction_content', $blocked_media_title_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan reports media nonproduction-pattern policy failure' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][94] = (object) array(
	'ID'             => 94,
	'post_title'     => 'Runtime Smoke Referenced Image',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_excerpt'   => '',
	'post_content'   => '',
	'post_name'      => 'runtime-smoke-referenced-image',
	'post_author'    => 7,
	'post_parent'    => 91,
	'post_mime_type' => 'image/jpeg',
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][95] = (object) array(
	'ID'           => 95,
	'post_title'   => 'Editorial Draft',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_content' => '<!-- wp:image {"id":94} --><figure class="wp-block-image"><img class="wp-image-94" /></figure><!-- /wp:image -->',
	'post_name'    => 'editorial-draft',
	'post_author'  => 7,
	'post_parent'  => 0,
);
$referenced_media_delete_plan = $core_read_package->build_media_inventory_fix_plan(
	array(
		'attachment_ids'              => array( 94 ),
		'issue_types'                 => array( 'possibly_unattached' ),
		'include_delete_candidates'   => true,
		'include_trash_parent_media'  => true,
	)
);
npcink_abilities_toolkit_assert_same( 0, count( (array) ( $referenced_media_delete_plan['data']['write_actions'] ?? array() ) ), 'media inventory fix plan blocks trash-parent media referenced by live content' );
npcink_abilities_toolkit_assert_same( 'referenced_by_live_content', $referenced_media_delete_plan['data']['skipped_destructive_candidates'][0]['blocked_reason'] ?? '', 'media inventory fix plan reports live reference policy failure' );
npcink_abilities_toolkit_assert_true( (int) ( $referenced_media_delete_plan['data']['skipped_destructive_candidates'][0]['policy_checks']['live_reference_count'] ?? 0 ) >= 1, 'media inventory fix plan records live reference count for blocked media delete' );
$seo_geo_readiness = $core_read_package->get_post_seo_geo_readiness(
	array(
		'post_id'       => 77,
		'focus_keyword' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $seo_geo_readiness['success'] ?? null, 'get-post-seo-geo-readiness returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $seo_geo_readiness['data']['post']['post_id'] ?? null, 'get-post-seo-geo-readiness keeps post id' );
npcink_abilities_toolkit_assert_true( isset( $seo_geo_readiness['data']['readiness_score'] ), 'get-post-seo-geo-readiness returns a readiness score' );
$topic_coverage = $core_read_package->get_site_topic_coverage_report(
	array(
		'post_type'  => 'post',
		'status'     => 'any',
		'per_page'   => 5,
		'topic_seed' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $topic_coverage['success'] ?? null, 'get-site-topic-coverage-report returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $topic_coverage['data']['summary']['scanned_count'] ?? 0 ) >= 1, 'get-site-topic-coverage-report scans local posts' );
npcink_abilities_toolkit_assert_true( ! empty( $topic_coverage['data']['topics'] ), 'get-site-topic-coverage-report returns topic rows' );
$GLOBALS['npcink_abilities_toolkit_unit_terms'] = array(
	'category' => array(
		(object) array(
			'term_id'     => 301,
			'name'        => 'Workflow',
			'slug'        => 'workflow',
			'description' => '',
			'count'       => 0,
			'parent'      => 0,
		),
		(object) array(
			'term_id'     => 302,
			'name'        => 'Operations',
			'slug'        => 'operations',
			'description' => 'Operational notes.',
			'count'       => 3,
			'parent'      => 0,
		),
	),
	'post_tag' => array(
		(object) array(
			'term_id'     => 401,
			'name'        => 'AI Workflow',
			'slug'        => 'ai-workflow',
			'description' => '',
			'count'       => 0,
			'parent'      => 0,
		),
		(object) array(
			'term_id'     => 402,
			'name'        => 'AI workflow',
			'slug'        => 'ai-workflow-2',
			'description' => '',
			'count'       => 2,
			'parent'      => 0,
		),
	),
);
$taxonomy_health = $core_read_package->get_taxonomy_inventory_health(
	array(
		'taxonomy' => 'category',
		'per_page' => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $taxonomy_health['success'] ?? null, 'get-taxonomy-inventory-health returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'category', $taxonomy_health['data']['taxonomy'] ?? '', 'get-taxonomy-inventory-health keeps taxonomy name' );
npcink_abilities_toolkit_assert_true( isset( $taxonomy_health['data']['issue_counts']['missing_description'] ), 'get-taxonomy-inventory-health counts missing descriptions' );
npcink_abilities_toolkit_assert_true( isset( $taxonomy_health['data']['issue_counts']['unused_term'] ), 'get-taxonomy-inventory-health counts unused terms' );
$taxonomy_consolidation = $core_read_package->get_taxonomy_consolidation_suggestions(
	array(
		'taxonomy' => 'post_tag',
		'per_page' => 10,
	)
);
npcink_abilities_toolkit_assert_same( true, $taxonomy_consolidation['success'] ?? null, 'get-taxonomy-consolidation-suggestions returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $taxonomy_consolidation['data']['summary']['suggestion_count'] ?? 0 ) >= 1, 'get-taxonomy-consolidation-suggestions returns suggestions' );
npcink_abilities_toolkit_assert_same( 'duplicate_or_near_duplicate', $taxonomy_consolidation['data']['suggestions'][1]['type'] ?? '', 'get-taxonomy-consolidation-suggestions detects duplicate term groups' );
$post_taxonomy_suggestions = $core_read_package->suggest_post_taxonomy_terms(
	array(
		'taxonomy'              => 'both',
		'title'                 => 'AI Workflow planning guide',
		'excerpt'               => 'A workflow operations guide for editorial teams.',
		'query'                 => 'AI workflow operations',
		'related_term_evidence' => array(
			array(
				'term_id'         => 401,
				'taxonomy'        => 'post_tag',
				'name'            => 'AI Workflow',
				'source_count'    => 1,
				'source_post_ids' => array( 77 ),
				'source_titles'   => array( 'Related AI workflow case study' ),
				'source_refs'     => array( 'site_knowledge:77' ),
				'max_similarity'  => 0.91,
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $post_taxonomy_suggestions['success'] ?? null, 'suggest-post-taxonomy-terms returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'article_taxonomy_suggestions.v1', $post_taxonomy_suggestions['data']['artifact_type'] ?? '', 'suggest-post-taxonomy-terms returns article taxonomy suggestions' );
npcink_abilities_toolkit_assert_same( 'suggestion_only', $post_taxonomy_suggestions['data']['write_posture'] ?? '', 'suggest-post-taxonomy-terms stays suggestion-only' );
npcink_abilities_toolkit_assert_same( 'core_proposal_required', $post_taxonomy_suggestions['data']['final_write_path'] ?? '', 'suggest-post-taxonomy-terms requires Core proposal for writes' );
npcink_abilities_toolkit_assert_same( 'AI Workflow', $post_taxonomy_suggestions['data']['tag_candidates'][0]['name'] ?? '', 'suggest-post-taxonomy-terms ranks matching existing tags' );
$taxonomy_recommendation_labels = array_map(
	static function ( array $candidate ): string {
		return (string) ( $candidate['label'] ?? '' );
	},
	is_array( $post_taxonomy_suggestions['data']['recommendation_candidates'] ?? null ) ? $post_taxonomy_suggestions['data']['recommendation_candidates'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'Existing tag', $taxonomy_recommendation_labels, true ), 'suggest-post-taxonomy-terms exposes recommendation candidate labels' );
npcink_abilities_toolkit_assert_true( in_array( 'related_site_knowledge_term', $post_taxonomy_suggestions['data']['tag_candidates'][0]['match_signals'] ?? array(), true ), 'suggest-post-taxonomy-terms keeps related evidence as ranking signal' );
npcink_abilities_toolkit_assert_same( true, $post_taxonomy_suggestions['data']['selection_policy']['new_terms_deferred'] ?? null, 'suggest-post-taxonomy-terms defers new term creation' );
$taxonomy_tag_review_set = $core_read_package->build_taxonomy_tag_review_set(
	array(
		'taxonomy'              => 'both',
		'title'                 => 'AI Workflow planning guide',
		'excerpt'               => 'A workflow operations guide for editorial teams.',
		'query'                 => 'AI workflow operations',
		'review_set_limit'      => 1,
		'related_term_evidence' => array(
			array(
				'term_id'         => 401,
				'taxonomy'        => 'post_tag',
				'name'            => 'AI Workflow',
				'source_count'    => 1,
				'source_post_ids' => array( 77 ),
				'source_titles'   => array( 'Related AI workflow case study' ),
				'source_refs'     => array( 'site_knowledge:77' ),
				'max_similarity'  => 0.91,
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $taxonomy_tag_review_set['success'] ?? null, 'build-taxonomy-tag-review-set returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'taxonomy_tag_review_set.v1', $taxonomy_tag_review_set['data']['contract_version'] ?? '', 'taxonomy/tag review set declares the reusable contract' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-taxonomy-tag-review-set', $taxonomy_tag_review_set['data']['source_ability_id'] ?? '', 'taxonomy/tag review set records Toolkit source ability' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/suggest-post-taxonomy-terms', $taxonomy_tag_review_set['data']['source_candidate_ability_id'] ?? '', 'taxonomy/tag review set records the source candidate ranker' );
npcink_abilities_toolkit_assert_same( false, $taxonomy_tag_review_set['data']['direct_wordpress_write'] ?? null, 'taxonomy/tag review set does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $taxonomy_tag_review_set['data']['proposal_created'] ?? null, 'taxonomy/tag review set does not create proposals' );
npcink_abilities_toolkit_assert_same( false, $taxonomy_tag_review_set['data']['safety']['term_creation_allowed'] ?? null, 'taxonomy/tag review set does not authorize term creation' );
npcink_abilities_toolkit_assert_same( false, $taxonomy_tag_review_set['data']['safety']['term_assignment_allowed'] ?? null, 'taxonomy/tag review set does not authorize term assignment' );
npcink_abilities_toolkit_assert_same( 'AI Workflow', $taxonomy_tag_review_set['data']['selected_items'][0]['name'] ?? '', 'taxonomy/tag review set selects the strongest existing term' );
npcink_abilities_toolkit_assert_same( true, $taxonomy_tag_review_set['data']['selected_items'][0]['needs_operator_review'] ?? null, 'taxonomy/tag review set requires operator review' );
$taxonomy_tag_blocked_reasons = array_values( array_map( 'strval', array_column( $taxonomy_tag_review_set['data']['blocked_items'] ?? array(), 'blocked_reason' ) ) );
npcink_abilities_toolkit_assert_true( in_array( 'review_set_limit_reached', $taxonomy_tag_blocked_reasons, true ), 'taxonomy/tag review set reports over-limit rows as blocked' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-content-metadata-apply-plan', $taxonomy_tag_review_set['data']['handoff']['accepted_selection_target'] ?? '', 'taxonomy/tag review set points accepted selections to the content metadata apply planner' );
$GLOBALS['npcink_abilities_toolkit_unit_post_terms'][77]['post_tag'] = array(
	(object) array(
		'term_id' => 401,
		'name'    => 'AI Workflow',
		'slug'    => 'ai-workflow',
		'count'   => 0,
	),
);
$post_taxonomy_proposal = $core_read_package->propose_post_taxonomy_terms(
	array(
		'post_id'            => 77,
		'taxonomy'           => 'post_tag',
		'mode'               => 'append',
		'candidate_terms'    => array( 'AI workflow', 'Unknown Topic' ),
		'candidate_term_ids' => array( 402 ),
	)
);
npcink_abilities_toolkit_assert_same( true, $post_taxonomy_proposal['success'] ?? null, 'propose-post-taxonomy-terms returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/set-post-terms', $post_taxonomy_proposal['data']['proposal']['target_ability_id'] ?? '', 'post taxonomy proposal targets set-post-terms' );
npcink_abilities_toolkit_assert_same( false, $post_taxonomy_proposal['data']['proposal']['commit_execution'] ?? null, 'post taxonomy proposal does not execute commits' );
npcink_abilities_toolkit_assert_same( array( 401, 402 ), $post_taxonomy_proposal['data']['proposed_term_ids'] ?? array(), 'post taxonomy proposal computes proposed terms from current and matched candidates' );
npcink_abilities_toolkit_assert_same( 'Unknown Topic', $post_taxonomy_proposal['data']['unmatched_terms'][0]['value'] ?? '', 'post taxonomy proposal reports unmatched term names' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][81] = (object) array(
	'ID'           => 81,
	'post_title'   => 'Landing Page',
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_excerpt' => '',
	'post_content' => '<p>Short landing page.</p>',
	'post_name'    => 'landing-page',
	'post_author'  => 7,
);
$page_structure = $core_read_package->get_page_structure_health(
	array(
		'page_id' => 81,
	)
);
npcink_abilities_toolkit_assert_same( true, $page_structure['success'] ?? null, 'get-page-structure-health returns a success envelope' );
npcink_abilities_toolkit_assert_same( 1, $page_structure['data']['summary']['pages_with_issues'] ?? null, 'get-page-structure-health counts pages with issues' );
npcink_abilities_toolkit_assert_true( in_array( 'missing_cta', $page_structure['data']['items'][0]['issues'] ?? array(), true ), 'get-page-structure-health detects missing CTA' );
$seo_geo_gap = $core_read_package->get_seo_geo_gap_report(
	array(
		'post_type'  => 'post',
		'status'     => 'any',
		'per_page'   => 5,
		'topic_seed' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $seo_geo_gap['success'] ?? null, 'get-seo-geo-gap-report returns a success envelope' );
npcink_abilities_toolkit_assert_true( (int) ( $seo_geo_gap['data']['summary']['gap_count'] ?? 0 ) >= 1, 'get-seo-geo-gap-report reports gaps from refresh and coverage scans' );
$seo_geo_gap_cached = $core_read_package->get_seo_geo_gap_report(
	array(
		'post_type'  => 'post',
		'status'     => 'any',
		'per_page'   => 5,
		'topic_seed' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $seo_geo_gap_cached['meta']['cache_hit'] ?? null, 'get-seo-geo-gap-report uses the bounded read cache on repeated calls' );
$site_style_baseline = $core_read_package->get_site_style_baseline(
	array(
		'mode'  => 'site_recent',
		'limit' => 3,
	)
);
npcink_abilities_toolkit_assert_same( true, $site_style_baseline['success'] ?? null, 'get-site-style-baseline returns a success envelope' );
npcink_abilities_toolkit_assert_true( isset( $site_style_baseline['data']['profile'] ), 'get-site-style-baseline returns a profile payload' );
$workflow_context = $core_read_package->build_article_workflow_context(
	array(
		'workflow'   => 'publish',
		'post_id'    => 77,
		'topic_seed' => 'workflow',
	)
);
npcink_abilities_toolkit_assert_same( true, $workflow_context['success'] ?? null, 'build-article-workflow-context returns a success envelope' );
npcink_abilities_toolkit_assert_true( in_array( 'post_context', $workflow_context['data']['sections'] ?? array(), true ), 'build-article-workflow-context includes post context when post_id is provided' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][82] = (object) array(
	'ID'            => 82,
	'post_title'    => 'Scheduled Workflow Post',
	'post_status'   => 'future',
	'post_type'     => 'post',
	'post_excerpt'  => '',
	'post_content'  => 'Scheduled workflow content.',
	'post_name'     => 'scheduled-workflow-post',
	'post_author'   => 7,
	'post_date'     => '2030-01-02 03:04:05',
	'post_modified' => '2030-01-01 03:04:05',
);
$publishing_calendar = $core_read_package->get_publishing_calendar_context(
	array(
		'post_type'   => 'post',
		'window_days' => 365,
		'per_page'    => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $publishing_calendar['success'] ?? null, 'get-publishing-calendar-context returns a success envelope' );
npcink_abilities_toolkit_assert_true( isset( $publishing_calendar['data']['status_counts']['future'] ), 'get-publishing-calendar-context returns status counts' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][771] = (object) array(
	'ID'                => 771,
	'post_title'        => 'Previous Optimization Context',
	'post_status'       => 'inherit',
	'post_type'         => 'revision',
	'post_excerpt'      => '',
	'post_content'      => '<!-- wp:paragraph --><p>Previous optimization context content with more detail.</p><!-- /wp:paragraph -->',
	'post_name'         => '77-revision-v1',
	'post_author'       => 7,
	'post_parent'       => 77,
	'post_modified'     => '2024-01-03 03:04:05',
	'post_modified_gmt' => '2024-01-03 03:04:05',
);
$revision_risk = $core_read_package->get_revision_change_risk_report(
	array(
		'post_id'       => 77,
		'max_revisions' => 5,
	)
);
npcink_abilities_toolkit_assert_same( true, $revision_risk['success'] ?? null, 'get-revision-change-risk-report returns a success envelope' );
npcink_abilities_toolkit_assert_same( 77, $revision_risk['data']['post']['post_id'] ?? null, 'get-revision-change-risk-report keeps post id' );
npcink_abilities_toolkit_assert_true( in_array( 'title_changed', $revision_risk['data']['risk_flags'] ?? array(), true ), 'get-revision-change-risk-report detects title changes' );
$single_suggest = $core_read_package->build_article_single_optimization_suggest(
	array(
		'post'              => array(
			'id'         => 77,
			'title'      => 'Optimization Context Post',
			'excerpt'    => '',
			'slug'       => 'optimization-context-post',
			'content'    => '本文介绍 WordPress optimization workflow 的使用方式。',
			'categories' => array( 'Workflows' ),
			'tags'       => array( 'SEO' ),
			'seo'        => array(
				'title'       => '',
				'description' => '',
			),
		),
		'generated_excerpt' => array(
			'proposal_text' => 'Optimization workflow excerpt suggestion.',
		),
		'generated_seo'     => array(
			'meta_title'       => 'Optimization SEO Title',
			'meta_description' => 'Optimization SEO Description',
		),
		'seo_analysis'      => array(
			'recommendations' => array(
				array(
					'type'     => 'keyword',
					'priority' => 'high',
					'title'    => '补齐焦点关键词',
					'detail'   => '在首屏补齐焦点关键词。',
				),
			),
		),
		'geo_analysis'      => array(
			'recommendations' => array(
				array(
					'type'     => 'faq_candidate',
					'priority' => 'medium',
					'title'    => '补 FAQ',
					'detail'   => '增加问答块。',
				),
			),
		),
		'focus_keyword'     => 'canonical seo',
		'keywords'          => array( 'canonical seo', 'workflow' ),
	)
);
npcink_abilities_toolkit_assert_same( true, $single_suggest['success'] ?? null, 'build-article-single-optimization-suggest returns a success envelope' );
npcink_abilities_toolkit_assert_same( array( 'excerpt', 'seo_title', 'seo_description', 'slug' ), $single_suggest['data']['summary']['safe_apply_fields'] ?? array(), 'build-article-single-optimization-suggest keeps low-risk safe apply fields' );
npcink_abilities_toolkit_assert_true( ! empty( $single_suggest['data']['content_improvements'] ), 'build-article-single-optimization-suggest emits content improvements' );
npcink_abilities_toolkit_assert_true( ! empty( $single_suggest['data']['seo_improvements'] ), 'build-article-single-optimization-suggest emits SEO improvements' );
npcink_abilities_toolkit_assert_true( ! empty( $single_suggest['data']['geo_improvements'] ), 'build-article-single-optimization-suggest emits GEO improvements' );
$apply_plan = $core_read_package->build_article_optimization_apply_plan(
	array(
		'post'              => array(
			'id'      => 77,
			'title'   => 'Optimization Context Post',
			'status'  => 'draft',
			'excerpt' => 'Current excerpt.',
		),
		'report'            => array(
			'summary' => array(
				'status'                => 'needs_attention',
				'high_priority_count'   => 1,
				'total_recommendations' => 3,
			),
			'geo'     => array(
				'summary' => array(
					'faq_candidate_count' => 2,
				),
			),
		),
		'optimization_plan' => array(
			'excerpt_mode' => 'apply',
			'seo_mode'     => 'suggest',
		),
		'generated_excerpt' => array(
			'proposal_text' => 'Generated excerpt.',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $apply_plan['success'] ?? null, 'build-article-optimization-apply-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $apply_plan['data']['actions']['excerpt']['apply_generate'] ?? null, 'build-article-optimization-apply-plan marks generated excerpt as safe apply when explicitly requested' );
npcink_abilities_toolkit_assert_same( array( 'update_excerpt' ), $apply_plan['data']['summary']['safe_apply_supported'] ?? array(), 'build-article-optimization-apply-plan exposes safe apply action summary' );
npcink_abilities_toolkit_assert_same( 'article_optimization_apply_plan', $apply_plan['data']['artifact_type'] ?? '', 'build-article-optimization-apply-plan declares a Core-ready artifact type' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/recipes/article-optimization', $apply_plan['data']['source_recipe_ref'] ?? '', 'build-article-optimization-apply-plan carries the article optimization recipe ref' );
npcink_abilities_toolkit_assert_same( true, $apply_plan['data']['requires_approval'] ?? null, 'build-article-optimization-apply-plan requires host approval' );
npcink_abilities_toolkit_assert_same( true, $apply_plan['data']['dry_run'] ?? null, 'build-article-optimization-apply-plan is dry-run only' );
npcink_abilities_toolkit_assert_same( false, $apply_plan['data']['commit_execution'] ?? null, 'build-article-optimization-apply-plan does not execute commits' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-article-optimization-apply-plan', $apply_plan['data']['handoff']['plan_ability_id'] ?? '', 'build-article-optimization-apply-plan identifies itself for Core from-plan intake' );
$apply_plan_write_actions = is_array( $apply_plan['data']['write_actions'] ?? null ) ? $apply_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 1, count( $apply_plan_write_actions ), 'build-article-optimization-apply-plan emits one safe excerpt write action when requested' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post', $apply_plan_write_actions[0]['target_ability_id'] ?? '', 'build-article-optimization-apply-plan targets update-post for excerpt changes' );
npcink_abilities_toolkit_assert_same( 77, $apply_plan_write_actions[0]['input']['post_id'] ?? null, 'build-article-optimization-apply-plan includes the target post id' );
npcink_abilities_toolkit_assert_same( 'Generated excerpt.', $apply_plan_write_actions[0]['input']['excerpt'] ?? '', 'build-article-optimization-apply-plan includes the reviewed excerpt' );
npcink_abilities_toolkit_assert_same( true, $apply_plan_write_actions[0]['input']['dry_run'] ?? null, 'build-article-optimization-apply-plan write action is dry-run' );
npcink_abilities_toolkit_assert_same( false, $apply_plan_write_actions[0]['input']['commit'] ?? null, 'build-article-optimization-apply-plan write action does not request commit' );
$content_metadata_apply_plan = $core_read_package->build_content_metadata_apply_plan(
	array(
		'post_id'                => 77,
		'excerpt'                => 'Reviewed excerpt for Core proposal.',
		'category_ids'           => array( 3, 5, 5 ),
		'tag_ids'                => array( 8, 13 ),
		'category_mode'          => 'replace',
		'tag_mode'               => 'append',
		'evidence_refs'          => array( 'content-metadata-delta:excerpt', 'content-metadata-delta:taxonomy' ),
		'content_metadata_delta' => array(
			'source' => 'unit-test',
		),
		'new_term_candidates'    => array(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Deferred vocabulary gap',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $content_metadata_apply_plan['success'] ?? null, 'build-content-metadata-apply-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'content_metadata_apply_plan', $content_metadata_apply_plan['data']['artifact_type'] ?? '', 'build-content-metadata-apply-plan declares a Core-ready artifact type' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit', $content_metadata_apply_plan['data']['source_recipe_provider'] ?? '', 'build-content-metadata-apply-plan is owned by Toolkit' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-content-metadata-apply-plan', $content_metadata_apply_plan['data']['handoff']['plan_ability_id'] ?? '', 'build-content-metadata-apply-plan identifies the Toolkit plan ability for Core from-plan intake' );
npcink_abilities_toolkit_assert_same( true, $content_metadata_apply_plan['data']['requires_approval'] ?? null, 'build-content-metadata-apply-plan requires host approval' );
npcink_abilities_toolkit_assert_same( true, $content_metadata_apply_plan['data']['dry_run'] ?? null, 'build-content-metadata-apply-plan is dry-run only' );
npcink_abilities_toolkit_assert_same( false, $content_metadata_apply_plan['data']['commit_execution'] ?? null, 'build-content-metadata-apply-plan does not execute commits' );
npcink_abilities_toolkit_assert_same( 'core_proposal_required', $content_metadata_apply_plan['data']['authorization']['classification'] ?? '', 'build-content-metadata-apply-plan carries proposal-required classification evidence' );
npcink_abilities_toolkit_assert_same( array( 3, 5 ), $content_metadata_apply_plan['data']['accepted_choices']['category_ids'] ?? array(), 'build-content-metadata-apply-plan normalizes selected category ids' );
npcink_abilities_toolkit_assert_same( 'manual_review_only_no_create_term_action', $content_metadata_apply_plan['data']['accepted_choices']['new_term_policy'] ?? '', 'build-content-metadata-apply-plan preserves new terms as review-only notes' );
$content_metadata_write_actions = is_array( $content_metadata_apply_plan['data']['write_actions'] ?? null ) ? $content_metadata_apply_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 3, count( $content_metadata_write_actions ), 'build-content-metadata-apply-plan emits excerpt, category, and tag proposal actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post', $content_metadata_write_actions[0]['target_ability_id'] ?? '', 'build-content-metadata-apply-plan targets update-post for excerpt changes' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/set-post-terms', $content_metadata_write_actions[1]['target_ability_id'] ?? '', 'build-content-metadata-apply-plan targets set-post-terms for category changes' );
npcink_abilities_toolkit_assert_same( false, $content_metadata_write_actions[1]['input']['create_missing'] ?? null, 'build-content-metadata-apply-plan never creates missing category terms' );
npcink_abilities_toolkit_assert_same( true, $content_metadata_write_actions[1]['input']['dry_run'] ?? null, 'build-content-metadata-apply-plan category action is dry-run' );
npcink_abilities_toolkit_assert_same( false, $content_metadata_write_actions[1]['input']['commit'] ?? null, 'build-content-metadata-apply-plan category action does not request commit' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/set-post-terms', $content_metadata_write_actions[2]['target_ability_id'] ?? '', 'build-content-metadata-apply-plan targets set-post-terms for tag changes' );
npcink_abilities_toolkit_assert_same( false, $content_metadata_write_actions[2]['input']['create_missing'] ?? null, 'build-content-metadata-apply-plan never creates missing tag terms' );
npcink_abilities_toolkit_assert_same( 1, count( $content_metadata_apply_plan['data']['manual_review'] ?? array() ), 'build-content-metadata-apply-plan records new-term candidates as manual review only' );
$article_audio_adoption_plan = $core_read_package->build_article_audio_adoption_plan(
	array(
		'post_id'              => 501,
		'candidate_type'       => 'article_audio_summary',
		'audio_url'            => 'https://cloud.example.test/audio/article-summary.mp3',
		'source_content_hash'  => 'sha256:article-source',
		'source_word_count'    => 1234,
		'source_generated_at'  => '2026-06-24T09:30:00Z',
		'import_media'         => true,
		'media_file_name'      => 'reviewed-audio-summary',
		'audio_candidate'      => array(
			'title'            => 'Reviewed audio summary',
			'duration_seconds' => 42.5,
			'mime_type'        => 'audio/mpeg',
			'provider'         => 'minimax',
			'model'            => 'speech-2.8-turbo',
			'trace_id'         => 'trace_audio_123',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $article_audio_adoption_plan['success'] ?? null, 'build-article-audio-adoption-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'article_audio_adoption_plan.v1', $article_audio_adoption_plan['data']['artifact_type'] ?? '', 'article audio adoption plan declares the versioned artifact type' );
npcink_abilities_toolkit_assert_same( false, $article_audio_adoption_plan['data']['direct_wordpress_write'] ?? null, 'article audio adoption plan does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $article_audio_adoption_plan['data']['commit_execution'] ?? null, 'article audio adoption plan keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( true, $article_audio_adoption_plan['meta']['readonly'] ?? null, 'article audio adoption plan remains read-only' );
$article_audio_write_actions = is_array( $article_audio_adoption_plan['data']['write_actions'] ?? null ) ? $article_audio_adoption_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 1, count( $article_audio_write_actions ), 'article audio adoption plan emits one post-meta write action' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/adopt-article-audio', $article_audio_write_actions[0]['target_ability_id'] ?? '', 'article audio adoption plan targets the governed audio adoption ability' );
npcink_abilities_toolkit_assert_same( 'low', $article_audio_write_actions[0]['risk'] ?? '', 'article audio adoption action is low risk' );
npcink_abilities_toolkit_assert_same( true, $article_audio_write_actions[0]['requires_approval'] ?? null, 'article audio adoption action still requires governance approval' );
npcink_abilities_toolkit_assert_same( 501, $article_audio_write_actions[0]['input']['post_id'] ?? 0, 'article audio adoption action includes the target post id' );
npcink_abilities_toolkit_assert_same( 'https://cloud.example.test/audio/article-summary.mp3', $article_audio_write_actions[0]['input']['audio_url'] ?? '', 'article audio adoption action includes the reviewed audio url' );
npcink_abilities_toolkit_assert_same( 'article_audio_summary', $article_audio_write_actions[0]['input']['audio_kind'] ?? '', 'article audio adoption action preserves the candidate type' );
npcink_abilities_toolkit_assert_same( true, $article_audio_write_actions[0]['input']['import_media'] ?? null, 'article audio adoption action carries the local media import decision' );
npcink_abilities_toolkit_assert_same( 'reviewed-audio-summary', $article_audio_write_actions[0]['input']['media_file_name'] ?? '', 'article audio adoption action carries the reviewed audio file name' );
npcink_abilities_toolkit_assert_same( true, $article_audio_write_actions[0]['input']['dry_run'] ?? null, 'article audio adoption action is dry-run' );
npcink_abilities_toolkit_assert_same( false, $article_audio_write_actions[0]['input']['commit'] ?? null, 'article audio adoption action does not request commit' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-article-audio-adoption-plan', $article_audio_adoption_plan['data']['handoff']['plan_ability_id'] ?? '', 'article audio adoption plan identifies the Toolkit Core handoff ability' );
$article_block_plan = $core_read_package->build_article_block_plan(
	array(
		'title'              => 'Gutenberg Article Draft',
		'article_template'   => 'comparison-review',
		'responsive_profile' => 'article_standard',
		'media_strategy'     => 'existing_media_url',
		'variables'          => array(
			'dek'            => '用 Gutenberg 原生模块组织文章，让编辑、审查和移动端阅读都更稳定。',
			'intro'          => '文章计划应该和页面 Pattern 分开处理，重点放在语义结构和可编辑性。',
			'hero_media_url' => 'https://example.test/wp-content/uploads/2026/06/article-hero.jpg',
			'hero_media_attachment_id' => 9053,
			'hero_media_alt' => 'Article hero preview',
			'takeaways'      => array(
				'文章使用核心块，不依赖自定义 CSS。',
				'对比区使用 columns 并在移动端堆叠。',
				'FAQ 使用 details 块，编辑器可以继续维护。',
			),
			'sections'       => array(
				array(
					'title'      => '文章块红利',
					'paragraphs' => array( 'Gutenberg 让文章从纯 HTML 变成可回读、可编辑的内容结构。' ),
					'bullets'    => array( '标题层级', '要点列表', '图片和 FAQ' ),
				),
				array(
					'title'      => '治理路径',
					'paragraphs' => array( 'AI 只生成计划，写入仍然经过 proposal 审批。' ),
				),
				array(
					'title'      => '响应式验收',
					'paragraphs' => array( '移动端重点检查图片、对比区和 FAQ 是否正常换行。' ),
				),
			),
			'comparisons'    => array(
				array(
					'title'       => '纯 HTML',
					'description' => '短期自由，但编辑器维护成本更高。',
				),
				array(
					'title'       => 'Gutenberg blocks',
					'description' => '结构更稳定，也更适合审查和二次编辑。',
				),
			),
			'faq'            => array(
				array(
					'title'       => '会直接发布吗？',
					'description' => '不会，只创建 draft proposal。',
				),
				array(
					'title'       => '能继续编辑吗？',
					'description' => '可以，内容由核心块组成。',
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $article_block_plan['success'] ?? null, 'build-article-block-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'article_block_plan', $article_block_plan['data']['artifact_type'] ?? '', 'build-article-block-plan declares an article block artifact type' );
npcink_abilities_toolkit_assert_same( 'comparison-review', $article_block_plan['data']['article_template'] ?? '', 'build-article-block-plan preserves the article template' );
npcink_abilities_toolkit_assert_same( 'article_standard', $article_block_plan['data']['responsive_profile'] ?? '', 'build-article-block-plan preserves the responsive profile' );
npcink_abilities_toolkit_assert_same( 'existing_media_url', $article_block_plan['data']['media_strategy'] ?? '', 'build-article-block-plan preserves media strategy' );
npcink_abilities_toolkit_assert_same( 'post_content', $article_block_plan['data']['block_editor_surface']['surface_kind'] ?? '', 'build-article-block-plan declares a post content block-editor surface' );
npcink_abilities_toolkit_assert_same( 'block_editor', $article_block_plan['data']['block_editor_surface']['editor'] ?? '', 'build-article-block-plan declares the block editor surface owner' );
npcink_abilities_toolkit_assert_same( 'post', $article_block_plan['data']['block_editor_surface']['post_type'] ?? '', 'build-article-block-plan declares the article post type surface' );
npcink_abilities_toolkit_assert_same( 'create_draft', $article_block_plan['data']['block_editor_surface']['target_mode'] ?? '', 'build-article-block-plan reports create draft surface mode by default' );
npcink_abilities_toolkit_assert_same( false, $article_block_plan['data']['direct_wordpress_write'] ?? null, 'build-article-block-plan does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $article_block_plan['data']['commit_execution'] ?? null, 'build-article-block-plan keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-article-block-plan', $article_block_plan['data']['handoff']['plan_ability_id'] ?? '', 'build-article-block-plan identifies itself for Core from-plan intake' );
npcink_abilities_toolkit_assert_same( '1.0', $article_block_plan['data']['editorial_quality']['pattern_version'] ?? '', 'build-article-block-plan reports the v1 editorial pattern version' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_editorial', $article_block_plan['data']['editorial_quality']['style_strategy'] ?? '', 'build-article-block-plan reports editorial Gutenberg-native strategy' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['editorial_quality']['uses_native_blocks'] ?? null, 'build-article-block-plan reports native block usage' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['editorial_quality']['has_takeaways'] ?? null, 'build-article-block-plan reports takeaways' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['editorial_quality']['has_faq'] ?? null, 'build-article-block-plan reports FAQ' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['editorial_quality']['has_comparison_columns'] ?? null, 'build-article-block-plan reports comparison columns' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['editorial_quality']['has_hero_media_attachment_id'] ?? null, 'build-article-block-plan reports hero media attachment binding' );
npcink_abilities_toolkit_assert_same( false, $article_block_plan['data']['editorial_quality']['custom_css_required'] ?? true, 'build-article-block-plan reports no custom CSS requirement' );
npcink_abilities_toolkit_assert_same( 'article_standard', $article_block_plan['data']['responsive_quality']['responsive_profile'] ?? '', 'build-article-block-plan reports responsive profile quality' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['responsive_quality']['uses_core_responsive_blocks'] ?? null, 'build-article-block-plan reports core responsive blocks' );
	npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['responsive_quality']['uses_mobile_stack'] ?? null, 'build-article-block-plan reports mobile column stacking' );
	npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['responsive_quality']['has_responsive_media'] ?? null, 'build-article-block-plan reports responsive media' );
	npcink_abilities_toolkit_assert_same( 2, $article_block_plan['data']['responsive_quality']['max_columns_per_row'] ?? 0, 'build-article-block-plan reports bounded comparison columns' );
	npcink_abilities_toolkit_assert_same( 'block_editor_surface_review', $article_block_plan['data']['block_editor_review']['artifact_type'] ?? '', 'build-article-block-plan includes a block-editor self-review excerpt' );
	npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $article_block_plan['data']['composition_contract']['catalog_id'] ?? '', 'build-article-block-plan references the Gutenberg block capability catalog' );
	npcink_abilities_toolkit_assert_same( 'bounded_block_composition', $article_block_plan['data']['composition_contract']['composition_model'] ?? '', 'build-article-block-plan uses bounded block composition' );
	npcink_abilities_toolkit_assert_same( 'pass', $article_block_plan['data']['composition_contract']['contract_status'] ?? '', 'build-article-block-plan passes the block composition contract' );
	npcink_abilities_toolkit_assert_same( array(), $article_block_plan['data']['composition_contract']['non_core_blocks'] ?? array( 'unexpected' ), 'build-article-block-plan reports no non-core block contract violations' );
	npcink_abilities_toolkit_assert_true( in_array( 'core/image', $article_block_plan['data']['composition_contract']['used_block_names'] ?? array(), true ), 'build-article-block-plan contract records core image usage' );
	npcink_abilities_toolkit_assert_same( 'gutenberg_native_block_composer_v1', $article_block_plan['data']['composition_contract']['composer_instruction']['instruction_id'] ?? '', 'build-article-block-plan exposes AI composer instructions through the contract' );
	npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['block_editor_review']['media_quality']['has_hero_media_attachment_id'] ?? null, 'build-article-block-plan review reports hero media attachment ids present' );
	npcink_abilities_toolkit_assert_same( false, $article_block_plan['data']['block_editor_review']['media_quality']['has_temporary_cloud_preview_url'] ?? true, 'build-article-block-plan review reports no temporary Cloud preview URLs' );
	npcink_abilities_toolkit_assert_same( 'article_editor_safety', $article_block_plan['data']['block_editor_quality_gate']['profile'] ?? '', 'build-article-block-plan uses an article editor-safety quality gate' );
	npcink_abilities_toolkit_assert_same( true, $article_block_plan['data']['block_editor_quality_gate']['ready_for_proposal'] ?? null, 'build-article-block-plan marks editor-safe article blocks ready for proposal' );
	npcink_abilities_toolkit_assert_same( false, $article_block_plan['data']['block_editor_quality_gate']['commit_execution'] ?? null, 'build-article-block-plan quality gate does not execute commits' );
	$article_block_actions = is_array( $article_block_plan['data']['write_actions'] ?? null ) ? $article_block_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 2, count( $article_block_actions ), 'build-article-block-plan emits create and block update actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/create-draft', $article_block_actions[0]['target_ability_id'] ?? '', 'build-article-block-plan first creates a draft post' );
npcink_abilities_toolkit_assert_same( 'post', $article_block_actions[0]['input']['post_type'] ?? '', 'build-article-block-plan create action targets a post' );
npcink_abilities_toolkit_assert_same( 'draft', $article_block_actions[0]['input']['status'] ?? '', 'build-article-block-plan create action stays draft-only' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post-blocks', $article_block_actions[1]['target_ability_id'] ?? '', 'build-article-block-plan second action updates Gutenberg blocks' );
npcink_abilities_toolkit_assert_same( '$outputs.create-article-draft.post_id', $article_block_actions[1]['input']['post_id'] ?? '', 'build-article-block-plan uses exact output reference for the new post id' );
$article_blocks = is_array( $article_block_actions[1]['input']['blocks'] ?? null ) ? $article_block_actions[1]['input']['blocks'] : array();
$article_markup = wp_json_encode( $article_blocks );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, '"blockName":"core\\/image"' ), 'build-article-block-plan uses core image for existing media' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, '"id":9053' ), 'build-article-block-plan binds hero image to the reviewed attachment id' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, 'class=\"wp-image-9053\"' ), 'build-article-block-plan serializes wp-image attachment classes' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, '"blockName":"core\\/details"' ), 'build-article-block-plan uses details blocks for FAQ' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, '"blockName":"core\\/columns"' ), 'build-article-block-plan uses columns for comparison sections' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, '"isStackedOnMobile":true' ), 'build-article-block-plan stacks comparison columns on mobile' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, 'wp-block-group has-border-color has-background' ), 'build-article-block-plan emits Gutenberg support classes for styled group blocks' );
npcink_abilities_toolkit_assert_true( is_string( $article_markup ) && false !== strpos( $article_markup, 'border-color:#e5e5e5;border-width:1px;border-radius:16px;background-color:#f7f7f4;padding-top:24px' ), 'build-article-block-plan serializes styled group wrappers from attrs' );
$article_block_plan_top_level_media = $core_read_package->build_article_block_plan(
	array(
		'title'                    => 'Gutenberg Article Top-Level Media',
		'article_template'         => 'comparison-review',
		'responsive_profile'       => 'article_standard',
		'media_strategy'           => 'existing_media_url',
		'hero_media_url'           => 'https://magick-ai.local/wp-content/uploads/2026/06/preview.webp',
		'hero_media_attachment_id' => 8053,
		'hero_media_alt'           => 'WordPress AI governed workflow hero visual',
		'variables'                => array(
			'user_intent' => '写一篇介绍 Gutenberg 模块红利的文章草稿，需要配图和 FAQ。',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $article_block_plan_top_level_media['success'] ?? null, 'build-article-block-plan accepts top-level reviewed media inputs' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan_top_level_media['data']['editorial_quality']['has_hero_media_attachment_id'] ?? null, 'build-article-block-plan reports top-level media attachment binding' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan_top_level_media['data']['responsive_quality']['has_responsive_media'] ?? null, 'build-article-block-plan reports responsive media for top-level media inputs' );
npcink_abilities_toolkit_assert_same( true, $article_block_plan_top_level_media['data']['block_editor_review']['media_quality']['has_hero_media_attachment_id'] ?? null, 'build-article-block-plan review reports top-level media attachment ids present' );
$article_top_level_media_actions = is_array( $article_block_plan_top_level_media['data']['write_actions'] ?? null ) ? $article_block_plan_top_level_media['data']['write_actions'] : array();
$article_top_level_media_blocks  = is_array( $article_top_level_media_actions[1]['input']['blocks'] ?? null ) ? $article_top_level_media_actions[1]['input']['blocks'] : array();
$article_top_level_media_markup  = wp_json_encode( $article_top_level_media_blocks );
npcink_abilities_toolkit_assert_true( is_string( $article_top_level_media_markup ) && false !== strpos( $article_top_level_media_markup, '"blockName":"core\\/image"' ), 'build-article-block-plan uses core image for top-level existing media' );
npcink_abilities_toolkit_assert_true( is_string( $article_top_level_media_markup ) && false !== strpos( $article_top_level_media_markup, '"id":8053' ), 'build-article-block-plan binds top-level media to the reviewed attachment id' );
npcink_abilities_toolkit_assert_true( is_string( $article_top_level_media_markup ) && false !== strpos( $article_top_level_media_markup, 'class=\"wp-image-8053\"' ), 'build-article-block-plan serializes top-level media wp-image attachment classes' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280975] = (object) array(
	'ID'           => 280975,
	'post_type'    => 'post',
	'post_status'  => 'draft',
	'post_title'   => 'Existing Article Draft',
	'post_content' => '<!-- wp:paragraph --><p>Existing article draft body.</p><!-- /wp:paragraph -->',
);
$target_article_block_plan = $core_read_package->build_article_block_plan(
	array(
		'title'            => 'Update Existing Article',
		'target_post_id'   => 280975,
		'article_template' => 'editorial-longform',
		'variables'        => array(
			'title' => 'Update an existing Gutenberg article draft',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $target_article_block_plan['success'] ?? null, 'build-article-block-plan accepts an existing draft post target' );
npcink_abilities_toolkit_assert_same( 'update_existing', $target_article_block_plan['data']['target_post']['mode'] ?? '', 'build-article-block-plan reports existing draft post update mode' );
npcink_abilities_toolkit_assert_same( 280975, $target_article_block_plan['data']['target_post']['post_id'] ?? 0, 'build-article-block-plan preserves the target draft post id' );
npcink_abilities_toolkit_assert_same( 'draft', $target_article_block_plan['data']['target_post']['status'] ?? '', 'build-article-block-plan reports the target draft post status' );
npcink_abilities_toolkit_assert_same( 'update_existing', $target_article_block_plan['data']['block_editor_surface']['target_mode'] ?? '', 'build-article-block-plan reports update surface mode for target drafts' );
npcink_abilities_toolkit_assert_same( 1, $target_article_block_plan['data']['summary']['action_count'] ?? 0, 'build-article-block-plan emits one action when updating an existing draft post' );
$target_article_actions = is_array( $target_article_block_plan['data']['write_actions'] ?? null ) ? $target_article_block_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 1, count( $target_article_actions ), 'build-article-block-plan omits create-draft when target_post_id is supplied' );
npcink_abilities_toolkit_assert_same( 'update-article-blocks', $target_article_actions[0]['action_id'] ?? '', 'build-article-block-plan keeps the update action id for existing drafts' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post-blocks', $target_article_actions[0]['target_ability_id'] ?? '', 'build-article-block-plan targets update-post-blocks for existing article drafts' );
npcink_abilities_toolkit_assert_same( 280975, $target_article_actions[0]['input']['post_id'] ?? 0, 'build-article-block-plan uses the concrete target draft post id' );
npcink_abilities_toolkit_assert_same( false, $target_article_actions[0]['input']['commit'] ?? true, 'build-article-block-plan keeps existing draft update actions non-committing' );
npcink_abilities_toolkit_assert_same( true, $target_article_actions[0]['input']['dry_run'] ?? false, 'build-article-block-plan keeps existing draft update actions dry-run by default' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280976] = (object) array(
	'ID'           => 280976,
	'post_type'    => 'post',
	'post_status'  => 'publish',
	'post_title'   => 'Published Article',
	'post_content' => '<!-- wp:paragraph --><p>Published article body.</p><!-- /wp:paragraph -->',
);
$published_target_article_block_plan = $core_read_package->build_article_block_plan(
	array(
		'title'            => 'Reject Published Article Target',
		'target_post_id'   => 280976,
		'article_template' => 'editorial-longform',
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $published_target_article_block_plan ) && 'npcink_abilities_toolkit_article_block_target_status_invalid' === $published_target_article_block_plan->get_error_code(), 'build-article-block-plan rejects published target posts for replacement proposals' );

$page_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '帮我做一个现代官网介绍页，需要配图，手机端也要好看。',
	)
);
npcink_abilities_toolkit_assert_same( true, $page_intent_route['success'] ?? null, 'route-content-intent returns a success envelope for page intents' );
npcink_abilities_toolkit_assert_same( 'content_intent_route', $page_intent_route['data']['artifact_type'] ?? '', 'route-content-intent declares a routing artifact type' );
npcink_abilities_toolkit_assert_same( false, $page_intent_route['data']['prompt_is_authorization'] ?? true, 'route-content-intent never treats prompts as authorization' );
npcink_abilities_toolkit_assert_same( 'pattern_page_plan', $page_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps landing page prompts to pattern page plans' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-pattern-page-plan', $page_intent_route['data']['route']['plan_ability_id'] ?? '', 'route-content-intent selects the pattern page plan ability' );
npcink_abilities_toolkit_assert_same( 'existing_or_generated_media', $page_intent_route['data']['route']['media_strategy'] ?? '', 'route-content-intent detects visual media needs from page prompts' );
npcink_abilities_toolkit_assert_same( 'gutenberg-native-modern', $page_intent_route['data']['route']['style_strategy'] ?? '', 'route-content-intent detects modern style requests' );
npcink_abilities_toolkit_assert_same( 'page', $page_intent_route['data']['route']['recommended_plan_input']['post_type'] ?? '', 'route-content-intent recommends page plan input for landing pages' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-gutenberg-block-capability-catalog', $page_intent_route['data']['route']['block_capability_catalog_ability_id'] ?? '', 'route-content-intent points page composers at the block capability catalog' );
npcink_abilities_toolkit_assert_same( 'page', $page_intent_route['data']['route']['composer_instruction']['surface'] ?? '', 'route-content-intent scopes page composer instructions to the page surface' );
npcink_abilities_toolkit_assert_same( 'saas_landing', $page_intent_route['data']['route']['recommended_composer_profile_id'] ?? '', 'route-content-intent recommends the SaaS landing composer profile for page prompts' );
npcink_abilities_toolkit_assert_same( 'high', $page_intent_route['data']['route']['recommended_composer_profile']['quality_targets']['section_variance'] ?? '', 'route-content-intent exposes page composer profile quality targets' );
npcink_abilities_toolkit_assert_same( 'inspect_catalog', $page_intent_route['data']['route']['recommended_composer_flow'][0]['step'] ?? '', 'route-content-intent recommends catalog inspection before planning' );
npcink_abilities_toolkit_assert_same( 'bounded_block_composition', $page_intent_route['data']['guardrails']['composition_model'] ?? '', 'route-content-intent guardrails report bounded block composition' );
npcink_abilities_toolkit_assert_true( ! isset( $page_intent_route['data']['write_actions'] ), 'route-content-intent does not create write actions' );

$article_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '写一篇对比评测文章，加一张配图。',
	)
);
npcink_abilities_toolkit_assert_same( 'article_block_plan', $article_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps article prompts to article block plans' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-article-block-plan', $article_intent_route['data']['route']['plan_ability_id'] ?? '', 'route-content-intent selects the article block plan ability' );
npcink_abilities_toolkit_assert_same( 'post', $article_intent_route['data']['route']['recommended_plan_input']['post_type'] ?? '', 'route-content-intent recommends post plan input for article prompts' );
npcink_abilities_toolkit_assert_same( 'existing_media_url', $article_intent_route['data']['route']['recommended_plan_input']['media_strategy'] ?? '', 'route-content-intent keeps article media in the existing-media URL lane' );
npcink_abilities_toolkit_assert_same( 'post', $article_intent_route['data']['route']['composer_instruction']['surface'] ?? '', 'route-content-intent scopes article composer instructions to the post surface' );
npcink_abilities_toolkit_assert_same( 'comparison_review', $article_intent_route['data']['route']['recommended_composer_profile_id'] ?? '', 'route-content-intent recommends the comparison review composer profile for comparison articles' );

$template_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '给文章模板加面包屑导航。',
	)
);
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $template_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps supported template breadcrumb prompts to block theme site plans' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-block-theme-site-plan', $template_intent_route['data']['route']['plan_ability_id'] ?? '', 'route-content-intent selects the block theme site plan ability' );
npcink_abilities_toolkit_assert_same( 'add_breadcrumbs', $template_intent_route['data']['route']['recommended_plan_input']['intent'] ?? '', 'route-content-intent recommends the only supported block theme intent' );
npcink_abilities_toolkit_assert_same( array( 'single' ), $template_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent scopes article template breadcrumbs to single by natural language' );
npcink_abilities_toolkit_assert_same( 'block_theme_template', $template_intent_route['data']['route']['recommended_composer_profile_id'] ?? '', 'route-content-intent recommends the block theme template composer profile for template prompts' );

$page_template_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '页面也加上面包屑导航，位置不要跑到页眉前面。',
	)
);
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $page_template_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps page breadcrumb prompts to block theme site plans' );
npcink_abilities_toolkit_assert_same( array( 'page', 'front-page' ), $page_template_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent scopes page breadcrumb prompts to page and front-page templates' );

$archive_template_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '给归档模板加面包屑导航，并检查不要跑到页眉上方。',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $archive_template_intent_route['data']['route']['route'] ?? '', 'route-content-intent rejects archive template write prompts before Core handoff' );
npcink_abilities_toolkit_assert_same( 'archive_template_write_not_supported', $archive_template_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent explains archive template write prompts are outside the Core-compatible handoff' );

$site_template_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '给网站加面包屑导航。',
	)
);
npcink_abilities_toolkit_assert_same( array( 'single', 'page', 'front-page' ), $site_template_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent expands site breadcrumb prompts to common content templates' );

$article_template_layout_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '帮我把文章页改成更专业的布局：顶部有面包屑，标题下面显示作者和日期，下面是特色图和正文，底部放相关文章。',
	)
);
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $article_template_layout_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps article template layout requests to block theme site plans' );
npcink_abilities_toolkit_assert_same( 'site_template_layout', $article_template_layout_intent_route['data']['route']['route_key'] ?? '', 'route-content-intent uses the template layout route key for article template layouts' );
npcink_abilities_toolkit_assert_same( 'customize_template_layout', $article_template_layout_intent_route['data']['route']['recommended_plan_input']['intent'] ?? '', 'route-content-intent recommends the bounded template layout intent for article templates' );
npcink_abilities_toolkit_assert_same( array( 'single' ), $article_template_layout_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent scopes article template layouts to single' );
npcink_abilities_toolkit_assert_same( 'article_standard', $article_template_layout_intent_route['data']['route']['recommended_plan_input']['layout_profile'] ?? '', 'route-content-intent recommends the article layout profile' );

$homepage_template_layout_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '帮我自定义首页：顶部放一个大标题和介绍，下面展示最新文章、分类入口和一个行动按钮。',
	)
);
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $homepage_template_layout_intent_route['data']['route']['route'] ?? '', 'route-content-intent maps homepage template layout requests to block theme site plans' );
npcink_abilities_toolkit_assert_same( 'customize_template_layout', $homepage_template_layout_intent_route['data']['route']['recommended_plan_input']['intent'] ?? '', 'route-content-intent recommends the bounded template layout intent for homepage layouts' );
npcink_abilities_toolkit_assert_same( array( 'front-page' ), $homepage_template_layout_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent scopes homepage layouts to front-page' );
npcink_abilities_toolkit_assert_same( 'homepage_landing', $homepage_template_layout_intent_route['data']['route']['recommended_plan_input']['layout_profile'] ?? '', 'route-content-intent recommends the homepage layout profile' );

$homepage_template_layout_guardrailed_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '把首页改造成一个基础落地页：顶部有清晰的大标题和简短介绍，下面有一个行动按钮，再下面展示最新文章和分类入口。不要改导航，不要改 global styles，不要写 theme.json，不要写主题文件，不要输出 raw template HTML，只通过块主题模板 proposal 来处理。',
	)
);
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $homepage_template_layout_guardrailed_intent_route['data']['route']['route'] ?? '', 'route-content-intent keeps homepage layout supported when unsupported surfaces are negated guardrails' );
npcink_abilities_toolkit_assert_same( 'site_template_layout', $homepage_template_layout_guardrailed_intent_route['data']['route']['route_key'] ?? '', 'route-content-intent keeps guardrailed homepage layout on the template layout route' );
npcink_abilities_toolkit_assert_same( 'customize_template_layout', $homepage_template_layout_guardrailed_intent_route['data']['route']['recommended_plan_input']['intent'] ?? '', 'route-content-intent recommends template layout for guardrailed homepage prompts' );
npcink_abilities_toolkit_assert_same( array( 'front-page' ), $homepage_template_layout_guardrailed_intent_route['data']['route']['recommended_plan_input']['target_templates'] ?? array(), 'route-content-intent scopes guardrailed homepage layout prompts to front-page' );
npcink_abilities_toolkit_assert_same( 'homepage_landing', $homepage_template_layout_guardrailed_intent_route['data']['route']['recommended_plan_input']['layout_profile'] ?? '', 'route-content-intent recommends homepage landing for guardrailed homepage prompts' );

$template_part_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '帮我重做页眉模板部件。',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $template_part_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for template part edits without a recipe' );
npcink_abilities_toolkit_assert_same( true, $template_part_intent_route['data']['route']['needs_clarification'] ?? false, 'route-content-intent asks for clarification for unsupported template parts' );
npcink_abilities_toolkit_assert_same( 'template_part_recipe_not_available', $template_part_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent reports the missing template part recipe' );

$template_part_breadcrumb_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '在页眉模板部件里加面包屑导航。',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $template_part_breadcrumb_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for template part breadcrumb requests' );
npcink_abilities_toolkit_assert_same( 'template_part_recipe_not_available', $template_part_breadcrumb_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent does not route template part breadcrumb requests into template writes' );

$navigation_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => 'Change the navigation menu and add a Products link.',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $navigation_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for navigation menu writes' );
npcink_abilities_toolkit_assert_same( '', $navigation_intent_route['data']['route']['plan_ability_id'] ?? 'unexpected', 'route-content-intent does not select a plan ability for navigation menu writes' );
npcink_abilities_toolkit_assert_same( 'navigation_write_not_supported', $navigation_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent reports unsupported navigation writes precisely' );
npcink_abilities_toolkit_assert_true( ! isset( $navigation_intent_route['data']['write_actions'] ), 'route-content-intent does not create navigation write actions' );

$global_styles_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => 'Change global styles and write a theme.json color patch.',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $global_styles_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for global style writes' );
npcink_abilities_toolkit_assert_same( '', $global_styles_intent_route['data']['route']['plan_ability_id'] ?? 'unexpected', 'route-content-intent does not select a plan ability for global style writes' );
npcink_abilities_toolkit_assert_same( 'global_styles_write_not_supported', $global_styles_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent reports unsupported global style writes precisely' );
npcink_abilities_toolkit_assert_true( ! isset( $global_styles_intent_route['data']['write_actions'] ), 'route-content-intent does not create global style write actions' );

$custom_html_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => 'Directly execute a custom HTML template change.',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $custom_html_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for custom HTML template writes' );
npcink_abilities_toolkit_assert_same( '', $custom_html_intent_route['data']['route']['plan_ability_id'] ?? 'unexpected', 'route-content-intent does not select a plan ability for custom HTML template writes' );
npcink_abilities_toolkit_assert_same( 'custom_html_template_not_supported', $custom_html_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent reports unsupported custom HTML template writes precisely' );
npcink_abilities_toolkit_assert_true( ! isset( $custom_html_intent_route['data']['write_actions'] ), 'route-content-intent does not create custom HTML template write actions' );

$ambiguous_intent_route = $core_read_package->route_content_intent(
	array(
		'prompt' => '帮我做一个页面文章草稿。',
	)
);
npcink_abilities_toolkit_assert_same( 'unsupported', $ambiguous_intent_route['data']['route']['route'] ?? '', 'route-content-intent fails closed for ambiguous page/article prompts' );
npcink_abilities_toolkit_assert_same( 'ambiguous_page_vs_article', $ambiguous_intent_route['data']['route']['unsupported_reason'] ?? '', 'route-content-intent reports page/article ambiguity' );

$recipe_eval_posts_fixture = $GLOBALS['npcink_abilities_toolkit_unit_style_posts'];
$recipe_eval_options_fixture = $GLOBALS['npcink_abilities_toolkit_unit_options'] ?? array();
$recipe_eval_block_theme_fixture = $GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'] ?? null;
$GLOBALS['npcink_abilities_toolkit_unit_options'] = array(
	'show_on_front'  => 'posts',
	'page_for_posts' => 611,
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array(
	608 => (object) array(
		'ID'           => 608,
		'post_type'    => 'wp_template',
		'post_status'  => 'publish',
		'post_title'   => 'Single',
		'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
		'post_excerpt' => '',
		'post_author'  => 7,
		'post_name'    => 'single',
		'post_parent'  => 0,
	),
	609 => (object) array(
		'ID'           => 609,
		'post_type'    => 'wp_template',
		'post_status'  => 'publish',
		'post_title'   => 'Page',
		'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
		'post_excerpt' => '',
		'post_author'  => 7,
		'post_name'    => 'page',
		'post_parent'  => 0,
	),
	610 => (object) array(
		'ID'           => 610,
		'post_type'    => 'wp_template',
		'post_status'  => 'publish',
		'post_title'   => 'Front Page',
		'post_content' => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title /--><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
		'post_excerpt' => '',
		'post_author'  => 7,
		'post_name'    => 'front-page',
		'post_parent'  => 0,
	),
	611 => (object) array(
		'ID'           => 611,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Blog',
		'post_content' => 'Blog page fixture.',
		'post_excerpt' => '',
		'post_author'  => 7,
		'post_name'    => 'blog',
		'post_parent'  => 0,
	),
);
$GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'] = true;
$gutenberg_recipe_eval = $core_read_package->evaluate_gutenberg_recipe_suite(
	array(
		'minimum_pass_rate' => 1,
		'media_fixture'     => array(
			'url'           => 'https://magick-ai.local/wp-content/uploads/2026/06/preview.webp',
			'attachment_id' => 8053,
			'alt'           => 'WordPress AI governed workflow hero visual',
		),
		'cases'             => array(
			array(
				'id'             => 'eval_page_landing',
				'prompt'         => '帮我做一个现代官网介绍页，需要配图，手机端也要好看。',
				'expected_route' => 'pattern_page_plan',
			),
			array(
				'id'             => 'eval_article',
				'prompt'         => '写一篇对比评测文章，加一张配图和 FAQ。',
				'expected_route' => 'article_block_plan',
			),
			array(
				'id'             => 'eval_template',
				'prompt'         => '给文章模板加面包屑导航。',
				'expected_route' => 'block_theme_site_plan',
			),
			array(
				'id'                 => 'eval_navigation_fail_closed',
				'prompt'             => 'Change the navigation menu and add a Products link.',
				'expected_route'     => 'unsupported',
				'expected_supported' => false,
			),
		),
	)
);
$gutenberg_recipe_default_eval = $core_read_package->evaluate_gutenberg_recipe_suite(
	array(
		'minimum_pass_rate'    => 1,
		'include_case_details' => false,
		'media_fixture'        => array(
			'url'           => 'https://magick-ai.local/wp-content/uploads/2026/06/preview.webp',
			'attachment_id' => 8053,
			'alt'           => 'WordPress AI governed workflow hero visual',
		),
	)
);
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = $recipe_eval_posts_fixture;
$GLOBALS['npcink_abilities_toolkit_unit_options']     = $recipe_eval_options_fixture;
if ( null === $recipe_eval_block_theme_fixture ) {
	unset( $GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'] );
} else {
	$GLOBALS['npcink_abilities_toolkit_unit_is_block_theme'] = $recipe_eval_block_theme_fixture;
}
npcink_abilities_toolkit_assert_same( true, $gutenberg_recipe_eval['success'] ?? null, 'evaluate-gutenberg-recipe-suite returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'gutenberg_recipe_suite_evaluation', $gutenberg_recipe_eval['data']['artifact_type'] ?? '', 'Gutenberg recipe evaluation declares its artifact type' );
npcink_abilities_toolkit_assert_same( 'route_and_plan_only', $gutenberg_recipe_eval['data']['evaluation_mode'] ?? '', 'Gutenberg recipe evaluation only routes and builds plans' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_recipe_eval['data']['direct_wordpress_write'] ?? null, 'Gutenberg recipe evaluation does not write WordPress content' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_recipe_eval['data']['commit_execution'] ?? null, 'Gutenberg recipe evaluation does not execute commits' );
npcink_abilities_toolkit_assert_same( false, $gutenberg_recipe_eval['data']['proposal_created'] ?? null, 'Gutenberg recipe evaluation does not create proposals' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_eval['data']['suite_status'] ?? '', 'Gutenberg recipe evaluation passes when every case clears the gates' );
npcink_abilities_toolkit_assert_same( 2, $gutenberg_recipe_eval['data']['review_contract']['reviewer_count'] ?? 0, 'Gutenberg recipe evaluation exposes a two-reviewer contract' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_eval['data']['dual_review']['consensus']['decision'] ?? '', 'Gutenberg recipe evaluation consensus passes clean suites' );
npcink_abilities_toolkit_assert_same( 4, $gutenberg_recipe_eval['data']['summary']['total_cases'] ?? 0, 'Gutenberg recipe evaluation counts evaluated cases' );
npcink_abilities_toolkit_assert_same( 4, $gutenberg_recipe_eval['data']['summary']['passed_cases'] ?? 0, 'Gutenberg recipe evaluation counts passed cases' );
npcink_abilities_toolkit_assert_same( 1.0, $gutenberg_recipe_eval['data']['summary']['pass_rate'] ?? 0, 'Gutenberg recipe evaluation reports pass rate' );
$gutenberg_recipe_eval_cases = is_array( $gutenberg_recipe_eval['data']['cases'] ?? null ) ? $gutenberg_recipe_eval['data']['cases'] : array();
npcink_abilities_toolkit_assert_same( 'pattern_page_plan', $gutenberg_recipe_eval_cases[0]['route'] ?? '', 'Gutenberg recipe evaluation routes page cases to Pattern plans' );
npcink_abilities_toolkit_assert_same( 0, $gutenberg_recipe_eval_cases[0]['block_summary']['core_html_count'] ?? -1, 'Gutenberg recipe evaluation rejects core/html in page plans' );
npcink_abilities_toolkit_assert_same( array(), $gutenberg_recipe_eval_cases[0]['block_summary']['non_core_blocks'] ?? array( 'unexpected' ), 'Gutenberg recipe evaluation rejects non-core page blocks' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_eval_cases[0]['dual_review']['recipe_fit_reviewer']['decision'] ?? '', 'Gutenberg recipe evaluation case reviewer passes a valid page recipe' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_eval_cases[0]['dual_review']['governance_boundary_reviewer']['decision'] ?? '', 'Gutenberg recipe evaluation governance reviewer passes read-only plans' );
npcink_abilities_toolkit_assert_same( 'article_block_plan', $gutenberg_recipe_eval_cases[1]['route'] ?? '', 'Gutenberg recipe evaluation routes article cases to article plans' );
npcink_abilities_toolkit_assert_same( 'block_theme_site_plan', $gutenberg_recipe_eval_cases[2]['route'] ?? '', 'Gutenberg recipe evaluation routes template cases to block theme plans' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_eval_cases[2]['plan_summary']['template_placement_contract']['contract_status'] ?? '', 'Gutenberg recipe evaluation exports passing template placement contracts for AI judges' );
npcink_abilities_toolkit_assert_same( 'bounded_template_anchor_placement', $gutenberg_recipe_eval_cases[2]['plan_summary']['template_placement_contract']['placement_model'] ?? '', 'Gutenberg recipe evaluation exports the template placement model for AI judges' );
npcink_abilities_toolkit_assert_same( true, $gutenberg_recipe_eval_cases[2]['plan_summary']['template_placement_contract']['placements'][0]['anchor_allowed'] ?? null, 'Gutenberg recipe evaluation exposes allowed template anchors for AI judges' );
if ( 'no_changes_required' === ( $gutenberg_recipe_eval_cases[2]['plan_summary']['quality_gate_status'] ?? '' ) ) {
	npcink_abilities_toolkit_assert_true( ( $gutenberg_recipe_eval_cases[2]['plan_summary']['no_change_context']['no_change_count'] ?? 0 ) > 0, 'Gutenberg recipe evaluation explains template no-op cases for AI judges' );
	npcink_abilities_toolkit_assert_true( in_array( 'breadcrumbs_already_before_post_title', $gutenberg_recipe_eval_cases[2]['plan_summary']['no_change_context']['no_change_reasons'] ?? array(), true ), 'Gutenberg recipe evaluation exports stable template no-op reasons' );
}
npcink_abilities_toolkit_assert_same( 'unsupported', $gutenberg_recipe_eval_cases[3]['route'] ?? '', 'Gutenberg recipe evaluation keeps unsupported navigation requests fail-closed' );
npcink_abilities_toolkit_assert_same( array(), $gutenberg_recipe_eval['data']['failure_summary']['failure_count_by_code'] ?? array( 'unexpected' ), 'Gutenberg recipe evaluation reports no failure codes for passing suites' );
npcink_abilities_toolkit_assert_same( true, $gutenberg_recipe_default_eval['success'] ?? null, 'default Gutenberg recipe evaluation returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'pass', $gutenberg_recipe_default_eval['data']['suite_status'] ?? '', 'default Gutenberg recipe evaluation suite passes' );
npcink_abilities_toolkit_assert_same( 30, $gutenberg_recipe_default_eval['data']['summary']['total_cases'] ?? 0, 'default Gutenberg recipe evaluation covers 30 natural-language cases' );
npcink_abilities_toolkit_assert_same( 30, $gutenberg_recipe_default_eval['data']['summary']['passed_cases'] ?? 0, 'default Gutenberg recipe evaluation passes every built-in case' );
npcink_abilities_toolkit_assert_same( 1.0, $gutenberg_recipe_default_eval['data']['summary']['pass_rate'] ?? 0, 'default Gutenberg recipe evaluation reports a full pass rate under fixtures' );
npcink_abilities_toolkit_assert_same( array(), $gutenberg_recipe_default_eval['data']['failure_summary']['failure_count_by_code'] ?? array( 'unexpected' ), 'default Gutenberg recipe evaluation reports no built-in failure codes' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-gutenberg-block-capability-catalog', $gutenberg_recipe_default_eval['data']['guardrails']['block_capability_catalog_ability_id'] ?? '', 'Gutenberg recipe evaluation points agents at the block capability catalog' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $gutenberg_recipe_default_eval['data']['guardrails']['block_capability_catalog_id'] ?? '', 'Gutenberg recipe evaluation reports the block capability catalog id' );
npcink_abilities_toolkit_assert_same( 'bounded_block_composition', $gutenberg_recipe_default_eval['data']['guardrails']['composition_model'] ?? '', 'Gutenberg recipe evaluation reports bounded block composition' );
$gutenberg_recipe_composer = file_get_contents( dirname( __DIR__ ) . '/composer.json' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false !== strpos( $gutenberg_recipe_composer, 'smoke:block-theme-host-proof' ), 'Composer exposes the explicit real block-theme host proof command' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false !== strpos( $gutenberg_recipe_composer, 'eval:gutenberg-recipe:suite' ), 'Composer exposes Gutenberg recipe suite export command' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false !== strpos( $gutenberg_recipe_composer, 'eval:gutenberg-recipe:judge:eval-lab' ), 'Composer exposes Gutenberg recipe eval-lab judge wrapper command' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false !== strpos( $gutenberg_recipe_composer, 'export-default-suite.php' ), 'Gutenberg recipe eval-lab wrapper exports the latest default suite before judging' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false !== strpos( $gutenberg_recipe_composer, 'task=gutenberg_judge_cross' ), 'Gutenberg recipe eval-lab wrapper calls the Eval Lab Gutenberg task registry' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_composer ) && false === strpos( $gutenberg_recipe_composer, 'sk-' ), 'Gutenberg recipe eval-lab wrapper does not contain committed API keys' );
$gutenberg_recipe_eval_lab_wrapper = file_get_contents( dirname( __DIR__ ) . '/scripts/eval-lab.sh' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_eval_lab_wrapper ) && false !== strpos( $gutenberg_recipe_eval_lab_wrapper, 'NPCINK_EVAL_LAB_PATH' ), 'Gutenberg recipe eval-lab wrapper keeps provider calls outside Toolkit' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_eval_lab_wrapper ) && false !== strpos( $gutenberg_recipe_eval_lab_wrapper, 'COMPOSER_PROCESS_TIMEOUT' ), 'Gutenberg recipe eval-lab wrapper permits long provider-backed triad runs' );
npcink_abilities_toolkit_assert_true( is_string( $gutenberg_recipe_eval_lab_wrapper ) && false === strpos( $gutenberg_recipe_eval_lab_wrapper, 'API_KEY' ), 'Eval Lab wrapper does not read provider keys in the plugin repo' );
$gutenberg_recipe_manual_review = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/tests/gutenberg-recipe-eval/manual-review-cases.json' ), true );
$gutenberg_recipe_challenge_cases = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/tests/gutenberg-recipe-eval/challenge-cases.json' ), true );
npcink_abilities_toolkit_assert_same( 'gutenberg_recipe_manual_review_queue', $gutenberg_recipe_manual_review['artifact_type'] ?? '', 'Gutenberg recipe eval keeps a manual adjudication queue' );
npcink_abilities_toolkit_assert_same( array(), $gutenberg_recipe_manual_review['items'] ?? array( 'unexpected' ), 'Gutenberg recipe manual adjudication queue starts empty after clean triad runs' );
npcink_abilities_toolkit_assert_same( 'gutenberg_recipe_challenge_cases', $gutenberg_recipe_challenge_cases['artifact_type'] ?? '', 'Gutenberg recipe eval keeps challenge cases separate from the all-green suite' );
npcink_abilities_toolkit_assert_true( count( $gutenberg_recipe_challenge_cases['cases'] ?? array() ) >= 10, 'Gutenberg recipe challenge cases cover boundary and ambiguity prompts' );
$composer_repair_loop = $core_read_package->compose_gutenberg_block_plan(
	array(
		'prompt'      => '帮我做一个现代官网介绍页，需要配图，手机端也要好看。',
		'target_hint' => 'page',
		'plan_input'  => array(
			'pattern_id'     => 'openai-style-landing',
			'media_strategy' => 'existing_media_url',
			'variables'      => array(
				'hero_title'     => 'A very long Gutenberg native landing page headline that should be shortened before proposal handoff because it would be too dense inside the hero section',
				'hero_media_url' => 'https://magick-ai.local/wp-content/uploads/2026/06/composer-repair-dashboard.jpg',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $composer_repair_loop['success'] ?? null, 'compose-gutenberg-block-plan returns a success envelope for repairable page requests' );
npcink_abilities_toolkit_assert_same( 'gutenberg_composer_repair_loop', $composer_repair_loop['data']['artifact_type'] ?? '', 'compose-gutenberg-block-plan declares the composer repair artifact type' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $composer_repair_loop['data']['block_capability_catalog_id'] ?? '', 'compose-gutenberg-block-plan references the Gutenberg block catalog' );
npcink_abilities_toolkit_assert_same( false, $composer_repair_loop['data']['proposal_created'] ?? true, 'compose-gutenberg-block-plan does not create Core proposals' );
npcink_abilities_toolkit_assert_same( false, $composer_repair_loop['data']['direct_wordpress_write'] ?? true, 'compose-gutenberg-block-plan does not write WordPress' );
npcink_abilities_toolkit_assert_true( in_array( 'hero_title_too_long', $composer_repair_loop['data']['initial_review']['finding_codes'] ?? array(), true ), 'compose-gutenberg-block-plan reports overlong hero title findings before repair' );
npcink_abilities_toolkit_assert_true( in_array( 'media_alt_missing', $composer_repair_loop['data']['initial_review']['finding_codes'] ?? array(), true ), 'compose-gutenberg-block-plan reports missing media alt findings before repair' );
$composer_repair_codes = array_map(
	static function ( $repair ) {
		return is_array( $repair ) ? (string) ( $repair['repair_code'] ?? '' ) : '';
	},
	is_array( $composer_repair_loop['data']['applied_repairs'] ?? null ) ? $composer_repair_loop['data']['applied_repairs'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'shorten_overlong_heading', $composer_repair_codes, true ), 'compose-gutenberg-block-plan applies an overlong heading repair' );
npcink_abilities_toolkit_assert_true( in_array( 'fill_missing_media_alt', $composer_repair_codes, true ), 'compose-gutenberg-block-plan applies a missing media alt repair' );
npcink_abilities_toolkit_assert_same( true, $composer_repair_loop['data']['final_review']['ready_for_proposal'] ?? null, 'compose-gutenberg-block-plan marks repaired page plans proposal eligible' );
npcink_abilities_toolkit_assert_same( true, $composer_repair_loop['data']['proposal_allowed'] ?? null, 'compose-gutenberg-block-plan allows proposal handoff only after the final gate passes' );
$composer_repaired_plan = is_array( $composer_repair_loop['data']['plan'] ?? null ) ? $composer_repair_loop['data']['plan'] : array();
npcink_abilities_toolkit_assert_same( 'pattern_page_plan', $composer_repaired_plan['artifact_type'] ?? '', 'compose-gutenberg-block-plan exposes the repaired page plan as the proposal candidate' );

$composer_missing_media_repair = $core_read_package->compose_gutenberg_block_plan(
	array(
		'prompt'      => '写一篇介绍 Gutenberg 模块红利的文章草稿，需要配图和 FAQ。',
		'target_hint' => 'post',
		'plan_input'  => array(
			'title'            => 'Composer Missing Media Article',
			'article_template' => 'comparison-review',
			'media_strategy'   => 'existing_media_url',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $composer_missing_media_repair['success'] ?? null, 'compose-gutenberg-block-plan returns a success envelope for missing media article requests' );
npcink_abilities_toolkit_assert_true( in_array( 'missing_reviewed_media', $composer_missing_media_repair['data']['initial_review']['finding_codes'] ?? array(), true ), 'compose-gutenberg-block-plan reports missing reviewed media before repair' );
$composer_missing_media_repair_codes = array_map(
	static function ( $repair ) {
		return is_array( $repair ) ? (string) ( $repair['repair_code'] ?? '' ) : '';
	},
	is_array( $composer_missing_media_repair['data']['applied_repairs'] ?? null ) ? $composer_missing_media_repair['data']['applied_repairs'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'fallback_to_no_media_article_structure', $composer_missing_media_repair_codes, true ), 'compose-gutenberg-block-plan applies a missing article media fallback repair' );
npcink_abilities_toolkit_assert_same( true, $composer_missing_media_repair['data']['proposal_allowed'] ?? null, 'compose-gutenberg-block-plan marks repaired missing-media article plans proposal eligible' );

$composer_product_docs = $core_read_package->compose_gutenberg_block_plan(
	array(
		'prompt'              => '写一篇教程，说明如何用 Gutenberg 块组织一篇长文。',
		'composer_profile_id' => 'product_docs',
	)
);
npcink_abilities_toolkit_assert_same( true, $composer_product_docs['success'] ?? null, 'compose-gutenberg-block-plan accepts explicit product docs composer profiles' );
npcink_abilities_toolkit_assert_same( 'product_docs', $composer_product_docs['data']['composer_profile_id'] ?? '', 'compose-gutenberg-block-plan preserves compatible explicit composer profile ids' );
npcink_abilities_toolkit_assert_same( 'how-to-guide', $composer_product_docs['data']['plan']['article_template'] ?? '', 'product docs composer profile selects the how-to article template by default' );
npcink_abilities_toolkit_assert_same( true, $composer_product_docs['data']['proposal_allowed'] ?? null, 'product docs composer profile output is proposal eligible after the final gate' );

$composer_pilot_prompts = array(
	'帮我做一个现代官网介绍页，需要配图，手机端也要好看。',
	'做一个 SaaS 产品首页，突出核心能力、客户价值和移动端体验。',
	'帮我做一个有色彩强调的 editorial-accent 官网落地页。',
	'创建一个服务介绍页面，先不要配图，但要有清楚的功能、FAQ 和 CTA。',
	'帮我搭一个 WordPress 插件功能介绍页面，结构清楚，适合客户浏览。',
	'给产品做一个移动端优先的首页，标题不要挤，内容要能扫读。',
	'写一篇介绍 Gutenberg 模块红利的文章草稿，需要配图和 FAQ。',
	'写一篇对比评测文章，说明普通 AI 直接写入和 proposal-first 的区别。',
	'写一篇博客文章，解释内容编辑为什么要经过 proposal 审核。',
	'写一篇教程，说明如何用 Gutenberg 块组织一篇长文。',
	'写一篇产品文档文章，介绍 OpenClaw 如何先生成 proposal 再执行。',
	'帮我写一篇博客长文，主题是 WordPress 内容治理。',
);
$composer_pilot_passed = 0;
foreach ( $composer_pilot_prompts as $composer_pilot_prompt ) {
	$composer_pilot_result = $core_read_package->compose_gutenberg_block_plan(
		array(
			'prompt'        => $composer_pilot_prompt,
			'media_fixture' => array(
				'url'           => 'https://magick-ai.local/wp-content/uploads/2026/06/pilot-hero.jpg',
				'attachment_id' => 8053,
				'alt'           => 'OpenClaw Gutenberg pilot visual',
			),
		)
	);
	if ( true === ( $composer_pilot_result['success'] ?? null ) && true === ( $composer_pilot_result['data']['proposal_allowed'] ?? null ) && false === ( $composer_pilot_result['data']['proposal_created'] ?? true ) ) {
		++$composer_pilot_passed;
	}
}
npcink_abilities_toolkit_assert_same( 12, $composer_pilot_passed, 'compose-gutenberg-block-plan passes a twelve-task natural-language pilot without creating proposals' );

$pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'WordPress AI',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'section_variant_hints' => array(
			'comparison' => 'center-title-two-cards',
		),
		'variables'          => array(
			'eyebrow'          => 'WordPress AI Plugin',
			'hero_title'       => '把 AI 工作流带进 WordPress 内容现场',
			'hero_description' => '让内容生产、SEO 优化、媒体处理与发布协作在同一个可审计流程中完成。',
			'primary_cta'      => '查看工作流',
			'secondary_cta'    => '了解能力',
			'hero_media_url'   => 'https://magick-ai.local/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg',
			'hero_media_attachment_id' => 8053,
			'hero_media_alt'   => 'WordPress AI dashboard preview',
			'features'         => array(
				array(
					'title'       => 'AI 内容草稿',
					'description' => '从主题和上下文生成结构化草稿。',
				),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['success'] ?? null, 'build-pattern-page-plan returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'pattern_page_plan', $pattern_page_plan['data']['artifact_type'] ?? '', 'build-pattern-page-plan declares a pattern page artifact type' );
npcink_abilities_toolkit_assert_same( 'openai-style-landing', $pattern_page_plan['data']['pattern_id'] ?? '', 'build-pattern-page-plan preserves the selected pattern id' );
npcink_abilities_toolkit_assert_same( 'minimal-dark-light', $pattern_page_plan['data']['style_preset'] ?? '', 'build-pattern-page-plan preserves the selected style preset' );
npcink_abilities_toolkit_assert_same( 'minimal-dark-light', $pattern_page_plan['data']['color_story'] ?? '', 'build-pattern-page-plan preserves the default color story' );
npcink_abilities_toolkit_assert_same( 'landing_standard', $pattern_page_plan['data']['responsive_profile'] ?? '', 'build-pattern-page-plan preserves the responsive profile' );
npcink_abilities_toolkit_assert_same( 'balanced', $pattern_page_plan['data']['visual_density'] ?? '', 'build-pattern-page-plan preserves visual density' );
npcink_abilities_toolkit_assert_same( 'existing_media_url', $pattern_page_plan['data']['media_strategy'] ?? '', 'build-pattern-page-plan preserves media strategy' );
npcink_abilities_toolkit_assert_same( 'post_content', $pattern_page_plan['data']['block_editor_surface']['surface_kind'] ?? '', 'build-pattern-page-plan declares a post content block-editor surface' );
npcink_abilities_toolkit_assert_same( 'block_editor', $pattern_page_plan['data']['block_editor_surface']['editor'] ?? '', 'build-pattern-page-plan declares the block editor surface owner' );
npcink_abilities_toolkit_assert_same( 'page', $pattern_page_plan['data']['block_editor_surface']['post_type'] ?? '', 'build-pattern-page-plan declares the page post type surface' );
npcink_abilities_toolkit_assert_same( 'create_draft', $pattern_page_plan['data']['block_editor_surface']['target_mode'] ?? '', 'build-pattern-page-plan reports create draft surface mode by default' );
npcink_abilities_toolkit_assert_same( 'center-title-two-cards', $pattern_page_plan['data']['section_variant_hints']['comparison'] ?? '', 'build-pattern-page-plan preserves bounded section variant hints' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['direct_wordpress_write'] ?? null, 'build-pattern-page-plan does not directly write WordPress' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['commit_execution'] ?? null, 'build-pattern-page-plan keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-pattern-page-plan', $pattern_page_plan['data']['handoff']['plan_ability_id'] ?? '', 'build-pattern-page-plan identifies itself for Core from-plan intake' );
npcink_abilities_toolkit_assert_same( 'openclaw_recipes.ai_image_ratio_crop_media_adoption', $pattern_page_plan['data']['handoff']['media_recipe_ref'] ?? '', 'build-pattern-page-plan points media handoff at the AI image crop adoption recipe' );
$pattern_media_slots = is_array( $pattern_page_plan['data']['media_slots'] ?? null ) ? $pattern_page_plan['data']['media_slots'] : array();
npcink_abilities_toolkit_assert_same( 'hero_media', $pattern_media_slots[0]['id'] ?? '', 'build-pattern-page-plan declares a hero media slot' );
npcink_abilities_toolkit_assert_same( 'hero_media_url', $pattern_media_slots[0]['variable'] ?? '', 'build-pattern-page-plan maps hero media slot to hero_media_url variable' );
npcink_abilities_toolkit_assert_same( '16:9', $pattern_media_slots[0]['target_aspect_ratio'] ?? '', 'build-pattern-page-plan declares hero media target aspect ratio' );
npcink_abilities_toolkit_assert_same( 'aspect_ratio', $pattern_media_slots[0]['crop']['type'] ?? '', 'build-pattern-page-plan declares bounded crop type for hero media' );
npcink_abilities_toolkit_assert_same( '16:9', $pattern_media_slots[0]['crop']['aspect_ratio'] ?? '', 'build-pattern-page-plan carries hero aspect ratio into crop guidance' );
npcink_abilities_toolkit_assert_same( 'ai_image_ratio_crop_media_adoption', $pattern_media_slots[0]['recommended_recipe_id'] ?? '', 'build-pattern-page-plan recommends the AI image crop adoption recipe for hero media' );
npcink_abilities_toolkit_assert_same( 'https://magick-ai.local/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg', $pattern_media_slots[0]['existing_media_url'] ?? '', 'build-pattern-page-plan echoes reviewed existing hero media URL in media slot metadata' );
npcink_abilities_toolkit_assert_same( 'hero_media_attachment_id', $pattern_media_slots[0]['attachment_id_variable'] ?? '', 'build-pattern-page-plan declares the hero media attachment id variable' );
npcink_abilities_toolkit_assert_same( 8053, $pattern_media_slots[0]['existing_media_attachment_id'] ?? 0, 'build-pattern-page-plan echoes reviewed hero media attachment id in media slot metadata' );
npcink_abilities_toolkit_assert_same( true, $pattern_media_slots[0]['media_input_valid'] ?? null, 'build-pattern-page-plan marks reviewed media URLs valid' );
npcink_abilities_toolkit_assert_same( true, $pattern_media_slots[0]['media_input_has_attachment_id'] ?? null, 'build-pattern-page-plan marks reviewed media attachment ids present' );
npcink_abilities_toolkit_assert_same( '3.0', $pattern_page_plan['data']['design_quality']['pattern_version'] ?? '', 'build-pattern-page-plan reports the v3 Pattern quality version' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $pattern_page_plan['data']['design_quality']['design_system'] ?? '', 'build-pattern-page-plan reports the Gutenberg-native design system contract' );
npcink_abilities_toolkit_assert_same( 'media_first_saas_landing', $pattern_page_plan['data']['design_quality']['recipe_variant'] ?? '', 'build-pattern-page-plan reports a media-first recipe variant when reviewed hero media is supplied' );
npcink_abilities_toolkit_assert_true( '' !== (string) ( $pattern_page_plan['data']['design_quality']['variant_reason'] ?? '' ), 'build-pattern-page-plan explains why the recipe variant was selected' );
npcink_abilities_toolkit_assert_same( 'gutenberg_native', $pattern_page_plan['data']['design_quality']['style_strategy'] ?? '', 'build-pattern-page-plan reports Gutenberg-native style strategy' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['uses_native_styles'] ?? null, 'build-pattern-page-plan reports native style usage' );
npcink_abilities_toolkit_assert_same( 'minimal-dark-light', $pattern_page_plan['data']['design_quality']['color_story'] ?? '', 'build-pattern-page-plan reports the native color story in design quality' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['design_quality']['has_editorial_accent'] ?? true, 'build-pattern-page-plan does not report editorial accents for the default monochrome story' );
npcink_abilities_toolkit_assert_true( (int) ( $pattern_page_plan['data']['design_quality']['section_shape_variety'] ?? 0 ) >= 4, 'build-pattern-page-plan reports enough section shape variety for landing pages' );
npcink_abilities_toolkit_assert_true( (float) ( $pattern_page_plan['data']['design_quality']['media_coverage_score'] ?? 0 ) >= 0.6, 'build-pattern-page-plan reports media coverage for modern landing pages' );
npcink_abilities_toolkit_assert_true( (float) ( $pattern_page_plan['data']['design_quality']['template_similarity_score'] ?? 1 ) <= 0.75, 'build-pattern-page-plan reports bounded template similarity risk' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['design_quality']['uses_core_html'] ?? true, 'build-pattern-page-plan reports no core/html usage' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['design_quality']['uses_non_core_blocks'] ?? true, 'build-pattern-page-plan reports no non-core block usage' );
npcink_abilities_toolkit_assert_same( 8, $pattern_page_plan['data']['design_quality']['section_count'] ?? 0, 'build-pattern-page-plan reports eight v2 sections when media is supplied' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_split_hero'] ?? null, 'build-pattern-page-plan reports split hero' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['design_quality']['has_dashboard_mock'] ?? null, 'build-pattern-page-plan does not report dashboard mock when reviewed hero media is supplied' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_hero_media'] ?? null, 'build-pattern-page-plan reports reviewed hero media in the split hero' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_hero_media_attachment_id'] ?? null, 'build-pattern-page-plan reports reviewed hero media attachment binding' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_proof_strip'] ?? null, 'build-pattern-page-plan reports proof strip' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_bento_grid'] ?? null, 'build-pattern-page-plan reports the Gutenberg-native Bento feature grid' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_media_text'] ?? null, 'build-pattern-page-plan reports media-text section' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_comparison_section'] ?? null, 'build-pattern-page-plan reports the default proposal-first comparison section' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_faq'] ?? null, 'build-pattern-page-plan reports FAQ section' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['design_quality']['has_final_cta'] ?? null, 'build-pattern-page-plan reports final CTA' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['design_quality']['custom_css_required'] ?? true, 'build-pattern-page-plan reports no custom CSS requirement' );
npcink_abilities_toolkit_assert_same( 'landing_standard', $pattern_page_plan['data']['responsive_quality']['responsive_profile'] ?? '', 'build-pattern-page-plan reports responsive profile quality' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['responsive_quality']['uses_mobile_stack'] ?? null, 'build-pattern-page-plan reports mobile column stacking' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['responsive_quality']['uses_core_responsive_blocks'] ?? null, 'build-pattern-page-plan reports core responsive blocks' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['responsive_quality']['has_media_section'] ?? null, 'build-pattern-page-plan reports media section responsiveness' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['responsive_quality']['has_faq'] ?? null, 'build-pattern-page-plan reports responsive FAQ' );
npcink_abilities_toolkit_assert_same( 4, $pattern_page_plan['data']['responsive_quality']['max_columns_per_row'] ?? 0, 'build-pattern-page-plan reports bounded max columns per row' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['responsive_quality']['button_groups_use_flex_layout'] ?? null, 'build-pattern-page-plan reports flex button groups' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['responsive_quality']['custom_css_required'] ?? true, 'build-pattern-page-plan reports responsive output without custom CSS' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['quality_feedback']['feedback_received'] ?? true, 'build-pattern-page-plan reports no review feedback on first-generation plans' );
	npcink_abilities_toolkit_assert_same( 'pass', $pattern_page_plan['data']['quality_review']['review_status'] ?? '', 'build-pattern-page-plan self-reviews generated Pattern blocks' );
	npcink_abilities_toolkit_assert_true( (int) ( $pattern_page_plan['data']['quality_review']['score'] ?? 0 ) >= 80, 'build-pattern-page-plan self-review scores generated Pattern blocks above threshold' );
	npcink_abilities_toolkit_assert_same( 'gutenberg_native_v1', $pattern_page_plan['data']['composition_contract']['catalog_id'] ?? '', 'build-pattern-page-plan references the Gutenberg block capability catalog' );
	npcink_abilities_toolkit_assert_same( 'bounded_block_composition', $pattern_page_plan['data']['composition_contract']['composition_model'] ?? '', 'build-pattern-page-plan uses bounded block composition' );
	npcink_abilities_toolkit_assert_same( 'pass', $pattern_page_plan['data']['composition_contract']['contract_status'] ?? '', 'build-pattern-page-plan passes the block composition contract' );
	npcink_abilities_toolkit_assert_same( array(), $pattern_page_plan['data']['composition_contract']['forbidden_block_names'] ?? array( 'unexpected' ), 'build-pattern-page-plan reports no forbidden block contract violations' );
	npcink_abilities_toolkit_assert_true( in_array( 'core/media-text', $pattern_page_plan['data']['composition_contract']['used_block_names'] ?? array(), true ), 'build-pattern-page-plan contract records media-text usage' );
	npcink_abilities_toolkit_assert_same( 'gutenberg_native_block_composer_v1', $pattern_page_plan['data']['composition_contract']['composer_instruction']['instruction_id'] ?? '', 'build-pattern-page-plan exposes AI composer instructions through the contract' );
	npcink_abilities_toolkit_assert_same( 'landing_design', $pattern_page_plan['data']['block_editor_quality_gate']['profile'] ?? '', 'build-pattern-page-plan uses the full landing design quality gate' );
	npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['block_editor_quality_gate']['ready_for_proposal'] ?? null, 'build-pattern-page-plan marks high-quality blocks ready through the block-editor quality gate' );
	npcink_abilities_toolkit_assert_same( false, $pattern_page_plan['data']['block_editor_quality_gate']['commit_execution'] ?? null, 'build-pattern-page-plan quality gate does not execute commits' );
	npcink_abilities_toolkit_assert_same( 8, $pattern_page_plan['data']['quality_review']['layout_fingerprint']['section_count'] ?? 0, 'build-pattern-page-plan self-review includes a layout fingerprint' );
npcink_abilities_toolkit_assert_true( in_array( 'center', $pattern_page_plan['data']['quality_review']['layout_fingerprint']['alignment_mix'] ?? array(), true ), 'build-pattern-page-plan self-review sees centered section alignment' );
$pattern_self_review_finding_codes = array_map(
	static function ( $finding ) {
		return is_array( $finding ) ? (string) ( $finding['code'] ?? '' ) : '';
	},
	is_array( $pattern_page_plan['data']['quality_review']['visual_quality_findings'] ?? null ) ? $pattern_page_plan['data']['quality_review']['visual_quality_findings'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'color_story_monochrome', $pattern_self_review_finding_codes, true ), 'build-pattern-page-plan self-review flags monochrome native color stories as a non-blocking visual finding' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_plan['data']['revision_strategy']['ready_for_proposal'] ?? null, 'build-pattern-page-plan marks high-quality generated plans ready for Core proposal handoff' );
npcink_abilities_toolkit_assert_same( 'submit_core_proposal', $pattern_page_plan['data']['revision_strategy']['recommended_next_step'] ?? '', 'build-pattern-page-plan recommends Core proposal handoff when self-review passes' );
$pattern_page_actions = is_array( $pattern_page_plan['data']['write_actions'] ?? null ) ? $pattern_page_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 2, count( $pattern_page_actions ), 'build-pattern-page-plan emits create and block update actions' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/create-draft', $pattern_page_actions[0]['target_ability_id'] ?? '', 'build-pattern-page-plan first creates a draft page' );
npcink_abilities_toolkit_assert_same( 'page', $pattern_page_actions[0]['input']['post_type'] ?? '', 'build-pattern-page-plan create action targets a page' );
npcink_abilities_toolkit_assert_same( 'draft', $pattern_page_actions[0]['input']['status'] ?? '', 'build-pattern-page-plan create action stays draft-only' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post-blocks', $pattern_page_actions[1]['target_ability_id'] ?? '', 'build-pattern-page-plan second action updates Gutenberg blocks' );
npcink_abilities_toolkit_assert_same( '$outputs.create-pattern-page.post_id', $pattern_page_actions[1]['input']['post_id'] ?? '', 'build-pattern-page-plan uses exact output reference for the new page id' );
$pattern_blocks = is_array( $pattern_page_actions[1]['input']['blocks'] ?? null ) ? $pattern_page_actions[1]['input']['blocks'] : array();
npcink_abilities_toolkit_assert_same( 'core/group', $pattern_blocks[0]['blockName'] ?? '', 'build-pattern-page-plan renders core group blocks' );
npcink_abilities_toolkit_assert_true( in_array( null, $pattern_blocks[0]['innerContent'] ?? array(), true ), 'build-pattern-page-plan group blocks include innerContent null markers' );
npcink_abilities_toolkit_assert_true( in_array( 'npcink-ai-hero', $pattern_page_plan['data']['allowed_classes'] ?? array(), true ), 'build-pattern-page-plan exposes a class whitelist' );
npcink_abilities_toolkit_assert_true( in_array( 'npcink-ai-feature-bento', $pattern_page_plan['data']['allowed_classes'] ?? array(), true ), 'build-pattern-page-plan exposes the Bento feature class handle' );
npcink_abilities_toolkit_assert_true( in_array( 'npcink-ai-feature-spotlight', $pattern_page_plan['data']['allowed_classes'] ?? array(), true ), 'build-pattern-page-plan exposes the Bento spotlight class handle' );
npcink_abilities_toolkit_assert_true( in_array( 'npcink-ai-visual-delta', $pattern_page_plan['data']['allowed_classes'] ?? array(), true ), 'build-pattern-page-plan exposes the visual-delta class handle for review revisions' );
npcink_abilities_toolkit_assert_same( 'npcink-ai-page npcink-ai-hero', $pattern_blocks[0]['attrs']['className'] ?? '', 'build-pattern-page-plan applies only whitelisted page classes' );
npcink_abilities_toolkit_assert_same( 'full', $pattern_blocks[0]['attrs']['align'] ?? '', 'build-pattern-page-plan uses full-width Gutenberg sections' );
npcink_abilities_toolkit_assert_same( 'constrained', $pattern_blocks[0]['attrs']['layout']['type'] ?? '', 'build-pattern-page-plan uses constrained section layout' );
npcink_abilities_toolkit_assert_same( '1120px', $pattern_blocks[0]['attrs']['layout']['contentSize'] ?? '', 'build-pattern-page-plan sets native content width' );
npcink_abilities_toolkit_assert_same( '96px', $pattern_blocks[0]['attrs']['style']['spacing']['padding']['top'] ?? '', 'build-pattern-page-plan sets native hero spacing' );
npcink_abilities_toolkit_assert_same( '#f7f7f4', $pattern_blocks[0]['attrs']['style']['color']['background'] ?? '', 'build-pattern-page-plan sets native section background' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $pattern_blocks[0]['innerHTML'] ?? '' ), 'has-background' ), 'build-pattern-page-plan emits Gutenberg background support class for hero group' );
$pattern_hero_layout = is_array( $pattern_blocks[0]['innerBlocks'][0] ?? null ) ? $pattern_blocks[0]['innerBlocks'][0] : array();
npcink_abilities_toolkit_assert_same( 'core/columns', $pattern_hero_layout['blockName'] ?? '', 'build-pattern-page-plan uses a split hero columns block' );
npcink_abilities_toolkit_assert_same( 'npcink-ai-hero-layout', $pattern_hero_layout['attrs']['className'] ?? '', 'build-pattern-page-plan marks the split hero layout' );
$pattern_hero_visual = is_array( $pattern_hero_layout['innerBlocks'][1]['innerBlocks'][0] ?? null ) ? $pattern_hero_layout['innerBlocks'][1]['innerBlocks'][0] : array();
npcink_abilities_toolkit_assert_same( 'core/group', $pattern_hero_visual['blockName'] ?? '', 'build-pattern-page-plan wraps reviewed hero media in a native group panel' );
npcink_abilities_toolkit_assert_same( 'npcink-ai-dashboard-card npcink-ai-hero-media-card', $pattern_hero_visual['attrs']['className'] ?? '', 'build-pattern-page-plan marks the hero media panel with whitelisted classes' );
npcink_abilities_toolkit_assert_same( 'core/image', $pattern_hero_visual['innerBlocks'][0]['blockName'] ?? '', 'build-pattern-page-plan places reviewed media in the hero visual column' );
npcink_abilities_toolkit_assert_same( 'https://magick-ai.local/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg', $pattern_hero_visual['innerBlocks'][0]['attrs']['url'] ?? '', 'build-pattern-page-plan uses the reviewed hero media URL in the hero image block' );
npcink_abilities_toolkit_assert_same( 8053, $pattern_hero_visual['innerBlocks'][0]['attrs']['id'] ?? 0, 'build-pattern-page-plan binds the reviewed hero image block to the attachment id' );
$pattern_hero_copy = is_array( $pattern_hero_layout['innerBlocks'][0]['innerBlocks'] ?? null ) ? $pattern_hero_layout['innerBlocks'][0]['innerBlocks'] : array();
npcink_abilities_toolkit_assert_same( '56px', $pattern_hero_copy[1]['attrs']['style']['typography']['fontSize'] ?? '', 'build-pattern-page-plan sets native hero title typography' );
npcink_abilities_toolkit_assert_same( '1.08', $pattern_hero_copy[1]['attrs']['style']['typography']['lineHeight'] ?? '', 'build-pattern-page-plan sets native hero title line height' );
$pattern_buttons = is_array( $pattern_hero_copy[3]['innerBlocks'] ?? null ) ? $pattern_hero_copy[3]['innerBlocks'] : array();
npcink_abilities_toolkit_assert_same( '999px', $pattern_buttons[0]['attrs']['style']['border']['radius'] ?? '', 'build-pattern-page-plan sets native button radius' );
npcink_abilities_toolkit_assert_same( '#111111', $pattern_buttons[0]['attrs']['style']['color']['background'] ?? '', 'build-pattern-page-plan sets native primary button color' );
$pattern_markup = wp_json_encode( $pattern_blocks );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'font-size:56px;font-weight:500;letter-spacing:0;line-height:1.08' ), 'build-pattern-page-plan serializes heading typography in Gutenberg save order' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'wp-block-button__link has-text-color has-background wp-element-button' ), 'build-pattern-page-plan emits Gutenberg support classes for primary button links' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"blockName":"core\\/columns"' ), 'build-pattern-page-plan uses native columns for feature and workflow sections' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"isStackedOnMobile":true' ), 'build-pattern-page-plan stacks columns on mobile' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"blockName":"core\\/media-text"' ), 'build-pattern-page-plan uses media-text when existing media is supplied' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"mediaId":8053' ), 'build-pattern-page-plan binds media-text to the reviewed attachment id' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"blockName":"core\\/image"' ), 'build-pattern-page-plan uses core image for hero media when supplied' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"id":8053' ), 'build-pattern-page-plan serializes image attachment id attrs' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'class=\"wp-image-8053\"' ), 'build-pattern-page-plan serializes wp-image attachment classes' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"blockName":"core\\/details"' ), 'build-pattern-page-plan uses details blocks for FAQ' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-dashboard-card' ), 'build-pattern-page-plan includes a styled hero visual card' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false === strpos( $pattern_markup, 'npcink-ai-dashboard-mock' ), 'build-pattern-page-plan omits the dashboard mock when reviewed hero media is supplied' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-hero-media-card' ), 'build-pattern-page-plan includes a hero media card class handle' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-proof-strip' ), 'build-pattern-page-plan includes a proof strip' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-media-text' ), 'build-pattern-page-plan includes a media-text class handle' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-feature-bento' ), 'build-pattern-page-plan includes a Gutenberg-native Bento feature layout' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-feature-spotlight' ), 'build-pattern-page-plan includes a dark spotlight feature card' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'border-color:#111111;border-width:1px;border-radius:24px;background-color:#111111;color:#ffffff' ), 'build-pattern-page-plan serializes the spotlight card with native color and border styles' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-comparison' ), 'build-pattern-page-plan includes a default proposal-first comparison section' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-comparison-card' ), 'build-pattern-page-plan includes comparison cards' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-section-title-center' ), 'build-pattern-page-plan uses the centered comparison title variant by default' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'has-text-align-center' ), 'build-pattern-page-plan serializes centered heading classes for Gutenberg validation' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'background-color:#111111;color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px' ), 'build-pattern-page-plan renders the comparison section as a native dark contrast band' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'OpenClaw proposal-first' ), 'build-pattern-page-plan includes the proposal-first comparison side' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-faq-item' ), 'build-pattern-page-plan includes FAQ item class handles' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'npcink-ai-final-cta' ), 'build-pattern-page-plan includes a final CTA' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'wp-block-heading has-text-align-center has-text-color npcink-ai-section-title npcink-ai-section-title-light' ), 'build-pattern-page-plan centers the final CTA heading and emits Gutenberg text color classes' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'has-text-align-center has-text-color npcink-ai-lede' ), 'build-pattern-page-plan centers the final CTA description and emits Gutenberg text color classes' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'background-color:#111111;color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:96px;padding-left:40px' ), 'build-pattern-page-plan makes the dark final CTA text color explicit' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'border-radius:999px;background-color:#ffffff;color:#111111;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ), 'build-pattern-page-plan renders a visible primary CTA button on dark bands' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, 'border-color:#ffffff;border-width:1px;border-radius:999px;background-color:#111111;color:#ffffff;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ), 'build-pattern-page-plan renders a matching secondary CTA button on dark bands' );
npcink_abilities_toolkit_assert_true( is_string( $pattern_markup ) && false !== strpos( $pattern_markup, '"top":{"color":"#111111","width":"1px"}' ), 'build-pattern-page-plan uses native top-line card border attrs' );
$long_hero_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Long Hero Copy Fit',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'variables'          => array(
			'hero_title'       => '用 Gutenberg 原生块搭出现代官网介绍页，长标题也保持舒展清晰',
			'hero_media_url'   => 'https://magick-ai.local/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg',
			'hero_media_attachment_id' => 8053,
			'hero_media_alt'   => 'WordPress AI dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $long_hero_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts imprecise long natural-language hero titles' );
npcink_abilities_toolkit_assert_same( 'revised', $long_hero_pattern_page_plan['data']['copy_quality']['hero_title_fit'] ?? '', 'build-pattern-page-plan revises overlong hero titles before rendering blocks' );
npcink_abilities_toolkit_assert_same( '用 Gutenberg 原生块搭出现代官网', $long_hero_pattern_page_plan['data']['copy_quality']['hero_title'] ?? '', 'build-pattern-page-plan compacts long Chinese hero titles into a display-safe H1' );
npcink_abilities_toolkit_assert_same( true, $long_hero_pattern_page_plan['data']['copy_quality']['hero_title_changed'] ?? null, 'build-pattern-page-plan reports hero title copy changes' );
npcink_abilities_toolkit_assert_true( (int) ( $long_hero_pattern_page_plan['data']['copy_quality']['hero_title_display_units'] ?? 99 ) <= 34, 'build-pattern-page-plan keeps revised hero titles within the display budget' );
$long_hero_actions = is_array( $long_hero_pattern_page_plan['data']['write_actions'] ?? null ) ? $long_hero_pattern_page_plan['data']['write_actions'] : array();
$long_hero_blocks = is_array( $long_hero_actions[1]['input']['blocks'] ?? null ) ? $long_hero_actions[1]['input']['blocks'] : array();
$long_hero_layout = is_array( $long_hero_blocks[0]['innerBlocks'][0] ?? null ) ? $long_hero_blocks[0]['innerBlocks'][0] : array();
$long_hero_copy = is_array( $long_hero_layout['innerBlocks'][0]['innerBlocks'] ?? null ) ? $long_hero_layout['innerBlocks'][0]['innerBlocks'] : array();
$long_hero_heading_html = (string) ( $long_hero_copy[1]['innerHTML'] ?? '' );
npcink_abilities_toolkit_assert_true( false !== strpos( $long_hero_heading_html, '用 Gutenberg 原生块搭出现代官网</h1>' ), 'build-pattern-page-plan writes the fitted Hero title into Gutenberg blocks' );
npcink_abilities_toolkit_assert_true( false === strpos( $long_hero_heading_html, '长标题也保持舒展清晰</h1>' ), 'build-pattern-page-plan does not serialize prompt-like long clauses into the Hero H1' );
$inferred_title_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'variables'          => array(
			'hero_title'       => '用 Gutenberg 原生块搭出现代官网介绍页，长标题也保持舒展清晰',
			'hero_media_url'   => 'https://magick-ai.local/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg',
			'hero_media_attachment_id' => 8053,
			'hero_media_alt'   => 'WordPress AI dashboard preview',
		),
	)
);
$inferred_title_actions = is_array( $inferred_title_pattern_page_plan['data']['write_actions'] ?? null ) ? $inferred_title_pattern_page_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( true, $inferred_title_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts natural-language page requests without a top-level title' );
npcink_abilities_toolkit_assert_same( '用 Gutenberg 原生块搭出现代官网', $inferred_title_pattern_page_plan['data']['target_post']['title'] ?? '', 'build-pattern-page-plan infers the draft title from the fitted Hero title' );
npcink_abilities_toolkit_assert_same( '用 Gutenberg 原生块搭出现代官网', $inferred_title_actions[0]['input']['title'] ?? '', 'build-pattern-page-plan writes the inferred title into the create-draft action' );
npcink_abilities_toolkit_assert_same( 'revised', $inferred_title_pattern_page_plan['data']['copy_quality']['hero_title_fit'] ?? '', 'build-pattern-page-plan still reports copy fit when title is inferred' );
$accent_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Accent WordPress AI',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'color_story'        => 'editorial-accent',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'section_variant_hints' => array(
			'comparison' => 'center-title-two-cards',
		),
		'variables'          => array(
			'hero_title'     => 'Accent Gutenberg landing page',
			'hero_media_url' => 'https://magick-ai.local/wp-content/uploads/2026/06/accent-dashboard.jpg',
			'hero_media_alt' => 'Accent WordPress AI dashboard preview',
		),
	)
);
$accent_actions = is_array( $accent_pattern_page_plan['data']['write_actions'] ?? null ) ? $accent_pattern_page_plan['data']['write_actions'] : array();
$accent_blocks = is_array( $accent_actions[1]['input']['blocks'] ?? null ) ? $accent_actions[1]['input']['blocks'] : array();
$accent_markup = wp_json_encode( $accent_blocks );
npcink_abilities_toolkit_assert_same( true, $accent_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts the editorial accent color story' );
npcink_abilities_toolkit_assert_same( 'editorial-accent', $accent_pattern_page_plan['data']['color_story'] ?? '', 'build-pattern-page-plan preserves the editorial accent color story' );
npcink_abilities_toolkit_assert_same( 'editorial-accent', $accent_pattern_page_plan['data']['design_quality']['color_story'] ?? '', 'build-pattern-page-plan reports the editorial accent story in design quality' );
npcink_abilities_toolkit_assert_same( true, $accent_pattern_page_plan['data']['design_quality']['has_editorial_accent'] ?? null, 'build-pattern-page-plan marks editorial accent pages as visually accented' );
npcink_abilities_toolkit_assert_true( (int) ( $accent_pattern_page_plan['data']['design_quality']['accent_surface_count'] ?? 0 ) >= 4, 'build-pattern-page-plan counts accent surfaces in design quality' );
npcink_abilities_toolkit_assert_same( '#f4f8f7', $accent_blocks[0]['attrs']['style']['color']['background'] ?? '', 'build-pattern-page-plan applies the accent hero background natively' );
$accent_hero_layout = is_array( $accent_blocks[0]['innerBlocks'][0] ?? null ) ? $accent_blocks[0]['innerBlocks'][0] : array();
$accent_hero_copy = is_array( $accent_hero_layout['innerBlocks'][0]['innerBlocks'] ?? null ) ? $accent_hero_layout['innerBlocks'][0]['innerBlocks'] : array();
$accent_buttons = is_array( $accent_hero_copy[3]['innerBlocks'] ?? null ) ? $accent_hero_copy[3]['innerBlocks'] : array();
npcink_abilities_toolkit_assert_same( '#102b2d', $accent_buttons[0]['attrs']['style']['color']['background'] ?? '', 'build-pattern-page-plan uses the accent contrast color for the primary hero button' );
npcink_abilities_toolkit_assert_same( '#102b2d', $accent_buttons[1]['attrs']['style']['border']['color'] ?? '', 'build-pattern-page-plan uses the accent contrast color for the secondary hero button border' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'color:#2f6f68;font-size:13px' ), 'build-pattern-page-plan serializes the active accent color on eyebrow copy' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, '"top":{"color":"#2f6f68","width":"1px"}' ), 'build-pattern-page-plan applies the accent color to proof card top rules' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'border-color:#102b2d;border-width:1px;border-radius:24px;background-color:#102b2d;color:#ffffff' ), 'build-pattern-page-plan uses the accent contrast color for the feature spotlight card' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'border-color:#2f6f68;border-width:1px;border-radius:20px;background-color:#153f42;color:#ffffff' ), 'build-pattern-page-plan gives the accent comparison card a distinct native background' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'background-color:#dfeee9;padding-top:72px;padding-right:40px;padding-bottom:72px;padding-left:40px' ), 'build-pattern-page-plan applies the accent media section background natively' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'background-color:#102b2d;color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:96px;padding-left:40px' ), 'build-pattern-page-plan applies the accent dark final CTA background natively' );
npcink_abilities_toolkit_assert_true( is_string( $accent_markup ) && false !== strpos( $accent_markup, 'border-color:#ffffff;border-width:1px;border-radius:999px;background-color:#102b2d;color:#ffffff;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ), 'build-pattern-page-plan keeps the secondary CTA visible on accent dark bands' );
npcink_abilities_toolkit_assert_true( (int) ( $accent_pattern_page_plan['data']['quality_review']['layout_fingerprint']['accent_color_count'] ?? 0 ) >= 1, 'build-pattern-page-plan review fingerprint detects accent color usage' );
$accent_review_finding_codes = array_map(
	static function ( $finding ) {
		return is_array( $finding ) ? (string) ( $finding['code'] ?? '' ) : '';
	},
	is_array( $accent_pattern_page_plan['data']['quality_review']['visual_quality_findings'] ?? null ) ? $accent_pattern_page_plan['data']['quality_review']['visual_quality_findings'] : array()
);
npcink_abilities_toolkit_assert_true( ! in_array( 'color_story_monochrome', $accent_review_finding_codes, true ), 'build-pattern-page-plan does not flag the editorial accent story as monochrome' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280973] = (object) array(
	'ID'           => 280973,
	'post_type'    => 'page',
	'post_status'  => 'draft',
	'post_title'   => 'Existing Pattern Draft',
	'post_content' => '<!-- wp:paragraph --><p>Existing draft body.</p><!-- /wp:paragraph -->',
);
$target_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Update Existing WordPress AI',
		'target_post_id'     => 280973,
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'color_story'        => 'editorial-accent',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'variables'          => array(
			'hero_title'     => 'Update an existing Gutenberg draft',
			'hero_media_url' => 'https://magick-ai.local/wp-content/uploads/2026/06/existing-target-dashboard.jpg',
			'hero_media_alt' => 'Existing target WordPress AI dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $target_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts an existing draft page target' );
npcink_abilities_toolkit_assert_same( 'update_existing', $target_pattern_page_plan['data']['target_post']['mode'] ?? '', 'build-pattern-page-plan reports existing draft update mode' );
npcink_abilities_toolkit_assert_same( 280973, $target_pattern_page_plan['data']['target_post']['post_id'] ?? 0, 'build-pattern-page-plan preserves the target draft id' );
npcink_abilities_toolkit_assert_same( 'draft', $target_pattern_page_plan['data']['target_post']['status'] ?? '', 'build-pattern-page-plan reports the target draft status' );
npcink_abilities_toolkit_assert_same( 'update_existing', $target_pattern_page_plan['data']['block_editor_surface']['target_mode'] ?? '', 'build-pattern-page-plan reports update surface mode for target drafts' );
npcink_abilities_toolkit_assert_same( 1, $target_pattern_page_plan['data']['summary']['action_count'] ?? 0, 'build-pattern-page-plan emits one action when updating an existing draft page' );
$target_pattern_actions = is_array( $target_pattern_page_plan['data']['write_actions'] ?? null ) ? $target_pattern_page_plan['data']['write_actions'] : array();
npcink_abilities_toolkit_assert_same( 1, count( $target_pattern_actions ), 'build-pattern-page-plan omits create-draft when target_post_id is supplied' );
npcink_abilities_toolkit_assert_same( 'update-pattern-page-blocks', $target_pattern_actions[0]['action_id'] ?? '', 'build-pattern-page-plan keeps the update action id for existing drafts' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/update-post-blocks', $target_pattern_actions[0]['target_ability_id'] ?? '', 'build-pattern-page-plan targets update-post-blocks for existing drafts' );
npcink_abilities_toolkit_assert_same( 280973, $target_pattern_actions[0]['input']['post_id'] ?? 0, 'build-pattern-page-plan uses the concrete target draft id' );
npcink_abilities_toolkit_assert_same( false, $target_pattern_actions[0]['input']['commit'] ?? true, 'build-pattern-page-plan keeps existing draft update actions non-committing' );
npcink_abilities_toolkit_assert_same( true, $target_pattern_actions[0]['input']['dry_run'] ?? false, 'build-pattern-page-plan keeps existing draft update actions dry-run by default' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280974] = (object) array(
	'ID'           => 280974,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Published Pattern Page',
	'post_content' => '<!-- wp:paragraph --><p>Published body.</p><!-- /wp:paragraph -->',
);
$published_target_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'          => 'Reject Published Target',
		'target_post_id' => 280974,
		'pattern_id'     => 'openai-style-landing',
	)
);
npcink_abilities_toolkit_assert_true( is_wp_error( $published_target_pattern_page_plan ) && 'npcink_abilities_toolkit_pattern_page_target_status_invalid' === $published_target_pattern_page_plan->get_error_code(), 'build-pattern-page-plan rejects published target pages for replacement proposals' );
$placeholder_media_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Placeholder Media Pattern',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'variables'          => array(
			'hero_title'     => 'Placeholder media should not render',
			'hero_media_url' => 'https://example.test/wp-content/uploads/2026/06/placeholder-dashboard.jpg',
			'hero_media_alt' => 'Placeholder dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $placeholder_media_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts placeholder media inputs for repair planning' );
$placeholder_media_slots = is_array( $placeholder_media_pattern_page_plan['data']['media_slots'] ?? null ) ? $placeholder_media_pattern_page_plan['data']['media_slots'] : array();
npcink_abilities_toolkit_assert_same( '', $placeholder_media_slots[0]['existing_media_url'] ?? 'unexpected', 'build-pattern-page-plan does not echo placeholder media URLs as reviewed media' );
npcink_abilities_toolkit_assert_same( false, $placeholder_media_slots[0]['media_input_valid'] ?? null, 'build-pattern-page-plan marks placeholder media URLs invalid' );
$placeholder_actions = is_array( $placeholder_media_pattern_page_plan['data']['write_actions'] ?? null ) ? $placeholder_media_pattern_page_plan['data']['write_actions'] : array();
$placeholder_blocks = is_array( $placeholder_actions[1]['input']['blocks'] ?? null ) ? $placeholder_actions[1]['input']['blocks'] : array();
$placeholder_markup = wp_json_encode( $placeholder_blocks );
npcink_abilities_toolkit_assert_same( true, $placeholder_media_pattern_page_plan['data']['design_quality']['has_dashboard_mock'] ?? null, 'build-pattern-page-plan falls back to the dashboard mock for placeholder media URLs' );
npcink_abilities_toolkit_assert_same( false, $placeholder_media_pattern_page_plan['data']['design_quality']['has_hero_media'] ?? null, 'build-pattern-page-plan does not report placeholder media as hero media' );
npcink_abilities_toolkit_assert_true( is_string( $placeholder_markup ) && false === strpos( $placeholder_markup, 'placeholder-dashboard.jpg' ), 'build-pattern-page-plan does not serialize placeholder media URLs into blocks' );
npcink_abilities_toolkit_assert_true( is_string( $placeholder_markup ) && false !== strpos( $placeholder_markup, 'npcink-ai-dashboard-mock' ), 'build-pattern-page-plan serializes a mock panel instead of a broken image' );
$left_variant_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Left Variant Pattern',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'section_variant_hints' => array(
			'comparison' => 'left-title-two-cards',
		),
		'variables'          => array(
			'hero_title'     => 'Left comparison variant',
			'hero_media_url' => 'https://magick-ai.local/wp-content/uploads/2026/06/left-variant-dashboard.jpg',
			'hero_media_alt' => 'Left comparison variant dashboard preview',
		),
	)
);
$left_variant_actions = is_array( $left_variant_pattern_page_plan['data']['write_actions'] ?? null ) ? $left_variant_pattern_page_plan['data']['write_actions'] : array();
$left_variant_blocks = is_array( $left_variant_actions[1]['input']['blocks'] ?? null ) ? $left_variant_actions[1]['input']['blocks'] : array();
$left_variant_markup = wp_json_encode( $left_variant_blocks );
npcink_abilities_toolkit_assert_same( true, $left_variant_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts the left comparison title variant' );
npcink_abilities_toolkit_assert_same( 'left-title-two-cards', $left_variant_pattern_page_plan['data']['section_variant_hints']['comparison'] ?? '', 'build-pattern-page-plan preserves the left comparison title variant' );
npcink_abilities_toolkit_assert_true( is_string( $left_variant_markup ) && false === strpos( $left_variant_markup, 'npcink-ai-section-title-center' ), 'build-pattern-page-plan omits centered title class for the left comparison variant' );
$pattern_page_review = $core_read_package->review_pattern_page(
	array(
		'blocks' => $pattern_blocks,
	)
);
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['success'] ?? null, 'review-pattern-page returns a success envelope for proposed blocks' );
npcink_abilities_toolkit_assert_same( 'pattern_page_review', $pattern_page_review['data']['artifact_type'] ?? '', 'review-pattern-page declares a pattern page review artifact' );
npcink_abilities_toolkit_assert_same( 'blocks_input', $pattern_page_review['data']['source'] ?? '', 'review-pattern-page can review blocks before they are written' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_review['data']['direct_wordpress_write'] ?? null, 'review-pattern-page does not write WordPress' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_review['data']['commit_execution'] ?? null, 'review-pattern-page keeps commit execution disabled' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['server_side_review_only'] ?? null, 'review-pattern-page identifies server-side review limits' );
npcink_abilities_toolkit_assert_same( 'pass', $pattern_page_review['data']['review_status'] ?? '', 'review-pattern-page passes the v3 native pattern blocks' );
npcink_abilities_toolkit_assert_true( (int) ( $pattern_page_review['data']['score'] ?? 0 ) >= 80, 'review-pattern-page scores the v3 native pattern above the pass threshold' );
npcink_abilities_toolkit_assert_same( 8, $pattern_page_review['data']['top_level_count'] ?? 0, 'review-pattern-page reports top-level sections' );
npcink_abilities_toolkit_assert_same( 101, $pattern_page_review['data']['block_count'] ?? 0, 'review-pattern-page reports recursive block count' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['design_quality']['has_bento_grid'] ?? null, 'review-pattern-page carries Bento design quality' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['design_quality']['has_hero_media'] ?? null, 'review-pattern-page carries hero media design quality' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['design_quality']['has_hero_media_attachment_id'] ?? null, 'review-pattern-page carries hero media attachment binding quality' );
npcink_abilities_toolkit_assert_true( (int) ( $pattern_page_review['data']['design_quality']['section_shape_variety'] ?? 0 ) >= 5, 'review-pattern-page reports section shape variety' );
npcink_abilities_toolkit_assert_true( (int) ( $pattern_page_review['data']['design_quality']['native_style_density'] ?? 0 ) >= 40, 'review-pattern-page reports native style density' );
npcink_abilities_toolkit_assert_same( 'low', $pattern_page_review['data']['responsive_quality']['responsive_risk_level'] ?? '', 'review-pattern-page reports low responsive risk for stacked native columns' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['media_quality']['image_alt_complete'] ?? null, 'review-pattern-page reports complete image alt text' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['media_quality']['has_hero_media_url'] ?? null, 'review-pattern-page reports local hero media URLs present' );
npcink_abilities_toolkit_assert_same( true, $pattern_page_review['data']['media_quality']['has_hero_media_attachment_id'] ?? null, 'review-pattern-page reports hero media attachment ids present' );
npcink_abilities_toolkit_assert_same( false, $pattern_page_review['data']['media_quality']['has_temporary_cloud_preview_url'] ?? true, 'review-pattern-page reports no temporary Cloud preview URLs' );
npcink_abilities_toolkit_assert_same( 'low', $pattern_page_review['data']['editor_risk']['invalid_block_risk_level'] ?? '', 'review-pattern-page reports low server-observable invalid block risk' );
npcink_abilities_toolkit_assert_same( 8, $pattern_page_review['data']['layout_fingerprint']['section_count'] ?? 0, 'review-pattern-page reports layout fingerprint section count' );
npcink_abilities_toolkit_assert_same( 'center', $pattern_page_review['data']['layout_fingerprint']['comparison_title_alignment'] ?? '', 'review-pattern-page reports centered comparison title alignment' );
npcink_abilities_toolkit_assert_true( in_array( 'center', $pattern_page_review['data']['layout_fingerprint']['alignment_mix'] ?? array(), true ), 'review-pattern-page reports centered alignment in the alignment mix' );
$pattern_page_review_finding_codes = array_map(
	static function ( $finding ) {
		return is_array( $finding ) ? (string) ( $finding['code'] ?? '' ) : '';
	},
	is_array( $pattern_page_review['data']['visual_quality_findings'] ?? null ) ? $pattern_page_review['data']['visual_quality_findings'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'color_story_monochrome', $pattern_page_review_finding_codes, true ), 'review-pattern-page reports monochrome color stories as a non-blocking visual finding' );
npcink_abilities_toolkit_assert_true( in_array( 'preview_page_in_editor', $pattern_page_review['data']['next_actions'] ?? array(), true ), 'review-pattern-page recommends final editor preview after a pass' );
$color_revised_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Color Revised WordPress AI',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'review_feedback'    => $pattern_page_review['data'],
		'variables'          => array(
			'hero_title'     => 'Color-revised Gutenberg landing page',
			'hero_media_url' => 'https://magick-ai.local/wp-content/uploads/2026/06/color-revised-dashboard.jpg',
			'hero_media_alt' => 'Color-revised WordPress AI dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $color_revised_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts monochrome review feedback for color revision' );
npcink_abilities_toolkit_assert_same( 'editorial-accent', $color_revised_pattern_page_plan['data']['color_story'] ?? '', 'build-pattern-page-plan upgrades monochrome feedback to the editorial accent story when no color story is explicit' );
npcink_abilities_toolkit_assert_same( true, $color_revised_pattern_page_plan['data']['design_quality']['has_editorial_accent'] ?? null, 'build-pattern-page-plan reports editorial accents after monochrome feedback revision' );
$color_revised_goals = array_map(
	static function ( $goal ) {
		return is_array( $goal ) ? (string) ( $goal['goal'] ?? '' ) : '';
	},
	is_array( $color_revised_pattern_page_plan['data']['quality_feedback']['revision_goals'] ?? null ) ? $color_revised_pattern_page_plan['data']['quality_feedback']['revision_goals'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'increase_color_rhythm', $color_revised_goals, true ), 'build-pattern-page-plan converts monochrome findings into a bounded color rhythm revision goal' );
npcink_abilities_toolkit_assert_true( in_array( 'color_story_monochrome', $color_revised_pattern_page_plan['data']['revision_strategy']['applied_finding_codes'] ?? array(), true ), 'build-pattern-page-plan reports the monochrome finding as addressed by the accent revision' );
npcink_abilities_toolkit_assert_true( ! in_array( 'color_story_monochrome', $color_revised_pattern_page_plan['data']['revision_strategy']['current_finding_codes'] ?? array(), true ), 'build-pattern-page-plan removes the monochrome finding from current review after accent revision' );
$block_surface_blocks_review = $core_read_package->review_block_editor_surface(
	array(
		'surface_kind' => 'blocks_input',
		'blocks'       => $pattern_blocks,
	)
);
npcink_abilities_toolkit_assert_same( true, $block_surface_blocks_review['success'] ?? null, 'review-block-editor-surface reviews proposed blocks before write' );
npcink_abilities_toolkit_assert_same( 'blocks_input', $block_surface_blocks_review['data']['block_editor_surface']['surface_kind'] ?? '', 'review-block-editor-surface identifies proposed block input' );
npcink_abilities_toolkit_assert_same( 'review_blocks_input', $block_surface_blocks_review['data']['block_editor_surface']['target_mode'] ?? '', 'review-block-editor-surface reports proposed block review mode' );
npcink_abilities_toolkit_assert_same( 'pass', $block_surface_blocks_review['data']['review_status'] ?? '', 'review-block-editor-surface reuses quality review for proposed blocks' );
npcink_abilities_toolkit_assert_true( in_array( 'choose_target_block_editor_surface', $block_surface_blocks_review['data']['next_actions'] ?? array(), true ), 'review-block-editor-surface asks callers to choose a target surface for proposed blocks' );
$paragraph_only_surface_review = $core_read_package->review_block_editor_surface(
	array(
		'surface_kind' => 'blocks_input',
		'blocks'       => array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Adapter verification paragraph.</p>',
				'innerContent' => array( '<p>Adapter verification paragraph.</p>' ),
			),
		),
	)
);
$paragraph_only_finding_codes = array_map(
	static function ( $finding ) {
		return is_array( $finding ) ? (string) ( $finding['code'] ?? '' ) : '';
	},
	is_array( $paragraph_only_surface_review['data']['findings'] ?? null ) ? $paragraph_only_surface_review['data']['findings'] : array()
);
npcink_abilities_toolkit_assert_same( false, $paragraph_only_surface_review['data']['design_quality']['has_split_hero'] ?? null, 'review-block-editor-surface detects missing split hero in paragraph-only blocks' );
npcink_abilities_toolkit_assert_true( ! in_array( 'split_hero_present', $paragraph_only_finding_codes, true ), 'review-block-editor-surface does not report split hero pass finding when no split hero exists' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280977] = (object) array(
	'ID'           => 280977,
	'post_type'    => 'page',
	'post_status'  => 'draft',
	'post_title'   => 'Surface Review Page',
	'post_content' => '<!-- wp:heading --><h2>Surface review</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Review this page through the generic block editor surface ability.</p><!-- /wp:paragraph -->',
);
$page_surface_review = $core_read_package->review_block_editor_surface(
	array(
		'post_id' => 280977,
	)
);
npcink_abilities_toolkit_assert_same( true, $page_surface_review['success'] ?? null, 'review-block-editor-surface reviews pages by post id' );
npcink_abilities_toolkit_assert_same( 'post_content', $page_surface_review['data']['block_editor_surface']['surface_kind'] ?? '', 'review-block-editor-surface identifies post content surfaces' );
npcink_abilities_toolkit_assert_same( 'page', $page_surface_review['data']['block_editor_surface']['post_type'] ?? '', 'review-block-editor-surface reports the page post type' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/get-post-blocks', $page_surface_review['data']['block_editor_surface']['read_ability_id'] ?? '', 'review-block-editor-surface points post content reads at get-post-blocks' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-pattern-page-plan', $page_surface_review['data']['block_editor_surface']['plan_ability_id'] ?? '', 'review-block-editor-surface points page revisions at the page Pattern planner' );
npcink_abilities_toolkit_assert_same( false, $page_surface_review['data']['direct_wordpress_write'] ?? null, 'review-block-editor-surface does not write pages' );
npcink_abilities_toolkit_assert_true( in_array( 'revise_pattern_page_plan', $page_surface_review['data']['next_actions'] ?? array(), true ), 'review-block-editor-surface recommends the page Pattern revision loop for page surfaces' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][280978] = (object) array(
	'ID'           => 280978,
	'post_type'    => 'post',
	'post_status'  => 'draft',
	'post_title'   => 'Surface Review Article',
	'post_content' => '<!-- wp:heading --><h2>Article surface review</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Review this article through the generic block editor surface ability.</p><!-- /wp:paragraph -->',
);
$article_surface_review = $core_read_package->review_block_editor_surface(
	array(
		'post_id' => 280978,
	)
);
npcink_abilities_toolkit_assert_same( true, $article_surface_review['success'] ?? null, 'review-block-editor-surface reviews posts by post id' );
npcink_abilities_toolkit_assert_same( 'post', $article_surface_review['data']['block_editor_surface']['post_type'] ?? '', 'review-block-editor-surface reports the article post type' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/build-article-block-plan', $article_surface_review['data']['block_editor_surface']['plan_ability_id'] ?? '', 'review-block-editor-surface points article revisions at the article block planner' );
npcink_abilities_toolkit_assert_true( in_array( 'revise_article_block_plan', $article_surface_review['data']['next_actions'] ?? array(), true ), 'review-block-editor-surface recommends the article block revision loop for post surfaces' );
$custom_html_pattern_review = $core_read_package->review_pattern_page(
	array(
		'blocks' => array(
			array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerHTML'    => '<div style="display:grid">Unsafe custom section</div>',
				'innerContent' => array( '<div style="display:grid">Unsafe custom section</div>' ),
				'innerBlocks'  => array(),
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( 'needs_revision', $custom_html_pattern_review['data']['review_status'] ?? '', 'review-pattern-page flags custom HTML-only patterns for revision' );
npcink_abilities_toolkit_assert_same( 'medium', $custom_html_pattern_review['data']['editor_risk']['invalid_block_risk_level'] ?? '', 'review-pattern-page reports custom HTML as medium editor risk' );
npcink_abilities_toolkit_assert_true( in_array( 'revise_pattern_page_plan', $custom_html_pattern_review['data']['next_actions'] ?? array(), true ), 'review-pattern-page recommends revising risky pattern plans' );
$review_revised_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Revised WordPress AI',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'review_feedback'    => $custom_html_pattern_review['data'],
		'variables'          => array(
			'eyebrow'          => 'WordPress AI Plugin',
			'hero_title'       => 'Review-revised Gutenberg landing page',
			'hero_description' => 'Previous review findings are converted into bounded Pattern revision goals.',
			'hero_media_url'   => 'https://magick-ai.local/wp-content/uploads/2026/06/review-revised-dashboard.jpg',
			'hero_media_alt'   => 'Review-revised WordPress AI dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $review_revised_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts review feedback from a previous Pattern review' );
npcink_abilities_toolkit_assert_same( true, $review_revised_pattern_page_plan['data']['quality_feedback']['feedback_received'] ?? null, 'build-pattern-page-plan reports received review feedback' );
npcink_abilities_toolkit_assert_same( 'needs_revision', $review_revised_pattern_page_plan['data']['quality_feedback']['source_review_status'] ?? '', 'build-pattern-page-plan preserves previous review status in feedback summary' );
npcink_abilities_toolkit_assert_true( in_array( 'editor_invalid_block_risk', $review_revised_pattern_page_plan['data']['quality_feedback']['finding_codes'] ?? array(), true ), 'build-pattern-page-plan carries previous editor-risk finding code' );
$review_revised_goals = array_map(
	static function ( $goal ) {
		return is_array( $goal ) ? (string) ( $goal['goal'] ?? '' ) : '';
	},
	is_array( $review_revised_pattern_page_plan['data']['quality_feedback']['revision_goals'] ?? null ) ? $review_revised_pattern_page_plan['data']['quality_feedback']['revision_goals'] : array()
);
npcink_abilities_toolkit_assert_true( in_array( 'avoid_invalid_blocks', $review_revised_goals, true ), 'build-pattern-page-plan converts editor-risk feedback into a bounded revision goal' );
npcink_abilities_toolkit_assert_same( 'pass', $review_revised_pattern_page_plan['data']['quality_review']['review_status'] ?? '', 'build-pattern-page-plan self-review passes after applying Pattern revision defaults' );
npcink_abilities_toolkit_assert_true( in_array( 'editor_invalid_block_risk', $review_revised_pattern_page_plan['data']['revision_strategy']['applied_finding_codes'] ?? array(), true ), 'build-pattern-page-plan reports previous editor-risk finding as addressed by the new plan' );
npcink_abilities_toolkit_assert_true( in_array( 'native_style_density_low', $review_revised_pattern_page_plan['data']['revision_strategy']['applied_finding_codes'] ?? array(), true ), 'build-pattern-page-plan reports previous native-style finding as addressed by the new plan' );
npcink_abilities_toolkit_assert_same( array(), $review_revised_pattern_page_plan['data']['revision_strategy']['remaining_finding_codes'] ?? array( 'unexpected' ), 'build-pattern-page-plan reports no remaining previous findings after a high-quality revision' );
npcink_abilities_toolkit_assert_same( true, $review_revised_pattern_page_plan['data']['revision_strategy']['ready_for_proposal'] ?? null, 'build-pattern-page-plan marks review-revised plans ready for Core proposal handoff' );
$review_revised_actions = is_array( $review_revised_pattern_page_plan['data']['write_actions'] ?? null ) ? $review_revised_pattern_page_plan['data']['write_actions'] : array();
$review_revised_blocks = is_array( $review_revised_actions[1]['input']['blocks'] ?? null ) ? $review_revised_actions[1]['input']['blocks'] : array();
$review_revised_markup = wp_json_encode( $review_revised_blocks );
npcink_abilities_toolkit_assert_same( '4.0', $review_revised_pattern_page_plan['data']['design_quality']['pattern_version'] ?? '', 'build-pattern-page-plan upgrades feedback revisions to the v4 visual-delta Pattern quality version' );
npcink_abilities_toolkit_assert_same( 'strong_revision', $review_revised_pattern_page_plan['data']['design_quality']['visual_delta_mode'] ?? '', 'build-pattern-page-plan marks feedback revisions as strong visual revisions' );
npcink_abilities_toolkit_assert_same( true, $review_revised_pattern_page_plan['data']['design_quality']['has_visual_delta_section'] ?? null, 'build-pattern-page-plan reports the visual-delta feature section' );
npcink_abilities_toolkit_assert_same( true, $review_revised_pattern_page_plan['data']['design_quality']['has_feature_proof_row'] ?? null, 'build-pattern-page-plan reports the added feature proof row' );
npcink_abilities_toolkit_assert_true( is_string( $review_revised_markup ) && false !== strpos( $review_revised_markup, 'npcink-ai-feature-grid npcink-ai-visual-delta' ), 'build-pattern-page-plan serializes the feedback revision as a visibly different feature section' );
npcink_abilities_toolkit_assert_true( is_string( $review_revised_markup ) && false !== strpos( $review_revised_markup, 'background-color:#111111;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px' ), 'build-pattern-page-plan renders the visual-delta feature section as a native dark band' );
npcink_abilities_toolkit_assert_true( is_string( $review_revised_markup ) && false !== strpos( $review_revised_markup, 'npcink-ai-feature-proof-row' ), 'build-pattern-page-plan adds a native proof row to make feedback revisions visually distinct' );
npcink_abilities_toolkit_assert_true( is_string( $review_revised_markup ) && false !== strpos( $review_revised_markup, 'background-color:#ffffff;color:#111111' ), 'build-pattern-page-plan resets light visual-delta card text color instead of inheriting dark-section text' );
npcink_abilities_toolkit_assert_true( is_string( $review_revised_markup ) && false !== strpos( $review_revised_markup, 'border-color:#3a3a3a;border-width:1px;border-radius:20px;background-color:#1f1f1f;color:#ffffff;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px' ), 'build-pattern-page-plan gives proof row cards complete native padding' );
$research_backed_pattern_page_plan = $core_read_package->build_pattern_page_plan(
	array(
		'title'              => 'Research Backed WordPress AI',
		'pattern_id'         => 'openai-style-landing',
		'style_preset'       => 'minimal-dark-light',
		'responsive_profile' => 'landing_standard',
		'visual_density'     => 'balanced',
		'media_strategy'     => 'existing_media_url',
		'research_brief'     => array(
			'artifact_type'                 => 'landing_page_research_brief',
			'write_posture'                 => 'suggestion_only',
			'direct_wordpress_write'        => false,
			'source_count'                  => 3,
			'section_patterns'              => array(
				array(
					'title'       => 'Evidence-led hero',
					'description' => 'Open with product proof and reviewed workflow context before detailed feature cards.',
				),
			),
			'visual_asset_recommendations' => array(
				array(
					'title'       => 'Proposal dashboard visual',
					'description' => 'Use a reviewed product interface image that shows approval status and block validation.',
				),
			),
			'proof_points'                  => array(
				array(
					'title'       => 'Reference-backed proof',
					'description' => 'Show why reviewable drafts matter before asking visitors to compare features.',
				),
			),
			'comparison_angles'             => array(
				array(
					'title'       => 'Direct automation',
					'description' => 'Fast, but hard to audit when writes skip proposal review.',
				),
				array(
					'title'       => 'Proposal-first pages',
					'description' => 'Keeps final WordPress changes reviewable, reversible, and traceable.',
				),
			),
			'faq_seed_questions'            => array(
				array(
					'question' => 'Can the page use external research safely?',
					'answer'   => 'Yes, when references are summarized as evidence and not copied into the page.',
				),
			),
		),
		'variables'          => array(
			'eyebrow'          => 'WordPress AI Plugin',
			'hero_title'       => 'Research-backed Gutenberg landing page',
			'hero_description' => 'Cloud search evidence shapes the brief while Toolkit keeps Gutenberg output native.',
			'hero_media_url'   => 'https://magick-ai.local/wp-content/uploads/2026/06/research-backed-dashboard.jpg',
			'hero_media_alt'   => 'Research-backed WordPress AI dashboard preview',
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $research_backed_pattern_page_plan['success'] ?? null, 'build-pattern-page-plan accepts a reviewed landing page research brief' );
npcink_abilities_toolkit_assert_same( true, $research_backed_pattern_page_plan['data']['research_brief']['research_backed'] ?? null, 'build-pattern-page-plan reports research-backed page planning' );
npcink_abilities_toolkit_assert_same( 3, $research_backed_pattern_page_plan['data']['research_brief']['source_count'] ?? 0, 'build-pattern-page-plan preserves compact research source count' );
npcink_abilities_toolkit_assert_same( 2, $research_backed_pattern_page_plan['data']['research_brief']['comparison_angle_count'] ?? 0, 'build-pattern-page-plan counts comparison angles from research brief' );
npcink_abilities_toolkit_assert_same( false, $research_backed_pattern_page_plan['data']['research_brief']['reference_copying_allowed'] ?? true, 'build-pattern-page-plan keeps reference copying disabled' );
npcink_abilities_toolkit_assert_same( true, $research_backed_pattern_page_plan['data']['design_quality']['research_backed'] ?? null, 'build-pattern-page-plan design quality marks research-backed output' );
npcink_abilities_toolkit_assert_same( true, $research_backed_pattern_page_plan['data']['design_quality']['has_comparison_section'] ?? null, 'build-pattern-page-plan adds a comparison section from research angles' );
npcink_abilities_toolkit_assert_same( 8, $research_backed_pattern_page_plan['data']['design_quality']['section_count'] ?? 0, 'build-pattern-page-plan adds one research-backed comparison section when media is supplied' );
$research_backed_actions = is_array( $research_backed_pattern_page_plan['data']['write_actions'] ?? null ) ? $research_backed_pattern_page_plan['data']['write_actions'] : array();
$research_backed_blocks = is_array( $research_backed_actions[1]['input']['blocks'] ?? null ) ? $research_backed_actions[1]['input']['blocks'] : array();
$research_backed_markup = wp_json_encode( $research_backed_blocks );
npcink_abilities_toolkit_assert_true( is_string( $research_backed_markup ) && false !== strpos( $research_backed_markup, 'npcink-ai-comparison' ), 'build-pattern-page-plan serializes research-backed comparison section blocks' );
npcink_abilities_toolkit_assert_true( is_string( $research_backed_markup ) && false !== strpos( $research_backed_markup, 'Reference-backed proof' ), 'build-pattern-page-plan uses research proof points' );
npcink_abilities_toolkit_assert_true( is_string( $research_backed_markup ) && false !== strpos( $research_backed_markup, 'Proposal dashboard visual' ), 'build-pattern-page-plan uses visual asset recommendations for media copy' );
npcink_abilities_toolkit_assert_true( is_string( $research_backed_markup ) && false !== strpos( $research_backed_markup, 'Can the page use external research safely?' ), 'build-pattern-page-plan uses FAQ seeds from research brief' );
$apply_result = $core_read_package->compose_article_optimization_apply_result(
	array(
		'report'        => array(
			'summary' => array(
				'status' => 'needs_attention',
			),
		),
		'apply_plan'    => $apply_plan['data'],
		'apply_excerpt' => array(
			'updated' => true,
			'post_id' => 77,
			'changes' => array(
				'excerpt' => 'Generated excerpt.',
			),
		),
	)
);
npcink_abilities_toolkit_assert_same( true, $apply_result['success'] ?? null, 'compose-article-optimization-apply-result returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'partial_apply', $apply_result['data']['summary']['result_mode'] ?? '', 'compose-article-optimization-apply-result marks partial apply when excerpt changed' );
npcink_abilities_toolkit_assert_same( 1, $apply_result['data']['summary']['applied_count'] ?? null, 'compose-article-optimization-apply-result counts applied changes' );
$draft_result = $core_read_package->compose_article_draft_result(
	array(
		'input' => array(
			'topic'            => 'Local draft',
			'preview_only'     => true,
			'platform_profile' => 'wechat',
			'human_signals'    => array( '案例/数据来源：内部复盘记录' ),
		),
		'draft' => array(
			'post_id'      => 0,
			'preview_link' => 'https://example.test/preview/draft',
		),
		'generated_seo' => array(
			'title'       => 'Local draft SEO title',
			'description' => 'Local draft SEO description',
		),
		'metadata_plan_resolution' => array(
			'slug' => 'local-draft',
		),
		'seo_analysis' => array(
			'overall_score' => 72,
		),
		'geo_analysis' => array(
			'geo_score' => 82,
		),
		'quality_scoring' => array(
			'overall_score' => 58,
			'improvement_suggestions' => array( '补充更具体的真实案例。' ),
		),
		'review' => array(
			'needs_human_review'  => true,
			'next_action'         => 'needs_human_review',
			'template_risk_level' => 'medium',
		),
	)
);
$draft_data = is_array( $draft_result['data'] ?? null ) ? $draft_result['data'] : array();
npcink_abilities_toolkit_assert_same( true, $draft_result['success'] ?? null, 'compose-article-draft-result returns a success envelope' );
npcink_abilities_toolkit_assert_same( true, $draft_data['draft']['preview_only'] ?? null, 'compose-article-draft-result preserves preview-only mode' );
npcink_abilities_toolkit_assert_same( false, $draft_data['draft']['real_draft_created'] ?? null, 'compose-article-draft-result does not claim a real draft in preview mode' );
npcink_abilities_toolkit_assert_same( 'review_preview', $draft_data['handoff']['next_action'] ?? '', 'compose-article-draft-result keeps preview handoff local to draft workflow' );
npcink_abilities_toolkit_assert_same( 'npcink-abilities-toolkit/recipes/article-draft', $draft_data['handoff']['recommended_entry'] ?? '', 'compose-article-draft-result keeps draft recommended entry for preview-only output' );
npcink_abilities_toolkit_assert_same( '内部复盘记录', $draft_data['source_references'][0] ?? '', 'compose-article-draft-result extracts source references from human signals' );
$publication_decision = $core_read_package->resolve_article_publication_decision(
	array(
		'publish_mode' => 'schedule',
		'review'       => array( 'needs_human_review' => true ),
	)
);
npcink_abilities_toolkit_assert_same( true, $publication_decision['success'] ?? null, 'resolve-article-publication-decision returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'schedule', $publication_decision['data']['requested_publish_mode'] ?? '', 'resolve-article-publication-decision preserves requested mode' );
npcink_abilities_toolkit_assert_same( 'review', $publication_decision['data']['effective_publish_mode'] ?? '', 'resolve-article-publication-decision routes blocked schedules to review' );
npcink_abilities_toolkit_assert_same( true, $publication_decision['data']['publish_blocked'] ?? null, 'resolve-article-publication-decision marks human-review gate as blocked' );
npcink_abilities_toolkit_assert_same( 'quality_review_requires_handoff', $publication_decision['data']['gate_reason'] ?? '', 'resolve-article-publication-decision records quality gate reason' );
$template_publication_decision = $core_read_package->resolve_article_publication_decision(
	array(
		'publish_mode' => 'publish',
		'review'       => array(
			'needs_human_review' => true,
			'template_risk_level' => 'high',
		),
	)
);
npcink_abilities_toolkit_assert_same( 'template_style_requires_handoff', $template_publication_decision['data']['gate_reason'] ?? '', 'resolve-article-publication-decision records high template risk gate reason' );
$duplicate_publication_decision = $core_read_package->resolve_article_publication_decision(
	array(
		'publish_mode'     => 'publish',
		'duplicate_guard'  => array( 'skip_recommended' => true ),
	)
);
npcink_abilities_toolkit_assert_same( 'duplicate_production_candidate', $duplicate_publication_decision['data']['gate_reason'] ?? '', 'resolve-article-publication-decision records duplicate gate reason' );
$draft_publication_decision = $core_read_package->resolve_article_publication_decision( array( 'publish_mode' => 'unexpected' ) );
npcink_abilities_toolkit_assert_same( 'draft', $draft_publication_decision['data']['requested_publish_mode'] ?? '', 'resolve-article-publication-decision falls back invalid mode to draft' );
npcink_abilities_toolkit_assert_same( false, $draft_publication_decision['data']['publish_blocked'] ?? null, 'resolve-article-publication-decision leaves draft unblocked' );
$article_style_profile = $core_read_package->build_article_style_profile(
	array(
		'reference_profile' => array(
			'dominant_voice_profile'   => 'experiential_editorial',
			'dominant_opening_style'   => 'scene',
			'structure_style'          => 'reference-first',
			'style_brief'              => 'Reference article favors lived experience.',
		),
		'baseline_profile' => array(
			'dominant_voice_profile'   => 'practical_editorial',
			'dominant_opening_style'   => 'direct_judgement',
			'structure_style'          => 'baseline-short-paragraphs',
			'style_brief'              => 'Site baseline favors practical judgement.',
		),
		'voice_profile'     => 'practical_editorial',
		'opening_style'     => 'scene',
		'structure_style'   => 'alternating paragraph lengths',
	)
);
npcink_abilities_toolkit_assert_same( true, $article_style_profile['success'] ?? null, 'build-article-style-profile returns a success envelope' );
npcink_abilities_toolkit_assert_same( 'practical_editorial', $article_style_profile['data']['profile']['resolved_voice_profile'] ?? '', 'build-article-style-profile preserves explicit voice override' );
npcink_abilities_toolkit_assert_same( 'scene', $article_style_profile['data']['profile']['resolved_opening_style'] ?? '', 'build-article-style-profile preserves explicit opening override' );
npcink_abilities_toolkit_assert_same( 'alternating paragraph lengths', $article_style_profile['data']['profile']['resolved_structure_style'] ?? '', 'build-article-style-profile preserves explicit structure override' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $article_style_profile['data']['profile']['style_brief'] ?? '' ), 'Reference article favors lived experience.' ), 'build-article-style-profile carries reference brief' );
npcink_abilities_toolkit_assert_true( false !== strpos( (string) ( $article_style_profile['data']['profile']['style_brief'] ?? '' ), 'Site baseline favors practical judgement.' ), 'build-article-style-profile carries baseline brief' );
$reference_first_style_profile = $core_read_package->build_article_style_profile(
	array(
		'reference_profile' => array(
			'dominant_voice_profile' => 'reference_voice',
			'dominant_opening_style' => 'reference_opening',
			'style_brief' => 'Repeated brief.',
		),
		'baseline_profile' => array(
			'dominant_voice_profile' => 'baseline_voice',
			'dominant_opening_style' => 'baseline_opening',
			'style_brief' => 'Repeated brief.',
		),
	)
);
npcink_abilities_toolkit_assert_same( 'reference_voice', $reference_first_style_profile['data']['profile']['resolved_voice_profile'] ?? '', 'build-article-style-profile prefers reference voice over baseline when no explicit override exists' );
npcink_abilities_toolkit_assert_same( 'reference_opening', $reference_first_style_profile['data']['profile']['resolved_opening_style'] ?? '', 'build-article-style-profile prefers reference opening over baseline when no explicit override exists' );
npcink_abilities_toolkit_assert_same( 'Repeated brief.', $reference_first_style_profile['data']['profile']['style_brief'] ?? '', 'build-article-style-profile deduplicates repeated style briefs' );
$package_bridge = new Npcink_Catalog_Bridge( $package_registrar );
$package_catalog = $package_bridge->filter_catalog( array(), array() );
foreach ( $migrated_read_ability_ids as $migrated_ability_id ) {
	$catalog_key = str_replace( '/', '_', $migrated_ability_id );
	npcink_abilities_toolkit_assert_true( isset( $package_catalog[ $catalog_key ] ), "catalog bridge projects migrated {$migrated_ability_id}" );
	npcink_abilities_toolkit_assert_same( 'wp_ability', $package_catalog[ $catalog_key ]['executor_type'], "{$migrated_ability_id} catalog entry executes through wp_ability" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['open_api_enabled'] ), "{$migrated_ability_id} catalog projection does not own Open API policy" );
}
foreach ( $migrated_write_ability_ids as $migrated_write_ability_id ) {
	$catalog_key = str_replace( '/', '_', $migrated_write_ability_id );
	npcink_abilities_toolkit_assert_true( isset( $package_catalog[ $catalog_key ] ), "catalog bridge projects migrated {$migrated_write_ability_id}" );
	npcink_abilities_toolkit_assert_same( 'wp_ability', $package_catalog[ $catalog_key ]['executor_type'], "{$migrated_write_ability_id} catalog entry executes through wp_ability" );
	npcink_abilities_toolkit_assert_same( true, $package_catalog[ $catalog_key ]['requires_confirm'], "{$migrated_write_ability_id} catalog projection requires confirmation" );
	npcink_abilities_toolkit_assert_same( 'write', $package_catalog[ $catalog_key ]['risk_level'], "{$migrated_write_ability_id} catalog projection is write risk" );
	npcink_abilities_toolkit_assert_same( true, $package_catalog[ $catalog_key ]['show_in_rest'], "{$migrated_write_ability_id} catalog projection exposes show_in_rest for host normalization" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['write_mode'] ), "{$migrated_write_ability_id} catalog projection does not own write mode policy" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['open_api_enabled'] ), "{$migrated_write_ability_id} catalog projection does not own Open API policy" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['skip_catalog_manifest_fallback'] ), "{$migrated_write_ability_id} catalog projection does not own host fallback policy" );
}
foreach ( $migrated_destructive_ability_ids as $migrated_destructive_ability_id ) {
	$catalog_key = str_replace( '/', '_', $migrated_destructive_ability_id );
	npcink_abilities_toolkit_assert_true( isset( $package_catalog[ $catalog_key ] ), "catalog bridge projects migrated {$migrated_destructive_ability_id}" );
	npcink_abilities_toolkit_assert_same( 'wp_ability', $package_catalog[ $catalog_key ]['executor_type'], "{$migrated_destructive_ability_id} catalog entry executes through wp_ability" );
	npcink_abilities_toolkit_assert_same( true, $package_catalog[ $catalog_key ]['requires_confirm'], "{$migrated_destructive_ability_id} catalog projection requires confirmation" );
	npcink_abilities_toolkit_assert_same( 'destructive', $package_catalog[ $catalog_key ]['risk_level'], "{$migrated_destructive_ability_id} catalog projection is destructive risk" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['write_mode'] ), "{$migrated_destructive_ability_id} catalog projection does not own write mode policy" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['tool_policy'] ), "{$migrated_destructive_ability_id} catalog projection does not own destructive tool policy" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['open_api_enabled'] ), "{$migrated_destructive_ability_id} catalog projection does not own Open API policy" );
	npcink_abilities_toolkit_assert_true( ! isset( $package_catalog[ $catalog_key ]['skip_catalog_manifest_fallback'] ), "{$migrated_destructive_ability_id} catalog projection does not own host fallback policy" );
}
npcink_abilities_toolkit_assert_true( ! isset( $package_catalog['npcink-abilities-toolkit_wp-diagnostics-summary'] ), 'catalog bridge does not project standalone diagnostics ability' );
npcink_abilities_toolkit_assert_true( ! isset( $package_catalog['npcink-abilities-toolkit_wp-ops-diagnostics-detail'] ), 'catalog bridge does not project standalone ops diagnostics ability' );

$workflow_replay_path = __DIR__ . '/fixtures/agent-workflow-replay.json';
$workflow_replay_json = file_get_contents( $workflow_replay_path );
npcink_abilities_toolkit_assert_true( false !== $workflow_replay_json, 'agent workflow replay fixture is readable' );
$workflow_replay = json_decode( (string) $workflow_replay_json, true );
npcink_abilities_toolkit_assert_true( is_array( $workflow_replay ), 'agent workflow replay fixture decodes as an object' );
$workflow_manifest = \Npcink_Abilities_Toolkit\Workflow\Workflow_Definition_Provider::manifest();
npcink_abilities_toolkit_assert_same( $workflow_manifest, $workflow_replay, 'agent workflow replay fixture matches production workflow definition provider' );
npcink_abilities_toolkit_assert_same( $workflow_manifest, npcink_abilities_toolkit_get_workflow_definitions(), 'public workflow definitions helper matches provider manifest' );
npcink_abilities_toolkit_assert_same( $workflow_manifest['cases']['article_publish_preflight'], npcink_abilities_toolkit_get_workflow_definition( 'npcink-abilities-toolkit/recipes/article-publish-preflight' ), 'public workflow definition helper resolves recipe id' );
npcink_abilities_toolkit_assert_same( 'v1', $workflow_replay['schema_version'] ?? '', 'agent workflow replay fixture schema is v1' );
npcink_abilities_toolkit_assert_true( is_array( $workflow_replay['cases'] ?? null ), 'agent workflow replay fixture exposes cases' );
$forbidden_workflow_definition_fields = \Npcink_Abilities_Toolkit\Workflow\Workflow_Definition_Provider::forbidden_field_keys();
npcink_abilities_toolkit_assert_array_omits_keys( $workflow_replay, $forbidden_workflow_definition_fields, 'agent workflow replay fixture' );
$expected_workflow_replay_cases = array(
	'article_draft'                  => array(
		'ability_id'         => 'npcink-abilities-toolkit/compose-article-draft-result',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/article-draft',
		'recipe_aliases'     => array( 'article_draft_v1' ),
		'required_scope'     => 'cap.text.extract',
		'required_inputs'    => array(),
		'expected_sections'  => array( 'article', 'draft', 'metadata_plan_resolution', 'review', 'handoff' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/resolve-post-metadata-plan',
			'npcink-abilities-toolkit/resolve-internal-link-targets',
			'npcink-abilities-toolkit/build-inline-image-blocks',
			'npcink-abilities-toolkit/build-media-seo-assets',
			'npcink-abilities-toolkit/review-article-output-light',
			'npcink-abilities-toolkit/compose-article-draft-result',
		),
		'handoff_kind'       => 'suggestion',
		'disallowed_default' => array( 'npcink-abilities-toolkit/create-draft', 'npcink-abilities-toolkit/update-post', 'npcink-abilities-toolkit/patch-post-content', 'npcink-abilities-toolkit/publish-post' ),
	),
	'article_publish_preflight'      => array(
		'ability_id'         => 'npcink-abilities-toolkit/get-article-publish-preflight-context',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/article-publish-preflight',
		'required_scope'     => 'post.read',
		'required_inputs'    => array( 'post_id' ),
		'expected_sections'  => array( 'post_context', 'publishing_checklist', 'publish_risk', 'workflow_context', 'publishing_calendar' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/get-post-context',
			'npcink-abilities-toolkit/get-content-publishing-checklist',
			'npcink-abilities-toolkit/get-post-publish-risk-report',
			'npcink-abilities-toolkit/build-article-workflow-context',
			'npcink-abilities-toolkit/get-publishing-calendar-context',
		),
		'handoff_kind'       => 'context',
		'disallowed_default' => array( 'npcink-abilities-toolkit/schedule-post', 'npcink-abilities-toolkit/publish-post' ),
	),
	'article_optimization'           => array(
		'ability_id'         => 'npcink-abilities-toolkit/read-post-optimization-context',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/article-optimization',
		'required_scope'     => 'post.read',
		'required_inputs'    => array( 'post_id' ),
		'expected_sections'  => array( 'post_context', 'seo_report', 'optimization_suggestion', 'apply_plan', 'handoff' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/read-post-optimization-context',
			'npcink-abilities-toolkit/seo-report-context',
			'npcink-abilities-toolkit/build-article-single-optimization-suggest',
			'npcink-abilities-toolkit/build-article-optimization-apply-plan',
			'npcink-abilities-toolkit/compose-article-optimization-apply-result',
		),
		'handoff_kind'       => 'suggestion',
		'disallowed_default' => array( 'npcink-abilities-toolkit/patch-post-content', 'npcink-abilities-toolkit/set-post-seo-meta', 'npcink-abilities-toolkit/update-post-blocks' ),
	),
	'media_optimization'             => array(
		'ability_id'         => 'npcink-abilities-toolkit/build-media-optimization-plan',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/media-optimization',
		'recipe_aliases'     => array( 'media_optimization_v1' ),
		'required_scope'     => 'media.read',
		'required_inputs'    => array( 'attachment_id', 'media_details_input', 'derivative_artifact' ),
		'expected_sections'  => array( 'artifact_type', 'proposal_mode', 'write_actions', 'derivative_preview', 'content_reference_repairs_preview' ),
		'expanded_abilities' => array( 'npcink-abilities-toolkit/build-media-optimization-plan' ),
		'handoff_kind'       => 'approval_request',
		'disallowed_default' => array( 'npcink-abilities-toolkit/update-media-details', 'npcink-abilities-toolkit/adopt-cloud-media-derivative' ),
	),
	'article_media_handoff'          => array(
		'ability_id'         => 'npcink-abilities-toolkit/build-media-seo-assets',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/article-media-handoff',
		'required_scope'     => 'media.read',
		'required_inputs'    => array(),
		'expected_sections'  => array( 'post_context', 'media_assets', 'inline_blocks', 'positioned_blocks', 'handoff' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/get-post-context',
			'npcink-abilities-toolkit/build-inline-image-blocks',
			'npcink-abilities-toolkit/build-media-seo-assets',
			'npcink-abilities-toolkit/position-inline-image-blocks',
		),
		'handoff_kind'       => 'suggestion',
		'disallowed_default' => array( 'npcink-abilities-toolkit/upload-media-from-url', 'npcink-abilities-toolkit/update-media-details', 'npcink-abilities-toolkit/set-post-featured-image' ),
	),
	'old_article_refresh_discovery' => array(
		'ability_id'         => 'npcink-abilities-toolkit/get-old-article-refresh-context',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/old-article-refresh-discovery',
		'required_scope'     => 'post.read',
		'required_inputs'    => array(),
		'expected_sections'  => array( 'refresh_opportunities', 'seo_geo_gap_report', 'site_style_baseline', 'internal_link_graph_health' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/get-content-refresh-opportunities',
			'npcink-abilities-toolkit/get-seo-geo-gap-report',
			'npcink-abilities-toolkit/get-site-style-baseline',
			'npcink-abilities-toolkit/get-internal-link-graph-health',
			'npcink-abilities-toolkit/get-internal-link-opportunity-report',
		),
		'handoff_kind'       => 'context',
		'disallowed_default' => array( 'npcink-abilities-toolkit/patch-post-content', 'npcink-abilities-toolkit/update-post', 'npcink-abilities-toolkit/update-post-blocks' ),
	),
	'comment_compliance_handoff'    => array(
		'ability_id'         => 'npcink-abilities-toolkit/get-comment-compliance-handoff',
		'recipe_id'          => 'npcink-abilities-toolkit/recipes/comment-compliance-handoff',
		'required_scope'     => 'comments.manage',
		'required_inputs'    => array(),
		'expected_sections'  => array( 'queue_health', 'priority_queue', 'selected_moderation_suggestion' ),
		'expanded_abilities' => array(
			'npcink-abilities-toolkit/get-comment-queue-health',
			'npcink-abilities-toolkit/get-comment-action-priority-queue',
			'npcink-abilities-toolkit/build-comment-moderation-suggest',
			'npcink-abilities-toolkit/build-comment-mention-reply-suggest',
			'npcink-abilities-toolkit/compose-comment-moderation-result',
		),
		'handoff_kind'       => 'context',
		'disallowed_default' => array( 'npcink-abilities-toolkit/approve-comment', 'npcink-abilities-toolkit/reply-comment', 'npcink-abilities-toolkit/spam-comment', 'npcink-abilities-toolkit/trash-comment' ),
	),
);
npcink_abilities_toolkit_assert_same( array_keys( $expected_workflow_replay_cases ), array_keys( $workflow_replay['cases'] ), 'agent workflow replay fixture keeps the approved local recipe cases in order' );
foreach ( $expected_workflow_replay_cases as $case_id => $expected_case ) {
	$case = $workflow_replay['cases'][ $case_id ] ?? array();
	npcink_abilities_toolkit_assert_true( is_array( $case ), "agent workflow replay case {$case_id} is an object" );
	npcink_abilities_toolkit_assert_same( 'workflow_recipe', $case['definition_kind'] ?? '', "agent workflow replay case {$case_id} is a workflow recipe definition" );
	npcink_abilities_toolkit_assert_same( 'v1', $case['contract_version'] ?? '', "agent workflow replay case {$case_id} uses definition contract v1" );
	npcink_abilities_toolkit_assert_true( is_array( $case['natural_tasks'] ?? null ), "agent workflow replay case {$case_id} exposes natural task examples" );
	npcink_abilities_toolkit_assert_true( count( $case['natural_tasks'] ) >= 3, "agent workflow replay case {$case_id} keeps at least three natural task examples" );
	npcink_abilities_toolkit_assert_same( $expected_case['ability_id'], $case['preferred_ability_id'] ?? '', "agent workflow replay case {$case_id} prefers the bundle ability" );
	npcink_abilities_toolkit_assert_same( $expected_case['ability_id'], $case['entrypoint_ability_id'] ?? '', "agent workflow replay case {$case_id} exposes the preferred bundle as entrypoint" );
	npcink_abilities_toolkit_assert_same( $expected_case['recipe_id'], $case['recipe_id'] ?? '', "agent workflow replay case {$case_id} keeps the recipe id" );
	if ( isset( $expected_case['recipe_aliases'] ) ) {
		npcink_abilities_toolkit_assert_same( $expected_case['recipe_aliases'], $case['recipe_aliases'] ?? array(), "agent workflow replay case {$case_id} keeps recipe aliases" );
	}
	npcink_abilities_toolkit_assert_same( $expected_case['required_scope'], $case['required_scope'] ?? '', "agent workflow replay case {$case_id} keeps the required scope" );
	npcink_abilities_toolkit_assert_same( $expected_case['required_inputs'], $case['required_inputs'] ?? array(), "agent workflow replay case {$case_id} keeps required inputs" );
	npcink_abilities_toolkit_assert_same( $expected_case['expected_sections'], $case['expected_sections'] ?? array(), "agent workflow replay case {$case_id} keeps expected output sections" );
	npcink_abilities_toolkit_assert_same( $expected_case['expanded_abilities'], $case['expanded_ability_ids'] ?? array(), "agent workflow replay case {$case_id} keeps expanded ability chain" );
	npcink_abilities_toolkit_assert_true( is_array( $case['handoff'] ?? null ), "agent workflow replay case {$case_id} exposes a structured handoff" );
	npcink_abilities_toolkit_assert_same( $expected_case['handoff_kind'], $case['handoff']['kind'] ?? '', "agent workflow replay case {$case_id} keeps handoff kind" );
	npcink_abilities_toolkit_assert_same( 'host', $case['handoff']['owner'] ?? '', "agent workflow replay case {$case_id} keeps host-owned handoff" );
	npcink_abilities_toolkit_assert_true( is_string( $case['handoff']['next_action'] ?? null ) && '' !== $case['handoff']['next_action'], "agent workflow replay case {$case_id} keeps a host next action hint" );
	npcink_abilities_toolkit_assert_same( 'fail_closed', $case['failure_policy'] ?? '', "agent workflow replay case {$case_id} fails closed" );
	npcink_abilities_toolkit_assert_same( $expected_case['disallowed_default'], $case['disallowed_default_ability_ids'] ?? array(), "agent workflow replay case {$case_id} keeps disallowed default write abilities" );
	npcink_abilities_toolkit_assert_same( true, $case['host_governed_write_boundary'] ?? null, "agent workflow replay case {$case_id} keeps host-governed write boundary" );
	npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $expected_case['ability_id'] ] ), "agent workflow replay case {$case_id} points to a registered ability" );
	$entrypoint_ability = $package_abilities[ $expected_case['ability_id'] ];
	npcink_abilities_toolkit_assert_same( 'read', $entrypoint_ability['risk_level'] ?? '', "agent workflow replay case {$case_id} points to a read-risk bundle" );
	npcink_abilities_toolkit_assert_same( false, $entrypoint_ability['requires_confirm'] ?? null, "agent workflow replay case {$case_id} points to a read-only bundle without confirmation" );
	npcink_abilities_toolkit_assert_same( $expected_case['required_scope'], $entrypoint_ability['required_scope'] ?? '', "agent workflow replay case {$case_id} matches registered ability scope" );
	npcink_abilities_toolkit_assert_same( $expected_case['required_inputs'], $entrypoint_ability['input_schema']['required'] ?? array(), "agent workflow replay case {$case_id} required inputs match the entrypoint schema" );
	$case_catalog_key = str_replace( '/', '_', $expected_case['ability_id'] );
	npcink_abilities_toolkit_assert_same( 'wp_ability', $package_catalog[ $case_catalog_key ]['executor_type'] ?? '', "agent workflow replay case {$case_id} is projected for wp_ability execution" );
	foreach ( $expected_case['expanded_abilities'] as $expanded_ability_id ) {
		npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $expanded_ability_id ] ), "agent workflow replay case {$case_id} references known expanded ability {$expanded_ability_id}" );
		npcink_abilities_toolkit_assert_true( 'destructive' !== ( $package_abilities[ $expanded_ability_id ]['risk_level'] ?? '' ), "agent workflow replay case {$case_id} expanded ability {$expanded_ability_id} is not destructive" );
	}
	foreach ( $expected_case['disallowed_default'] as $disallowed_ability_id ) {
		npcink_abilities_toolkit_assert_true( $disallowed_ability_id !== $expected_case['ability_id'], "agent workflow replay case {$case_id} does not disallow its preferred bundle" );
		npcink_abilities_toolkit_assert_true( isset( $package_abilities[ $disallowed_ability_id ] ), "agent workflow replay case {$case_id} references a known disallowed default ability {$disallowed_ability_id}" );
		npcink_abilities_toolkit_assert_true( 'read' !== ( $package_abilities[ $disallowed_ability_id ]['risk_level'] ?? 'read' ), "agent workflow replay case {$case_id} disallowed default {$disallowed_ability_id} is write-like" );
	}
}

$workflow_list = call_user_func( $package_abilities['npcink-abilities-toolkit/list-workflow-recipes']['execute_callback'], array() );
npcink_abilities_toolkit_assert_same( $workflow_manifest, $workflow_list, 'workflow recipe discovery ability returns provider manifest' );
$workflow_draft_alias = call_user_func( $package_abilities['npcink-abilities-toolkit/get-workflow-recipe']['execute_callback'], array( 'recipe_id' => 'article_draft_v1' ) );
npcink_abilities_toolkit_assert_same( $workflow_manifest['cases']['article_draft'], $workflow_draft_alias, 'workflow recipe detail ability resolves article_draft_v1 alias' );
$workflow_media_optimization_alias = call_user_func( $package_abilities['npcink-abilities-toolkit/get-workflow-recipe']['execute_callback'], array( 'recipe_id' => 'media_optimization_v1' ) );
npcink_abilities_toolkit_assert_same( $workflow_manifest['cases']['media_optimization'], $workflow_media_optimization_alias, 'workflow recipe detail ability resolves media_optimization_v1 alias' );
$workflow_get = call_user_func( $package_abilities['npcink-abilities-toolkit/get-workflow-recipe']['execute_callback'], array( 'recipe_id' => 'npcink-abilities-toolkit/recipes/comment-compliance-handoff' ) );
npcink_abilities_toolkit_assert_same( $workflow_manifest['cases']['comment_compliance_handoff'], $workflow_get, 'workflow recipe detail ability resolves recipe id' );
$workflow_missing = call_user_func( $package_abilities['npcink-abilities-toolkit/get-workflow-recipe']['execute_callback'], array( 'recipe_id' => 'workflow/missing' ) );
npcink_abilities_toolkit_assert_true( is_wp_error( $workflow_missing ), 'workflow recipe detail ability fails closed for missing recipe' );

// Media backup cleanup is maintenance-only: preview is read-only and
// execution is restricted to the dedicated backup root.
$cleanup_uploads = sys_get_temp_dir() . '/npcink-abilities-toolkit-cleanup-' . getmypid();
$GLOBALS['npcink_abilities_toolkit_unit_upload_basedir'] = $cleanup_uploads;
wp_mkdir_p( $cleanup_uploads . '/2026/06' );
wp_mkdir_p( $cleanup_uploads . '/npcink-abilities-toolkit-backups/2026/06' );
$cleanup_current_relative = '2026/06/cleanup-current.jpg';
$cleanup_backup_relative = 'npcink-abilities-toolkit-backups/2026/06/cleanup-original.jpg';
$cleanup_current_path = $cleanup_uploads . '/' . $cleanup_current_relative;
$cleanup_backup_path = $cleanup_uploads . '/' . $cleanup_backup_relative;
file_put_contents( $cleanup_current_path, 'current-media-bytes' );
file_put_contents( $cleanup_backup_path, 'original-media-bytes' );
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'][777] = (object) array(
	'ID' => 777,
	'post_type' => 'attachment',
	'post_status' => 'inherit',
	'post_title' => 'Cleanup fixture',
);
$cleanup_history = array(
	array(
		'replacement_id' => 'cleanup-expired',
		'replaced_at_gmt' => gmdate( 'c', time() - ( 60 * 86400 ) ),
		'status' => 'completed',
		'backup' => array( 'relative_file' => $cleanup_backup_relative, 'file_exists' => true ),
	),
	array(
		'replacement_id' => 'cleanup-current',
		'replaced_at_gmt' => gmdate( 'c' ),
		'status' => 'completed',
		'backup' => array( 'relative_file' => '2026/06/not-yet-expired.jpg', 'file_exists' => true ),
	),
	array(
		'replacement_id' => 'cleanup-outside-root',
		'replaced_at_gmt' => gmdate( 'c', time() - ( 60 * 86400 ) ),
		'status' => 'completed',
		'backup' => array( 'relative_file' => '2026/06/not-a-backup.jpg', 'file_exists' => true ),
	),
);
update_post_meta( 777, '_npcink_ai_media_file_replacement_history', $cleanup_history );
update_post_meta( 777, '_wp_attached_file', $cleanup_current_relative );
// Isolate the maintenance scan from the larger fixture corpus above.
$GLOBALS['npcink_abilities_toolkit_unit_style_posts'] = array( 777 => $GLOBALS['npcink_abilities_toolkit_unit_style_posts'][777] );
$GLOBALS['npcink_abilities_toolkit_unit_post_meta'] = array( 777 => $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][777] );
$cleanup_preview = $core_write_package->preview_expired_media_backups();
npcink_abilities_toolkit_assert_same( 30, $cleanup_preview['retention_days'] ?? 0, 'media backup cleanup uses the default 30-day retention period' );
npcink_abilities_toolkit_assert_same( 1, $cleanup_preview['expired'] ?? 0, 'media backup cleanup preview finds only the expired dedicated backup' );
npcink_abilities_toolkit_assert_same( 0, $cleanup_preview['removed'] ?? -1, 'media backup cleanup preview never removes files' );
npcink_abilities_toolkit_assert_true( is_file( $cleanup_backup_path ), 'media backup cleanup preview leaves the expired backup in place' );
$cleanup_result = $core_write_package->cleanup_expired_media_backups( true );
npcink_abilities_toolkit_assert_same( 1, $cleanup_result['expired'] ?? 0, 'media backup cleanup execution processes the expired backup' );
npcink_abilities_toolkit_assert_same( 1, $cleanup_result['removed'] ?? 0, 'media backup cleanup removes one expired dedicated backup' );
npcink_abilities_toolkit_assert_true( ! is_file( $cleanup_backup_path ), 'media backup cleanup removes the expired backup file' );
npcink_abilities_toolkit_assert_true( is_file( $cleanup_current_path ), 'media backup cleanup never removes the current attachment file' );
$cleanup_history_after = get_post_meta( 777, '_npcink_ai_media_file_replacement_history', true );
npcink_abilities_toolkit_assert_same( 'backup_expired', $cleanup_history_after[0]['status'] ?? '', 'media backup cleanup retains history and marks the backup expired' );
npcink_abilities_toolkit_assert_same( 'completed', $cleanup_history_after[1]['status'] ?? '', 'media backup cleanup leaves non-expired history unchanged' );
npcink_abilities_toolkit_assert_same( 'completed', $cleanup_history_after[2]['status'] ?? '', 'media backup cleanup ignores files outside the dedicated backup root' );
$cleanup_repeat = $core_write_package->cleanup_expired_media_backups( true );
npcink_abilities_toolkit_assert_same( 0, $cleanup_repeat['expired'] ?? -1, 'media backup cleanup is idempotent after an expired backup is marked' );
@unlink( $cleanup_current_path );

echo "OK: {$assertions} assertions\n";
