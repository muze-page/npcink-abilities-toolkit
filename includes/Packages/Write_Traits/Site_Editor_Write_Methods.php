<?php
/**
 * Site Editor and Gutenberg write methods for Core_Write_Package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

use Npcink_Abilities_Toolkit\Support\Gutenberg_Block_Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Provides host-governed post, template, and template-part block writes.
 */
trait Site_Editor_Write_Methods {
	/**
	 * Writes Gutenberg blocks into one post.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update_post_blocks( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $this->get_editable_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$mode = sanitize_key( (string) ( $input['mode'] ?? 'replace' ) );
		if ( ! in_array( $mode, array( 'replace', 'append' ), true ) ) {
			$mode = 'replace';
		}
		$validate_roundtrip = ! array_key_exists( 'validate_roundtrip', $input ) || ! empty( $input['validate_roundtrip'] );
		$raw_blocks         = is_array( $input['blocks'] ?? null ) ? $input['blocks'] : array();
		if ( empty( $raw_blocks ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_required', __( 'Blocks are required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$block_errors            = array();
		$normalized_input_blocks = $this->normalize_blocks_input( $raw_blocks, $block_errors, 'blocks' );
		if ( ! empty( $block_errors ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_invalid', __( 'Blocks structure is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'errors' => $block_errors ) );
		}

		$before_content       = (string) ( $post->post_content ?? '' );
		$before_parsed_blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $before_content ) : array();
		$before_block_count   = $this->count_blocks_recursive( $before_parsed_blocks );
		$target_blocks        = $normalized_input_blocks;
		if ( 'append' === $mode ) {
			$existing_errors = array();
			$existing_blocks = $this->normalize_blocks_input( $before_parsed_blocks, $existing_errors, 'existing' );
			$target_blocks   = array_merge( $existing_blocks, $normalized_input_blocks );
		}

		$after_content            = $this->serialize_blocks_native( $target_blocks );
		$content_size_check       = $this->assert_text_size( $after_content, 'blocks', Gutenberg_Block_Document::MAX_SERIALIZED_BYTES );
		if ( is_wp_error( $content_size_check ) ) {
			return $content_size_check;
		}
		$after_parsed_blocks      = function_exists( 'parse_blocks' ) ? parse_blocks( $after_content ) : array();
		$after_block_count        = $this->count_blocks_recursive( $target_blocks );
		$parsed_top_level_count   = is_array( $after_parsed_blocks ) ? count( $after_parsed_blocks ) : 0;
		$expected_top_level_count = count( $target_blocks );
		$roundtrip_checked        = $validate_roundtrip && function_exists( 'parse_blocks' );
		$roundtrip_ok             = true;
		if ( $roundtrip_checked ) {
			$roundtrip_ok = $expected_top_level_count === $parsed_top_level_count;
		}
		$validation = array(
			'valid'                    => $roundtrip_ok,
			'roundtrip_checked'        => $roundtrip_checked,
			'roundtrip_ok'             => $roundtrip_ok,
			'parse_available'          => function_exists( 'parse_blocks' ),
			'expected_top_level_count' => $expected_top_level_count,
			'parsed_top_level_count'   => $parsed_top_level_count,
			'errors'                   => $block_errors,
		);
		$updated = $before_content !== $after_content;

		$payload = array(
			'post_id'               => $post_id,
			'updated'               => $updated,
			'status'                => 'dry_run',
			'mode'                  => $mode,
			'edit_link'             => $this->edit_link( $post_id ),
			'block_count_before'    => $before_block_count,
			'block_count_after'     => $after_block_count,
			'content_length_before' => strlen( $before_content ),
			'content_length_after'  => strlen( $after_content ),
			'validation'            => $validation,
			'impact_ranges'         => $this->build_impact_ranges_from_diff( $before_content, $after_content ),
			'diff_preview'          => $this->build_text_diff_preview( $before_content, $after_content ),
			'preview'               => array(
				'action' => 'update_post_blocks',
				'mode'   => $mode,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}
		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/update-post-blocks', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( $roundtrip_checked && ! $roundtrip_ok ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_roundtrip_invalid', __( 'Blocks roundtrip validation failed; write was blocked.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'validation' => $validation ) );
		}

		if ( $updated ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $after_content ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$payload['status']  = $this->post_status( $post_id );
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Updates one block theme template block tree.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update_template_blocks( $input ) {
		return $this->update_site_editor_entity_blocks( $input, 'wp_template', 'npcink-abilities-toolkit/update-template-blocks' );
	}

	/**
	 * Creates or updates one block theme template override block tree.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function upsert_template_blocks( $input ) {
		$input              = is_array( $input ) ? $input : array();
		$post_id            = absint( $input['post_id'] ?? 0 );
		$slug               = $this->normalize_template_slug( (string) ( $input['slug'] ?? '' ) );
		$theme              = sanitize_key( (string) ( $input['theme'] ?? '' ) );
		$title              = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$source_template_id = sanitize_text_field( (string) ( $input['source_template_id'] ?? '' ) );
		$mode               = sanitize_key( (string) ( $input['mode'] ?? 'replace' ) );
		$validate_roundtrip = ! array_key_exists( 'validate_roundtrip', $input ) || ! empty( $input['validate_roundtrip'] );
		$raw_blocks         = $input['blocks'] ?? array();
		if ( 'replace' !== $mode ) {
			return new \WP_Error( 'npcink_abilities_toolkit_site_editor_block_mode_invalid', __( 'Site Editor block writes currently support mode=replace only.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to edit block theme templates.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}
		if ( '' === $slug ) {
			return new \WP_Error( 'npcink_abilities_toolkit_template_slug_required', __( 'Template slug is required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( '' === $theme ) {
			$theme = $this->active_theme_stylesheet();
		}
		if ( '' === $title ) {
			$title = ucwords( str_replace( '-', ' ', $slug ) );
		}
		if ( ! is_array( $raw_blocks ) || empty( $raw_blocks ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_required', __( 'Blocks are required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$existing_post = $post_id > 0 ? get_post( $post_id ) : $this->find_template_override_post( $theme, $slug );
		if ( $existing_post && 'wp_template' !== sanitize_key( (string) ( $existing_post->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_site_editor_post_type_invalid', __( 'Site Editor entity post type is invalid for this ability.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'expected_post_type' => 'wp_template' ) );
		}
		$existing_post_id = is_object( $existing_post ) ? absint( $existing_post->ID ?? 0 ) : 0;

		$block_errors  = array();
		$target_blocks = $this->normalize_blocks_input( $raw_blocks, $block_errors, 'blocks' );
		if ( ! empty( $block_errors ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_invalid', __( 'Blocks structure is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'errors' => $block_errors ) );
		}

		$before_content       = is_object( $existing_post ) ? (string) ( $existing_post->post_content ?? '' ) : '';
		$before_parsed_blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $before_content ) : array();
		$after_content        = $this->serialize_blocks_native( $target_blocks );
		$content_size_check   = $this->assert_text_size( $after_content, 'blocks', Gutenberg_Block_Document::MAX_SERIALIZED_BYTES );
		if ( is_wp_error( $content_size_check ) ) {
			return $content_size_check;
		}
		$after_parsed_blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $after_content ) : array();
		$roundtrip_checked    = $validate_roundtrip && function_exists( 'parse_blocks' );
		$roundtrip_ok         = true;
		if ( $roundtrip_checked ) {
			$roundtrip_ok = count( $target_blocks ) === count( is_array( $after_parsed_blocks ) ? $after_parsed_blocks : array() );
		}
		$validation = array(
			'valid'                    => $roundtrip_ok,
			'roundtrip_checked'        => $roundtrip_checked,
			'roundtrip_ok'             => $roundtrip_ok,
			'parse_available'          => function_exists( 'parse_blocks' ),
			'expected_top_level_count' => count( $target_blocks ),
			'parsed_top_level_count'   => count( is_array( $after_parsed_blocks ) ? $after_parsed_blocks : array() ),
			'errors'                   => $block_errors,
		);
		$created = 0 === $existing_post_id;
		$updated = $created || $before_content !== $after_content;
		$payload = array(
			'post_id'               => $existing_post_id,
			'post_type'             => 'wp_template',
			'slug'                  => $slug,
			'theme'                 => $theme,
			'source_template_id'    => $source_template_id,
			'created'               => $created,
			'updated'               => $updated,
			'status'                => 'dry_run',
			'mode'                  => $mode,
			'edit_link'             => $existing_post_id > 0 ? $this->edit_link( $existing_post_id ) : '',
			'block_count_before'    => $this->count_blocks_recursive( $before_parsed_blocks ),
			'block_count_after'     => $this->count_blocks_recursive( $target_blocks ),
			'content_length_before' => strlen( $before_content ),
			'content_length_after'  => strlen( $after_content ),
			'validation'            => $validation,
			'impact_ranges'         => $this->build_impact_ranges_from_diff( $before_content, $after_content ),
			'diff_preview'          => $this->build_text_diff_preview( $before_content, $after_content ),
			'preview'               => array(
				'action' => 'upsert_template_blocks',
				'mode'   => $mode,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}

		$allowed = $this->assert_commit_allowed( 'npcink-abilities-toolkit/upsert-template-blocks', $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( $roundtrip_checked && ! $roundtrip_ok ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_roundtrip_invalid', __( 'Blocks roundtrip validation failed; write was blocked.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'validation' => $validation ) );
		}

		if ( $existing_post_id > 0 ) {
			if ( $updated ) {
				$result = wp_update_post(
					array(
						'ID'           => $existing_post_id,
						'post_title'   => $title,
						'post_content' => $after_content,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$post_id = $existing_post_id;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'wp_template',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $after_content,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			$post_id = absint( $post_id );
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'wp_theme' ) && function_exists( 'wp_set_post_terms' ) ) {
				wp_set_post_terms( $post_id, $theme, 'wp_theme' );
			}
		}

		$payload['post_id']  = $post_id;
		$payload['status']   = $this->post_status( $post_id );
		$payload['edit_link'] = $this->edit_link( $post_id );
		$payload['dry_run']  = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Updates one block theme template part block tree.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update_template_part_blocks( $input ) {
		return $this->update_site_editor_entity_blocks( $input, 'wp_template_part', 'npcink-abilities-toolkit/update-template-part-blocks' );
	}

	/**
	 * Updates a Site Editor post content block tree after host approval.
	 *
	 * @param mixed  $input Input args.
	 * @param string $expected_post_type Expected post type.
	 * @param string $ability_id Ability id.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function update_site_editor_entity_blocks( $input, $expected_post_type, $ability_id ) {
		$input               = is_array( $input ) ? $input : array();
		$post_id             = absint( $input['post_id'] ?? 0 );
		$mode                = sanitize_key( (string) ( $input['mode'] ?? 'replace' ) );
		$validate_roundtrip  = ! array_key_exists( 'validate_roundtrip', $input ) || ! empty( $input['validate_roundtrip'] );
		$raw_blocks          = $input['blocks'] ?? array();
		$expected_post_type  = sanitize_key( (string) $expected_post_type );
		if ( 'replace' !== $mode ) {
			return new \WP_Error( 'npcink_abilities_toolkit_site_editor_block_mode_invalid', __( 'Site Editor block writes currently support mode=replace only.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to edit block theme templates.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$post = $this->get_editable_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( $expected_post_type !== sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_site_editor_post_type_invalid', __( 'Site Editor entity post type is invalid for this ability.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'expected_post_type' => $expected_post_type ) );
		}
		if ( ! is_array( $raw_blocks ) || empty( $raw_blocks ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_required', __( 'Blocks are required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$block_errors  = array();
		$target_blocks = $this->normalize_blocks_input( $raw_blocks, $block_errors, 'blocks' );
		if ( ! empty( $block_errors ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_invalid', __( 'Blocks structure is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'errors' => $block_errors ) );
		}

		$before_content       = (string) ( $post->post_content ?? '' );
		$before_parsed_blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $before_content ) : array();
		$after_content        = $this->serialize_blocks_native( $target_blocks );
		$content_size_check   = $this->assert_text_size( $after_content, 'blocks', Gutenberg_Block_Document::MAX_SERIALIZED_BYTES );
		if ( is_wp_error( $content_size_check ) ) {
			return $content_size_check;
		}
		$after_parsed_blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $after_content ) : array();
		$roundtrip_checked    = $validate_roundtrip && function_exists( 'parse_blocks' );
		$roundtrip_ok         = true;
		if ( $roundtrip_checked ) {
			$roundtrip_ok = count( $target_blocks ) === count( is_array( $after_parsed_blocks ) ? $after_parsed_blocks : array() );
		}
		$validation = array(
			'valid'                    => $roundtrip_ok,
			'roundtrip_checked'        => $roundtrip_checked,
			'roundtrip_ok'             => $roundtrip_ok,
			'parse_available'          => function_exists( 'parse_blocks' ),
			'expected_top_level_count' => count( $target_blocks ),
			'parsed_top_level_count'   => count( is_array( $after_parsed_blocks ) ? $after_parsed_blocks : array() ),
			'errors'                   => $block_errors,
		);
		$updated = $before_content !== $after_content;
		$payload = array(
			'post_id'               => $post_id,
			'post_type'             => $expected_post_type,
			'slug'                  => sanitize_key( (string) ( $post->post_name ?? '' ) ),
			'updated'               => $updated,
			'status'                => 'dry_run',
			'mode'                  => $mode,
			'edit_link'             => $this->edit_link( $post_id ),
			'block_count_before'    => $this->count_blocks_recursive( $before_parsed_blocks ),
			'block_count_after'     => $this->count_blocks_recursive( $target_blocks ),
			'content_length_before' => strlen( $before_content ),
			'content_length_after'  => strlen( $after_content ),
			'validation'            => $validation,
			'impact_ranges'         => $this->build_impact_ranges_from_diff( $before_content, $after_content ),
			'diff_preview'          => $this->build_text_diff_preview( $before_content, $after_content ),
			'preview'               => array(
				'action'    => 'update_site_editor_entity_blocks',
				'post_type' => $expected_post_type,
				'mode'      => $mode,
			),
		);
		if ( $this->should_dry_run( $input ) ) {
			return $this->dry_run_payload( $payload );
		}

		$allowed = $this->assert_commit_allowed( $ability_id, $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( $roundtrip_checked && ! $roundtrip_ok ) {
			return new \WP_Error( 'npcink_abilities_toolkit_blocks_roundtrip_invalid', __( 'Blocks roundtrip validation failed; write was blocked.', 'npcink-abilities-toolkit' ), array( 'status' => 400, 'validation' => $validation ) );
		}

		if ( $updated ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $after_content ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$payload['status']  = $this->post_status( $post_id );
		$payload['dry_run'] = false;
		unset( $payload['preview'] );
		return $payload;
	}

	/**
	 * Normalizes a Site Editor template slug.
	 *
	 * @param string $value Raw slug or template id.
	 * @return string
	 */
	private function normalize_template_slug( $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, '//' ) ) {
			$parts = explode( '//', $value );
			$value = (string) end( $parts );
		}
		return sanitize_key( $value );
	}

	/**
	 * Returns the active theme stylesheet.
	 *
	 * @return string
	 */
	private function active_theme_stylesheet() {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ) {
				return sanitize_key( (string) $theme->get_stylesheet() );
			}
		}
		return sanitize_key( (string) ( $GLOBALS['npcink_abilities_toolkit_unit_active_theme']['stylesheet'] ?? 'active-theme' ) );
	}

	/**
	 * Finds an existing wp_template override post for one theme and slug.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $slug Template slug.
	 * @return object|null
	 */
	private function find_template_override_post( $theme, $slug ) {
		$theme = sanitize_key( (string) $theme );
		$slug  = $this->normalize_template_slug( (string) $slug );
		$posts = function_exists( 'get_posts' )
			? get_posts(
				array(
					'post_type'        => 'wp_template',
					'post_status'      => array( 'publish', 'draft', 'auto-draft' ),
					'posts_per_page'   => 50,
					'suppress_filters' => false,
				)
			)
			: array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			if ( ! is_object( $post ) || 'wp_template' !== sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			$post_slug = $this->normalize_template_slug( (string) ( $post->post_name ?? '' ) );
			$post_name = (string) ( $post->post_name ?? '' );
			if ( $slug !== $post_slug ) {
				continue;
			}
			if ( false !== strpos( $post_name, '//' ) ) {
				if ( 0 === strpos( $post_name, $theme . '//' ) ) {
					return $post;
				}
				continue;
			}
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'wp_theme' ) && function_exists( 'wp_get_post_terms' ) ) {
				$terms = wp_get_post_terms( absint( $post->ID ?? 0 ), 'wp_theme', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && ! in_array( $theme, array_map( 'sanitize_key', (array) $terms ), true ) ) {
					continue;
				}
			}
			if ( $slug === $post_slug ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Normalizes one block list payload recursively.
	 *
	 * @param mixed        $blocks Raw block list.
	 * @param array<mixed> $errors Validation error rows.
	 * @param string       $path Path hint.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_blocks_input( $blocks, &$errors, $path = 'root' ) {
		return $this->block_document->normalize_blocks( $blocks, $errors, $path );
	}

	/**
	 * Counts a block tree recursively.
	 *
	 * @param mixed $blocks Block list.
	 * @return int
	 */
	private function count_blocks_recursive( $blocks ) {
		return $this->block_document->count_blocks( $blocks );
	}

	/**
	 * Serializes blocks using WordPress' native parsed-block format.
	 *
	 * @param array<mixed> $blocks Normalized blocks.
	 * @return string
	 */
	private function serialize_blocks_native( array $blocks ) {
		return $this->block_document->serialize_blocks( $blocks );
	}

}
