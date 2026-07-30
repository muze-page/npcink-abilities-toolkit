<?php
/**
 * Core WordPress host-governed destructive ability package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

use Npcink_Abilities_Toolkit\Registry\Ability_Registrar;
use Npcink_Abilities_Toolkit\Registry\Category_Registrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers destructive WordPress abilities migrated from the Npcink AI plugin.
 */
final class Core_Destructive_Package {
	/**
	 * Category registrar.
	 *
	 * @var Category_Registrar
	 */
	private $categories;

	/**
	 * Ability registrar.
	 *
	 * @var Ability_Registrar
	 */
	private $abilities;

	/**
	 * Constructor.
	 *
	 * @param Category_Registrar $categories Category registrar.
	 * @param Ability_Registrar  $abilities Ability registrar.
	 */
	public function __construct( Category_Registrar $categories, Ability_Registrar $abilities ) {
		$this->categories = $categories;
		$this->abilities  = $abilities;
	}

	/**
	 * Registers categories and abilities.
	 *
	 * @return void
	 */
	public function boot() {
		$this->categories->add(
			'npcink-abilities-toolkit-write',
			array(
				'label'       => __( 'WordPress Write Abilities', 'npcink-abilities-toolkit' ),
				'description' => __( 'Host-governed WordPress write and destructive abilities with dry-run previews and external approval.', 'npcink-abilities-toolkit' ),
			)
		);

		foreach ( $this->definitions() as $ability_id => $definition ) {
			$definition                              = $this->with_agent_usage_metadata( $ability_id, $definition );
			$definition['source']                    = 'official';
			$definition['project_to_npcink_catalog'] = true;
			$this->abilities->add_destructive_host_governed( $ability_id, $definition );
		}
	}

