<?php
/**
 * Page pattern planning methods for Core_Read_Package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds proposal-ready page pattern plans without writing WordPress content.
 */
trait Page_Pattern_Read_Methods {
	/**
	 * Builds one governed page pattern write plan.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_pattern_page_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to plan page drafts.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$pattern_id         = sanitize_key( (string) ( $input['pattern_id'] ?? 'openai-style-landing' ) );
		$style_preset       = sanitize_key( (string) ( $input['style_preset'] ?? 'minimal-dark-light' ) );
		$variables          = is_array( $input['variables'] ?? null ) ? $input['variables'] : array();
		$has_explicit_color_story = array_key_exists( 'color_story', $input ) || array_key_exists( 'color_story', $variables );
		$color_story        = sanitize_key( (string) ( $input['color_story'] ?? ( $variables['color_story'] ?? 'minimal-dark-light' ) ) );
		$responsive_profile = sanitize_key( (string) ( $input['responsive_profile'] ?? 'landing_standard' ) );
		$visual_density     = sanitize_key( (string) ( $input['visual_density'] ?? 'balanced' ) );
		$media_strategy     = sanitize_key( (string) ( $input['media_strategy'] ?? 'mock_or_existing_media' ) );
		$post_type          = sanitize_key( (string) ( $input['post_type'] ?? 'page' ) );
		$target_post_id     = absint( $input['target_post_id'] ?? 0 );
		if ( 'page' !== $post_type ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_post_type_invalid', __( 'Pattern page plans currently support post_type=page only.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( 'openai-style-landing' !== $pattern_id ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_pattern_invalid', __( 'Pattern id is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( 'minimal-dark-light' !== $style_preset ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_style_invalid', __( 'Style preset is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $color_story, array( 'minimal-dark-light', 'editorial-accent' ), true ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_color_story_invalid', __( 'Color story is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( 'landing_standard' !== $responsive_profile ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_responsive_profile_invalid', __( 'Responsive profile is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( 'balanced' !== $visual_density ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_visual_density_invalid', __( 'Visual density is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $media_strategy, array( 'mock_or_existing_media', 'existing_media_url' ), true ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_media_strategy_invalid', __( 'Media strategy is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$target_post = null;
		if ( $target_post_id > 0 ) {
			$target_post = get_post( $target_post_id );
			if ( ! is_object( $target_post ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_target_not_found', __( 'Target page was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $target_post_id ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to update the target page.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}
			if ( 'page' !== sanitize_key( (string) ( $target_post->post_type ?? '' ) ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_target_type_invalid', __( 'Target post must be a page.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}
			if ( 'draft' !== sanitize_key( (string) ( $target_post->post_status ?? '' ) ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_pattern_page_target_status_invalid', __( 'Target page must be a draft.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}
		}

		$research_brief = $this->pattern_research_brief( $input['research_brief'] ?? ( $variables['research_brief'] ?? array() ) );
		$review_feedback = $this->pattern_review_feedback( $input['review_feedback'] ?? ( $variables['review_feedback'] ?? array() ) );
		if ( ! $has_explicit_color_story && in_array( 'color_story_monochrome', (array) ( $review_feedback['finding_codes'] ?? array() ), true ) ) {
			$color_story = 'editorial-accent';
		}
		$section_variant_hints = $this->pattern_section_variant_hints( $input['section_variant_hints'] ?? ( $variables['section_variant_hints'] ?? array() ) );
		$hero_copy_fit = $this->pattern_hero_copy_fit(
			$variables['hero_title'] ?? ( $input['title'] ?? 'WordPress AI' ),
			$variables['hero_description'] ?? ''
		);
		$variables['hero_title'] = $hero_copy_fit['hero_title'];
		if ( '' !== (string) $hero_copy_fit['hero_description'] ) {
			$variables['hero_description'] = $hero_copy_fit['hero_description'];
		}
		$title     = $this->pattern_text( $input['title'] ?? ( $variables['hero_title'] ?? 'WordPress AI' ), 'WordPress AI' );
		$blocks    = $this->render_openai_style_landing_blocks(
			$variables,
			array(
				'responsive_profile'    => $responsive_profile,
				'visual_density'        => $visual_density,
				'media_strategy'        => $media_strategy,
				'color_story'           => $color_story,
				'research_brief'        => $research_brief,
				'review_feedback'       => $review_feedback,
				'section_variant_hints' => $section_variant_hints,
			)
		);
		$design_quality     = $this->pattern_design_quality_summary( $blocks, $research_brief, $color_story );
		$responsive_quality = $this->pattern_responsive_quality_summary( $blocks, $responsive_profile );
		$media_slots        = $this->pattern_media_slots( $variables, $media_strategy );
		$quality_review     = $this->pattern_review_summary_for_blocks( $blocks, true );
		$block_editor_quality_gate = $this->block_editor_plan_quality_gate(
			$quality_review,
			array(
				'profile'      => 'landing_design',
				'surface_kind' => 'post_content',
				'editor'       => 'block_editor',
				'post_type'    => 'page',
				'target_mode'  => $target_post_id > 0 ? 'update_existing' : 'create_draft',
			)
		);
		$revision_strategy  = $this->pattern_revision_strategy( $review_feedback, $quality_review, $media_slots );
		$batch_seed         = wp_json_encode( array( $pattern_id, $style_preset, $color_story, $responsive_profile, $visual_density, $media_strategy, $target_post_id, $title, $variables, $section_variant_hints ) );
		$batch_id           = 'pattern_page_' . substr( md5( is_string( $batch_seed ) ? $batch_seed : $title ), 0, 12 );
		if ( $target_post_id > 0 ) {
			$write_actions = array(
				$this->build_plan_action(
					'update-pattern-page-blocks',
					'npcink-abilities-toolkit/update-post-blocks',
					array(
						'post_id'            => $target_post_id,
						'mode'               => 'replace',
						'validate_roundtrip' => true,
						'blocks'             => $blocks,
					),
					array( 'post.write' ),
					'medium',
					__( 'Replace the existing draft page body with whitelist-class Gutenberg pattern blocks.', 'npcink-abilities-toolkit' )
				),
			);
		} else {
			$write_actions = array(
				$this->build_plan_action(
					'create-pattern-page',
					'npcink-abilities-toolkit/create-draft',
					array(
						'post_type'      => 'page',
						'status'         => 'draft',
						'title'          => $title,
						'content'        => '',
						'content_format' => 'html',
						'meta'           => array(
							'npcink_pattern_id'         => $pattern_id,
							'npcink_style_preset'       => $style_preset,
							'npcink_color_story'        => $color_story,
							'npcink_responsive_profile' => $responsive_profile,
						),
					),
					array( 'post.write' ),
					'medium',
					__( 'Create a draft page before applying reviewed Gutenberg pattern blocks.', 'npcink-abilities-toolkit' )
				),
				$this->build_plan_action(
					'update-pattern-page-blocks',
					'npcink-abilities-toolkit/update-post-blocks',
					array(
						'post_id'            => '$outputs.create-pattern-page.post_id',
						'mode'               => 'replace',
						'validate_roundtrip' => true,
						'blocks'             => $blocks,
					),
					array( 'post.write' ),
					'medium',
					__( 'Replace the draft page body with whitelist-class Gutenberg pattern blocks.', 'npcink-abilities-toolkit' )
				),
			);
		}

		return $this->build_analysis_success_response(
			array(
				'artifact_type'          => 'pattern_page_plan',
				'composition_role'       => 'core_pattern_page_plan',
				'version'                => 1,
				'pattern_id'             => $pattern_id,
				'style_preset'           => $style_preset,
				'color_story'            => $color_story,
				'responsive_profile'     => $responsive_profile,
				'visual_density'         => $visual_density,
				'media_strategy'         => $media_strategy,
				'section_variant_hints'  => $section_variant_hints,
				'research_brief'         => $this->pattern_research_brief_summary( $research_brief ),
				'allowed_classes'        => $this->pattern_allowed_classes(),
				'media_slots'            => $media_slots,
				'block_editor_surface'   => array(
					'surface_kind'      => 'post_content',
					'editor'            => 'block_editor',
					'post_type'         => 'page',
					'target_mode'       => $target_post_id > 0 ? 'update_existing' : 'create_draft',
					'write_ability_id'  => 'npcink-abilities-toolkit/update-post-blocks',
					'create_ability_id' => $target_post_id > 0 ? null : 'npcink-abilities-toolkit/create-draft',
				),
				'target_post'            => $target_post_id > 0 ? array(
					'mode'      => 'update_existing',
					'post_id'   => $target_post_id,
					'post_type' => sanitize_key( (string) ( $target_post->post_type ?? 'page' ) ),
					'status'    => sanitize_key( (string) ( $target_post->post_status ?? 'draft' ) ),
					'title'     => sanitize_text_field( (string) ( $target_post->post_title ?? '' ) ),
				) : array(
					'mode'      => 'create_draft',
					'post_id'   => 0,
					'post_type' => 'page',
					'status'    => 'draft',
					'title'     => $title,
				),
				'copy_quality'           => $hero_copy_fit,
				'quality_feedback'       => $review_feedback,
				'quality_review'         => $quality_review,
				'block_editor_quality_gate' => $block_editor_quality_gate,
				'revision_strategy'      => $revision_strategy,
				'write_posture'          => 'core_proposal_handoff',
				'direct_wordpress_write' => false,
				'requires_approval'      => true,
				'dry_run'                => true,
				'commit_execution'       => false,
				'proposal_mode'          => 'batch',
				'batch_id'               => $batch_id,
				'summary'                => array(
					'title'        => $title,
					'block_count'  => $this->count_pattern_blocks_recursive( $blocks ),
					'action_count' => count( $write_actions ),
				),
				'composition_contract'   => $this->gutenberg_block_composition_contract( $blocks, 'page' ),
				'design_quality'         => $design_quality,
				'responsive_quality'     => $responsive_quality,
				'write_actions'          => $write_actions,
				'handoff'                => array(
					'plan_ability_id'        => 'npcink-abilities-toolkit/build-pattern-page-plan',
					'recipe_id'              => 'pattern_page_v1',
					'recipe_ref'             => 'workflow/pattern_page_plan',
					'media_recipe_ref'       => 'openclaw_recipes.ai_image_ratio_crop_media_adoption',
					'core_route'             => '/wp-json/npcink-governance-core/v1/proposals/from-plan',
					'final_write_path'       => 'core_proposal_required',
					'direct_wordpress_write' => false,
				),
			),
			array(
				'source'         => 'local_pattern_page_plan',
				'execution_mode' => 'deterministic',
			),
			'Pattern page plan built.'
		);
	}

	/**
	 * Reviews an existing or proposed pattern page block tree.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function review_pattern_page( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review page patterns.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$post_id        = absint( $input['post_id'] ?? 0 );
		$blocks         = array();
		$content_length = 0;
		$source         = 'blocks_input';
		$post_context   = array();

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'npcink_abilities_toolkit_post_not_found', __( 'Post was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}

			$content        = (string) ( $post->post_content ?? '' );
			$content_length = strlen( $content );
			$parsed_blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
			$blocks         = $this->pattern_review_normalize_blocks( is_array( $parsed_blocks ) ? $parsed_blocks : array() );
			if ( empty( $blocks ) && '' !== trim( $content ) ) {
				$blocks[] = array(
					'blockName'    => 'core/freeform',
					'attrs'        => array(),
					'innerHTML'    => $content,
					'innerContent' => array( $content ),
					'innerBlocks'  => array(),
				);
			}
			$source       = 'post_content';
			$post_context = array(
				'post_id'   => $post_id,
				'post_type' => sanitize_key( (string) ( $post->post_type ?? '' ) ),
				'status'    => sanitize_key( (string) ( $post->post_status ?? '' ) ),
			);
		} elseif ( is_array( $input['blocks'] ?? null ) ) {
			$blocks = $this->pattern_review_normalize_blocks( $input['blocks'] );
		} else {
			return new \WP_Error( 'npcink_abilities_toolkit_pattern_review_input_missing', __( 'Provide either post_id or blocks to review a pattern page.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$review_summary     = $this->pattern_review_summary_for_blocks( $blocks, ! array_key_exists( 'include_findings', $input ) || ! empty( $input['include_findings'] ) );

		return $this->build_analysis_success_response(
			array(
				'artifact_type'          => 'pattern_page_review',
				'version'                => 1,
				'source'                 => $source,
				'post'                   => $post_context,
				'block_count'            => $review_summary['block_count'],
				'top_level_count'        => $review_summary['top_level_count'],
				'content_length'         => $content_length,
				'review_status'          => $review_summary['review_status'],
				'score'                  => $review_summary['score'],
				'direct_wordpress_write' => false,
				'commit_execution'       => false,
				'server_side_review_only' => true,
				'editor_validation_note' => 'Server-side review checks structure, native attrs, media, and responsive signals; Gutenberg editor save() validation still requires opening the editor.',
				'design_quality'         => $review_summary['design_quality'],
				'responsive_quality'     => $review_summary['responsive_quality'],
				'media_quality'          => $review_summary['media_quality'],
				'content_quality'        => $review_summary['content_quality'],
				'editor_risk'            => $review_summary['editor_risk'],
				'layout_fingerprint'     => $review_summary['layout_fingerprint'],
				'visual_quality_findings' => $review_summary['visual_quality_findings'],
				'findings'               => $review_summary['findings'],
				'next_actions'           => $review_summary['next_actions'],
			),
			array(
				'source'         => 'local_pattern_page_review',
				'execution_mode' => 'deterministic_readonly',
			),
			'Pattern page review built.'
		);
	}

	/**
	 * Reviews any supported block-editor surface without writing content.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function review_block_editor_surface( $input ) {
		$input            = is_array( $input ) ? $input : array();
		$surface_kind     = sanitize_key( (string) ( $input['surface_kind'] ?? '' ) );
		$post_type        = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$post_id          = absint( $input['post_id'] ?? 0 );
		$slug             = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		$include_findings = ! array_key_exists( 'include_findings', $input ) || ! empty( $input['include_findings'] );
		$blocks           = array();
		$content          = '';
		$content_length   = 0;
		$source           = 'blocks_input';
		$surface          = array(
			'surface_kind' => '' !== $surface_kind ? $surface_kind : 'blocks_input',
			'editor'       => 'block_editor',
			'post_type'    => '' !== $post_type ? $post_type : '',
			'post_id'      => 0,
			'slug'         => '',
			'status'       => '',
			'title'        => '',
			'source'       => '',
		);

		if ( is_array( $input['blocks'] ?? null ) ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review proposed blocks.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}
			$blocks = $this->pattern_review_normalize_blocks( $input['blocks'] );
			$surface['target_mode'] = 'review_blocks_input';
		} else {
			if ( '' === $post_type && $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error( 'npcink_abilities_toolkit_post_not_found', __( 'Post was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
				}
				$post_type = sanitize_key( (string) ( $post->post_type ?? '' ) );
			}

			if ( in_array( $surface_kind, array( 'site_editor_template', 'site_editor_template_part' ), true ) && '' === $post_type ) {
				$post_type = 'site_editor_template_part' === $surface_kind ? 'wp_template_part' : 'wp_template';
			}

			if ( in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
				if ( ! current_user_can( 'edit_theme_options' ) ) {
					return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review block theme templates.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
				}
				$entity = $this->block_theme_find_entity( $post_type, $slug, $post_id );
				if ( empty( $entity ) ) {
					return new \WP_Error( 'npcink_abilities_toolkit_block_theme_entity_not_found', __( 'Block theme entity was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
				}
				$content        = (string) ( $entity['content'] ?? '' );
				$content_length = strlen( $content );
				$parsed_blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
				$blocks         = $this->pattern_review_normalize_blocks( is_array( $parsed_blocks ) ? $parsed_blocks : array() );
				$source         = 'site_editor_entity';
				$surface        = array(
					'surface_kind'      => 'wp_template_part' === $post_type ? 'site_editor_template_part' : 'site_editor_template',
					'editor'            => 'site_editor',
					'post_type'         => $post_type,
					'post_id'           => absint( $entity['post_id'] ?? 0 ),
					'slug'              => sanitize_key( (string) ( $entity['slug'] ?? '' ) ),
					'template_id'       => sanitize_text_field( (string) ( $entity['template_id'] ?? '' ) ),
					'theme'             => sanitize_key( (string) ( $entity['theme'] ?? '' ) ),
					'source'            => sanitize_key( (string) ( $entity['source'] ?? '' ) ),
					'title'             => sanitize_text_field( (string) ( $entity['title'] ?? '' ) ),
					'target_mode'       => 'review_existing',
					'read_ability_id'   => 'wp_template_part' === $post_type ? 'npcink-abilities-toolkit/get-template-part-blocks' : 'npcink-abilities-toolkit/get-template-blocks',
					'write_ability_ids' => 'wp_template_part' === $post_type
						? array( 'npcink-abilities-toolkit/update-template-part-blocks' )
						: array( 'npcink-abilities-toolkit/update-template-blocks', 'npcink-abilities-toolkit/upsert-template-blocks' ),
				);
			} else {
				if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
					return new \WP_Error( 'npcink_abilities_toolkit_block_editor_surface_type_invalid', __( 'Block editor surface type is not supported.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error( 'npcink_abilities_toolkit_post_not_found', __( 'Post was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
				}
				$content        = (string) ( $post->post_content ?? '' );
				$content_length = strlen( $content );
				$parsed_blocks  = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
				$blocks         = $this->pattern_review_normalize_blocks( is_array( $parsed_blocks ) ? $parsed_blocks : array() );
				$source         = 'post_content';
				$surface        = array(
					'surface_kind'       => 'post_content',
					'editor'             => 'block_editor',
					'post_type'          => $post_type,
					'post_id'            => $post_id,
					'slug'               => sanitize_title( (string) ( $post->post_name ?? '' ) ),
					'status'             => sanitize_key( (string) ( $post->post_status ?? '' ) ),
					'title'              => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
					'target_mode'        => 'review_existing',
					'read_ability_id'    => 'npcink-abilities-toolkit/get-post-blocks',
					'write_ability_id'   => 'npcink-abilities-toolkit/update-post-blocks',
					'plan_ability_id'    => 'page' === $post_type ? 'npcink-abilities-toolkit/build-pattern-page-plan' : 'npcink-abilities-toolkit/build-article-block-plan',
				);
			}
		}

		if ( empty( $blocks ) && ! empty( $content ) ) {
			$blocks[] = array(
				'blockName'    => 'core/freeform',
				'attrs'        => array(),
				'innerHTML'    => $content,
				'innerContent' => array( $content ),
				'innerBlocks'  => array(),
			);
		}

		$review_summary = $this->pattern_review_summary_for_blocks( $blocks, $include_findings );

		return $this->build_analysis_success_response(
			array(
				'artifact_type'          => 'block_editor_surface_review',
				'version'                => 1,
				'source'                 => $source,
				'block_editor_surface'   => $surface,
				'block_count'            => $review_summary['block_count'],
				'top_level_count'        => $review_summary['top_level_count'],
				'content_length'         => $content_length,
				'review_status'          => $review_summary['review_status'],
				'score'                  => $review_summary['score'],
				'direct_wordpress_write' => false,
				'commit_execution'       => false,
				'server_side_review_only' => true,
				'editor_validation_note' => 'Server-side review checks structure, native attrs, media, and responsive signals; Gutenberg editor save() validation still requires opening the editor.',
				'design_quality'         => $review_summary['design_quality'],
				'responsive_quality'     => $review_summary['responsive_quality'],
				'media_quality'          => $review_summary['media_quality'],
				'content_quality'        => $review_summary['content_quality'],
				'editor_risk'            => $review_summary['editor_risk'],
				'layout_fingerprint'     => $review_summary['layout_fingerprint'],
				'visual_quality_findings' => $review_summary['visual_quality_findings'],
				'findings'               => $review_summary['findings'],
				'next_actions'           => $this->block_editor_surface_next_actions( $surface, $review_summary['next_actions'] ),
			),
			array(
				'source'         => 'local_block_editor_surface_review',
				'execution_mode' => 'deterministic_readonly',
			),
			'Block editor surface review built.'
		);
	}

	/**
	 * Adds surface-specific next actions to generic review advice.
	 *
	 * @param array<string,mixed> $surface Surface metadata.
	 * @param array<int,string>   $review_next_actions Generic next actions.
	 * @return array<int,string>
	 */
	private function block_editor_surface_next_actions( array $surface, array $review_next_actions ): array {
		$next_actions = array_values( array_filter( array_map( 'sanitize_key', $review_next_actions ) ) );
		$surface_kind = sanitize_key( (string) ( $surface['surface_kind'] ?? '' ) );
		$post_type    = sanitize_key( (string) ( $surface['post_type'] ?? '' ) );

		if ( 'post_content' === $surface_kind && 'page' === $post_type ) {
			$next_actions[] = 'revise_pattern_page_plan';
		} elseif ( 'post_content' === $surface_kind && 'post' === $post_type ) {
			$next_actions[] = 'revise_article_block_plan';
		} elseif ( in_array( $surface_kind, array( 'site_editor_template', 'site_editor_template_part' ), true ) ) {
			$next_actions[] = 'plan_site_editor_block_change';
		} elseif ( 'blocks_input' === $surface_kind ) {
			$next_actions[] = 'choose_target_block_editor_surface';
		}

		return array_values( array_unique( $next_actions ) );
	}