	/**
	 * Returns package ability definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function definitions() {
		$term_id    = array( 'type' => 'integer', 'minimum' => 1 );
		$post_id    = array( 'type' => 'integer', 'minimum' => 1 );
		$comment_id = array( 'type' => 'integer', 'minimum' => 1 );

		return array(
			'npcink-abilities-toolkit/delete-term'              => array(
				'label'           => __( 'Delete Term', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Deletes a taxonomy term after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'manage_categories',
				'required_scopes' => array( 'taxonomy.manage' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'taxonomy' => array( 'type' => 'string', 'default' => 'category' ),
						'term_id'  => $term_id,
					),
					array( 'taxonomy', 'term_id' )
				),
				'output_schema'   => $this->schema(
					array(
						'taxonomy' => array( 'type' => 'string' ),
						'term_id'  => array( 'type' => 'integer' ),
						'deleted'  => array( 'type' => 'boolean' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
					array( 'taxonomy', 'term_id', 'deleted', 'dry_run' )
				),
				'execute_callback' => array( $this, 'delete_term' ),
			),
			'npcink-abilities-toolkit/merge-terms'              => array(
				'label'           => __( 'Merge Terms', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Moves objects from source terms to a target term, then deletes source terms after host approval.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'manage_categories',
				'required_scopes' => array( 'taxonomy.manage' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'taxonomy'        => array( 'type' => 'string', 'default' => 'category' ),
						'target_term_id'  => $term_id,
						'source_term_ids' => array(
							'type'     => 'array',
							'items'    => $term_id,
							'minItems' => 1,
						),
					),
					array( 'taxonomy', 'target_term_id', 'source_term_ids' )
				),
				'output_schema'   => $this->schema(
					array(
						'taxonomy'         => array( 'type' => 'string' ),
						'target_term_id'   => array( 'type' => 'integer' ),
						'merged'           => array( 'type' => 'boolean' ),
						'merged_count'     => array( 'type' => 'integer' ),
						'removed_term_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'dry_run'          => array( 'type' => 'boolean' ),
					),
					array( 'taxonomy', 'target_term_id', 'merged', 'merged_count', 'dry_run' )
				),
				'execute_callback' => array( $this, 'merge_terms' ),
			),
			'npcink-abilities-toolkit/bulk-update-post-terms'   => array(
				'label'           => __( 'Bulk Update Post Terms', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Bulk updates post or page taxonomy terms after host approval, with computed dry-run previews and per-post before/after audit.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'edit_posts',
				'required_scopes' => array( 'taxonomy.manage', 'post.write' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'post_type'      => array(
							'type'    => 'string',
							'enum'    => array( 'post', 'page' ),
							'default' => 'post',
						),
						'post_status'    => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'draft', 'pending', 'future', 'any' ),
							'default' => 'publish',
						),
						'taxonomy'       => array(
							'type'    => 'string',
							'enum'    => array( 'category', 'post_tag' ),
							'default' => 'post_tag',
						),
						'operation'      => array(
							'type'    => 'string',
							'enum'    => array( 'add', 'replace', 'remove' ),
							'default' => 'add',
						),
						'terms'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'term_ids'       => array(
							'type'  => 'array',
							'items' => $term_id,
						),
						'create_missing' => array( 'type' => 'boolean', 'default' => false ),
						'filters'        => array(
							'type'                 => 'object',
							'properties'           => array(
								'include_term_ids' => array(
									'type'  => 'array',
									'items' => $term_id,
								),
								'exclude_term_ids' => array(
									'type'  => 'array',
									'items' => $term_id,
								),
								'search'           => array( 'type' => 'string' ),
								'date_after'       => array( 'type' => 'string' ),
								'date_before'      => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
						'limit'          => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'sample_limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						),
					)
				),
				'output_schema'   => $this->schema(
					array(
						'matched_count'     => array( 'type' => 'integer' ),
						'affected_count'    => array( 'type' => 'integer' ),
						'sample'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'skipped'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'warnings'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'approval_required' => array( 'type' => 'boolean' ),
						'processed_count'   => array( 'type' => 'integer' ),
						'updated_count'     => array( 'type' => 'integer' ),
						'skipped_count'     => array( 'type' => 'integer' ),
						'failed_count'      => array( 'type' => 'integer' ),
						'items'             => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'dry_run'           => array( 'type' => 'boolean' ),
					)
				),
				'execute_callback' => array( $this, 'bulk_update_post_terms' ),
			),
			'npcink-abilities-toolkit/spam-comment'             => array(
				'label'           => __( 'Spam Comment', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Marks one comment as spam after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'moderate_comments',
				'required_scopes' => array( 'comments.manage' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'comment_id' => $comment_id,
					),
					array( 'comment_id' )
				),
				'output_schema'   => $this->comment_output_schema( true ),
				'execute_callback' => array( $this, 'spam_comment' ),
			),
			'npcink-abilities-toolkit/trash-comment'            => array(
				'label'           => __( 'Trash Comment', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Moves one comment to trash after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'moderate_comments',
				'required_scopes' => array( 'comments.manage' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'comment_id' => $comment_id,
					),
					array( 'comment_id' )
				),
				'output_schema'   => $this->comment_output_schema( true ),
				'execute_callback' => array( $this, 'trash_comment' ),
			),
			'npcink-abilities-toolkit/delete-media-permanently' => array(
				'label'           => __( 'Delete Media Permanently', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Permanently deletes one media attachment after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'delete_posts',
				'required_scopes' => array( 'media.write' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta( false ),
				'input_schema'    => $this->schema(
					array(
						'attachment_id' => $post_id,
					),
					array( 'attachment_id' )
				),
				'output_schema'   => $this->schema(
					array(
						'attachment_id'  => array( 'type' => 'integer' ),
						'deleted'        => array( 'type' => 'boolean' ),
						'parent_post_id' => array( 'type' => 'integer' ),
						'dry_run'        => array( 'type' => 'boolean' ),
					),
					array( 'attachment_id', 'deleted', 'parent_post_id', 'dry_run' )
				),
				'execute_callback' => array( $this, 'delete_media_permanently' ),
			),
			'npcink-abilities-toolkit/trash-post'               => array(
				'label'           => __( 'Trash Post', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Moves one post to trash after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'delete_posts',
				'required_scopes' => array( 'post.delete' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta( false ),
				'input_schema'    => $this->schema(
					array(
						'post_id' => $post_id,
					),
					array( 'post_id' )
				),
				'output_schema'   => $this->schema(
					array(
						'trashed' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					array( 'trashed', 'post_id', 'dry_run' )
				),
				'execute_callback' => array( $this, 'trash_post' ),
			),
			'npcink-abilities-toolkit/delete-post-permanently'  => array(
				'label'           => __( 'Delete Post Permanently', 'npcink-abilities-toolkit' ),
				'description'     => __( 'Permanently deletes one post after host approval, or returns a dry-run preview by default.', 'npcink-abilities-toolkit' ),
				'category'        => 'npcink-abilities-toolkit-write',
				'capability'      => 'delete_posts',
				'required_scopes' => array( 'post.delete' ),
				'channels'        => array( 'agent', 'mcp' ),
				'meta'            => $this->destructive_meta(),
				'input_schema'    => $this->schema(
					array(
						'post_id' => $post_id,
					),
					array( 'post_id' )
				),
				'output_schema'   => $this->schema(
					array(
						'post_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					array( 'post_id', 'deleted', 'dry_run' )
				),
				'execute_callback' => array( $this, 'delete_post_permanently' ),
			),
		);
	}

	/**
	 * Deletes one taxonomy term.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function delete_term( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = $this->valid_taxonomy( $input['taxonomy'] ?? 'category' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term_id = absint( $input['term_id'] ?? 0 );
		$term    = $this->get_term_object( $term_id, $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$cap_check = $this->check_taxonomy_capability( $taxonomy, 'delete_terms' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$payload = array(
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
			'deleted'  => false,
			'preview'  => array(
				'action'   => 'delete_term',
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
				'name'     => sanitize_text_field( (string) ( $term->name ?? '' ) ),
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/delete-term', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$deleted = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}
		if ( false === $deleted ) {
			return new \WP_Error( 'npcink_abilities_toolkit_term_delete_failed', __( 'Term deletion failed.', 'npcink-abilities-toolkit' ), array( 'status' => 500 ) );
		}

		$payload['deleted'] = true;
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Merges source terms into one target term and deletes source terms.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function merge_terms( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = $this->valid_taxonomy( $input['taxonomy'] ?? 'category' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$target_term_id = absint( $input['target_term_id'] ?? 0 );
		$target_term    = $this->get_term_object( $target_term_id, $taxonomy );
		if ( is_wp_error( $target_term ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_target_term_not_found', __( 'Target term was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		$cap_check = $this->check_taxonomy_capability( $taxonomy, 'manage_terms' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$cap_check = $this->check_taxonomy_capability( $taxonomy, 'delete_terms' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$source_term_ids = is_array( $input['source_term_ids'] ?? null ) ? $input['source_term_ids'] : array();
		$source_term_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_term_ids ) ) ) );
		$source_term_ids = array_values(
			array_filter(
				$source_term_ids,
				static function ( $term_id ) use ( $target_term_id ) {
					return (int) $term_id !== (int) $target_term_id;
				}
			)
		);
		if ( empty( $source_term_ids ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_source_terms_required', __( 'At least one source term is required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$valid_source_ids = array();
		foreach ( $source_term_ids as $source_term_id ) {
			if ( ! is_wp_error( $this->get_term_object( $source_term_id, $taxonomy ) ) ) {
				$valid_source_ids[] = $source_term_id;
			}
		}
		$valid_source_ids = array_values( array_unique( $valid_source_ids ) );
		if ( empty( $valid_source_ids ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_source_terms_not_found', __( 'Source terms were not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'taxonomy'         => $taxonomy,
			'target_term_id'   => $target_term_id,
			'merged'           => false,
			'merged_count'     => 0,
			'removed_term_ids' => array(),
			'preview'          => array(
				'action'          => 'merge_terms',
				'taxonomy'        => $taxonomy,
				'target_term_id'  => $target_term_id,
				'source_term_ids' => $valid_source_ids,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/merge-terms', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$removed_term_ids = array();
		foreach ( $valid_source_ids as $source_term_id ) {
			$object_ids = get_objects_in_term( $source_term_id, $taxonomy );
			$object_ids = is_array( $object_ids ) ? $object_ids : array();
			foreach ( $object_ids as $object_id ) {
				$object_id = absint( $object_id );
				if ( $object_id <= 0 ) {
					continue;
				}
				$current_term_ids = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $current_term_ids ) ) {
					continue;
				}
				$current_term_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $current_term_ids ) ) ) );
				$next_term_ids    = array_values( array_unique( array_merge( array_diff( $current_term_ids, array( $source_term_id ) ), array( $target_term_id ) ) ) );
				wp_set_object_terms( $object_id, $next_term_ids, $taxonomy, false );
			}

			$deleted = wp_delete_term( $source_term_id, $taxonomy );
			if ( is_wp_error( $deleted ) || false === $deleted ) {
				continue;
			}
			$removed_term_ids[] = $source_term_id;
		}

		$payload['merged']           = ! empty( $removed_term_ids );
		$payload['merged_count']     = count( $removed_term_ids );
		$payload['removed_term_ids'] = $removed_term_ids;
		$payload['dry_run']          = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Bulk updates post taxonomy terms.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function bulk_update_post_terms( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$validated = $this->validate_bulk_post_terms_input( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$taxonomy = (string) $validated['taxonomy'];
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_taxonomy_invalid', __( 'Taxonomy is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$cap_check = $this->check_taxonomy_capability( $taxonomy, 'assign_terms' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$resolve_result = $this->resolve_term_ids_for_assignment(
			$taxonomy,
			is_array( $validated['term_ids'] ?? null ) ? $validated['term_ids'] : array(),
			is_array( $validated['terms'] ?? null ) ? $validated['terms'] : array(),
			! empty( $validated['create_missing'] ),
			$this->should_dry_run( $input )
		);
		if ( is_wp_error( $resolve_result ) ) {
			return $resolve_result;
		}
		$resolved_term_ids   = is_array( $resolve_result['term_ids'] ?? null ) ? $resolve_result['term_ids'] : array();
		$missing_term_labels = is_array( $resolve_result['missing'] ?? null ) ? $resolve_result['missing'] : array();
		$operation           = (string) $validated['operation'];
		if ( empty( $resolved_term_ids ) && 'replace' !== $operation ) {
			return new \WP_Error( 'npcink_abilities_toolkit_terms_required', __( 'Valid terms or term_ids are required for add/remove operations.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$query = new \WP_Query( $this->bulk_post_terms_query_args( $validated ) );
		$post_ids = is_array( $query->posts ) ? $query->posts : array();
		$matched_count = count( $post_ids );
		$items = array();
		$skipped = array();
		$warnings = array();
		$affected_count = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$skipped[] = array(
					'post_id' => $post_id,
					'reason'  => __( 'Current user cannot edit this post.', 'npcink-abilities-toolkit' ),
				);
				continue;
			}
			$current_term_ids = $this->object_term_ids( $post_id, $taxonomy );
			if ( is_wp_error( $current_term_ids ) ) {
				$skipped[] = array(
					'post_id' => $post_id,
					'reason'  => $current_term_ids->get_error_message(),
				);
				continue;
			}
			$next_term_ids = $this->compute_term_update( $current_term_ids, $resolved_term_ids, $operation );
			$summary       = $this->post_terms_summary( $post_id, $taxonomy, $current_term_ids, $next_term_ids );
			$summary['has_changes'] = $current_term_ids !== $next_term_ids;
			if ( ! empty( $summary['has_changes'] ) ) {
				$affected_count++;
			}
			$items[ $post_id ] = $summary;
		}

		$sample = array();
		$sample_post_ids = array_slice( $post_ids, 0, absint( $validated['sample_limit'] ?? 20 ) );
		foreach ( $sample_post_ids as $sample_post_id ) {
			$sample_post_id = absint( $sample_post_id );
			if ( isset( $items[ $sample_post_id ] ) ) {
				$sample[] = $items[ $sample_post_id ];
			}
		}
		if ( $matched_count >= absint( $validated['limit'] ?? 50 ) ) {
			$warnings[] = sprintf(
				/* translators: %d: query limit. */
				__( 'Matched post count reached limit (%d); more matching posts may exist.', 'npcink-abilities-toolkit' ),
				absint( $validated['limit'] ?? 50 )
			);
		}
		if ( empty( $resolved_term_ids ) && 'replace' === $operation ) {
			$warnings[] = __( 'Replace operation has no valid terms; matching posts will have these terms cleared.', 'npcink-abilities-toolkit' );
		}
		if ( ! empty( $missing_term_labels ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated term labels. */
				__( 'These terms will be created during approved commit: %s', 'npcink-abilities-toolkit' ),
				implode( ', ', $missing_term_labels )
			);
		}

		$payload = array(
			'matched_count'     => $matched_count,
			'affected_count'    => $affected_count,
			'sample'            => $sample,
			'skipped'           => $skipped,
			'warnings'          => $warnings,
			'approval_required' => true,
			'preview'           => array(
				'action'              => 'bulk_update_post_terms',
				'taxonomy'            => $taxonomy,
				'operation'           => $operation,
				'post_type'           => (string) $validated['post_type'],
				'post_status'         => (string) $validated['post_status'],
				'limit'               => absint( $validated['limit'] ?? 50 ),
				'resolved_term_count' => count( $resolved_term_ids ),
				'missing_terms'       => $missing_term_labels,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/bulk-update-post-terms', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! empty( $missing_term_labels ) ) {
			$resolve_result = $this->resolve_term_ids_for_assignment(
				$taxonomy,
				is_array( $validated['term_ids'] ?? null ) ? $validated['term_ids'] : array(),
				is_array( $validated['terms'] ?? null ) ? $validated['terms'] : array(),
				! empty( $validated['create_missing'] ),
				false
			);
			if ( is_wp_error( $resolve_result ) ) {
				return $resolve_result;
			}
			$resolved_term_ids = is_array( $resolve_result['term_ids'] ?? null ) ? $resolve_result['term_ids'] : array();
			foreach ( $items as $post_id => &$summary ) {
				$current_term_ids = is_array( $summary['before']['term_ids'] ?? null ) ? $summary['before']['term_ids'] : array();
				$next_term_ids    = $this->compute_term_update( $current_term_ids, $resolved_term_ids, $operation );
				$summary          = $this->post_terms_summary( absint( $post_id ), $taxonomy, $current_term_ids, $next_term_ids );
				$summary['has_changes'] = $current_term_ids !== $next_term_ids;
			}
			unset( $summary );
		}

		$processed_count = 0;
		$updated_count   = 0;
		$skipped_count   = 0;
		$failed_count    = 0;
		$commit_items    = array();
		foreach ( $items as $post_id => $summary ) {
			$post_id = absint( $post_id );
			$processed_count++;
			if ( empty( $summary['has_changes'] ) ) {
				$skipped_count++;
				$commit_items[] = array(
					'post_id' => $post_id,
					'updated' => false,
					'before'  => $summary['before'],
					'after'   => $summary['after'],
					'error'   => null,
				);
				continue;
			}
			$next_term_ids = is_array( $summary['after']['term_ids'] ?? null ) ? $summary['after']['term_ids'] : array();
			$set_result    = wp_set_post_terms( $post_id, $next_term_ids, $taxonomy, false );
			if ( is_wp_error( $set_result ) ) {
				$failed_count++;
				$commit_items[] = array(
					'post_id' => $post_id,
					'updated' => false,
					'before'  => $summary['before'],
					'after'   => $summary['after'],
					'error'   => $set_result->get_error_message(),
				);
				continue;
			}
			$updated_count++;
			$applied_term_ids = $this->object_term_ids( $post_id, $taxonomy );
			$applied_term_ids = is_wp_error( $applied_term_ids ) ? $next_term_ids : $applied_term_ids;
			$commit_items[] = array(
				'post_id' => $post_id,
				'updated' => true,
				'before'  => $summary['before'],
				'after'   => array(
					'count'    => count( $applied_term_ids ),
					'term_ids' => $applied_term_ids,
					'terms'    => $this->collect_term_rows( $taxonomy, $applied_term_ids ),
				),
				'error'   => null,
			);
		}

		return array(
			'processed_count' => $processed_count,
			'updated_count'   => $updated_count,
			'skipped_count'   => $skipped_count,
			'failed_count'    => $failed_count,
			'items'           => $commit_items,
			'dry_run'         => false,
		);
	}

	/**
	 * Marks one comment as spam.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function spam_comment( $input ) {
		return $this->change_comment_status( 'npcink-abilities-toolkit/spam-comment', $input, 'spam', 'spam_comment' );
	}

	/**
	 * Moves one comment to trash.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function trash_comment( $input ) {
		return $this->change_comment_status( 'npcink-abilities-toolkit/trash-comment', $input, 'trash', 'trash_comment' );
	}

	/**
	 * Permanently deletes one attachment.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function delete_media_permanently( $input ) {
		$input         = is_array( $input ) ? $input : array();
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$attachment    = $this->get_media_attachment( $attachment_id );
		if ( is_wp_error( $attachment ) && ! $this->should_dry_run( $input ) ) {
			$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/delete-media-permanently', $input );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			return array(
				'attachment_id'  => $attachment_id,
				'deleted'        => true,
				'parent_post_id' => 0,
				'dry_run'        => false,
			);
		}
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to delete this attachment.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$payload = array(
			'attachment_id'  => $attachment_id,
			'deleted'        => false,
			'parent_post_id' => absint( $attachment->post_parent ?? 0 ),
			'preview'        => array(
				'action'          => 'delete_media_permanently',
				'attachment_id'   => $attachment_id,
				'parent_post_id'  => absint( $attachment->post_parent ?? 0 ),
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/delete-media-permanently', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$deleted = function_exists( 'wp_delete_attachment' )
			? wp_delete_attachment( $attachment_id, true )
			: wp_delete_post( $attachment_id, true );
		if ( ! $deleted ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_delete_failed', __( 'Attachment deletion failed.', 'npcink-abilities-toolkit' ), array( 'status' => 500 ) );
		}

		$payload['deleted'] = true;
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Moves one post to trash.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function trash_post( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $this->get_deletable_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$payload = array(
			'trashed' => false,
			'post_id' => $post_id,
			'preview' => array(
				'action'      => 'trash_post',
				'from_status' => sanitize_key( (string) ( $post->post_status ?? '' ) ),
				'to_status'   => 'trash',
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/trash-post', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return new \WP_Error( 'npcink_abilities_toolkit_trash_failed', __( 'Post trash failed.', 'npcink-abilities-toolkit' ), array( 'status' => 500 ) );
		}

		$payload['trashed'] = true;
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Permanently deletes one post.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function delete_post_permanently( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $this->get_deletable_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$payload = array(
			'post_id' => $post_id,
			'deleted' => false,
			'preview' => array(
				'action'      => 'delete_post_permanently',
				'from_status' => sanitize_key( (string) ( $post->post_status ?? '' ) ),
				'to_status'   => 'deleted',
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/delete-post-permanently', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			return new \WP_Error( 'npcink_abilities_toolkit_delete_failed', __( 'Post deletion failed.', 'npcink-abilities-toolkit' ), array( 'status' => 500 ) );
		}

		$payload['deleted'] = true;
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Builds shared destructive metadata.
	 *
	 * @param bool $mcp_public Whether the ability is public on the governed MCP write server.
	 * @return array<string,mixed>
	 */
	private function destructive_meta( $mcp_public = true ) {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => $mcp_public,
				'server' => 'npcink-abilities-toolkit-write',
				'risk'   => 'destructive',
			),
		);
	}

	/**
	 * Adds static agent and MCP selection guidance to priority destructive abilities.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return array<string,mixed>
	 */
	private function with_agent_usage_metadata( $ability_id, array $definition ) {
		if ( 'npcink-abilities-toolkit/delete-media-permanently' !== $ability_id ) {
			return $definition;
		}

		$definition['agent_usage'] = array(
			'when_to_use'     => array(
				'Prepare or commit permanent deletion of one media attachment after explicit review.',
			),
			'not_for'         => array(
				'Do not use this for cleanup discovery, reversible trash actions, or bulk deletion.',
			),
			'best_for'        => array(
				'Executing one approved destructive media cleanup action with a known attachment id.',
			),
			'stopping_points' => array(
				'Default to dry_run; final permanent deletion requires host approval context and idempotency protection.',
			),
		);

		return $definition;
	}

	/**
	 * Builds comment status output schema.
	 *
	 * @param bool $include_post_id Whether post_id is required in the migrated contract.
	 * @return array<string,mixed>
	 */
	private function comment_output_schema( $include_post_id ) {
		$properties = array(
			'comment_id'     => array( 'type' => 'integer' ),
			'updated'        => array( 'type' => 'boolean' ),
			'comment_status' => array( 'type' => 'string' ),
			'dry_run'        => array( 'type' => 'boolean' ),
		);
		$required = array( 'comment_id', 'updated', 'comment_status', 'dry_run' );
		if ( $include_post_id ) {
			$properties['post_id'] = array( 'type' => 'integer' );
			$required[] = 'post_id';
		}
		return $this->schema( $properties, $required );
	}

	/**
	 * Builds an object schema.
	 *
	 * @param array<string,mixed> $properties Schema properties.
	 * @param array<int,string>   $required Required keys.
	 * @return array<string,mixed>
	 */
	private function schema( array $properties, array $required = array() ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Returns whether the request should only preview changes.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return bool
	 */
	private function should_dry_run( array $input ) {
		if ( empty( $input['commit'] ) ) {
			return true;
		}

		return array_key_exists( 'dry_run', $input ) && ! empty( $input['dry_run'] );
	}

	/**
	 * Wraps one dry-run payload.
	 *
	 * @param array<string,mixed> $payload Preview payload.
	 * @return array<string,mixed>
	 */
	private function dry_run_payload( array $payload ) {
		$payload['dry_run']         = true;
		$payload['host_governed']   = true;
		$payload['commit_required'] = true;
		return $payload;
	}

	/**
	 * Returns whether a host runtime approved the final commit.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Input args.
	 * @return true|\WP_Error
	 */
	private function assert_commit_allowed( $ability_id, array $input ) {
		$runtime_context = isset( $GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] ) && is_array( $GLOBALS['npcink_ai_runtime_wp_ability_context']['context'] )
			? $GLOBALS['npcink_ai_runtime_wp_ability_context']['context']
			: array();
		$allowed = ! empty( $runtime_context['approval_commit_authorized'] );
		if ( function_exists( 'apply_filters' ) ) {
			$allowed = (bool) apply_filters( 'npcink_abilities_toolkit_write_commit_allowed', $allowed, $ability_id, $input, $runtime_context );
		}
		if ( $allowed ) {
			return true;
		}

		return new \WP_Error(
			'npcink_abilities_toolkit_host_approval_required',
			__( 'This destructive ability requires approval from a host runtime before commit.', 'npcink-abilities-toolkit' ),
			array(
				'status'       => 403,
				'ability_id'   => $ability_id,
				'host_governed' => true,
			)
		);
	}

	/**
	 * Changes one comment status with host-governed commit.
	 *
	 * @param string $ability_id Ability id.
	 * @param mixed  $input Input args.
	 * @param string $status Target status.
	 * @param string $action Preview action.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function change_comment_status( $ability_id, $input, $status, $action ) {
		$input      = is_array( $input ) ? $input : array();
		$comment    = $this->get_comment_for_write( absint( $input['comment_id'] ?? 0 ) );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}
		$comment_id = absint( $comment->comment_ID ?? 0 );

		$payload = array(
			'comment_id'     => $comment_id,
			'post_id'        => absint( $comment->comment_post_ID ?? 0 ),
			'updated'        => false,
			'comment_status' => sanitize_key( (string) ( $comment->comment_approved ?? '' ) ),
			'preview'        => array(
				'action'      => $action,
				'from_status' => sanitize_key( (string) ( $comment->comment_approved ?? '' ) ),
				'to_status'   => $status,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( $ability_id, $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( 'spam' === $status ) {
			wp_spam_comment( $comment_id );
		} elseif ( 'trash' === $status ) {
			wp_trash_comment( $comment_id );
		} else {
			wp_set_comment_status( $comment_id, $status, true );
		}
		$updated = get_comment( $comment_id );

		$payload['post_id']        = absint( $updated->comment_post_ID ?? $comment->comment_post_ID ?? 0 );
		$payload['updated']        = true;
		$payload['comment_status'] = sanitize_key( (string) ( $updated->comment_approved ?? $status ) );
		$payload['dry_run']        = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Resolves one comment for write operations.
	 *
	 * @param int $comment_id Comment id.
	 * @return object|\WP_Error
	 */
	private function get_comment_for_write( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_comment_invalid', __( 'Comment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new \WP_Error( 'npcink_abilities_toolkit_comment_not_found', __( 'Comment was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to moderate comments.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}
		return $comment;
	}

	/**
	 * Validates and normalizes bulk post terms input.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_bulk_post_terms_input( array $input ) {
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_post_type_invalid', __( 'post_type only supports post and page.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$post_status = sanitize_key( (string) ( $input['post_status'] ?? 'publish' ) );
		if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'future', 'any' ), true ) ) {
			$post_status = 'publish';
		}
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? 'post_tag' ) );
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_taxonomy_invalid', __( 'taxonomy only supports category and post_tag.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$operation = sanitize_key( (string) ( $input['operation'] ?? 'add' ) );
		if ( ! in_array( $operation, array( 'add', 'replace', 'remove' ), true ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_operation_invalid', __( 'operation only supports add, replace, and remove.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$limit = min( 200, max( 1, absint( $input['limit'] ?? 50 ) ) );
		$sample_limit = min( 50, max( 1, absint( $input['sample_limit'] ?? 20 ) ) );
		$filters = is_array( $input['filters'] ?? null ) ? $input['filters'] : array();
		$normalized_filters = array(
			'include_term_ids' => $this->normalize_positive_ids( is_array( $filters['include_term_ids'] ?? null ) ? $filters['include_term_ids'] : array() ),
			'exclude_term_ids' => $this->normalize_positive_ids( is_array( $filters['exclude_term_ids'] ?? null ) ? $filters['exclude_term_ids'] : array() ),
			'search'           => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'date_after'       => sanitize_text_field( (string) ( $filters['date_after'] ?? '' ) ),
			'date_before'      => sanitize_text_field( (string) ( $filters['date_before'] ?? '' ) ),
		);
		$terms = array();
		foreach ( is_array( $input['terms'] ?? null ) ? $input['terms'] : array() as $term ) {
			$term = sanitize_text_field( (string) $term );
			if ( '' !== $term ) {
				$terms[] = $term;
			}
		}
		return array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'taxonomy'       => $taxonomy,
			'operation'      => $operation,
			'term_ids'       => $this->normalize_positive_ids( is_array( $input['term_ids'] ?? null ) ? $input['term_ids'] : array() ),
			'terms'          => array_values( array_unique( $terms ) ),
			'create_missing' => ! empty( $input['create_missing'] ),
			'filters'        => $normalized_filters,
			'limit'          => $limit,
			'sample_limit'   => $sample_limit,
		);
	}

	/**
	 * Normalizes positive integer IDs.
	 *
	 * @param array<mixed> $ids Raw ids.
	 * @return array<int,int>
	 */
	private function normalize_positive_ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Builds query args for bulk post terms.
	 *
	 * @param array<string,mixed> $input Normalized input.
	 * @return array<string,mixed>
	 */
	private function bulk_post_terms_query_args( array $input ) {
		$args = array(
			'post_type'              => $input['post_type'],
			'posts_per_page'         => $input['limit'],
			'post_status'            => $input['post_status'],
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$filters = is_array( $input['filters'] ?? null ) ? $input['filters'] : array();
		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = $filters['search'];
		}
		if ( ! empty( $filters['date_after'] ) || ! empty( $filters['date_before'] ) ) {
			$args['date_query'] = array();
			if ( ! empty( $filters['date_after'] ) ) {
				$args['date_query'][] = array(
					'after'     => $filters['date_after'],
					'inclusive' => true,
				);
			}
			if ( ! empty( $filters['date_before'] ) ) {
				$args['date_query'][] = array(
					'before'    => $filters['date_before'],
					'inclusive' => true,
				);
			}
		}
		if ( ! empty( $filters['include_term_ids'] ) || ! empty( $filters['exclude_term_ids'] ) ) {
			$tax_query = array();
			if ( ! empty( $filters['include_term_ids'] ) ) {
				$tax_query[] = array(
					'taxonomy' => $input['taxonomy'],
					'field'    => 'term_id',
					'terms'    => $filters['include_term_ids'],
					'operator' => 'IN',
				);
			}
			if ( ! empty( $filters['exclude_term_ids'] ) ) {
				$tax_query[] = array(
					'taxonomy' => $input['taxonomy'],
					'field'    => 'term_id',
					'terms'    => $filters['exclude_term_ids'],
					'operator' => 'NOT IN',
				);
			}
				if ( count( $tax_query ) > 1 ) {
					$tax_query['relation'] = 'AND';
				}
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Destructive plans honor user-selected taxonomy filters and are bounded by caller limits.
				$args['tax_query'] = $tax_query;
			}
			return $args;
	}

	/**
	 * Resolves term IDs from explicit IDs and labels.
	 *
	 * @param string       $taxonomy Taxonomy id.
	 * @param array<mixed> $term_ids Explicit term ids.
	 * @param array<mixed> $terms Term labels.
	 * @param bool         $create_missing Whether missing terms can be created during commit.
	 * @param bool         $dry_run Whether this is a dry-run.
	 * @return array{term_ids:array<int,int>,missing:array<int,string>}|\WP_Error
	 */
	private function resolve_term_ids_for_assignment( $taxonomy, array $term_ids, array $terms, $create_missing, $dry_run ) {
		$resolved = array();
		$missing  = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) && is_object( $term ) && ! empty( $term->term_id ) ) {
				$resolved[] = absint( $term->term_id );
			}
		}
		foreach ( $terms as $raw_term ) {
			$term_label = sanitize_text_field( (string) $raw_term );
			if ( '' === $term_label ) {
				continue;
			}
			$term_slug = sanitize_title( $term_label );
			$term      = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( ! is_object( $term ) || empty( $term->term_id ) ) {
				$term = get_term_by( 'name', $term_label, $taxonomy );
			}
			if ( is_object( $term ) && ! empty( $term->term_id ) ) {
				$resolved[] = absint( $term->term_id );
				continue;
			}
			if ( ! $create_missing ) {
				continue;
			}
			$missing[] = $term_label;
			if ( $dry_run ) {
				continue;
			}
			$cap_check = $this->check_taxonomy_capability( $taxonomy, 'manage_terms' );
			if ( is_wp_error( $cap_check ) ) {
				return $cap_check;
			}
			$created = wp_insert_term( $term_label, $taxonomy, array( 'slug' => $term_slug ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$created_term_id = absint( $created['term_id'] ?? 0 );
			if ( $created_term_id > 0 ) {
				$resolved[] = $created_term_id;
			}
		}
		return array(
			'term_ids' => $this->normalize_positive_ids( $resolved ),
			'missing'  => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * Returns object term IDs.
	 *
	 * @param int    $object_id Object id.
	 * @param string $taxonomy Taxonomy id.
	 * @return array<int,int>|\WP_Error
	 */
	private function object_term_ids( $object_id, $taxonomy ) {
		$term_ids = wp_get_object_terms( absint( $object_id ), $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		return $this->normalize_positive_ids( (array) $term_ids );
	}

	/**
	 * Computes next term IDs for a bulk operation.
	 *
	 * @param array<int> $current_term_ids Current term ids.
	 * @param array<int> $resolved_term_ids Target term ids.
	 * @param string     $operation Operation.
	 * @return array<int,int>
	 */
	private function compute_term_update( array $current_term_ids, array $resolved_term_ids, $operation ) {
		$current_term_ids  = $this->normalize_positive_ids( $current_term_ids );
		$resolved_term_ids = $this->normalize_positive_ids( $resolved_term_ids );
		if ( 'add' === $operation ) {
			return array_values( array_unique( array_merge( $current_term_ids, $resolved_term_ids ) ) );
		}
		if ( 'remove' === $operation ) {
			return array_values( array_diff( $current_term_ids, $resolved_term_ids ) );
		}
		return $resolved_term_ids;
	}

	/**
	 * Builds per-post term summary.
	 *
	 * @param int        $post_id Post id.
	 * @param string     $taxonomy Taxonomy id.
	 * @param array<int> $current_term_ids Current term ids.
	 * @param array<int> $next_term_ids Next term ids.
	 * @return array<string,mixed>
	 */
	private function post_terms_summary( $post_id, $taxonomy, array $current_term_ids, array $next_term_ids ) {
		return array(
			'post_id'          => absint( $post_id ),
			'before'           => array(
				'count'    => count( $current_term_ids ),
				'term_ids' => $current_term_ids,
				'terms'    => $this->collect_term_rows( $taxonomy, $current_term_ids ),
			),
			'after'            => array(
				'count'    => count( $next_term_ids ),
				'term_ids' => $next_term_ids,
				'terms'    => $this->collect_term_rows( $taxonomy, $next_term_ids ),
			),
			'added_term_ids'   => array_values( array_diff( $next_term_ids, $current_term_ids ) ),
			'removed_term_ids' => array_values( array_diff( $current_term_ids, $next_term_ids ) ),
		);
	}

	/**
	 * Collects normalized term rows.
	 *
	 * @param string     $taxonomy Taxonomy id.
	 * @param array<int> $term_ids Term ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_term_rows( $taxonomy, array $term_ids ) {
		$rows = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || ! is_object( $term ) || empty( $term->term_id ) ) {
				continue;
			}
			$rows[] = array(
				'term_id' => absint( $term->term_id ),
				'slug'    => sanitize_title( (string) ( $term->slug ?? '' ) ),
				'name'    => sanitize_text_field( (string) ( $term->name ?? '' ) ),
			);
		}
		return $rows;
	}

	/**
	 * Returns a valid taxonomy id.
	 *
	 * @param mixed $taxonomy Raw taxonomy.
	 * @return string|\WP_Error
	 */
	private function valid_taxonomy( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_taxonomy_invalid', __( 'Taxonomy is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		return $taxonomy;
	}

	/**
	 * Checks a taxonomy capability.
	 *
	 * @param string $taxonomy Taxonomy id.
	 * @param string $cap_key Capability key on taxonomy object.
	 * @return true|\WP_Error
	 */
	private function check_taxonomy_capability( $taxonomy, $cap_key ) {
		$taxonomy_object = get_taxonomy( $taxonomy );
		$capability      = is_object( $taxonomy_object ) && ! empty( $taxonomy_object->cap->{$cap_key} )
			? sanitize_key( (string) $taxonomy_object->cap->{$cap_key} )
			: 'manage_categories';
		if ( '' !== $capability && ! current_user_can( $capability ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission for this taxonomy operation.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Returns one term object.
	 *
	 * @param int    $term_id Term id.
	 * @param string $taxonomy Taxonomy id.
	 * @return object|\WP_Error
	 */
	private function get_term_object( $term_id, $taxonomy ) {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_term_invalid', __( 'Term ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_term_not_found', __( 'Term was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		return $term;
	}

	/**
	 * Returns one editable attachment object.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return object|\WP_Error
	 */
	private function get_media_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		return $attachment;
	}

	/**
	 * Returns one deletable post object.
	 *
	 * @param int $post_id Post id.
	 * @return object|\WP_Error
	 */
	private function get_deletable_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_post_invalid', __( 'Post ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'npcink_abilities_toolkit_post_not_found', __( 'Post was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to delete this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}
		return $post;
	}
}