	/**
	 * Builds a proposal-readiness gate from block-editor review signals.
	 *
	 * @param array<string,mixed> $review_summary Review summary.
	 * @param array<string,mixed> $surface Surface metadata.
	 * @return array<string,mixed>
	 */
	private function block_editor_plan_quality_gate( array $review_summary, array $surface ): array {
		$profile         = sanitize_key( (string) ( $surface['profile'] ?? 'editor_safety' ) );
		$review_status   = sanitize_key( (string) ( $review_summary['review_status'] ?? '' ) );
		$score           = absint( $review_summary['score'] ?? 0 );
		$editor_risk     = sanitize_key( (string) ( $review_summary['editor_risk']['invalid_block_risk_level'] ?? '' ) );
		$responsive_risk = sanitize_key( (string) ( $review_summary['responsive_quality']['responsive_risk_level'] ?? '' ) );
		$finding_codes   = is_array( $review_summary['finding_codes'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $review_summary['finding_codes'] ) ) ) : array();

		$blocking_codes = array();
		if ( 'low' !== $editor_risk ) {
			$blocking_codes[] = 'editor_invalid_block_risk';
		}
		if ( ! in_array( $responsive_risk, array( 'low', 'medium' ), true ) ) {
			$blocking_codes[] = 'responsive_risk_detected';
		}
		if ( 'landing_design' === $profile && ( 'pass' !== $review_status || $score < 80 ) ) {
			$blocking_codes = array_merge( $blocking_codes, $finding_codes );
		}

		$blocking_codes = array_values( array_unique( array_filter( $blocking_codes ) ) );
		$ready          = empty( $blocking_codes );

		return array(
			'gate_id'             => sanitize_key( (string) ( $surface['gate_id'] ?? 'block_editor_quality_gate' ) ),
			'review_ability_id'   => 'npcink-abilities-toolkit/review-block-editor-surface',
			'profile'             => $profile,
			'surface_kind'        => sanitize_key( (string) ( $surface['surface_kind'] ?? '' ) ),
			'editor'              => sanitize_key( (string) ( $surface['editor'] ?? '' ) ),
			'post_type'           => sanitize_key( (string) ( $surface['post_type'] ?? '' ) ),
			'target_mode'         => sanitize_key( (string) ( $surface['target_mode'] ?? '' ) ),
			'review_status'       => $review_status,
			'score'               => $score,
			'editor_risk_level'   => $editor_risk,
			'responsive_risk_level' => $responsive_risk,
			'finding_codes'       => $finding_codes,
			'blocking_finding_codes' => $blocking_codes,
			'ready_for_proposal'  => $ready,
			'recommended_next_step' => $ready ? 'submit_core_proposal' : 'revise_block_editor_plan',
			'direct_wordpress_write' => false,
			'commit_execution'    => false,
		);
	}

	/**
	 * Returns a compact block-editor review payload for generated plans.
	 *
	 * @param array<string,mixed> $review_summary Review summary.
	 * @return array<string,mixed>
	 */
	private function block_editor_plan_review_excerpt( array $review_summary ): array {
		return array(
			'artifact_type'       => 'block_editor_surface_review',
			'review_status'       => sanitize_key( (string) ( $review_summary['review_status'] ?? '' ) ),
			'score'               => absint( $review_summary['score'] ?? 0 ),
			'block_count'         => absint( $review_summary['block_count'] ?? 0 ),
			'top_level_count'     => absint( $review_summary['top_level_count'] ?? 0 ),
			'editor_risk'         => is_array( $review_summary['editor_risk'] ?? null ) ? $review_summary['editor_risk'] : array(),
			'responsive_quality'  => is_array( $review_summary['responsive_quality'] ?? null ) ? $review_summary['responsive_quality'] : array(),
			'media_quality'       => is_array( $review_summary['media_quality'] ?? null ) ? $review_summary['media_quality'] : array(),
			'finding_codes'       => is_array( $review_summary['finding_codes'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $review_summary['finding_codes'] ) ) ) : array(),
			'next_actions'        => is_array( $review_summary['next_actions'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $review_summary['next_actions'] ) ) ) : array(),
			'server_side_review_only' => true,
			'editor_validation_note' => 'Server-side review checks structure, native attrs, media, and responsive signals; Gutenberg editor save() validation still requires opening the editor.',
		);
	}

	/**
	 * Returns media slot requirements for clients composing visual assets.
	 *
	 * @param array<string,mixed> $variables Pattern variables.
	 * @param string              $media_strategy Media strategy.
	 * @return array<int,array<string,mixed>>
	 */
	private function pattern_media_slots( array $variables, string $media_strategy ): array {
		$hero_ratio = $this->pattern_aspect_ratio( $variables['hero_media_target_aspect_ratio'] ?? '16:9', '16:9' );
		$hero_media_url = $this->pattern_sanitized_media_url( $variables['hero_media_url'] ?? '' );
		$hero_media_attachment_id = $this->pattern_media_attachment_id( $variables['hero_media_attachment_id'] ?? ( $variables['hero_media_id'] ?? 0 ) );
		return array(
			array(
				'id'                    => 'hero_media',
				'variable'              => 'hero_media_url',
				'attachment_id_variable' => 'hero_media_attachment_id',
				'alt_variable'          => 'hero_media_alt',
				'target_slot'           => 'hero',
				'target_aspect_ratio'   => $hero_ratio,
				'preferred_format'      => 'webp',
				'quality'               => 84,
				'crop'                  => array(
					'type'         => 'aspect_ratio',
					'aspect_ratio' => $hero_ratio,
					'position'     => 'center',
				),
				'media_strategy'        => $media_strategy,
				'required_for_quality'  => true,
				'recommended_recipe_id' => 'ai_image_ratio_crop_media_adoption',
				'recommended_openclaw_recipe' => 'openclaw_recipes.ai_image_ratio_crop_media_adoption',
				'existing_media_url'    => $hero_media_url,
				'existing_media_attachment_id' => $hero_media_attachment_id,
				'media_input_valid'     => '' !== $hero_media_url,
				'media_input_has_attachment_id' => $hero_media_attachment_id > 0,
			),
		);
	}

	/**
	 * Builds the reusable server-side review summary for a block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param bool                           $include_findings Whether findings should be returned.
	 * @return array<string,mixed>
	 */
	private function pattern_review_summary_for_blocks( array $blocks, bool $include_findings ): array {
		$metrics            = $this->pattern_review_metrics( $blocks );
		$design_quality     = array_merge(
			$this->pattern_design_quality_summary( $blocks ),
			array(
				'section_shape_variety' => $metrics['section_shape_variety'],
				'visual_asset_count'    => $metrics['visual_asset_count'],
				'native_style_density'  => $metrics['native_style_density'],
				'text_heaviness_score'  => $metrics['text_heaviness_score'],
			)
		);
		$responsive_quality = array_merge(
			$this->pattern_responsive_quality_summary( $blocks, 'landing_standard' ),
			array(
				'responsive_risk_level' => $this->pattern_review_responsive_risk_level( $blocks, $metrics ),
			)
		);
		$media_quality      = array(
			'visual_asset_count'      => $metrics['visual_asset_count'],
			'image_block_count'       => $metrics['image_block_count'],
			'media_text_block_count'  => $metrics['media_text_block_count'],
			'image_attachment_id_count' => $metrics['image_attachment_id_count'],
			'media_text_attachment_id_count' => $metrics['media_text_attachment_id_count'],
			'hero_media_url_count'    => $metrics['hero_media_url_count'],
			'hero_media_attachment_id_count' => $metrics['hero_media_attachment_id_count'],
			'has_hero_media_url'      => (int) $metrics['hero_media_url_count'] > 0,
			'has_hero_media_attachment_id' => (int) $metrics['hero_media_attachment_id_count'] > 0,
			'temporary_cloud_preview_url_count' => $metrics['temporary_cloud_preview_url_count'],
			'has_temporary_cloud_preview_url' => (int) $metrics['temporary_cloud_preview_url_count'] > 0,
			'image_alt_missing_count' => $metrics['image_alt_missing_count'],
			'image_alt_complete'      => 0 === (int) $metrics['image_alt_missing_count'],
		);
		$content_quality    = array(
			'heading_count'         => $metrics['heading_count'],
			'paragraph_count'       => $metrics['paragraph_count'],
			'button_count'          => $metrics['button_count'],
			'cta_count'             => $metrics['button_count'],
			'text_heaviness_score'  => $metrics['text_heaviness_score'],
			'native_style_density'  => $metrics['native_style_density'],
			'class_dependency_ratio' => $metrics['class_dependency_ratio'],
		);
		$risk_review        = $this->pattern_review_editor_risk( $metrics );
		$layout_fingerprint = $this->pattern_layout_fingerprint( $blocks, $metrics );
		$visual_quality_findings = $this->pattern_visual_quality_findings( $blocks, $layout_fingerprint );
		$findings           = array_merge( $this->pattern_review_findings( $design_quality, $responsive_quality, $media_quality, $content_quality, $risk_review ), $visual_quality_findings );
		$score              = $this->pattern_review_score( $design_quality, $responsive_quality, $media_quality, $content_quality, $risk_review );
		$review_status      = $score >= 80 && 'high' !== $risk_review['invalid_block_risk_level'] && ! $this->pattern_has_blocking_visual_findings( $visual_quality_findings ) ? 'pass' : 'needs_revision';

		return array(
			'review_status'      => $review_status,
			'score'              => $score,
			'block_count'        => $metrics['block_count'],
			'top_level_count'    => $metrics['top_level_count'],
			'design_quality'     => $design_quality,
			'responsive_quality' => $responsive_quality,
			'media_quality'      => $media_quality,
			'content_quality'    => $content_quality,
			'editor_risk'        => $risk_review,
			'layout_fingerprint' => $layout_fingerprint,
			'visual_quality_findings' => $include_findings ? $visual_quality_findings : array(),
			'findings'           => $include_findings ? $findings : array(),
			'finding_codes'      => $this->pattern_finding_codes( $findings ),
			'next_actions'       => $this->pattern_review_next_actions( $review_status, $findings ),
		);
	}

	/**
	 * Normalizes optional review feedback for the next Pattern plan.
	 *
	 * @param mixed $feedback Previous review feedback.
	 * @return array<string,mixed>
	 */
	private function pattern_review_feedback( $feedback ): array {
		if ( ! is_array( $feedback ) || empty( $feedback ) ) {
			return array(
				'feedback_received' => false,
				'finding_codes'     => array(),
				'next_actions'      => array(),
				'revision_goals'    => array(),
			);
		}

		$findings = array();
		foreach ( array_slice( is_array( $feedback['findings'] ?? null ) ? $feedback['findings'] : array(), 0, 12 ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$code = sanitize_key( (string) ( $finding['code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$findings[] = array(
				'severity' => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'code'     => $code,
				'message'  => sanitize_text_field( (string) ( $finding['message'] ?? '' ) ),
			);
		}

		$next_actions = array();
		foreach ( array_slice( is_array( $feedback['next_actions'] ?? null ) ? $feedback['next_actions'] : array(), 0, 12 ) as $action ) {
			$action = sanitize_key( (string) $action );
			if ( '' !== $action ) {
				$next_actions[] = $action;
			}
		}
		$next_actions = array_values( array_unique( $next_actions ) );
		$finding_codes = $this->pattern_finding_codes( $findings );
		foreach ( array_slice( is_array( $feedback['finding_codes'] ?? null ) ? $feedback['finding_codes'] : array(), 0, 12 ) as $code ) {
			$code = sanitize_key( (string) $code );
			if ( '' !== $code ) {
				$finding_codes[] = $code;
			}
		}
		$finding_codes = array_values( array_unique( $finding_codes ) );

		return array(
			'feedback_received'  => true,
			'source_review_status' => sanitize_key( (string) ( $feedback['review_status'] ?? '' ) ),
			'source_score'       => array_key_exists( 'score', $feedback ) ? max( 0, min( 100, absint( $feedback['score'] ) ) ) : null,
			'finding_codes'      => $finding_codes,
			'next_actions'       => $next_actions,
			'revision_goals'     => $this->pattern_revision_goals_from_codes( $finding_codes, $next_actions ),
		);
	}

	/**
	 * Normalizes bounded section variant hints.
	 *
	 * @param mixed $hints Section variant hints.
	 * @return array<string,string>
	 */
	private function pattern_section_variant_hints( $hints ): array {
		if ( ! is_array( $hints ) ) {
			return array(
				'comparison' => 'center-title-two-cards',
			);
		}

		$comparison = sanitize_key( (string) ( $hints['comparison'] ?? 'center-title-two-cards' ) );
		if ( ! in_array( $comparison, array( 'center-title-two-cards', 'left-title-two-cards' ), true ) ) {
			$comparison = 'center-title-two-cards';
		}

		return array(
			'comparison' => $comparison,
		);
	}

	/**
	 * Returns normalized finding codes.
	 *
	 * @param array<int,array<string,string>> $findings Findings.
	 * @return string[]
	 */
	private function pattern_finding_codes( array $findings ): array {
		$codes = array();
		foreach ( $findings as $finding ) {
			$code = sanitize_key( (string) ( is_array( $finding ) ? ( $finding['code'] ?? '' ) : '' ) );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * Maps review finding codes to bounded revision goals.
	 *
	 * @param string[] $finding_codes Finding codes.
	 * @param string[] $next_actions Next action ids.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_revision_goals_from_codes( array $finding_codes, array $next_actions ): array {
		$goal_map = array(
			'split_hero_present'        => array( 'goal' => 'use_split_hero', 'strategy' => 'render_native_columns_hero' ),
			'hero_media_missing'        => array( 'goal' => 'add_reviewed_hero_media', 'strategy' => 'request_or_reuse_16_9_hero_asset' ),
			'bento_grid_missing'        => array( 'goal' => 'use_bento_feature_grid', 'strategy' => 'render_native_columns_bento' ),
			'comparison_missing'        => array( 'goal' => 'add_proposal_first_comparison', 'strategy' => 'render_dark_native_comparison_band' ),
			'faq_missing'               => array( 'goal' => 'add_faq', 'strategy' => 'render_core_details_faq' ),
			'final_cta_missing'         => array( 'goal' => 'add_final_cta', 'strategy' => 'render_contrast_cta_band' ),
			'section_variety_low'       => array( 'goal' => 'increase_section_variety', 'strategy' => 'mix_hero_proof_bento_workflow_comparison_faq_cta' ),
			'native_style_density_low'  => array( 'goal' => 'increase_native_styles', 'strategy' => 'prefer_gutenberg_style_layout_spacing_attrs' ),
			'responsive_risk_detected'  => array( 'goal' => 'reduce_responsive_risk', 'strategy' => 'use_core_columns_with_mobile_stacking' ),
			'image_alt_missing'         => array( 'goal' => 'complete_media_alt_text', 'strategy' => 'require_alt_for_image_and_media_text_blocks' ),
			'editor_invalid_block_risk' => array( 'goal' => 'avoid_invalid_blocks', 'strategy' => 'avoid_custom_html_and_non_core_blocks' ),
			'text_heaviness_high'       => array( 'goal' => 'reduce_text_heaviness', 'strategy' => 'add_visual_asset_or_split_copy_into_cards' ),
			'placeholder_media_url'     => array( 'goal' => 'repair_media_inputs', 'strategy' => 'replace_placeholder_media_with_reviewed_site_media_or_mock_panel' ),
			'insufficient_card_padding' => array( 'goal' => 'repair_card_spacing', 'strategy' => 'use_complete_gutenberg_spacing_padding_attrs' ),
			'light_card_inherits_light_text' => array( 'goal' => 'repair_card_contrast', 'strategy' => 'set_text_color_on_light_cards_inside_dark_sections' ),
			'section_title_alignment_suggestion' => array( 'goal' => 'improve_section_alignment', 'strategy' => 'choose_center_title_variant_for_symmetric_comparison_sections' ),
			'layout_similarity_risk'    => array( 'goal' => 'increase_layout_variation', 'strategy' => 'mix_section_variants_and_visual_rhythm' ),
			'color_story_monochrome'     => array( 'goal' => 'increase_color_rhythm', 'strategy' => 'switch_to_editorial_accent_color_story' ),
		);

		$goals = array();
		foreach ( $finding_codes as $code ) {
			if ( isset( $goal_map[ $code ] ) ) {
				$goals[ $code ] = array_merge( array( 'finding_code' => $code ), $goal_map[ $code ] );
			}
		}
		if ( in_array( 'repair_media_inputs', $next_actions, true ) && ! isset( $goals['hero_media_missing'] ) ) {
			$goals['repair_media_inputs'] = array(
				'finding_code' => 'repair_media_inputs',
				'goal'         => 'repair_media_inputs',
				'strategy'     => 'request_reviewed_media_url_and_alt_before_final_proposal',
			);
		}
		if ( in_array( 'open_editor_and_rebuild_invalid_blocks', $next_actions, true ) && ! isset( $goals['editor_invalid_block_risk'] ) ) {
			$goals['open_editor_and_rebuild_invalid_blocks'] = array(
				'finding_code' => 'open_editor_and_rebuild_invalid_blocks',
				'goal'         => 'avoid_invalid_blocks',
				'strategy'     => 'rebuild_with_core_blocks_before_resubmitting',
			);
		}

		return array_values( $goals );
	}

	/**
	 * Compares previous feedback with the newly generated plan review.
	 *
	 * @param array<string,mixed> $feedback Normalized previous feedback.
	 * @param array<string,mixed> $quality_review Generated plan quality review.
	 * @param array<int,array<string,mixed>> $media_slots Media slot requirements.
	 * @return array<string,mixed>
	 */
	private function pattern_revision_strategy( array $feedback, array $quality_review, array $media_slots ): array {
		$previous_codes = array_values( array_filter( is_array( $feedback['finding_codes'] ?? null ) ? $feedback['finding_codes'] : array(), 'is_string' ) );
		$current_codes  = array_values( array_filter( is_array( $quality_review['finding_codes'] ?? null ) ? $quality_review['finding_codes'] : array(), 'is_string' ) );
		$remaining      = array_values( array_intersect( $previous_codes, $current_codes ) );
		$applied        = array_values( array_diff( $previous_codes, $remaining ) );
		$needs_media    = in_array( 'hero_media_missing', $current_codes, true ) || in_array( 'image_alt_missing', $current_codes, true );

		return array(
			'feedback_received'       => ! empty( $feedback['feedback_received'] ),
			'generated_review_status' => sanitize_key( (string) ( $quality_review['review_status'] ?? '' ) ),
			'generated_score'         => absint( $quality_review['score'] ?? 0 ),
			'applied_finding_codes'   => $applied,
			'remaining_finding_codes' => $remaining,
			'current_finding_codes'   => $current_codes,
			'ready_for_proposal'      => 'pass' === (string) ( $quality_review['review_status'] ?? '' ),
			'recommended_next_step'   => 'pass' === (string) ( $quality_review['review_status'] ?? '' ) ? 'submit_core_proposal' : 'revise_pattern_page_plan',
			'media_inputs_required'   => $needs_media,
			'required_media_slots'    => $needs_media ? $media_slots : array(),
		);
	}

	/**
	 * Returns whether review feedback should force a visibly different native Pattern revision.
	 *
	 * @param array<string,mixed> $feedback Normalized previous feedback.
	 * @return bool
	 */
	private function pattern_should_render_visual_delta( array $feedback ): bool {
		if ( empty( $feedback['feedback_received'] ) ) {
			return false;
		}
		$finding_codes = array_values( array_filter( is_array( $feedback['finding_codes'] ?? null ) ? $feedback['finding_codes'] : array(), 'is_string' ) );
		$next_actions  = array_values( array_filter( is_array( $feedback['next_actions'] ?? null ) ? $feedback['next_actions'] : array(), 'is_string' ) );
		foreach ( array( 'bento_grid_missing', 'section_variety_low', 'native_style_density_low', 'text_heaviness_high' ) as $code ) {
			if ( in_array( $code, $finding_codes, true ) ) {
				return true;
			}
		}
		return in_array( 'revise_pattern_page_plan', $next_actions, true );
	}

	/**
	 * Sanitizes a W:H aspect ratio for media slot planning.
	 *
	 * @param mixed  $value Ratio candidate.
	 * @param string $fallback Fallback ratio.
	 * @return string
	 */
	private function pattern_aspect_ratio( $value, string $fallback ): string {
		$ratio = trim( sanitize_text_field( (string) $value ) );
		if ( 1 !== preg_match( '/^([1-9][0-9]{0,2}):([1-9][0-9]{0,2})$/', $ratio, $matches ) ) {
			return $fallback;
		}
		$width  = absint( $matches[1] );
		$height = absint( $matches[2] );
		if ( $width < 1 || $width > 100 || $height < 1 || $height > 100 ) {
			return $fallback;
		}
		return $ratio;
	}

	/**
	 * Renders the built-in landing pattern as parsed Gutenberg blocks.
	 *
	 * @param array<string,mixed> $variables Pattern variables.
	 * @param array<string,mixed> $options Pattern options.
	 * @return array<int,array<string,mixed>>
	 */
	private function render_openai_style_landing_blocks( array $variables, array $options = array() ) {
		$eyebrow          = $this->pattern_text( $variables['eyebrow'] ?? '', 'WordPress AI Plugin' );
		$hero_title       = $this->pattern_text( $variables['hero_title'] ?? '', '把 AI 工作流带进 WordPress 内容现场' );
		$hero_description = $this->pattern_text( $variables['hero_description'] ?? '', '让内容生产、SEO 优化、媒体处理与发布协作在同一个可审计流程中完成。' );
		$primary_cta      = $this->pattern_text( $variables['primary_cta'] ?? '', '查看工作流' );
		$secondary_cta    = $this->pattern_text( $variables['secondary_cta'] ?? '', '了解能力' );
		$media_strategy   = sanitize_key( (string) ( $options['media_strategy'] ?? 'mock_or_existing_media' ) );
		$research_brief   = is_array( $options['research_brief'] ?? null ) ? $options['research_brief'] : array();
		$review_feedback  = is_array( $options['review_feedback'] ?? null ) ? $options['review_feedback'] : array();
		$section_variant_hints = is_array( $options['section_variant_hints'] ?? null ) ? $this->pattern_section_variant_hints( $options['section_variant_hints'] ) : $this->pattern_section_variant_hints( array() );
		$palette          = $this->pattern_color_story_palette( $options['color_story'] ?? 'minimal-dark-light' );
		$comparison_variant = (string) ( $section_variant_hints['comparison'] ?? 'center-title-two-cards' );
		$visual_delta     = $this->pattern_should_render_visual_delta( $review_feedback );
		$hero_media_url   = $this->pattern_sanitized_media_url( $variables['hero_media_url'] ?? '' );
		$hero_media_attachment_id = $this->pattern_media_attachment_id( $variables['hero_media_attachment_id'] ?? ( $variables['hero_media_id'] ?? 0 ) );
		$hero_media_alt   = $this->pattern_text( $variables['hero_media_alt'] ?? '', 'WordPress AI workflow interface' );
		$proof_points     = $this->pattern_items(
			$variables['proof_points'] ?? array(),
			$this->pattern_items(
				$research_brief['proof_points'] ?? array(),
				array(
					array(
						'title'       => 'Proposal-first',
						'description' => 'AI 只生成计划和提案，审批后才进入执行。',
					),
					array(
						'title'       => 'Gutenberg-native',
						'description' => '页面结构以 WordPress 核心块保存，编辑器可继续维护。',
					),
					array(
						'title'       => 'Auditable',
						'description' => '每次写入都关联 proposal、preflight 和审计记录。',
					),
					array(
						'title'       => 'Draft-safe',
						'description' => '默认创建草稿，不直接发布生产内容。',
					),
				),
				4
			),
			4
		);
		$comparison_angles = $this->pattern_items(
			$research_brief['comparison_angles'] ?? array(),
			array(
				array(
					'title'       => '普通 AI 直接写入',
					'description' => '自动化看起来更快，但写入意图、审批上下文和回滚证据往往分散在流程之外。',
				),
				array(
					'title'       => 'OpenClaw proposal-first',
					'description' => '先形成可审查计划，再由 Core 审批、preflight 和 Adapter 执行 profile 进入 WordPress。',
				),
			),
			0
		);
		$section_patterns  = $this->pattern_items(
			$research_brief['section_patterns'] ?? array(),
			array(),
			0
		);
		$visual_recommendations = $this->pattern_items(
			$research_brief['visual_asset_recommendations'] ?? array(),
			array(),
			0
		);
		$faq_seed_questions = $this->pattern_faq_seed_items( $research_brief['faq_seed_questions'] ?? array() );
		$feature_fallback = array(
			array(
				'title'       => 'AI 内容草稿',
				'description' => '从主题、上下文和站点知识出发，生成结构化草稿。',
			),
			array(
				'title'       => '可审查提案',
				'description' => '所有写入先形成 proposal，再经过审批和 preflight。',
			),
			array(
				'title'       => 'Gutenberg 原生块',
				'description' => '输出标准核心块结构，便于编辑器继续维护。',
			),
		);
		if ( ! empty( $section_patterns ) ) {
			$feature_fallback = $this->pattern_merge_item_lists( $section_patterns, $feature_fallback, 3 );
		}
		$features         = $this->pattern_items(
			$variables['features'] ?? array(),
			$feature_fallback,
			3
		);
		$workflow         = $this->pattern_items(
			$variables['workflow'] ?? array(),
			array(
				array(
					'title'       => '规划',
					'description' => 'AI 生成页面结构、文案变量和写入动作。',
				),
				array(
					'title'       => '审批',
					'description' => 'Core 记录 proposal、审批状态和 commit preflight。',
				),
				array(
					'title'       => '写入',
					'description' => 'Adapter 只执行 allowlisted Gutenberg 块更新。',
				),
				array(
					'title'       => '验证',
					'description' => '回读块结构、roundtrip 和审计记录，确认写入可追踪。',
				),
			),
			4
		);
		$faq              = $this->pattern_items(
			$variables['faq'] ?? array(),
			$this->pattern_merge_item_lists(
				$faq_seed_questions,
				array(
					array(
						'title'       => '这个页面会直接发布吗？',
						'description' => '不会。Pattern 计划只创建 proposal，最终写入仍由 Core 审批和 Adapter 执行 profile 控制。',
					),
					array(
						'title'       => '编辑器里还能继续改吗？',
						'description' => '可以。页面由 Gutenberg 核心块组成，目标是保持块结构可读、可解析、可继续编辑。',
					),
					array(
						'title'       => '移动端会怎么显示？',
						'description' => '多列区块使用移动端堆叠策略，按钮和内容区保持可换行、可阅读。',
					),
				),
				3
			),
			3
		);
		$final_cta_title       = $this->pattern_text( $variables['final_cta_title'] ?? '', '从一个可审查的 AI 页面草稿开始' );
		$final_cta_description = $this->pattern_text( $variables['final_cta_description'] ?? '', '用 Gutenberg-native Pattern 生成页面计划，再通过 Core proposal 审批进入写入。' );
		$final_cta_primary     = $this->pattern_text( $variables['final_cta_primary'] ?? '', '创建页面计划' );
		$final_cta_secondary   = $this->pattern_text( $variables['final_cta_secondary'] ?? '', '查看治理流程' );
		$hero_visual_blocks    = '' !== $hero_media_url && in_array( $media_strategy, array( 'mock_or_existing_media', 'existing_media_url' ), true )
			? array( $this->pattern_hero_media_block( $hero_media_url, $hero_media_alt, $hero_media_attachment_id ) )
			: array( $this->pattern_dashboard_mock_block() );

		$blocks = array(
			$this->pattern_group_block(
				'npcink-ai-page npcink-ai-hero',
				array(
					$this->pattern_columns_block(
						array(
							$this->pattern_column_block(
								array(
									$this->pattern_paragraph_block( $eyebrow, 'npcink-ai-eyebrow', $this->pattern_accent_eyebrow_attrs( $palette ), 'color:' . $palette['accent'] . ';font-size:13px;font-weight:600;line-height:1.2;text-transform:uppercase' ),
									$this->pattern_heading_block( $hero_title, 1, 'npcink-ai-title', $this->pattern_hero_title_attrs(), 'font-size:56px;font-weight:500;line-height:1.08;letter-spacing:0;margin-top:0;margin-bottom:0' ),
									$this->pattern_paragraph_block( $hero_description, 'npcink-ai-lede', $this->pattern_lede_attrs(), 'color:#333333;font-size:22px;line-height:1.4' ),
									$this->pattern_buttons_block(
										array(
											$this->pattern_button_block( $primary_cta, 'npcink-ai-button-primary', $this->pattern_primary_button_attrs( $palette ), 'background-color:' . $palette['contrast_background'] . ';color:#ffffff;border-radius:999px;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ),
											$this->pattern_button_block( $secondary_cta, 'npcink-ai-button-secondary', $this->pattern_secondary_button_attrs( $palette ), 'background-color:#ffffff;color:' . $palette['contrast_background'] . ';border:1px solid ' . $palette['contrast_background'] . ';border-radius:999px;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ),
										),
										'npcink-ai-cta',
										$this->pattern_buttons_attrs()
									),
								),
								'npcink-ai-hero-copy'
							),
							$this->pattern_column_block(
								$hero_visual_blocks,
								'npcink-ai-hero-dashboard'
							),
						),
						'npcink-ai-hero-layout',
						$this->pattern_columns_attrs( '40px' )
					),
				),
				$this->pattern_section_attrs( $palette['hero_background'], '96px', '88px', true ),
				'background-color:#f7f7f4;border-bottom-color:#e5e5e5;border-bottom-width:1px;padding-top:96px;padding-right:40px;padding-bottom:88px;padding-left:40px'
			),
			$this->pattern_group_block(
				'npcink-ai-proof-strip',
				array(
					$this->pattern_columns_block(
						array_map(
							function ( $item ) use ( $palette ) {
								$line_color = 'editorial-accent' === (string) ( $palette['name'] ?? '' ) ? (string) $palette['accent'] : '#111111';
								return $this->pattern_column_block(
									array(
										$this->pattern_group_block(
											'npcink-ai-proof-card',
											array(
												$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $this->pattern_card_title_attrs(), 'font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
												$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' ),
											),
											$this->pattern_line_card_attrs( $palette ),
											'border-top-color:' . $line_color . ';border-top-width:1px;padding-top:22px;padding-right:0;padding-bottom:0;padding-left:0'
										),
									)
								);
							},
							$proof_points
						),
						'npcink-ai-proof-strip',
						$this->pattern_columns_attrs( '24px' )
					),
				),
				$this->pattern_section_attrs( $palette['surface'], '48px', '56px', false ),
				'background-color:#ffffff;padding-top:48px;padding-right:40px;padding-bottom:56px;padding-left:40px'
			),
		);

		if ( '' !== $hero_media_url && in_array( $media_strategy, array( 'mock_or_existing_media', 'existing_media_url' ), true ) ) {
			$blocks[] = $this->pattern_group_block(
				'npcink-ai-media-section',
				array(
					$this->pattern_media_text_block(
						$hero_media_url,
						$hero_media_alt,
						$hero_media_attachment_id,
						array(
							$this->pattern_paragraph_block( 'Responsive Pattern', 'npcink-ai-eyebrow', $this->pattern_accent_eyebrow_attrs( $palette ), 'color:' . $palette['accent'] . ';font-size:13px;font-weight:600;line-height:1.2;text-transform:uppercase' ),
							$this->pattern_heading_block( $this->pattern_text( $variables['media_title'] ?? '', $this->pattern_research_item_title( $visual_recommendations, '视觉结构和治理流程一起交付' ) ), 2, 'npcink-ai-section-title', $this->pattern_section_title_attrs(), 'font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' ),
							$this->pattern_paragraph_block( $this->pattern_text( $variables['media_description'] ?? '', $this->pattern_research_item_description( $visual_recommendations, 'OpenClaw 提供页面意图和素材引用，Toolkit 输出响应式 Gutenberg 模块，Core 继续保留审批和审计。' ) ), 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' ),
						),
						'npcink-ai-media-text',
						$this->pattern_media_text_attrs()
					),
				),
				$this->pattern_section_attrs( $palette['media_background'], '72px', '72px', false ),
				'background-color:#f7f7f4;padding-top:72px;padding-right:40px;padding-bottom:72px;padding-left:40px'
			);
		}

		$research_sections = array();
		if ( ! empty( $comparison_angles ) ) {
			$research_sections[] = $this->pattern_comparison_block(
				$comparison_angles,
				! empty( $research_brief ) ? __( '研究驱动的页面取舍', 'npcink-abilities-toolkit' ) : __( '为什么坚持 proposal-first', 'npcink-abilities-toolkit' ),
				$comparison_variant,
				$palette
			);
		}

		$blocks = array_merge(
			$blocks,
			array(
				$this->pattern_group_block(
					$visual_delta ? 'npcink-ai-feature-grid npcink-ai-visual-delta' : 'npcink-ai-feature-grid',
					array(
						$this->pattern_heading_block(
							$this->pattern_text( $variables['features_title'] ?? '', $visual_delta ? '让页面一眼看出产品节奏' : 'AI 内容现场的基础能力' ),
							2,
							$visual_delta ? 'npcink-ai-section-title npcink-ai-section-title-light' : 'npcink-ai-section-title',
							$visual_delta ? $this->pattern_light_section_title_attrs() : $this->pattern_section_title_attrs(),
							$visual_delta ? 'color:#ffffff;font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' : 'font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0'
						),
						$visual_delta ? $this->pattern_feature_visual_delta_block( $features ) : $this->pattern_feature_bento_block( $features, $palette ),
					),
					$this->pattern_section_attrs( $visual_delta ? $palette['contrast_background'] : $palette['surface'], '88px', '88px', false ),
					$visual_delta ? 'background-color:#111111;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px' : 'background-color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px'
				),
				$this->pattern_group_block(
					'npcink-ai-workflow',
					array(
						$this->pattern_heading_block( $this->pattern_text( $variables['workflow_title'] ?? '', '从生成到发布，始终可审查' ), 2, 'npcink-ai-section-title', $this->pattern_section_title_attrs(), 'font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' ),
						$this->pattern_columns_block(
							array_map(
								function ( $item ) {
									return $this->pattern_column_block(
										array(
											$this->pattern_group_block(
												'npcink-ai-workflow-step',
												array(
													$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $this->pattern_card_title_attrs(), 'font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
													$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' ),
												),
												$this->pattern_card_attrs(),
												'background-color:#ffffff;border-color:#dddddd;border-width:1px;border-radius:20px;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px'
											),
										)
									);
								},
								$workflow
							),
							'npcink-ai-workflow',
							$this->pattern_columns_attrs()
						),
					),
					$this->pattern_section_attrs( $palette['workflow_background'], '88px', '96px', false ),
					'background-color:#f7f7f4;padding-top:88px;padding-right:40px;padding-bottom:96px;padding-left:40px'
				),
			),
			$research_sections,
			array(
				$this->pattern_group_block(
					'npcink-ai-faq',
					array(
						$this->pattern_heading_block( $this->pattern_text( $variables['faq_title'] ?? '', '常见问题' ), 2, 'npcink-ai-section-title', $this->pattern_section_title_attrs(), 'font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' ),
						$this->pattern_group_block(
							'npcink-ai-faq-list',
							array_map(
								function ( $item ) {
									return $this->pattern_details_block(
										(string) $item['title'],
										(string) $item['description'],
										'npcink-ai-faq-item',
										$this->pattern_faq_item_attrs()
									);
								},
								$faq
							),
							$this->pattern_faq_list_attrs(),
							''
						),
					),
					$this->pattern_section_attrs( $palette['surface'], '88px', '88px', false ),
					'background-color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px'
				),
				$this->pattern_group_block(
					'npcink-ai-final-cta',
					array(
						$this->pattern_heading_block( $final_cta_title, 2, 'npcink-ai-section-title npcink-ai-section-title-light', $this->pattern_centered_light_section_title_attrs(), 'color:#ffffff;font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' ),
						$this->pattern_paragraph_block( $final_cta_description, 'npcink-ai-lede', $this->pattern_centered_light_lede_attrs(), 'color:#f2f2f2;font-size:22px;line-height:1.4' ),
						$this->pattern_buttons_block(
							array(
								$this->pattern_button_block( $final_cta_primary, 'npcink-ai-button-primary', $this->pattern_dark_primary_button_attrs(), 'background-color:#ffffff;color:#111111;border-radius:999px;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ),
								$this->pattern_button_block( $final_cta_secondary, 'npcink-ai-button-secondary', $this->pattern_dark_secondary_button_attrs( $palette['contrast_background'] ), 'background-color:' . $palette['contrast_background'] . ';color:#ffffff;border:1px solid #ffffff;border-radius:999px;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px' ),
							),
							'npcink-ai-cta',
							$this->pattern_buttons_attrs( 'center' )
						),
					),
					$this->pattern_dark_section_attrs( $palette['contrast_background'], '88px', '96px', false ),
					'background-color:#111111;color:#ffffff;padding-top:88px;padding-right:40px;padding-bottom:96px;padding-left:40px'
				),
			)
		);

		return $blocks;
	}

	/**
	 * Returns allowed pattern classes.
	 *
	 * @return string[]
	 */
	private function pattern_allowed_classes() {
		return array(
			'npcink-ai-page',
			'npcink-ai-hero',
			'npcink-ai-eyebrow',
			'npcink-ai-title',
			'npcink-ai-lede',
			'npcink-ai-cta',
			'npcink-ai-button-primary',
			'npcink-ai-button-secondary',
			'npcink-ai-hero-layout',
			'npcink-ai-hero-copy',
			'npcink-ai-hero-dashboard',
			'npcink-ai-dashboard-card',
			'npcink-ai-dashboard-mock',
			'npcink-ai-hero-media-card',
			'npcink-ai-dashboard-label',
			'npcink-ai-dashboard-value',
			'npcink-ai-dashboard-row',
			'npcink-ai-proof-strip',
			'npcink-ai-proof-card',
			'npcink-ai-media-section',
			'npcink-ai-media-text',
			'npcink-ai-feature-grid',
			'npcink-ai-feature-bento',
			'npcink-ai-feature-card',
			'npcink-ai-feature-spotlight',
			'npcink-ai-visual-delta',
			'npcink-ai-section-title-light',
			'npcink-ai-feature-proof',
			'npcink-ai-feature-proof-row',
			'npcink-ai-feature-rail',
			'npcink-ai-workflow',
			'npcink-ai-workflow-step',
			'npcink-ai-comparison',
			'npcink-ai-comparison-grid',
			'npcink-ai-comparison-card',
			'npcink-ai-section-title-center',
			'npcink-ai-faq',
			'npcink-ai-faq-list',
			'npcink-ai-faq-item',
			'npcink-ai-final-cta',
			'npcink-ai-section-title',
			'npcink-ai-card-title',
			'npcink-ai-card-text',
			'openclaw-template-layout',
			'openclaw-template-layout-article_standard',
			'openclaw-template-layout-page_standard',
			'openclaw-template-layout-homepage_landing',
			'openclaw-template-title-stack',
			'openclaw-template-page-title-band',
			'openclaw-template-meta',
			'openclaw-template-meta-label',
			'openclaw-template-meta-separator',
			'openclaw-template-featured-image',
			'openclaw-template-media-band',
			'openclaw-template-content-stack',
			'openclaw-template-page-content-panel',
			'openclaw-template-section-title',
			'openclaw-template-related',
			'openclaw-template-post-navigation',
			'openclaw-home-hero',
			'openclaw-home-hero-title',
			'openclaw-home-hero-description',
			'openclaw-home-cta',
			'openclaw-home-cta-button',
			'openclaw-home-latest-posts',
			'openclaw-home-categories',
			'openclaw-home-content',
			'openclaw-home-empty-fallback',
		);
	}

	/**
	 * Builds a group block with innerContent markers.
	 *
	 * @param string                    $class_name CSS classes.
	 * @param array<int,array<string,mixed>> $inner_blocks Inner blocks.
	 * @param array<string,mixed>       $attrs Additional block attrs.
	 * @param string                    $style_attr Wrapper style attribute.
	 * @return array<string,mixed>
	 */
	private function pattern_group_block( $class_name, array $inner_blocks, array $attrs = array(), $style_attr = '' ) {
		$class_name = $this->pattern_class_names( $class_name );
		$attrs      = array_merge( $attrs, array( 'className' => $class_name ) );
		$classes    = $this->pattern_group_classes( $attrs );
		$style_attr = $this->pattern_style_from_attrs( $attrs );
		$style_html = '' !== $style_attr ? ' style="' . $this->pattern_attr( $style_attr ) . '"' : '';
		return array(
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerHTML'    => '<div class="' . $this->pattern_attr( $classes ) . '"' . $style_html . '></div>',
			'innerContent' => array_merge( array( '<div class="' . $this->pattern_attr( $classes ) . '"' . $style_html . '>' ), array_fill( 0, count( $inner_blocks ), null ), array( '</div>' ) ),
			'innerBlocks'  => array_values( $inner_blocks ),
		);
	}

	/**
	 * Builds a heading block.
	 *
	 * @param string $text Text.
	 * @param int    $level Heading level.
	 * @param string $class_name CSS classes.
	 * @param array<string,mixed> $attrs Additional block attrs.
	 * @param string $style_attr Element style attribute.
	 * @return array<string,mixed>
	 */
	private function pattern_heading_block( $text, $level, $class_name, array $attrs = array(), $style_attr = '' ) {
		$level      = max( 1, min( 6, (int) $level ) );
		$class_name = $this->pattern_class_names( $class_name );
		$attrs      = array_merge(
			$attrs,
			array(
				'level'     => $level,
				'className' => $class_name,
			)
		);
		$style_attr = $this->pattern_style_from_attrs( $attrs );
		$style_html = '' !== $style_attr ? ' style="' . $this->pattern_attr( $style_attr ) . '"' : '';
		$text_align = sanitize_key( (string) ( $attrs['textAlign'] ?? '' ) );
		$classes    = array( 'wp-block-heading' );
		if ( in_array( $text_align, array( 'left', 'center', 'right' ), true ) ) {
			$classes[] = 'has-text-align-' . $text_align;
		}
		$classes = array_merge( $classes, $this->pattern_color_support_classes( $attrs ) );
		foreach ( preg_split( '/\s+/', $class_name ) ?: array() as $class ) {
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		$html       = '<h' . $level . ' class="' . $this->pattern_attr( implode( ' ', array_values( array_unique( $classes ) ) ) ) . '"' . $style_html . '>' . esc_html( $text ) . '</h' . $level . '>';
		return array(
			'blockName'    => 'core/heading',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Builds a paragraph block.
	 *
	 * @param string $text Text.
	 * @param string $class_name CSS classes.
	 * @param array<string,mixed> $attrs Additional block attrs.
	 * @param string $style_attr Element style attribute.
	 * @return array<string,mixed>
	 */
	private function pattern_paragraph_block( $text, $class_name, array $attrs = array(), $style_attr = '' ) {
		$class_name = $this->pattern_class_names( $class_name );
		$attrs      = array_merge( $attrs, array( 'className' => $class_name ) );
		$style_attr = $this->pattern_style_from_attrs( $attrs );
		$style_html = '' !== $style_attr ? ' style="' . $this->pattern_attr( $style_attr ) . '"' : '';
		$text_align = sanitize_key( (string) ( $attrs['textAlign'] ?? '' ) );
		$classes    = array();
		if ( in_array( $text_align, array( 'left', 'center', 'right' ), true ) ) {
			$classes[] = 'has-text-align-' . $text_align;
		}
		$classes = array_merge( $classes, $this->pattern_color_support_classes( $attrs ) );
		foreach ( preg_split( '/\s+/', $class_name ) ?: array() as $class ) {
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		$html = '<p class="' . $this->pattern_attr( implode( ' ', array_values( array_unique( $classes ) ) ) ) . '"' . $style_html . '>' . esc_html( $text ) . '</p>';
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Builds a buttons block.
	 *
	 * @param array<int,array<string,mixed>> $buttons Button blocks.
	 * @param string                         $class_name CSS classes.
	 * @param array<string,mixed>            $attrs Additional block attrs.
	 * @return array<string,mixed>
	 */
	private function pattern_buttons_block( array $buttons, $class_name, array $attrs = array() ) {
		$class_name = $this->pattern_class_names( $class_name );
		$attr_class = $this->pattern_attr( $class_name );
		return array(
			'blockName'    => 'core/buttons',
			'attrs'        => array_merge( $attrs, array( 'className' => $class_name ) ),
			'innerHTML'    => '<div class="wp-block-buttons ' . $attr_class . '"></div>',
			'innerContent' => array_merge( array( '<div class="wp-block-buttons ' . $attr_class . '">' ), array_fill( 0, count( $buttons ), null ), array( '</div>' ) ),
			'innerBlocks'  => array_values( $buttons ),
		);
	}

	/**
	 * Builds a button block.
	 *
	 * @param string $text Text.
	 * @param string $class_name CSS classes.
	 * @param array<string,mixed> $attrs Additional block attrs.
	 * @param string $style_attr Link style attribute.
	 * @return array<string,mixed>
	 */
	private function pattern_button_block( $text, $class_name, array $attrs = array(), $style_attr = '' ) {
		$class_name = $this->pattern_class_names( $class_name );
		$attrs      = array_merge( $attrs, array( 'className' => $class_name ) );
		$style_attr = $this->pattern_style_from_attrs( $attrs );
		$style_html = '' !== $style_attr ? ' style="' . $this->pattern_attr( $style_attr ) . '"' : '';
		$link_class = $this->pattern_button_link_classes( $attrs );
		$html       = '<div class="wp-block-button ' . $this->pattern_attr( $class_name ) . '"><a class="' . $this->pattern_attr( $link_class ) . '"' . $style_html . '>' . esc_html( $text ) . '</a></div>';
		return array(
			'blockName'    => 'core/button',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Builds a columns block.
	 *
	 * @param array<int,array<string,mixed>> $columns Column blocks.
	 * @param string                         $class_name CSS classes.
	 * @param array<string,mixed>            $attrs Additional block attrs.
	 * @return array<string,mixed>
	 */
	private function pattern_columns_block( array $columns, $class_name, array $attrs = array() ) {
		$class_name = $this->pattern_class_names( $class_name );
		$attr_class = $this->pattern_attr( $class_name );
		return array(
			'blockName'    => 'core/columns',
			'attrs'        => array_merge( $attrs, array( 'className' => $class_name ) ),
			'innerHTML'    => '<div class="wp-block-columns ' . $attr_class . '"></div>',
			'innerContent' => array_merge( array( '<div class="wp-block-columns ' . $attr_class . '">' ), array_fill( 0, count( $columns ), null ), array( '</div>' ) ),
			'innerBlocks'  => array_values( $columns ),
		);
	}

	/**
	 * Builds a column block.
	 *
	 * @param array<int,array<string,mixed>> $inner_blocks Inner blocks.
	 * @return array<string,mixed>
	 */
	private function pattern_column_block( array $inner_blocks, $class_name = '', array $attrs = array() ) {
		$class_name = $this->pattern_class_names( $class_name );
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}
		$attr_class = '' !== $class_name ? ' ' . $this->pattern_attr( $class_name ) : '';
		return array(
			'blockName'    => 'core/column',
			'attrs'        => $attrs,
			'innerHTML'    => '<div class="wp-block-column' . $attr_class . '"></div>',
			'innerContent' => array_merge( array( '<div class="wp-block-column' . $attr_class . '">' ), array_fill( 0, count( $inner_blocks ), null ), array( '</div>' ) ),
			'innerBlocks'  => array_values( $inner_blocks ),
		);
	}

	/**
	 * Builds a responsive media-text block from an existing media URL.
	 *
	 * @param string                         $media_url Media URL.
	 * @param string                         $media_alt Media alt text.
	 * @param int                            $media_attachment_id Attachment id.
	 * @param array<int,array<string,mixed>> $inner_blocks Content blocks.
	 * @param string                         $class_name CSS classes.
	 * @param array<string,mixed>            $attrs Additional block attrs.
	 * @return array<string,mixed>
	 */
	private function pattern_media_text_block( $media_url, $media_alt, $media_attachment_id, array $inner_blocks, $class_name, array $attrs = array() ) {
		$class_name  = $this->pattern_class_names( $class_name );
		$media_url   = esc_url_raw( (string) $media_url );
		$media_alt   = sanitize_text_field( (string) $media_alt );
		$media_attachment_id = $this->pattern_media_attachment_id( $media_attachment_id );
		$attrs       = array_merge(
			array(
				'align'              => 'wide',
				'mediaType'          => 'image',
				'mediaUrl'           => $media_url,
				'mediaAlt'           => $media_alt,
				'mediaWidth'         => 48,
				'isStackedOnMobile'  => true,
			),
			$attrs,
			array( 'className' => $class_name )
		);
		if ( $media_attachment_id > 0 ) {
			$attrs['mediaId'] = $media_attachment_id;
		}
		$classes     = trim( 'wp-block-media-text alignwide is-stacked-on-mobile ' . $this->pattern_attr( $class_name ) );
		$style_html  = ' style="grid-template-columns:48% auto"';
		$image_class = $media_attachment_id > 0 ? ' class="wp-image-' . $media_attachment_id . '"' : '';
		$media_html  = '<figure class="wp-block-media-text__media"><img src="' . $this->pattern_attr( $media_url ) . '" alt="' . $this->pattern_attr( $media_alt ) . '"' . $image_class . '/></figure>';
		$open_html   = '<div class="' . $this->pattern_attr( $classes ) . '"' . $style_html . '>' . $media_html . '<div class="wp-block-media-text__content">';
		$close_html  = '</div></div>';
		return array(
			'blockName'    => 'core/media-text',
			'attrs'        => $attrs,
			'innerHTML'    => '<div class="' . $this->pattern_attr( $classes ) . '"' . $style_html . '>' . $media_html . '<div class="wp-block-media-text__content"></div></div>',
			'innerContent' => array_merge( array( $open_html ), array_fill( 0, count( $inner_blocks ), null ), array( $close_html ) ),
			'innerBlocks'  => array_values( $inner_blocks ),
		);
	}

	/**
	 * Builds an image block from a reviewed media URL.
	 *
	 * @param string $media_url Media URL.
	 * @param string $media_alt Media alt text.
	 * @param int    $media_attachment_id Attachment id.
	 * @return array<string,mixed>
	 */
	private function pattern_image_block( $media_url, $media_alt, $media_attachment_id = 0 ) {
		$media_url = esc_url_raw( (string) $media_url );
		$media_alt = sanitize_text_field( (string) $media_alt );
		$media_attachment_id = $this->pattern_media_attachment_id( $media_attachment_id );
		$image_class = $media_attachment_id > 0 ? ' class="wp-image-' . $media_attachment_id . '"' : '';
		$html      = '<figure class="wp-block-image size-large"><img src="' . $this->pattern_attr( $media_url ) . '" alt="' . $this->pattern_attr( $media_alt ) . '"' . $image_class . '/></figure>';
		$attrs     = array(
			'url'      => $media_url,
			'alt'      => $media_alt,
			'sizeSlug' => 'large',
		);
		if ( $media_attachment_id > 0 ) {
			$attrs['id'] = $media_attachment_id;
		}
		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Sanitizes a Pattern media URL and rejects documentation/example placeholders.
	 *
	 * @param mixed $value URL candidate.
	 * @return string
	 */
	private function pattern_sanitized_media_url( $value ): string {
		$url = esc_url_raw( (string) $value );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( '' === $host ) {
			return '';
		}
		if ( preg_match( '/(^|\\.)example\\.(com|net|org|test)$/', $host ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Normalizes an optional media attachment id for Pattern block attrs.
	 *
	 * @param mixed $value Attachment id candidate.
	 * @return int
	 */
	private function pattern_media_attachment_id( $value ): int {
		$attachment_id = absint( $value );
		return $attachment_id > 0 ? $attachment_id : 0;
	}

	/**
	 * Returns whether a media URL looks like a temporary Cloud derivative preview.
	 *
	 * @param mixed $value URL candidate.
	 * @return bool
	 */
	private function pattern_is_temporary_cloud_preview_url( $value ): bool {
		$url = (string) $value;
		if ( '' === $url ) {
			return false;
		}
		return false !== strpos( $url, '/media-derivative-artifacts/' )
			|| false !== strpos( $url, 'preview_sig=' )
			|| false !== strpos( $url, 'artifact_id=' );
	}

	/**
	 * Builds a details FAQ item.
	 *
	 * @param string              $summary Summary text.
	 * @param string              $body Body text.
	 * @param string              $class_name CSS classes.
	 * @param array<string,mixed> $attrs Additional block attrs.
	 * @return array<string,mixed>
	 */
	private function pattern_details_block( $summary, $body, $class_name, array $attrs = array() ) {
		$class_name = $this->pattern_class_names( $class_name );
		$summary    = $this->pattern_text( $summary, '' );
		$body_block = $this->pattern_paragraph_block( $body, 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' );
		$attrs      = array_merge(
			$attrs,
			array(
				'summary'   => $summary,
				'className' => $class_name,
			)
		);
		$style_html = ' style="border-top-color:#dddddd;border-top-width:1px;padding-top:20px;padding-right:0;padding-bottom:20px;padding-left:0"';
		$open_html  = '<details class="wp-block-details ' . $this->pattern_attr( $class_name ) . '"' . $style_html . '><summary>' . esc_html( $summary ) . '</summary>';
		return array(
			'blockName'    => 'core/details',
			'attrs'        => $attrs,
			'innerHTML'    => $open_html . '</details>',
			'innerContent' => array( $open_html, null, '</details>' ),
			'innerBlocks'  => array( $body_block ),
		);
	}

	/**
	 * Builds the hero proposal preview card.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_dashboard_mock_block() {
		return $this->pattern_group_block(
			'npcink-ai-dashboard-card npcink-ai-dashboard-mock',
			array(
				$this->pattern_paragraph_block( 'Proposal preview', 'npcink-ai-dashboard-label', $this->pattern_eyebrow_attrs(), 'color:#555555;font-size:13px;font-weight:600;line-height:1.2;text-transform:uppercase' ),
				$this->pattern_heading_block( 'Manual approval required', 3, 'npcink-ai-dashboard-value', $this->pattern_card_title_attrs(), 'font-size:24px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
				$this->pattern_dashboard_row_block( 'Status', 'Pending proposal' ),
				$this->pattern_dashboard_row_block( 'Write actions', '2' ),
				$this->pattern_dashboard_row_block( 'Blocks', 'Gutenberg-native' ),
				$this->pattern_dashboard_row_block( 'Validation', 'Roundtrip ready' ),
			),
			$this->pattern_dashboard_card_attrs(),
			'background-color:#ffffff;border-color:#dcdcdc;border-width:1px;border-radius:24px;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px'
		);
	}

	/**
	 * Builds the hero visual panel when a reviewed media URL is supplied.
	 *
	 * @param string $media_url Media URL.
	 * @param string $media_alt Media alt text.
	 * @param int    $media_attachment_id Attachment id.
	 * @return array<string,mixed>
	 */
	private function pattern_hero_media_block( $media_url, $media_alt, $media_attachment_id = 0 ) {
		return $this->pattern_group_block(
			'npcink-ai-dashboard-card npcink-ai-hero-media-card',
			array(
				$this->pattern_image_block( $media_url, $media_alt, $media_attachment_id ),
				$this->pattern_dashboard_row_block( 'Media', 'Reviewed 16:9 WebP hero asset' ),
				$this->pattern_dashboard_row_block( 'Adoption', 'Core proposal executed' ),
			),
			$this->pattern_dashboard_card_attrs(),
			'background-color:#ffffff;border-color:#dcdcdc;border-width:1px;border-radius:24px;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px'
		);
	}

	/**
	 * Builds one row inside the hero dashboard mock.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return array<string,mixed>
	 */
	private function pattern_dashboard_row_block( $label, $value ) {
		return $this->pattern_group_block(
			'npcink-ai-dashboard-row',
			array(
				$this->pattern_paragraph_block( $label, 'npcink-ai-dashboard-label', $this->pattern_small_muted_text_attrs(), 'color:#666666;font-size:14px;line-height:1.4' ),
				$this->pattern_paragraph_block( $value, 'npcink-ai-dashboard-value', $this->pattern_small_strong_text_attrs(), 'color:#111111;font-size:15px;font-weight:600;line-height:1.4' ),
			),
			$this->pattern_dashboard_row_attrs(),
			'border-top-color:#e5e5e5;border-top-width:1px;padding-top:12px;padding-right:0;padding-bottom:0;padding-left:0'
		);
	}

	/**
	 * Builds a proposal-first comparison section.
	 *
	 * @param array<int,array<string,string>> $items Comparison angle items.
	 * @param string                          $title Section title.
	 * @param string                          $variant Section layout variant.
	 * @return array<string,mixed>
	 */
	private function pattern_comparison_block( array $items, $title = '', $variant = 'center-title-two-cards', array $palette = array() ) {
		$palette = ! empty( $palette ) ? $palette : $this->pattern_color_story_palette( 'minimal-dark-light' );
		$items = array_slice( $items, 0, 3 );
		$title = $this->pattern_text( $title, '为什么坚持 proposal-first' );
		$variant = sanitize_key( (string) $variant );
		$center_title = 'left-title-two-cards' !== $variant;
		$title_class  = $center_title ? 'npcink-ai-section-title npcink-ai-section-title-center' : 'npcink-ai-section-title';
		$title_attrs  = $center_title ? $this->pattern_centered_light_section_title_attrs() : $this->pattern_light_section_title_attrs();
		return $this->pattern_group_block(
			'npcink-ai-comparison',
			array(
				$this->pattern_heading_block( $title, 2, $title_class, $title_attrs, 'color:#ffffff;font-size:40px;font-weight:500;line-height:1.1;letter-spacing:0' ),
				$this->pattern_columns_block(
					array_map(
						function ( $item, $index ) use ( $palette ) {
							$is_primary  = 0 === (int) $index;
							$card_attrs  = $is_primary ? $this->pattern_comparison_primary_card_attrs( $palette ) : $this->pattern_comparison_light_card_attrs();
							$title_attrs = $is_primary ? $this->pattern_light_card_title_attrs() : $this->pattern_card_title_attrs();
							$text_attrs  = $is_primary ? $this->pattern_light_card_text_attrs() : $this->pattern_card_text_attrs();
							$primary_background = ( 'editorial-accent' === (string) ( $palette['name'] ?? '' ) ) ? '#153f42' : '#1f1f1f';
							$primary_border     = ( 'editorial-accent' === (string) ( $palette['name'] ?? '' ) ) ? (string) $palette['accent'] : '#3a3a3a';
							return $this->pattern_column_block(
								array(
									$this->pattern_group_block(
										'npcink-ai-comparison-card',
										array(
											$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $title_attrs, $is_primary ? 'color:#ffffff;font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' : 'font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
											$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $text_attrs, $is_primary ? 'color:#dddddd;font-size:16px;line-height:1.55' : 'color:#454545;font-size:16px;line-height:1.55' ),
										),
										$card_attrs,
										$is_primary ? 'background-color:' . $primary_background . ';color:#ffffff;border-color:' . $primary_border . ';border-width:1px;border-radius:20px;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px' : 'background-color:#ffffff;border-color:#dddddd;border-width:1px;border-radius:20px;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px'
									),
								)
							);
						},
						$items,
						array_keys( $items )
					),
					'npcink-ai-comparison-grid',
					$this->pattern_columns_attrs()
				),
			),
			$this->pattern_dark_section_attrs( $palette['contrast_background'], '88px', '88px', false ),
			'background-color:#111111;padding-top:88px;padding-right:40px;padding-bottom:88px;padding-left:40px'
		);
	}

	/**
	 * Builds a Gutenberg-native Bento feature section without plugin CSS.
	 *
	 * @param array<int,array<string,string>> $features Feature items.
	 * @return array<string,mixed>
	 */
	private function pattern_feature_bento_block( array $features, array $palette = array() ) {
		$palette  = ! empty( $palette ) ? $palette : $this->pattern_color_story_palette( 'minimal-dark-light' );
		$features = array_values( $features );
		while ( count( $features ) < 3 ) {
			$features[] = array(
				'title'       => 'Gutenberg 原生模块',
				'description' => '用核心块、原生样式属性和移动端堆叠能力搭建可编辑页面。',
			);
		}

		$primary   = $features[0];
		$secondary = array_slice( $features, 1, 2 );

		return $this->pattern_columns_block(
			array(
				$this->pattern_column_block(
					array(
						$this->pattern_group_block(
							'npcink-ai-feature-card npcink-ai-feature-spotlight',
							array(
								$this->pattern_paragraph_block( 'Core capability', 'npcink-ai-eyebrow', $this->pattern_light_eyebrow_attrs(), 'color:#dddddd;font-size:13px;font-weight:600;line-height:1.2;text-transform:uppercase' ),
								$this->pattern_heading_block( (string) $primary['title'], 3, 'npcink-ai-card-title', $this->pattern_feature_spotlight_title_attrs(), 'color:#ffffff;font-size:30px;font-weight:500;line-height:1.08;letter-spacing:0;margin-top:0' ),
								$this->pattern_paragraph_block( (string) $primary['description'], 'npcink-ai-card-text', $this->pattern_light_card_text_attrs(), 'color:#dddddd;font-size:16px;line-height:1.55' ),
							),
							$this->pattern_feature_spotlight_attrs( $palette ),
							'background-color:' . $palette['contrast_background'] . ';color:#ffffff;border-color:' . $palette['contrast_background'] . ';border-width:1px;border-radius:24px;padding-top:36px;padding-right:36px;padding-bottom:36px;padding-left:36px'
						),
					)
				),
				$this->pattern_column_block(
					array_map(
						function ( $item ) {
							return $this->pattern_group_block(
								'npcink-ai-feature-card',
								array(
									$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $this->pattern_card_title_attrs(), 'font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
									$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' ),
								),
								$this->pattern_card_attrs(),
								'background-color:#ffffff;border-color:#dddddd;border-width:1px;border-radius:20px;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px'
							);
						},
						$secondary
					)
				),
			),
			'npcink-ai-feature-bento',
			$this->pattern_columns_attrs()
		);
	}

	/**
	 * Builds a higher-contrast revision Bento when review feedback says the page still feels flat.
	 *
	 * @param array<int,array<string,string>> $features Feature items.
	 * @return array<string,mixed>
	 */
	private function pattern_feature_visual_delta_block( array $features ) {
		$features = array_values( $features );
		while ( count( $features ) < 3 ) {
			$features[] = array(
				'title'       => 'Gutenberg 原生模块',
				'description' => '用核心块、原生样式属性和移动端堆叠能力搭建可编辑页面。',
			);
		}

		$primary   = $features[0];
		$secondary = array_slice( $features, 1, 2 );
		$proofs    = array(
			array(
				'title'       => '01',
				'description' => '先生成计划',
			),
			array(
				'title'       => '02',
				'description' => '再审批写入',
			),
			array(
				'title'       => '03',
				'description' => '最后回读验证',
			),
		);

		return $this->pattern_group_block(
			'npcink-ai-feature-bento npcink-ai-feature-rail',
			array(
				$this->pattern_columns_block(
					array(
						$this->pattern_column_block(
							array(
								$this->pattern_group_block(
									'npcink-ai-feature-card npcink-ai-feature-spotlight',
									array(
										$this->pattern_paragraph_block( 'Revision focus', 'npcink-ai-eyebrow', $this->pattern_eyebrow_attrs(), 'color:#555555;font-size:13px;font-weight:600;line-height:1.2;text-transform:uppercase' ),
										$this->pattern_heading_block( (string) $primary['title'], 3, 'npcink-ai-card-title', $this->pattern_feature_hero_title_attrs(), 'color:#111111;font-size:40px;font-weight:500;line-height:1.02;letter-spacing:0;margin-top:0' ),
										$this->pattern_paragraph_block( (string) $primary['description'], 'npcink-ai-card-text', $this->pattern_card_text_attrs(), 'color:#454545;font-size:16px;line-height:1.55' ),
									),
									$this->pattern_feature_delta_spotlight_attrs(),
									'background-color:#f7f7f4;color:#111111;border-color:#f7f7f4;border-width:1px;border-radius:28px;padding-top:44px;padding-right:44px;padding-bottom:44px;padding-left:44px'
								),
							)
						),
						$this->pattern_column_block(
							array_map(
								function ( $item, $index ) {
									$is_dark     = 1 === (int) $index;
									$card_attrs  = $is_dark ? $this->pattern_feature_delta_dark_card_attrs() : $this->pattern_feature_delta_light_card_attrs();
									$title_attrs = $is_dark ? $this->pattern_light_card_title_attrs() : $this->pattern_card_title_attrs();
									$text_attrs  = $is_dark ? $this->pattern_light_card_text_attrs() : $this->pattern_card_text_attrs();
									return $this->pattern_group_block(
										'npcink-ai-feature-card',
										array(
											$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $title_attrs, $is_dark ? 'color:#ffffff;font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' : 'font-size:22px;font-weight:500;line-height:1.2;letter-spacing:0;margin-top:0' ),
											$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $text_attrs, $is_dark ? 'color:#dddddd;font-size:16px;line-height:1.55' : 'color:#454545;font-size:16px;line-height:1.55' ),
										),
										$card_attrs,
										$is_dark ? 'background-color:#1f1f1f;color:#ffffff;border-color:#3a3a3a;border-width:1px;border-radius:24px;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px' : 'background-color:#ffffff;border-color:#ffffff;border-width:1px;border-radius:24px;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px'
									);
								},
								$secondary,
								array_keys( $secondary )
							)
						),
					),
					'npcink-ai-feature-bento',
					$this->pattern_columns_attrs()
				),
				$this->pattern_columns_block(
					array_map(
						function ( $item ) {
							return $this->pattern_column_block(
								array(
									$this->pattern_group_block(
										'npcink-ai-feature-proof',
										array(
											$this->pattern_heading_block( (string) $item['title'], 3, 'npcink-ai-card-title', $this->pattern_light_card_title_attrs(), 'color:#ffffff;font-size:30px;font-weight:500;line-height:1.1;letter-spacing:0;margin-top:0' ),
											$this->pattern_paragraph_block( (string) $item['description'], 'npcink-ai-card-text', $this->pattern_light_card_text_attrs(), 'color:#dddddd;font-size:15px;line-height:1.45' ),
										),
										$this->pattern_feature_proof_attrs(),
										'background-color:#1f1f1f;color:#ffffff;border-color:#3a3a3a;border-width:1px;border-radius:20px;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px'
									),
								)
							);
						},
						$proofs
					),
					'npcink-ai-feature-proof-row',
					$this->pattern_columns_attrs( '20px' )
				),
			),
			$this->pattern_feature_rail_attrs(),
			'background-color:#111111;color:#ffffff;padding-top:0;padding-right:0;padding-bottom:0;padding-left:0'
		);
	}

	/**
	 * Section attrs using Gutenberg native layout, spacing, color, and border supports.
	 *
	 * @param string $background Background color.
	 * @param string $padding_top Top padding.
	 * @param string $padding_bottom Bottom padding.
	 * @param bool   $with_bottom_border Whether to add bottom border.
	 * @return array<string,mixed>
	 */
	private function pattern_section_attrs( $background, $padding_top, $padding_bottom, $with_bottom_border ) {
		$attrs = array(
			'align'  => 'full',
			'layout' => array(
				'type'        => 'constrained',
				'contentSize' => '1120px',
			),
			'style'  => array(
				'spacing' => array(
					'padding' => array(
						'top'    => (string) $padding_top,
						'right'  => '40px',
						'bottom' => (string) $padding_bottom,
						'left'   => '40px',
					),
				),
				'color'   => array(
					'background' => (string) $background,
				),
			),
		);
		if ( $with_bottom_border ) {
			$attrs['style']['border'] = array(
				'bottom' => array(
					'color' => '#e5e5e5',
					'width' => '1px',
				),
			);
		}
		return $attrs;
	}

	/**
	 * Section attrs for dark bands that must explicitly set readable text color.
	 *
	 * @param string $background Background color.
	 * @param string $padding_top Top padding.
	 * @param string $padding_bottom Bottom padding.
	 * @param bool   $with_bottom_border Whether to add bottom border.
	 * @return array<string,mixed>
	 */
	private function pattern_dark_section_attrs( $background, $padding_top, $padding_bottom, $with_bottom_border ) {
		$attrs = $this->pattern_section_attrs( $background, $padding_top, $padding_bottom, $with_bottom_border );
		$attrs['style']['color']['text'] = '#ffffff';
		return $attrs;
	}

	/**
	 * Returns bounded Gutenberg-native color tokens for Pattern rendering.
	 *
	 * @param mixed $color_story Requested color story.
	 * @return array<string,string>
	 */
	private function pattern_color_story_palette( $color_story ) {
		$color_story = sanitize_key( (string) $color_story );
		if ( 'editorial-accent' === $color_story ) {
			return array(
				'name'                => 'editorial-accent',
				'surface'             => '#ffffff',
				'hero_background'     => '#f4f8f7',
				'media_background'    => '#dfeee9',
				'workflow_background' => '#eef6f3',
				'contrast_background' => '#102b2d',
				'accent'              => '#2f6f68',
			);
		}
		return array(
			'name'                => 'minimal-dark-light',
			'surface'             => '#ffffff',
			'hero_background'     => '#f7f7f4',
			'media_background'    => '#f7f7f4',
			'workflow_background' => '#f7f7f4',
			'contrast_background' => '#111111',
			'accent'              => '#555555',
		);
	}

	/**
	 * Hero title typography attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_hero_title_attrs() {
		return array(
			'style' => array(
				'typography' => array(
					'fontSize'      => '56px',
					'lineHeight'     => '1.08',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Eyebrow paragraph attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_eyebrow_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#555555' ),
				'typography' => array(
					'fontSize'      => '13px',
					'lineHeight'     => '1.2',
					'letterSpacing' => '0',
					'fontWeight'    => '600',
					'textTransform' => 'uppercase',
				),
			),
		);
	}

	/**
	 * Eyebrow attrs with the active color story accent.
	 *
	 * @param array<string,string> $palette Color story palette.
	 * @return array<string,mixed>
	 */
	private function pattern_accent_eyebrow_attrs( array $palette ) {
		$attrs = $this->pattern_eyebrow_attrs();
		$attrs['style']['color']['text'] = (string) ( $palette['accent'] ?? '#555555' );
		return $attrs;
	}

	/**
	 * Light eyebrow paragraph attrs for dark cards.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_light_eyebrow_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#dddddd' ),
				'typography' => array(
					'fontSize'      => '13px',
					'lineHeight'     => '1.2',
					'letterSpacing' => '0',
					'fontWeight'    => '600',
					'textTransform' => 'uppercase',
				),
			),
		);
	}

	/**
	 * Lede paragraph attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_lede_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#333333' ),
				'typography' => array(
					'fontSize'  => '22px',
					'lineHeight' => '1.4',
				),
			),
		);
	}

	/**
	 * Light lede paragraph attrs for dark sections.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_light_lede_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#f2f2f2' ),
				'typography' => array(
					'fontSize'  => '22px',
					'lineHeight' => '1.4',
				),
			),
		);
	}

	/**
	 * Section title typography attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_section_title_attrs() {
		return array(
			'style' => array(
				'typography' => array(
					'fontSize'      => '40px',
					'lineHeight'     => '1.1',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Light section title attrs for dark sections.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_light_section_title_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#ffffff' ),
				'typography' => array(
					'fontSize'      => '40px',
					'lineHeight'     => '1.1',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Centered light section title attrs for symmetric dark sections.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_centered_light_section_title_attrs() {
		return array_merge(
			$this->pattern_light_section_title_attrs(),
			array(
				'textAlign' => 'center',
			)
		);
	}

	/**
	 * Centered light lede attrs for symmetric CTA bands.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_centered_light_lede_attrs() {
		return array_merge(
			$this->pattern_light_lede_attrs(),
			array(
				'textAlign' => 'center',
			)
		);
	}

	/**
	 * Card wrapper attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_card_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '28px',
						'right'  => '28px',
						'bottom' => '28px',
						'left'   => '28px',
					),
				),
				'border'  => array(
					'color'  => '#dddddd',
					'width'  => '1px',
					'radius' => '20px',
				),
				'color'   => array(
					'background' => '#ffffff',
					'text'       => '#111111',
				),
			),
		);
	}

	/**
	 * Dark feature spotlight card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_spotlight_attrs( array $palette = array() ) {
		$palette    = ! empty( $palette ) ? $palette : $this->pattern_color_story_palette( 'minimal-dark-light' );
		$background = (string) ( $palette['contrast_background'] ?? '#111111' );
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '36px',
						'right'  => '36px',
						'bottom' => '36px',
						'left'   => '36px',
					),
				),
				'border'  => array(
					'color'  => $background,
					'width'  => '1px',
					'radius' => '24px',
				),
				'color'   => array(
					'background' => $background,
					'text'       => '#ffffff',
				),
			),
		);
	}

	/**
	 * Large dark title attrs for the visual-delta feature lead card.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_hero_title_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#111111' ),
				'typography' => array(
					'fontSize'      => '40px',
					'lineHeight'     => '1.02',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Visual-delta lead card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_delta_spotlight_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '44px',
						'right'  => '44px',
						'bottom' => '44px',
						'left'   => '44px',
					),
				),
				'border'  => array(
					'color'  => '#f7f7f4',
					'width'  => '1px',
					'radius' => '28px',
				),
				'color'   => array(
					'background' => '#f7f7f4',
					'text'       => '#111111',
				),
			),
		);
	}

	/**
	 * Visual-delta light feature card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_delta_light_card_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '30px',
						'right'  => '30px',
						'bottom' => '30px',
						'left'   => '30px',
					),
				),
				'border'  => array(
					'color'  => '#ffffff',
					'width'  => '1px',
					'radius' => '24px',
				),
				'color'   => array(
					'background' => '#ffffff',
					'text'       => '#111111',
				),
			),
		);
	}

	/**
	 * Visual-delta dark feature card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_delta_dark_card_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '30px',
						'right'  => '30px',
						'bottom' => '30px',
						'left'   => '30px',
					),
				),
				'border'  => array(
					'color'  => '#3a3a3a',
					'width'  => '1px',
					'radius' => '24px',
				),
				'color'   => array(
					'background' => '#1f1f1f',
					'text'       => '#ffffff',
				),
			),
		);
	}

	/**
	 * Visual-delta proof card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_proof_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '24px',
						'right'  => '24px',
						'bottom' => '24px',
						'left'   => '24px',
					),
				),
				'border'  => array(
					'color'  => '#3a3a3a',
					'width'  => '1px',
					'radius' => '20px',
				),
				'color'   => array(
					'background' => '#1f1f1f',
					'text'       => '#ffffff',
				),
			),
		);
	}

	/**
	 * Visual-delta feature rail attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_rail_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '0',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
					),
				),
				'color'   => array(
					'background' => '#111111',
					'text'       => '#ffffff',
				),
			),
		);
	}

	/**
	 * Dark comparison card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_comparison_primary_card_attrs( array $palette = array() ) {
		$is_accent  = 'editorial-accent' === (string) ( $palette['name'] ?? '' );
		$background = $is_accent ? '#153f42' : '#1f1f1f';
		$border     = $is_accent ? (string) ( $palette['accent'] ?? '#2f6f68' ) : '#3a3a3a';
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '28px',
						'right'  => '28px',
						'bottom' => '28px',
						'left'   => '28px',
					),
				),
				'border'  => array(
					'color'  => $border,
					'width'  => '1px',
					'radius' => '20px',
				),
				'color'   => array(
					'background' => $background,
					'text'       => '#ffffff',
				),
			),
		);
	}

	/**
	 * Light comparison card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_comparison_light_card_attrs() {
		return $this->pattern_card_attrs();
	}

	/**
	 * Top-line card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_line_card_attrs( array $palette = array() ) {
		$line_color = 'editorial-accent' === (string) ( $palette['name'] ?? '' ) ? (string) $palette['accent'] : '#111111';
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '28px',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
					),
				),
				'border'  => array(
					'top' => array(
						'color' => $line_color,
						'width' => '1px',
					),
				),
			),
		);
	}

	/**
	 * Hero dashboard card attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_dashboard_card_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '28px',
						'right'  => '28px',
						'bottom' => '28px',
						'left'   => '28px',
					),
				),
				'border'  => array(
					'color'  => '#dcdcdc',
					'width'  => '1px',
					'radius' => '24px',
				),
				'color'   => array(
					'background' => '#ffffff',
				),
			),
		);
	}

	/**
	 * Dashboard row attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_dashboard_row_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '12px',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
					),
				),
				'border'  => array(
					'top' => array(
						'color' => '#e5e5e5',
						'width' => '1px',
					),
				),
			),
		);
	}

	/**
	 * Card title attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_card_title_attrs() {
		return array(
			'style' => array(
				'typography' => array(
					'fontSize'      => '22px',
					'lineHeight'     => '1.2',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Large light feature title attrs for the Bento spotlight card.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_feature_spotlight_title_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#ffffff' ),
				'typography' => array(
					'fontSize'      => '30px',
					'lineHeight'     => '1.08',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Light card title attrs for dark cards.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_light_card_title_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#ffffff' ),
				'typography' => array(
					'fontSize'      => '22px',
					'lineHeight'     => '1.2',
					'letterSpacing' => '0',
					'fontWeight'    => '500',
				),
			),
		);
	}

	/**
	 * Card body text attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_card_text_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#454545' ),
				'typography' => array(
					'fontSize'  => '16px',
					'lineHeight' => '1.55',
				),
			),
		);
	}

	/**
	 * Light card body text attrs for dark cards.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_light_card_text_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#dddddd' ),
				'typography' => array(
					'fontSize'  => '16px',
					'lineHeight' => '1.55',
				),
			),
		);
	}

	/**
	 * Small muted text attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_small_muted_text_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#666666' ),
				'typography' => array(
					'fontSize'  => '14px',
					'lineHeight' => '1.4',
				),
			),
		);
	}

	/**
	 * Small strong text attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_small_strong_text_attrs() {
		return array(
			'style' => array(
				'color'      => array( 'text' => '#111111' ),
				'typography' => array(
					'fontSize'   => '15px',
					'lineHeight'  => '1.4',
					'fontWeight' => '600',
				),
			),
		);
	}

	/**
	 * Buttons layout attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_buttons_attrs( $justify = 'left' ) {
		return array(
			'layout' => array(
				'type'           => 'flex',
				'justifyContent' => sanitize_key( (string) $justify ),
			),
			'style'  => array(
				'spacing' => array(
					'blockGap' => '12px',
				),
			),
		);
	}

	/**
	 * Primary button attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_primary_button_attrs( array $palette = array() ) {
		$background = (string) ( $palette['contrast_background'] ?? '#111111' );
		return array(
			'style' => array(
				'border'  => array( 'radius' => '999px' ),
				'color'   => array(
					'background' => $background,
					'text'       => '#ffffff',
				),
				'spacing' => array(
					'padding' => array(
						'top'    => '14px',
						'right'  => '24px',
						'bottom' => '14px',
						'left'   => '24px',
					),
				),
			),
		);
	}

	/**
	 * Secondary button attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_secondary_button_attrs( array $palette = array() ) {
		$color = (string) ( $palette['contrast_background'] ?? '#111111' );
		return array(
			'style' => array(
				'border'  => array(
					'color'  => $color,
					'width'  => '1px',
					'radius' => '999px',
				),
				'color'   => array(
					'background' => '#ffffff',
					'text'       => $color,
				),
				'spacing' => array(
					'padding' => array(
						'top'    => '14px',
						'right'  => '24px',
						'bottom' => '14px',
						'left'   => '24px',
					),
				),
			),
		);
	}

	/**
	 * Primary button attrs for dark CTA bands.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_dark_primary_button_attrs() {
		return array(
			'style' => array(
				'border'  => array( 'radius' => '999px' ),
				'color'   => array(
					'background' => '#ffffff',
					'text'       => '#111111',
				),
				'spacing' => array(
					'padding' => array(
						'top'    => '14px',
						'right'  => '24px',
						'bottom' => '14px',
						'left'   => '24px',
					),
				),
			),
		);
	}

	/**
	 * Secondary button attrs for dark CTA bands.
	 *
	 * @param string $background Section background color.
	 * @return array<string,mixed>
	 */
	private function pattern_dark_secondary_button_attrs( $background = '#111111' ) {
		return array(
			'style' => array(
				'border'  => array(
					'color'  => '#ffffff',
					'width'  => '1px',
					'radius' => '999px',
				),
				'color'   => array(
					'background' => (string) $background,
					'text'       => '#ffffff',
				),
				'spacing' => array(
					'padding' => array(
						'top'    => '14px',
						'right'  => '24px',
						'bottom' => '14px',
						'left'   => '24px',
					),
				),
			),
		);
	}

	/**
	 * Columns layout attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_columns_attrs( $gap = '24px' ) {
		return array(
			'isStackedOnMobile' => true,
			'style'             => array(
				'spacing' => array(
					'blockGap' => (string) $gap,
				),
			),
		);
	}

	/**
	 * Media-text layout attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_media_text_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'blockGap' => '40px',
				),
			),
		);
	}

	/**
	 * FAQ list wrapper attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_faq_list_attrs() {
		return array(
			'layout' => array(
				'type'        => 'constrained',
				'contentSize' => '880px',
			),
			'style'  => array(
				'spacing' => array(
					'blockGap' => '0',
				),
			),
		);
	}

	/**
	 * FAQ item attrs.
	 *
	 * @return array<string,mixed>
	 */
	private function pattern_faq_item_attrs() {
		return array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '20px',
						'right'  => '0',
						'bottom' => '20px',
						'left'   => '0',
					),
				),
				'border'  => array(
					'top' => array(
						'color' => '#dddddd',
						'width' => '1px',
					),
				),
			),
		);
	}

	/**
	 * Normalizes text with fallback.
	 *
	 * @param mixed  $value Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function pattern_text( $value, $fallback ) {
		$text = sanitize_text_field( (string) $value );
		return '' !== $text ? $text : sanitize_text_field( (string) $fallback );
	}

	/**
	 * Fits Hero copy so imprecise natural-language prompts do not become huge H1s.
	 *
	 * @param mixed $title Raw Hero title.
	 * @param mixed $description Raw Hero description.
	 * @return array<string,mixed>
	 */
	private function pattern_hero_copy_fit( $title, $description ): array {
		$original_title       = $this->pattern_text( $title, 'WordPress AI' );
		$original_description = $this->pattern_text( $description, '' );
		$max_units            = 34;
		$fitted_title         = $this->pattern_compact_hero_title( $original_title, $max_units );
		$title_changed        = $fitted_title !== $original_title;
		$description_changed  = false;
		$fitted_description   = $original_description;
		if ( $title_changed && '' === $fitted_description ) {
			$fitted_description  = $this->pattern_hero_description_from_long_title( $original_title );
			$description_changed = true;
		}

		return array(
			'hero_title_fit'                  => $title_changed ? 'revised' : 'pass',
			'hero_title'                      => $fitted_title,
			'hero_title_original'             => $original_title,
			'hero_title_changed'              => $title_changed,
			'hero_title_display_units'        => $this->pattern_copy_display_units( $fitted_title ),
			'hero_title_original_display_units' => $this->pattern_copy_display_units( $original_title ),
			'max_hero_title_display_units'    => $max_units,
			'hero_description'                => $fitted_description,
			'hero_description_changed'        => $description_changed,
			'copy_fit_reason'                 => $title_changed ? 'hero_title_was_too_long_for_landing_page_display' : 'hero_title_within_display_budget',
		);
	}

	/**
	 * Compacts a Hero title without relying on OpenClaw prompt precision.
	 *
	 * @param string $title Title.
	 * @param int    $max_units Max display units.
	 * @return string
	 */
	private function pattern_compact_hero_title( string $title, int $max_units ): string {
		$title = $this->pattern_normalize_copy_spaces( $title );
		if ( $this->pattern_copy_display_units( $title ) <= $max_units ) {
			return $title;
		}

		$parts = preg_split( '/[，,。；;：:、|｜]+/u', $title );
		$candidate = is_array( $parts ) && '' !== trim( (string) ( $parts[0] ?? '' ) ) ? trim( (string) $parts[0] ) : $title;
		$candidate = $this->pattern_trim_generic_page_suffix( $candidate );
		if ( $this->pattern_copy_display_units( $candidate ) <= $max_units ) {
			return $candidate;
		}

		return $this->pattern_truncate_copy_to_units( $candidate, $max_units );
	}

	/**
	 * Builds a fallback description when a user prompt only supplied an oversized H1.
	 *
	 * @param string $original_title Original title.
	 * @return string
	 */
	private function pattern_hero_description_from_long_title( string $original_title ): string {
		$parts = preg_split( '/[，,。；;：:、|｜]+/u', $original_title );
		$tail  = is_array( $parts ) && isset( $parts[1] ) ? $this->pattern_normalize_copy_spaces( (string) $parts[1] ) : '';
		if ( '' !== $tail && false === strpos( $tail, '标题' ) && false === strpos( $tail, '测试' ) && false === strpos( $tail, '验证' ) && $this->pattern_copy_display_units( $tail ) >= 8 && $this->pattern_copy_display_units( $tail ) <= 56 ) {
			return $tail;
		}
		return '通过可审查的 Pattern 页面计划，生成配图、响应式布局和可继续编辑的核心区块。';
	}

	/**
	 * Removes generic page-type suffixes when they make Hero H1s wrap poorly.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private function pattern_trim_generic_page_suffix( string $title ): string {
		$title = $this->pattern_normalize_copy_spaces( $title );
		foreach ( array( '介绍页', '落地页', '页面草稿', '页面' ) as $suffix ) {
			$suffix_length = strlen( $suffix );
			if ( $suffix_length > 0 && substr( $title, -$suffix_length ) === $suffix ) {
				$trimmed = trim( substr( $title, 0, -$suffix_length ) );
				if ( $this->pattern_copy_display_units( $trimmed ) >= 12 ) {
					return $trimmed;
				}
			}
		}
		return $title;
	}

	/**
	 * Normalizes copy spacing.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function pattern_normalize_copy_spaces( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Estimates mixed Chinese/English display width for landing-page H1 copy.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function pattern_copy_display_units( string $text ): int {
		$text = $this->pattern_normalize_copy_spaces( $text );
		if ( '' === $text ) {
			return 0;
		}
		if ( false === preg_match_all( '/./u', $text, $matches ) ) {
			return strlen( $text );
		}
		$units = 0;
		foreach ( $matches[0] as $char ) {
			if ( 1 === preg_match( '/[\x{3400}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', (string) $char ) ) {
				$units += 2;
			} elseif ( ' ' === $char ) {
				$units += 1;
			} else {
				$units += 1;
			}
		}
		return $units;
	}

	/**
	 * Truncates copy to a display-unit budget without adding decorative ellipses.
	 *
	 * @param string $text Text.
	 * @param int    $max_units Max display units.
	 * @return string
	 */
	private function pattern_truncate_copy_to_units( string $text, int $max_units ): string {
		$text = $this->pattern_normalize_copy_spaces( $text );
		if ( false === preg_match_all( '/./u', $text, $matches ) ) {
			return substr( $text, 0, max( 1, $max_units ) );
		}
		$output = '';
		$units  = 0;
		foreach ( $matches[0] as $char ) {
			$char_units = 1 === preg_match( '/[\x{3400}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', (string) $char ) ? 2 : 1;
			if ( $units + $char_units > $max_units ) {
				break;
			}
			$output .= $char;
			$units  += $char_units;
		}
		return trim( $output );
	}

	/**
	 * Normalizes a landing page research brief into bounded page-planning hints.
	 *
	 * @param mixed $brief Raw research brief.
	 * @return array<string,mixed>
	 */
	private function pattern_research_brief( $brief ) {
		$brief = is_array( $brief ) ? $brief : array();
		if ( 'landing_page_research_brief' !== sanitize_key( (string) ( $brief['artifact_type'] ?? '' ) ) ) {
			return array();
		}
		if ( false !== (bool) ( $brief['direct_wordpress_write'] ?? false ) ) {
			return array();
		}

		return array(
			'artifact_type'                 => 'landing_page_research_brief',
			'write_posture'                 => sanitize_key( (string) ( $brief['write_posture'] ?? 'suggestion_only' ) ),
			'direct_wordpress_write'        => false,
			'source_count'                  => absint( $brief['source_count'] ?? 0 ),
			'section_patterns'              => $this->pattern_items( $brief['section_patterns'] ?? array(), array(), 0 ),
			'visual_asset_recommendations' => $this->pattern_items( $brief['visual_asset_recommendations'] ?? array(), array(), 0 ),
			'proof_points'                  => $this->pattern_items( $brief['proof_points'] ?? array(), array(), 0 ),
			'comparison_angles'             => $this->pattern_items( $brief['comparison_angles'] ?? array(), array(), 0 ),
			'faq_seed_questions'            => $this->pattern_faq_seed_items( $brief['faq_seed_questions'] ?? array() ),
		);
	}

	/**
	 * Returns a compact public-safe research brief summary.
	 *
	 * @param array<string,mixed> $brief Normalized research brief.
	 * @return array<string,mixed>
	 */
	private function pattern_research_brief_summary( array $brief ) {
		$research_backed = ! empty( $brief );
		return array(
			'research_backed'                      => $research_backed,
			'artifact_type'                        => $research_backed ? 'landing_page_research_brief' : '',
			'source_count'                         => absint( $brief['source_count'] ?? 0 ),
			'section_pattern_count'                => count( (array) ( $brief['section_patterns'] ?? array() ) ),
			'visual_asset_recommendation_count'   => count( (array) ( $brief['visual_asset_recommendations'] ?? array() ) ),
			'proof_point_count'                    => count( (array) ( $brief['proof_points'] ?? array() ) ),
			'comparison_angle_count'               => count( (array) ( $brief['comparison_angles'] ?? array() ) ),
			'faq_seed_question_count'              => count( (array) ( $brief['faq_seed_questions'] ?? array() ) ),
			'reference_copying_allowed'            => false,
			'direct_wordpress_write'               => false,
		);
	}

	/**
	 * Normalizes FAQ seed questions into title/description items.
	 *
	 * @param mixed $items Raw FAQ seeds.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_faq_seed_items( $items ) {
		$items      = is_array( $items ) ? array_values( $items ) : array();
		$normalized = array();
		foreach ( $items as $item ) {
			$item        = is_array( $item ) ? $item : array();
			$title       = $this->pattern_text( $item['title'] ?? ( $item['question'] ?? '' ), '' );
			$description = $this->pattern_text( $item['description'] ?? ( $item['answer'] ?? '' ), '用审查后的页面证据回答这个问题。' );
			if ( '' === $title || '' === $description ) {
				continue;
			}
			$normalized[] = array(
				'title'       => $title,
				'description' => $description,
			);
			if ( count( $normalized ) >= 4 ) {
				break;
			}
		}
		return $normalized;
	}

	/**
	 * Merges item lists without duplicate titles.
	 *
	 * @param array<int,array<string,string>> $primary Primary items.
	 * @param array<int,array<string,string>> $fallback Fallback items.
	 * @param int                             $min_items Minimum item count before stopping fallback.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_merge_item_lists( array $primary, array $fallback, $min_items ) {
		$merged = array();
		foreach ( array_merge( $primary, $fallback ) as $item ) {
			$title       = $this->pattern_text( $item['title'] ?? '', '' );
			$description = $this->pattern_text( $item['description'] ?? '', '' );
			if ( '' === $title || '' === $description ) {
				continue;
			}
			$already_present = false;
			foreach ( $merged as $existing ) {
				if ( $title === (string) ( $existing['title'] ?? '' ) ) {
					$already_present = true;
					break;
				}
			}
			if ( ! $already_present ) {
				$merged[] = array(
					'title'       => $title,
					'description' => $description,
				);
			}
			if ( count( $merged ) >= 6 ) {
				break;
			}
		}
		return array_slice( $merged, 0, max( 0, (int) $min_items, count( $primary ) ) );
	}

	/**
	 * Returns a title from the first research item when available.
	 *
	 * @param array<int,array<string,string>> $items Items.
	 * @param string                          $fallback Fallback.
	 * @return string
	 */
	private function pattern_research_item_title( array $items, $fallback ) {
		return $this->pattern_text( $items[0]['title'] ?? '', $fallback );
	}

	/**
	 * Returns a description from the first research item when available.
	 *
	 * @param array<int,array<string,string>> $items Items.
	 * @param string                          $fallback Fallback.
	 * @return string
	 */
	private function pattern_research_item_description( array $items, $fallback ) {
		return $this->pattern_text( $items[0]['description'] ?? '', $fallback );
	}

	/**
	 * Normalizes variable item arrays.
	 *
	 * @param mixed                         $items Raw items.
	 * @param array<int,array<string,string>> $fallback Fallback items.
	 * @param int                           $min_items Minimum item count.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_items( $items, array $fallback, $min_items = 1 ) {
		$items      = is_array( $items ) ? array_values( $items ) : array();
		$normalized = array();
		foreach ( $items as $item ) {
			$item        = is_array( $item ) ? $item : array();
			$title       = $this->pattern_text( $item['title'] ?? '', '' );
			$description = $this->pattern_text( $item['description'] ?? '', '' );
			if ( '' === $title || '' === $description ) {
				continue;
			}
			$normalized[] = array(
				'title'       => $title,
				'description' => $description,
			);
			if ( count( $normalized ) >= 6 ) {
				break;
			}
		}
		foreach ( $fallback as $item ) {
			if ( count( $normalized ) >= $min_items ) {
				break;
			}
			$title       = $this->pattern_text( $item['title'] ?? '', '' );
			$description = $this->pattern_text( $item['description'] ?? '', '' );
			if ( '' === $title || '' === $description ) {
				continue;
			}
			$already_present = false;
			foreach ( $normalized as $existing ) {
				if ( $title === (string) ( $existing['title'] ?? '' ) ) {
					$already_present = true;
					break;
				}
			}
			if ( ! $already_present ) {
				$normalized[] = array(
					'title'       => $title,
					'description' => $description,
				);
			}
		}
		return ! empty( $normalized ) ? array_slice( $normalized, 0, 6 ) : $fallback;
	}

	/**
	 * Returns deterministic Pattern quality signals for proposal preflight review.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<string,mixed>
	 */
	private function pattern_design_quality_summary( array $blocks, array $research_brief = array(), $color_story = '' ) {
		$has_visual_delta = $this->pattern_has_class_name( $blocks, 'npcink-ai-visual-delta' );
		$color_story      = '' !== (string) $color_story ? sanitize_key( (string) $color_story ) : $this->pattern_detect_color_story( $blocks );
		$accent_surface_count = $this->pattern_accent_surface_count( $blocks );
		$section_shape_variety = $this->pattern_review_section_shape_variety( $blocks );
		$visual_asset_count    = $this->pattern_count_visual_asset_blocks( $blocks );
		$has_hero_media        = $this->pattern_has_class_name( $blocks, 'npcink-ai-hero-media-card' );
		$has_dashboard_mock    = $this->pattern_has_class_name( $blocks, 'npcink-ai-dashboard-mock' );
		$has_media_text        = $this->pattern_has_block_name( $blocks, 'core/media-text' );
		$has_core_html         = $this->pattern_has_block_name( $blocks, 'core/html' ) || $this->pattern_has_block_name( $blocks, 'core/freeform' );
		$uses_non_core_blocks  = $this->pattern_uses_non_core_blocks( $blocks );
		$recipe_variant        = $this->pattern_recipe_variant( $blocks, $research_brief, $has_visual_delta );
		return array(
			'pattern_version'       => $has_visual_delta ? '4.0' : '3.0',
			'design_system'         => 'gutenberg_native_v1',
			'recipe_variant'        => $recipe_variant,
			'variant_reason'        => $this->pattern_variant_reason( $recipe_variant, $blocks, $research_brief ),
			'style_strategy'        => 'gutenberg_native',
			'uses_native_styles'    => true,
			'color_story'           => $color_story,
			'has_editorial_accent'  => 'editorial-accent' === $color_story || $accent_surface_count > 0,
			'accent_surface_count'  => $accent_surface_count,
			'section_shape_variety' => $section_shape_variety,
			'media_coverage_score'  => $this->pattern_media_coverage_score( $visual_asset_count, $has_hero_media, $has_dashboard_mock, $has_media_text ),
			'template_similarity_score' => $this->pattern_template_similarity_score( $blocks, $section_shape_variety, $visual_asset_count ),
			'uses_core_html'        => $has_core_html,
			'uses_non_core_blocks'  => $uses_non_core_blocks,
			'visual_delta_mode'     => $has_visual_delta ? 'strong_revision' : 'standard',
			'research_backed'       => ! empty( $research_brief ),
			'research_source_count' => absint( $research_brief['source_count'] ?? 0 ),
			'top_level_count'       => count( $blocks ),
			'section_count'         => count( $blocks ),
			'block_count'           => $this->count_pattern_blocks_recursive( $blocks ),
			'has_split_hero'        => $this->pattern_has_class_name( $blocks, 'npcink-ai-hero-layout' ),
			'has_dashboard_mock'    => $has_dashboard_mock,
			'has_hero_media'        => $has_hero_media,
			'has_hero_media_attachment_id' => $this->pattern_has_media_attachment_binding( $blocks ),
			'has_proof_strip'       => $this->pattern_has_class_name( $blocks, 'npcink-ai-proof-strip' ),
			'has_bento_grid'        => $this->pattern_has_class_name( $blocks, 'npcink-ai-feature-bento' ),
			'has_visual_delta_section' => $has_visual_delta,
			'has_feature_proof_row' => $this->pattern_has_class_name( $blocks, 'npcink-ai-feature-proof-row' ),
			'has_media_text'        => $has_media_text,
			'has_comparison_section' => $this->pattern_has_class_name( $blocks, 'npcink-ai-comparison' ),
			'has_faq'               => $this->pattern_has_block_name( $blocks, 'core/details' ),
			'has_final_cta'         => $this->pattern_has_class_name( $blocks, 'npcink-ai-final-cta' ),
			'has_columns'           => $this->pattern_has_block_name( $blocks, 'core/columns' ),
			'custom_css_required'   => false,
		);
	}

	/**
	 * Detects the bounded color story from generated native block backgrounds.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return string
	 */
	private function pattern_detect_color_story( array $blocks ): string {
		return $this->pattern_accent_surface_count( $blocks ) > 0 ? 'editorial-accent' : 'minimal-dark-light';
	}

	/**
	 * Returns a bounded recipe variant label for anti-template routing.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $research_brief Research brief.
	 * @param bool                           $has_visual_delta Whether the plan is a visual revision.
	 * @return string
	 */
	private function pattern_recipe_variant( array $blocks, array $research_brief, bool $has_visual_delta ): string {
		if ( $has_visual_delta ) {
			return 'visual_delta_saas_landing';
		}
		if ( ! empty( $research_brief ) ) {
			return 'research_backed_saas_landing';
		}
		if ( $this->pattern_has_class_name( $blocks, 'npcink-ai-hero-media-card' ) ) {
			return 'media_first_saas_landing';
		}
		return 'dashboard_mock_saas_landing';
	}

	/**
	 * Returns a short human-readable reason for the selected recipe variant.
	 *
	 * @param string                         $variant Variant.
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $research_brief Research brief.
	 * @return string
	 */
	private function pattern_variant_reason( string $variant, array $blocks, array $research_brief ): string {
		if ( 'visual_delta_saas_landing' === $variant ) {
			return 'The plan applies review feedback with a darker visual-delta feature section and proof row so the revision is visibly different from the baseline.';
		}
		if ( 'research_backed_saas_landing' === $variant ) {
			return 'The plan uses a reviewed research brief to shape proof points, comparison angles, FAQ seeds, and media copy without copying reference sites.';
		}
		if ( 'media_first_saas_landing' === $variant ) {
			return 'The plan has reviewed hero media, so it uses a split hero, media-text proof section, Bento features, comparison cards, FAQ, and final CTA.';
		}
		if ( $this->pattern_has_class_name( $blocks, 'npcink-ai-dashboard-mock' ) ) {
			return 'No reviewed hero image was supplied, so the plan uses a Gutenberg-native dashboard mock with proof, Bento, comparison, FAQ, and CTA sections.';
		}
		return 'The plan uses a bounded Gutenberg-native landing composition with varied section primitives and no custom CSS.';
	}

	/**
	 * Counts visual asset blocks used by the Pattern.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return int
	 */
	private function pattern_count_visual_asset_blocks( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block_name = (string) ( $block['blockName'] ?? '' );
			if ( 'core/image' === $block_name || 'core/media-text' === $block_name ) {
				++$count;
			}
			$count += $this->pattern_count_visual_asset_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		}
		return $count;
	}

	/**
	 * Returns a normalized media coverage score for landing page quality gates.
	 *
	 * @param int  $visual_asset_count Visual asset count.
	 * @param bool $has_hero_media Whether hero media is present.
	 * @param bool $has_dashboard_mock Whether a dashboard mock is present.
	 * @param bool $has_media_text Whether media-text exists.
	 * @return float
	 */
	private function pattern_media_coverage_score( int $visual_asset_count, bool $has_hero_media, bool $has_dashboard_mock, bool $has_media_text ): float {
		$score = 0.0;
		if ( $visual_asset_count > 0 ) {
			$score += 0.35;
		}
		if ( $has_hero_media ) {
			$score += 0.35;
		}
		if ( $has_media_text ) {
			$score += 0.20;
		}
		if ( $has_dashboard_mock ) {
			$score += 0.20;
		}
		return min( 1.0, round( $score, 2 ) );
	}

	/**
	 * Returns a bounded same-template risk score; lower is more varied.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param int                            $section_shape_variety Section shape variety.
	 * @param int                            $visual_asset_count Visual asset count.
	 * @return float
	 */
	private function pattern_template_similarity_score( array $blocks, int $section_shape_variety, int $visual_asset_count ): float {
		$score = 0.85;
		$score -= min( 0.30, max( 0, $section_shape_variety - 3 ) * 0.06 );
		if ( $visual_asset_count > 0 || $this->pattern_has_class_name( $blocks, 'npcink-ai-dashboard-mock' ) ) {
			$score -= 0.12;
		}
		if ( $this->pattern_has_class_name( $blocks, 'npcink-ai-feature-bento' ) ) {
			$score -= 0.08;
		}
		if ( $this->pattern_has_class_name( $blocks, 'npcink-ai-comparison' ) ) {
			$score -= 0.08;
		}
		if ( $this->pattern_has_class_name( $blocks, 'npcink-ai-visual-delta' ) ) {
			$score -= 0.10;
		}
		return max( 0.0, min( 1.0, round( $score, 2 ) ) );
	}

	/**
	 * Returns whether the block tree uses non-core blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return bool
	 */
	private function pattern_uses_non_core_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block_name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $block_name && 0 !== strpos( $block_name, 'core/' ) ) {
				return true;
			}
			if ( $this->pattern_uses_non_core_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Counts non-neutral Pattern section/card backgrounds used for visual rhythm.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return int
	 */
	private function pattern_accent_surface_count( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$background = $this->pattern_block_background_color( $block );
			if ( '' !== $background && ! in_array( $background, array( '#111111', '#1f1f1f', '#ffffff', '#f7f7f4', '#e5e5e5', '#dddddd', '#dcdcdc', 'black', 'white' ), true ) ) {
				++$count;
			}
			$count += $this->pattern_accent_surface_count( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		}
		return $count;
	}

	/**
	 * Returns deterministic responsive quality signals for proposal preflight review.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $responsive_profile Responsive profile.
	 * @return array<string,mixed>
	 */
	private function pattern_responsive_quality_summary( array $blocks, $responsive_profile ) {
		return array(
			'responsive_profile'           => sanitize_key( (string) $responsive_profile ),
			'uses_mobile_stack'            => $this->pattern_all_columns_stack_on_mobile( $blocks ),
			'uses_core_responsive_blocks'  => $this->pattern_has_block_name( $blocks, 'core/columns' ) || $this->pattern_has_block_name( $blocks, 'core/media-text' ),
			'has_media_section'            => $this->pattern_has_block_name( $blocks, 'core/media-text' ),
			'has_faq'                      => $this->pattern_has_block_name( $blocks, 'core/details' ),
			'max_columns_per_row'          => $this->pattern_max_columns_per_row( $blocks ),
			'button_groups_use_flex_layout' => $this->pattern_button_groups_use_flex_layout( $blocks ),
			'custom_css_required'          => false,
		);
	}

	/**
	 * Normalizes block rows for pattern review without mutating content.
	 *
	 * @param array<int,mixed> $blocks Blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function pattern_review_normalize_blocks( array $blocks ) {
		$normalized = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$normalized[] = array(
				'blockName'    => sanitize_text_field( (string) ( $block['blockName'] ?? '' ) ),
				'attrs'        => is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				'innerHTML'    => (string) ( $block['innerHTML'] ?? '' ),
				'innerContent' => is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array(),
				'innerBlocks'  => $this->pattern_review_normalize_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ),
				'_hasInnerContent' => array_key_exists( 'innerContent', $block ),
			);
		}
		return $normalized;
	}

	/**
	 * Collects bounded deterministic pattern review metrics.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<string,mixed>
	 */
	private function pattern_review_metrics( array $blocks ) {
		$metrics = array(
			'top_level_count'        => count( $blocks ),
			'block_count'            => 0,
			'block_names'            => array(),
			'heading_count'          => 0,
			'paragraph_count'        => 0,
			'button_count'           => 0,
			'image_block_count'      => 0,
			'media_text_block_count' => 0,
			'image_attachment_id_count' => 0,
			'media_text_attachment_id_count' => 0,
			'visual_asset_count'     => 0,
			'hero_media_url_count'   => 0,
			'hero_media_attachment_id_count' => 0,
			'temporary_cloud_preview_url_count' => 0,
			'image_alt_missing_count' => 0,
			'native_style_block_count' => 0,
			'class_name_block_count' => 0,
			'empty_block_name_count' => 0,
			'unknown_block_count'    => 0,
			'custom_html_block_count' => 0,
			'freeform_block_count'   => 0,
			'missing_inner_content_count' => 0,
			'section_shape_variety'  => $this->pattern_review_section_shape_variety( $blocks ),
			'native_style_density'   => 0,
			'class_dependency_ratio' => 0,
			'text_heaviness_score'   => 0,
		);
		$this->pattern_review_metrics_walk( $blocks, $metrics );

		$block_count = max( 1, (int) $metrics['block_count'] );
		$text_count  = (int) $metrics['heading_count'] + (int) $metrics['paragraph_count'];
		$visual_count = max( 0, (int) $metrics['visual_asset_count'] );

		$metrics['native_style_density']   = (int) round( ( (int) $metrics['native_style_block_count'] / $block_count ) * 100 );
		$metrics['class_dependency_ratio'] = (int) round( ( (int) $metrics['class_name_block_count'] / $block_count ) * 100 );
		$metrics['text_heaviness_score']   = min( 100, max( 0, (int) round( ( $text_count - ( $visual_count * 4 ) - (int) $metrics['button_count'] ) * 2 ) ) );
		ksort( $metrics['block_names'] );

		return $metrics;
	}

	/**
	 * Walks blocks and updates review metrics.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $metrics Metrics.
	 * @return void
	 */
	private function pattern_review_metrics_walk( array $blocks, array &$metrics ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			++$metrics['block_count'];
			$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
			if ( '' === $block_name ) {
				++$metrics['empty_block_name_count'];
			} else {
				$metrics['block_names'][ $block_name ] = (int) ( $metrics['block_names'][ $block_name ] ?? 0 ) + 1;
				if ( 0 !== strpos( $block_name, 'core/' ) ) {
					++$metrics['unknown_block_count'];
				}
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( '' !== (string) ( $attrs['className'] ?? '' ) ) {
				++$metrics['class_name_block_count'];
			}
			if ( $this->pattern_review_block_uses_native_attrs( $attrs ) ) {
				++$metrics['native_style_block_count'];
			}
			if ( ! empty( $block['_hasInnerContent'] ) && empty( $block['innerContent'] ) && ! empty( $block['innerBlocks'] ) ) {
				++$metrics['missing_inner_content_count'];
			}

			if ( 'core/heading' === $block_name ) {
				++$metrics['heading_count'];
			} elseif ( 'core/paragraph' === $block_name ) {
				++$metrics['paragraph_count'];
			} elseif ( 'core/button' === $block_name ) {
				++$metrics['button_count'];
			} elseif ( 'core/image' === $block_name ) {
				++$metrics['image_block_count'];
				++$metrics['visual_asset_count'];
				if ( '' !== trim( (string) ( $attrs['url'] ?? '' ) ) ) {
					++$metrics['hero_media_url_count'];
				}
				if ( $this->pattern_media_attachment_id( $attrs['id'] ?? 0 ) > 0 ) {
					++$metrics['image_attachment_id_count'];
					++$metrics['hero_media_attachment_id_count'];
				}
				if ( $this->pattern_is_temporary_cloud_preview_url( $attrs['url'] ?? '' ) ) {
					++$metrics['temporary_cloud_preview_url_count'];
				}
				if ( '' === trim( (string) ( $attrs['alt'] ?? '' ) ) ) {
					++$metrics['image_alt_missing_count'];
				}
			} elseif ( 'core/media-text' === $block_name ) {
				++$metrics['media_text_block_count'];
				++$metrics['visual_asset_count'];
				if ( '' !== trim( (string) ( $attrs['mediaUrl'] ?? '' ) ) ) {
					++$metrics['hero_media_url_count'];
				}
				if ( $this->pattern_media_attachment_id( $attrs['mediaId'] ?? 0 ) > 0 ) {
					++$metrics['media_text_attachment_id_count'];
					++$metrics['hero_media_attachment_id_count'];
				}
				if ( $this->pattern_is_temporary_cloud_preview_url( $attrs['mediaUrl'] ?? '' ) ) {
					++$metrics['temporary_cloud_preview_url_count'];
				}
				if ( '' === trim( (string) ( $attrs['mediaAlt'] ?? '' ) ) ) {
					++$metrics['image_alt_missing_count'];
				}
			} elseif ( 'core/html' === $block_name ) {
				++$metrics['custom_html_block_count'];
			} elseif ( 'core/freeform' === $block_name ) {
				++$metrics['freeform_block_count'];
			}

			$this->pattern_review_metrics_walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $metrics );
		}
	}

	/**
	 * Returns whether a block uses Gutenberg native visual attrs.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return bool
	 */
	private function pattern_review_block_uses_native_attrs( array $attrs ) {
		return ! empty( $attrs['align'] ) || ! empty( $attrs['layout'] ) || ! empty( $attrs['style'] ) || ! empty( $attrs['backgroundColor'] ) || ! empty( $attrs['textColor'] ) || ! empty( $attrs['fontSize'] );
	}

	/**
	 * Counts top-level section shape variety from class handles and child block names.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return int
	 */
	private function pattern_review_section_shape_variety( array $blocks ) {
		$shapes = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs       = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$class_names = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) );
			$first_class = is_array( $class_names ) && ! empty( $class_names[0] ) ? (string) $class_names[0] : '';
			$child_names = array();
			foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner_block ) {
				if ( is_array( $inner_block ) ) {
					$child_names[] = (string) ( $inner_block['blockName'] ?? '' );
				}
			}
			$shapes[] = (string) ( $block['blockName'] ?? '' ) . ':' . $first_class . ':' . implode( ',', array_slice( $child_names, 0, 3 ) );
		}
		return count( array_unique( $shapes ) );
	}

	/**
	 * Reviews editor invalid-block risk from server-observable signals.
	 *
	 * @param array<string,mixed> $metrics Metrics.
	 * @return array<string,mixed>
	 */
	private function pattern_review_editor_risk( array $metrics ) {
		$level   = 'low';
		$reasons = array();
		if ( (int) $metrics['empty_block_name_count'] > 0 ) {
			$level     = 'high';
			$reasons[] = 'empty_block_name_detected';
		}
		if ( (int) $metrics['unknown_block_count'] > 0 ) {
			$level     = 'high' === $level ? 'high' : 'medium';
			$reasons[] = 'non_core_block_detected';
		}
		if ( (int) $metrics['custom_html_block_count'] > 0 || (int) $metrics['freeform_block_count'] > 0 ) {
			$level     = 'high' === $level ? 'high' : 'medium';
			$reasons[] = 'custom_html_or_freeform_detected';
		}
		if ( (int) $metrics['missing_inner_content_count'] > 0 ) {
			$level     = 'high' === $level ? 'high' : 'medium';
			$reasons[] = 'container_missing_inner_content_markers';
		}
		if ( empty( $reasons ) ) {
			$reasons[] = 'server_observable_block_structure_clean';
		}
		return array(
			'invalid_block_risk_level' => $level,
			'risk_reasons'             => $reasons,
			'server_side_only'         => true,
			'editor_save_validation_required' => true,
		);
	}

	/**
	 * Returns responsive risk level from core responsive signals.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $metrics Metrics.
	 * @return string
	 */
	private function pattern_review_responsive_risk_level( array $blocks, array $metrics ) {
		if ( (int) $metrics['block_count'] <= 0 ) {
			return 'high';
		}
		if ( $this->pattern_max_columns_per_row( $blocks ) > 4 ) {
			return 'high';
		}
		if ( $this->pattern_has_block_name( $blocks, 'core/columns' ) && ! $this->pattern_all_columns_stack_on_mobile( $blocks ) ) {
			return 'medium';
		}
		if ( ! $this->pattern_has_block_name( $blocks, 'core/columns' ) && ! $this->pattern_has_block_name( $blocks, 'core/media-text' ) ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Builds review findings.
	 *
	 * @param array<string,mixed> $design Design quality.
	 * @param array<string,mixed> $responsive Responsive quality.
	 * @param array<string,mixed> $media Media quality.
	 * @param array<string,mixed> $content Content quality.
	 * @param array<string,mixed> $risk Risk review.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_review_findings( array $design, array $responsive, array $media, array $content, array $risk ) {
		$findings = array();
		if ( ! empty( $design['has_split_hero'] ) ) {
			$findings[] = array(
				'severity' => 'pass',
				'code'     => 'split_hero_present',
				'message'  => '页面包含 split hero 结构。',
			);
		}
		$this->pattern_review_add_finding( $findings, ! empty( $design['has_hero_media'] ) || (int) $media['visual_asset_count'] > 0, 'medium', 'hero_media_missing', '页面缺少强视觉资产，建议补 reviewed media 或产品面板。' );
		$this->pattern_review_add_finding( $findings, ! empty( $design['has_bento_grid'] ), 'medium', 'bento_grid_missing', 'Feature 区仍偏普通网格，建议使用 Bento 或非重复 section 形态。' );
		$this->pattern_review_add_finding( $findings, ! empty( $design['has_comparison_section'] ), 'medium', 'comparison_missing', '页面缺少 proposal-first 对比区，说服力会偏弱。' );
		$this->pattern_review_add_finding( $findings, ! empty( $design['has_faq'] ), 'low', 'faq_missing', '页面缺少 FAQ，可编辑说明和异议处理不完整。' );
		$this->pattern_review_add_finding( $findings, ! empty( $design['has_final_cta'] ), 'medium', 'final_cta_missing', '页面缺少收束 CTA。' );
		$this->pattern_review_add_finding( $findings, (int) $design['section_shape_variety'] >= 5, 'low', 'section_variety_low', 'Section 形态变化不足，页面容易显得模板化。' );
		$this->pattern_review_add_finding( $findings, (int) $design['native_style_density'] >= 40, 'medium', 'native_style_density_low', '原生 Gutenberg style/layout attrs 使用不足，页面会过度依赖主题默认样式。' );
		$this->pattern_review_add_finding( $findings, 'low' === (string) $responsive['responsive_risk_level'], 'medium', 'responsive_risk_detected', '响应式信号不足，请检查 columns 是否移动端堆叠、列数是否受控。' );
		$this->pattern_review_add_finding( $findings, ! empty( $media['image_alt_complete'] ), 'medium', 'image_alt_missing', '存在图片或 media-text 缺少 alt 文本。' );
		$this->pattern_review_add_finding( $findings, empty( $media['has_temporary_cloud_preview_url'] ), 'medium', 'temporary_cloud_preview_url', '页面仍引用临时 Cloud preview URL，应先采纳为本地媒体 URL。' );
		$this->pattern_review_add_finding( $findings, 'low' === (string) $risk['invalid_block_risk_level'], 'medium', 'editor_invalid_block_risk', '存在服务端可见的编辑器无效块风险信号。' );
		if ( (int) $content['text_heaviness_score'] >= 70 ) {
			$findings[] = array(
				'severity' => 'info',
				'code'     => 'text_heaviness_high',
				'message'  => '页面文字密度偏高；可考虑增加截图、流程图或更强视觉节奏。',
			);
		}
		return $findings;
	}

	/**
	 * Builds a stable layout fingerprint so clients can detect template sameness.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $metrics Review metrics.
	 * @return array<string,mixed>
	 */
	private function pattern_layout_fingerprint( array $blocks, array $metrics ): array {
		$section_sequence      = array();
		$section_alignment_map = array();
		$alignment_mix         = array();
		$section_backgrounds   = array();
		$accent_color_count    = 0;
		$contrast_band_count   = 0;
		$media_section_count   = 0;
		$card_grid_count       = 0;
		$comparison_title_alignment = '';

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$section_signature = $this->pattern_section_signature( $block );
			$section_sequence[] = $section_signature;

			$alignment = $this->pattern_first_heading_alignment( array( $block ) );
			if ( '' === $alignment ) {
				$alignment = 'left';
			}
			$section_alignment_map[] = array(
				'index'     => $index,
				'section'   => $section_signature,
				'alignment' => $alignment,
			);
			$alignment_mix[] = $alignment;

			$background = $this->pattern_block_background_color( $block );
			if ( '' !== $background ) {
				$section_backgrounds[] = $background;
				if ( ! in_array( $background, array( '#111111', '#1f1f1f', '#ffffff', '#f7f7f4', '#e5e5e5', '#dddddd', 'black', 'white' ), true ) ) {
					++$accent_color_count;
				}
			}

			if ( $this->pattern_section_is_dark_contrast_band( $block ) ) {
				++$contrast_band_count;
			}
			if ( $this->pattern_has_block_name( array( $block ), 'core/image' ) || $this->pattern_has_block_name( array( $block ), 'core/media-text' ) ) {
				++$media_section_count;
			}
			if ( $this->pattern_section_uses_card_grid( $block ) ) {
				++$card_grid_count;
			}
			if ( $this->pattern_block_has_class_token( $block, 'npcink-ai-comparison' ) ) {
				$comparison_title_alignment = $alignment;
			}
		}

		$alignment_mix = array_values( array_unique( array_filter( $alignment_mix ) ) );
		$similarity_risk = 'low';
		if ( count( $section_sequence ) < 6 || (int) ( $metrics['section_shape_variety'] ?? 0 ) < 4 ) {
			$similarity_risk = 'high';
		} elseif ( $card_grid_count >= 4 && $contrast_band_count < 1 ) {
			$similarity_risk = 'medium';
		}

		return array(
			'section_count'              => count( $section_sequence ),
			'section_sequence'           => $section_sequence,
			'section_sequence_hash'      => substr( md5( implode( '|', $section_sequence ) ), 0, 12 ),
			'section_backgrounds'        => array_values( array_unique( $section_backgrounds ) ),
			'unique_background_count'    => count( array_unique( $section_backgrounds ) ),
			'accent_color_count'         => $accent_color_count,
			'section_alignment_map'      => $section_alignment_map,
			'alignment_mix'              => $alignment_mix,
			'contrast_band_count'        => $contrast_band_count,
			'media_section_count'        => $media_section_count,
			'card_grid_count'            => $card_grid_count,
			'comparison_title_alignment' => $comparison_title_alignment,
			'visual_similarity_risk'     => $similarity_risk,
		);
	}

	/**
	 * Finds visual quality issues that are not captured by block validity alone.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<string,mixed>            $layout_fingerprint Layout fingerprint.
	 * @return array<int,array<string,string>>
	 */
	private function pattern_visual_quality_findings( array $blocks, array $layout_fingerprint ): array {
		$findings = array();
		$encoded  = wp_json_encode( $blocks );
		if ( is_string( $encoded ) && false !== strpos( $encoded, 'example.test' ) ) {
			$findings[] = array(
				'severity' => 'medium',
				'code'     => 'placeholder_media_url',
				'message'  => '页面块里仍包含 placeholder media URL，前台会出现破图。',
			);
		}
		if ( $this->pattern_has_underpadded_cards( $blocks ) ) {
			$findings[] = array(
				'severity' => 'medium',
				'code'     => 'insufficient_card_padding',
				'message'  => '部分卡片缺少完整 Gutenberg spacing padding，容易出现文字贴边。',
			);
		}
		if ( $this->pattern_has_light_cards_inheriting_light_text( $blocks ) ) {
			$findings[] = array(
				'severity' => 'medium',
				'code'     => 'light_card_inherits_light_text',
				'message'  => '浅色卡片处在深色区时未重设文字颜色，可能导致标题或正文不可见。',
			);
		}
		if ( 'center' !== (string) ( $layout_fingerprint['comparison_title_alignment'] ?? '' ) && $this->pattern_has_class_name( $blocks, 'npcink-ai-comparison' ) ) {
			$findings[] = array(
				'severity' => 'info',
				'code'     => 'section_title_alignment_suggestion',
				'message'  => '对称 comparison 区标题可考虑居中，减少和下方双卡布局的错位感。',
			);
		}
		if ( in_array( (string) ( $layout_fingerprint['visual_similarity_risk'] ?? 'low' ), array( 'medium', 'high' ), true ) ) {
			$findings[] = array(
				'severity' => 'low',
				'code'     => 'layout_similarity_risk',
				'message'  => 'Section 序列变化不足，后续页面可能显得模板化。',
			);
		}
		if ( (int) ( $layout_fingerprint['section_count'] ?? 0 ) >= 6 && (int) ( $layout_fingerprint['accent_color_count'] ?? 0 ) < 1 && (int) ( $layout_fingerprint['unique_background_count'] ?? 0 ) <= 3 ) {
			$findings[] = array(
				'severity' => 'low',
				'code'     => 'color_story_monochrome',
				'message'  => '页面色彩主要停留在黑白灰，建议改用受控强调色 story 或补充视觉资产。',
			);
		}

		return $findings;
	}

	/**
	 * Returns whether visual findings should block a passing review.
	 *
	 * @param array<int,array<string,string>> $findings Visual findings.
	 * @return bool
	 */
	private function pattern_has_blocking_visual_findings( array $findings ): bool {
		foreach ( $findings as $finding ) {
			if ( in_array( (string) ( $finding['severity'] ?? '' ), array( 'medium', 'high' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds a finding when a condition is not satisfied.
	 *
	 * @param array<int,array<string,string>> $findings Findings.
	 * @param bool                            $condition Condition.
	 * @param string                          $severity Severity.
	 * @param string                          $code Code.
	 * @param string                          $message Message.
	 * @return void
	 */
	private function pattern_review_add_finding( array &$findings, $condition, $severity, $code, $message ) {
		if ( $condition ) {
			return;
		}
		$findings[] = array(
			'severity' => sanitize_key( (string) $severity ),
			'code'     => sanitize_key( (string) $code ),
			'message'  => sanitize_text_field( (string) $message ),
		);
	}

	/**
	 * Scores pattern review signals.
	 *
	 * @param array<string,mixed> $design Design quality.
	 * @param array<string,mixed> $responsive Responsive quality.
	 * @param array<string,mixed> $media Media quality.
	 * @param array<string,mixed> $content Content quality.
	 * @param array<string,mixed> $risk Risk review.
	 * @return int
	 */
	private function pattern_review_score( array $design, array $responsive, array $media, array $content, array $risk ) {
		$score = 100;
		foreach ( array( 'has_split_hero', 'has_bento_grid', 'has_comparison_section', 'has_faq', 'has_final_cta' ) as $signal ) {
			if ( empty( $design[ $signal ] ) ) {
				$score -= in_array( $signal, array( 'has_split_hero', 'has_comparison_section', 'has_final_cta' ), true ) ? 10 : 6;
			}
		}
		if ( empty( $design['has_hero_media'] ) && (int) $media['visual_asset_count'] <= 0 ) {
			$score -= 15;
		}
		if ( (int) $design['section_shape_variety'] < 5 ) {
			$score -= 8;
		}
		if ( (int) $design['native_style_density'] < 40 ) {
			$score -= 12;
		}
		if ( ! empty( $media['image_alt_missing_count'] ) ) {
			$score -= 10;
		}
		if ( ! empty( $media['has_temporary_cloud_preview_url'] ) ) {
			$score -= 15;
		}
		if ( 'high' === (string) $responsive['responsive_risk_level'] ) {
			$score -= 20;
		} elseif ( 'medium' === (string) $responsive['responsive_risk_level'] ) {
			$score -= 10;
		}
		if ( 'high' === (string) $risk['invalid_block_risk_level'] ) {
			$score -= 30;
		} elseif ( 'medium' === (string) $risk['invalid_block_risk_level'] ) {
			$score -= 15;
		}
		if ( (int) $content['text_heaviness_score'] >= 85 ) {
			$score -= 5;
		}
		return max( 0, min( 100, (int) $score ) );
	}

	/**
	 * Returns recommended next actions.
	 *
	 * @param string                          $review_status Review status.
	 * @param array<int,array<string,string>> $findings Findings.
	 * @return string[]
	 */
	private function pattern_review_next_actions( $review_status, array $findings ) {
		if ( 'pass' === $review_status ) {
			return array( 'preview_page_in_editor', 'confirm_mobile_stack', 'reuse_pattern_or_submit_next_proposal' );
		}
		$actions = array( 'revise_pattern_page_plan' );
		foreach ( $findings as $finding ) {
			$code = (string) ( $finding['code'] ?? '' );
			if ( 'hero_media_missing' === $code || 'image_alt_missing' === $code || 'placeholder_media_url' === $code || 'temporary_cloud_preview_url' === $code ) {
				$actions[] = 'repair_media_inputs';
			} elseif ( 'responsive_risk_detected' === $code ) {
				$actions[] = 'review_responsive_columns';
			} elseif ( 'editor_invalid_block_risk' === $code ) {
				$actions[] = 'open_editor_and_rebuild_invalid_blocks';
			} elseif ( in_array( $code, array( 'insufficient_card_padding', 'light_card_inherits_light_text', 'layout_similarity_risk', 'section_title_alignment_suggestion', 'color_story_monochrome' ), true ) ) {
				$actions[] = 'revise_visual_pattern_variants';
			}
		}
		return array_values( array_unique( $actions ) );
	}

	/**
	 * Returns whether a block tree contains a block name.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $block_name Block name.
	 * @return bool
	 */
	private function pattern_has_block_name( array $blocks, $block_name ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( $block_name === (string) ( $block['blockName'] ?? '' ) ) {
				return true;
			}
			if ( $this->pattern_has_block_name( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $block_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns whether a block tree includes image/media-text attachment bindings.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return bool
	 */
	private function pattern_has_media_attachment_binding( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block_name = (string) ( $block['blockName'] ?? '' );
			$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'core/image' === $block_name && $this->pattern_media_attachment_id( $attrs['id'] ?? 0 ) > 0 ) {
				return true;
			}
			if ( 'core/media-text' === $block_name && $this->pattern_media_attachment_id( $attrs['mediaId'] ?? 0 ) > 0 ) {
				return true;
			}
			if ( $this->pattern_has_media_attachment_binding( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns whether a block tree contains a class token.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $class_name Class name.
	 * @return bool
	 */
	private function pattern_has_class_name( array $blocks, $class_name ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$classes = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) );
			if ( in_array( $class_name, is_array( $classes ) ? $classes : array(), true ) ) {
				return true;
			}
			if ( $this->pattern_has_class_name( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $class_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a stable top-level section signature.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private function pattern_section_signature( array $block ): string {
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
			$class = is_string( $class ) ? $class : '';
			if ( '' !== $class && 'npcink-ai-page' !== $class ) {
				return $class;
			}
		}
		return sanitize_text_field( (string) ( $block['blockName'] ?? 'unknown' ) );
	}

	/**
	 * Returns whether one block has a class token.
	 *
	 * @param array<string,mixed> $block Block.
	 * @param string              $class_name Class token.
	 * @return bool
	 */
	private function pattern_block_has_class_token( array $block, string $class_name ): bool {
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) );
		return in_array( $class_name, is_array( $classes ) ? $classes : array(), true );
	}

	/**
	 * Returns the first heading alignment in a block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return string
	 */
	private function pattern_first_heading_alignment( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/heading' === (string) ( $block['blockName'] ?? '' ) ) {
				$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$text_align = sanitize_key( (string) ( $attrs['textAlign'] ?? '' ) );
				if ( in_array( $text_align, array( 'left', 'center', 'right' ), true ) ) {
					return $text_align;
				}
				$inner_html = (string) ( $block['innerHTML'] ?? '' );
				foreach ( array( 'left', 'center', 'right' ) as $align ) {
					if ( false !== strpos( $inner_html, 'has-text-align-' . $align ) ) {
						return $align;
					}
				}
				return 'left';
			}
			$inner_alignment = $this->pattern_first_heading_alignment( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
			if ( '' !== $inner_alignment ) {
				return $inner_alignment;
			}
		}
		return '';
	}

	/**
	 * Returns whether a section is a dark contrast band.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return bool
	 */
	private function pattern_section_is_dark_contrast_band( array $block ): bool {
		$background = $this->pattern_block_background_color( $block );
		return in_array( $background, array( '#111111', '#1f1f1f', '#102b2d', 'black' ), true );
	}

	/**
	 * Returns one block's native background color token.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private function pattern_block_background_color( array $block ): string {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$color = is_array( $style['color'] ?? null ) ? $style['color'] : array();
		return strtolower( trim( (string) ( $color['background'] ?? '' ) ) );
	}

	/**
	 * Returns whether a section uses a repeated-card grid shape.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return bool
	 */
	private function pattern_section_uses_card_grid( array $block ): bool {
		foreach ( array( 'npcink-ai-proof-strip', 'npcink-ai-feature-grid', 'npcink-ai-workflow', 'npcink-ai-comparison' ) as $class_name ) {
			if ( $this->pattern_has_class_name( array( $block ), $class_name ) ) {
				return true;
			}
		}
		return $this->pattern_max_columns_per_row( array( $block ) ) >= 2;
	}

	/**
	 * Returns whether known card blocks are missing complete native padding.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return bool
	 */
	private function pattern_has_underpadded_cards( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$is_card = false;
			foreach ( array( 'npcink-ai-comparison-card', 'npcink-ai-feature-proof', 'npcink-ai-workflow-step', 'npcink-ai-feature-card' ) as $class_name ) {
				if ( $this->pattern_block_has_class_token( $block, $class_name ) ) {
					$is_card = true;
					break;
				}
			}
			if ( $is_card ) {
				$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
				$spacing = is_array( $style['spacing'] ?? null ) ? $style['spacing'] : array();
				$padding = is_array( $spacing['padding'] ?? null ) ? $spacing['padding'] : array();
				foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
					if ( $this->pattern_px_value( $padding[ $side ] ?? '' ) < 16 ) {
						return true;
					}
				}
			}
			if ( $this->pattern_has_underpadded_cards( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns whether a dark parent contains a light card without explicit text color.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $inherited_text_color Inherited text color.
	 * @return bool
	 */
	private function pattern_has_light_cards_inheriting_light_text( array $blocks, string $inherited_text_color = '' ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
			$color   = is_array( $style['color'] ?? null ) ? $style['color'] : array();
			$text    = strtolower( (string) ( $color['text'] ?? $inherited_text_color ) );
			$bg      = strtolower( (string) ( $color['background'] ?? '' ) );
			$is_light_card = in_array( $bg, array( '#ffffff', '#f7f7f4' ), true ) && (
				$this->pattern_block_has_class_token( $block, 'npcink-ai-comparison-card' ) ||
				$this->pattern_block_has_class_token( $block, 'npcink-ai-feature-proof' ) ||
				$this->pattern_block_has_class_token( $block, 'npcink-ai-feature-card' )
			);
			if ( $is_light_card && ! isset( $color['text'] ) && in_array( $text, array( '#ffffff', '#f2f2f2' ), true ) ) {
				return true;
			}
			if ( $this->pattern_has_light_cards_inheriting_light_text( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses a px value for coarse spacing checks.
	 *
	 * @param mixed $value CSS value.
	 * @return int
	 */
	private function pattern_px_value( $value ): int {
		$value = trim( (string) $value );
		if ( 1 === preg_match( '/^([0-9]+)px$/', $value, $matches ) ) {
			return absint( $matches[1] );
		}
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		return 0;
	}

	/**
	 * Returns whether every columns block is configured to stack on mobile.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return bool
	 */
	private function pattern_all_columns_stack_on_mobile( array $blocks ) {
		$found_columns = false;
		return $this->pattern_all_columns_stack_on_mobile_walk( $blocks, $found_columns ) && $found_columns;
	}

	/**
	 * Walks columns blocks and tracks whether they stack on mobile.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param bool                           $found_columns Whether a columns block was found.
	 * @return bool
	 */
	private function pattern_all_columns_stack_on_mobile_walk( array $blocks, &$found_columns ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/columns' === (string) ( $block['blockName'] ?? '' ) ) {
				$found_columns = true;
				$attrs         = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				if ( true !== ( $attrs['isStackedOnMobile'] ?? null ) ) {
					return false;
				}
			}
			if ( ! $this->pattern_all_columns_stack_on_mobile_walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $found_columns ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the largest direct column count inside a columns block.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return int
	 */
	private function pattern_max_columns_per_row( array $blocks ) {
		$max = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/columns' === (string) ( $block['blockName'] ?? '' ) ) {
				$column_count = 0;
				foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner_block ) {
					if ( is_array( $inner_block ) && 'core/column' === (string) ( $inner_block['blockName'] ?? '' ) ) {
						++$column_count;
					}
				}
				$max = max( $max, $column_count );
			}
			$max = max( $max, $this->pattern_max_columns_per_row( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) );
		}
		return $max;
	}

	/**
	 * Returns whether button groups use core flex layout attrs.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return bool
	 */
	private function pattern_button_groups_use_flex_layout( array $blocks ) {
		$found_buttons = false;
		return $this->pattern_button_groups_use_flex_layout_walk( $blocks, $found_buttons ) && $found_buttons;
	}

	/**
	 * Walks button groups and tracks whether they use flex layout.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param bool                           $found_buttons Whether a buttons block was found.
	 * @return bool
	 */
	private function pattern_button_groups_use_flex_layout_walk( array $blocks, &$found_buttons ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/buttons' === (string) ( $block['blockName'] ?? '' ) ) {
				$found_buttons = true;
				$attrs         = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$layout        = is_array( $attrs['layout'] ?? null ) ? $attrs['layout'] : array();
				if ( 'flex' !== (string) ( $layout['type'] ?? '' ) ) {
					return false;
				}
			}
			if ( ! $this->pattern_button_groups_use_flex_layout_walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $found_buttons ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Builds the core/group wrapper classes expected by Gutenberg save().
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private function pattern_group_classes( array $attrs ) {
		$classes = array( 'wp-block-group' );
		$align   = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $attrs['align'] ?? '' ) );
		$align   = is_string( $align ) ? $align : '';
		if ( '' !== $align ) {
			$classes[] = 'align' . $align;
		}
		$class_name = $this->pattern_class_names( (string) ( $attrs['className'] ?? '' ) );
		foreach ( preg_split( '/\s+/', $class_name ) ?: array() as $class ) {
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$color = is_array( $style['color'] ?? null ) ? $style['color'] : array();
		$border = is_array( $style['border'] ?? null ) ? $style['border'] : array();
		if ( ! empty( $border['color'] ) ) {
			$classes[] = 'has-border-color';
		}
		if ( ! empty( $color['text'] ) ) {
			$classes[] = 'has-text-color';
		}
		if ( ! empty( $color['background'] ) ) {
			$classes[] = 'has-background';
		}
		return implode( ' ', array_values( array_unique( $classes ) ) );
	}

	/**
	 * Builds the core/button link classes expected by Gutenberg save().
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private function pattern_button_link_classes( array $attrs ) {
		$classes = array( 'wp-block-button__link' );
		$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$color   = is_array( $style['color'] ?? null ) ? $style['color'] : array();
		$border  = is_array( $style['border'] ?? null ) ? $style['border'] : array();
		if ( ! empty( $color['text'] ) ) {
			$classes[] = 'has-text-color';
		}
		if ( ! empty( $color['background'] ) ) {
			$classes[] = 'has-background';
		}
		if ( ! empty( $border['color'] ) ) {
			$classes[] = 'has-border-color';
		}
		$classes[] = 'wp-element-button';
		return implode( ' ', $classes );
	}

	/**
	 * Builds color support classes expected by Gutenberg save().
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string[]
	 */
	private function pattern_color_support_classes( array $attrs ) {
		$classes = array();
		$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$color   = is_array( $style['color'] ?? null ) ? $style['color'] : array();
		if ( ! empty( $color['text'] ) ) {
			$classes[] = 'has-text-color';
		}
		if ( ! empty( $color['background'] ) ) {
			$classes[] = 'has-background';
		}
		return $classes;
	}

	/**
	 * Serializes common Gutenberg support attrs into the save() style order.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private function pattern_style_from_attrs( array $attrs ) {
		$style = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$css   = array();

		$border = is_array( $style['border'] ?? null ) ? $style['border'] : array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$side_border = is_array( $border[ $side ] ?? null ) ? $border[ $side ] : array();
			if ( ! empty( $side_border['color'] ) ) {
				$css[] = 'border-' . $side . '-color:' . $this->pattern_css_value( (string) $side_border['color'] );
			}
			if ( ! empty( $side_border['width'] ) ) {
				$css[] = 'border-' . $side . '-width:' . $this->pattern_css_value( (string) $side_border['width'] );
			}
		}
		if ( ! empty( $border['color'] ) ) {
			$css[] = 'border-color:' . $this->pattern_css_value( (string) $border['color'] );
		}
		if ( ! empty( $border['width'] ) ) {
			$css[] = 'border-width:' . $this->pattern_css_value( (string) $border['width'] );
		}
		if ( ! empty( $border['radius'] ) ) {
			$css[] = 'border-radius:' . $this->pattern_css_value( (string) $border['radius'] );
		}

		$color = is_array( $style['color'] ?? null ) ? $style['color'] : array();
		if ( ! empty( $color['background'] ) ) {
			$css[] = 'background-color:' . $this->pattern_css_value( (string) $color['background'] );
		}
		if ( ! empty( $color['text'] ) ) {
			$css[] = 'color:' . $this->pattern_css_value( (string) $color['text'] );
		}

		$typography = is_array( $style['typography'] ?? null ) ? $style['typography'] : array();
		if ( ! empty( $typography['fontSize'] ) ) {
			$css[] = 'font-size:' . $this->pattern_css_value( (string) $typography['fontSize'] );
		}
		if ( ! empty( $typography['fontWeight'] ) ) {
			$css[] = 'font-weight:' . $this->pattern_css_value( (string) $typography['fontWeight'] );
		}
		if ( isset( $typography['letterSpacing'] ) && '' !== (string) $typography['letterSpacing'] ) {
			$css[] = 'letter-spacing:' . $this->pattern_css_value( (string) $typography['letterSpacing'] );
		}
		if ( ! empty( $typography['lineHeight'] ) ) {
			$css[] = 'line-height:' . $this->pattern_css_value( (string) $typography['lineHeight'] );
		}
		if ( ! empty( $typography['textTransform'] ) ) {
			$css[] = 'text-transform:' . $this->pattern_css_value( (string) $typography['textTransform'] );
		}

		$spacing = is_array( $style['spacing'] ?? null ) ? $style['spacing'] : array();
		$padding = is_array( $spacing['padding'] ?? null ) ? $spacing['padding'] : array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $padding[ $side ] ) && '' !== (string) $padding[ $side ] ) {
				$css[] = 'padding-' . $side . ':' . $this->pattern_css_value( (string) $padding[ $side ] );
			}
		}

		return implode( ';', $css );
	}

	/**
	 * Converts Gutenberg preset tokens to valid CSS values for static markup.
	 *
	 * @param string $value CSS or Gutenberg preset token.
	 * @return string
	 */
	private function pattern_css_value( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^var:preset\\|([A-Za-z0-9_-]+)\\|([A-Za-z0-9_-]+)$/', $value, $matches ) ) {
			return 'var(--wp--preset--' . sanitize_key( $matches[1] ) . '--' . sanitize_key( $matches[2] ) . ')';
		}
		return $value;
	}

	/**
	 * Filters class names to the pattern whitelist.
	 *
	 * @param string $class_names Class names.
	 * @return string
	 */
	private function pattern_class_names( $class_names ) {
		$allowed = array_flip( $this->pattern_allowed_classes() );
		$classes = preg_split( '/\s+/', (string) $class_names );
		$kept    = array();
		foreach ( is_array( $classes ) ? $classes : array() as $class_name ) {
			$class_name = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class_name );
			$class_name = is_string( $class_name ) ? $class_name : '';
			if ( '' !== $class_name && isset( $allowed[ $class_name ] ) ) {
				$kept[] = $class_name;
			}
		}
		return implode( ' ', array_values( array_unique( $kept ) ) );
	}

	/**
	 * Escapes an HTML attribute value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function pattern_attr( $value ) {
		return function_exists( 'esc_attr' ) ? esc_attr( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Counts nested pattern blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return int
	 */
	private function count_pattern_blocks_recursive( array $blocks ) {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			++$count;
			$count += $this->count_pattern_blocks_recursive( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		}
		return $count;
	}
}
