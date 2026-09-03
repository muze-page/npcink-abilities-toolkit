<?php
/**
 * Media read methods for Core_Read_Package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

use Npcink_Abilities_Toolkit\Support\Cloud_Derivative_Artifact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides media inventory, metadata, and inline image read callbacks.
 */
trait Media_Read_Methods {
	/**
	 * Lists media attachments.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function list_media( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mime_type = sanitize_text_field( (string) ( $input['mime_type'] ?? '' ) );
		$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
		$per_page = max( 1, min( 50, absint( $input['per_page'] ?? 10 ) ) );
		$page = max( 1, absint( $input['page'] ?? 1 ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( '' !== $mime_type ) {
			$args['post_mime_type'] = $mime_type;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$date_from = sanitize_text_field( (string) ( $input['date_from'] ?? '' ) );
		$date_to = sanitize_text_field( (string) ( $input['date_to'] ?? '' ) );
		if ( '' !== $date_from || '' !== $date_to ) {
			$args['date_query'] = array();
			if ( '' !== $date_from ) {
				$args['date_query']['after'] = $date_from;
			}
			if ( '' !== $date_to ) {
				$args['date_query']['before'] = $date_to;
			}
			}

			if ( ! empty( $input['has_empty_alt'] ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Optional bounded media audit filter for missing attachment alt text.
				$args['meta_query'] = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array();
				$args['meta_query'][] = array(
					'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
			}

			if ( ! empty( $input['has_empty_caption'] ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Optional bounded media audit filter for missing attachment metadata.
				$args['meta_query'] = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array();
				$args['meta_query'][] = array(
					'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_metadata',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_metadata',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$attachment = get_post( $post_id );
			$parent_id = is_object( $attachment ) ? absint( $attachment->post_parent ?? 0 ) : 0;
			$items[] = array(
				'id'        => $post_id,
				'title'     => sanitize_text_field( (string) get_the_title( $post_id ) ),
				'date'      => sanitize_text_field( (string) get_post_field( 'post_date', $post_id ) ),
				'mime_type' => sanitize_text_field( (string) get_post_mime_type( $post_id ) ),
				'url'       => esc_url_raw( (string) wp_get_attachment_url( $post_id ) ),
				'attached_to' => $parent_id > 0 ? array(
					'post_id'    => $parent_id,
					'post_type'  => sanitize_key( (string) get_post_type( $parent_id ) ),
					'post_title' => sanitize_text_field( (string) get_the_title( $parent_id ) ),
				) : null,
				'usage'     => $this->build_media_usage_context( $post_id ),
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
			);
		}

		return array(
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		);
	}

	/**
	 * Resolves a same-site uploads URL to bounded attachment candidates.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve_media_attachment_by_url( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to resolve media attachments.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$raw_url = trim( (string) ( $input['url'] ?? '' ) );
		$max_candidates = max( 1, min( 20, $this->absint_value( $input['max_candidates'] ?? 10 ) ) );
		$normalized = $this->normalize_media_attachment_resolution_url( $raw_url );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$relative_file = (string) ( $normalized['requested_relative_file'] ?? '' );
		$candidates = $this->find_media_attachment_url_resolution_candidates( $relative_file, $max_candidates );
		$strong_matches = array_values(
			array_filter(
				$candidates,
				static function ( $candidate ) {
					return is_array( $candidate ) && (int) ( $candidate['match_score'] ?? 0 ) >= 90;
				}
			)
		);

		$attachment_id = 0;
		$match_status = 'not_found';
		$resolution_quality = 'none';
		$warnings = array();

		if ( 1 === count( $strong_matches ) ) {
			$attachment_id = $this->absint_value( $strong_matches[0]['attachment_id'] ?? 0 );
			$match_status = 'resolved';
			$resolution_quality = 'metadata_size_file' === (string) ( $strong_matches[0]['match_type'] ?? '' ) ? 'size_variant' : 'exact';
		} elseif ( count( $strong_matches ) > 1 ) {
			$match_status = 'ambiguous';
			$resolution_quality = 'candidate';
			$warnings[] = 'multiple_strong_matches';
		} elseif ( ! empty( $candidates ) ) {
			$match_status = 'candidate';
			$resolution_quality = 'candidate';
			$warnings[] = 'filename_candidate_requires_inspection';
		} else {
			$warnings[] = 'no_attachment_candidate_found';
		}

		$data = array(
			'resolver_contract_version' => 'media_attachment_url_resolution.v1',
			'readonly'                  => true,
			'input_url'                 => $raw_url,
			'normalized_url'            => (string) ( $normalized['normalized_url'] ?? '' ),
			'uploads_base_url'          => (string) ( $normalized['uploads_base_url'] ?? '' ),
			'requested_relative_file'   => $relative_file,
			'match_status'              => $match_status,
			'resolution_quality'        => $resolution_quality,
			'candidates'                => $candidates,
			'warnings'                  => $warnings,
			'boundary'                  => array(
				'owner'                       => 'local_wordpress_host',
				'readonly'                    => true,
				'wordpress_write_included'    => false,
				'proposal_created'            => false,
				'approval_decision_included'  => false,
				'canonical_media_truth_included' => false,
				'suggested_next_step'         => __( 'Use the resolved attachment_id only after reviewing match evidence; if ambiguous, inspect candidates before creating any Core proposal.', 'npcink-abilities-toolkit' ),
			),
		);
		if ( $attachment_id > 0 ) {
			$data['attachment_id'] = $attachment_id;
		}

		return $this->build_analysis_success_response(
			$data,
			array(
				'source'         => 'local_media_attachment_url_resolution',
				'execution_mode' => 'deterministic',
				'readonly'       => true,
				'plan_only'      => true,
			),
			'Media attachment URL resolution completed.'
		);
	}

	/**
	 * Builds bounded usage context for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	private function build_media_usage_context( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$featured_posts = array();
		$featured_count = 0;
		if ( class_exists( '\WP_Query' ) ) {
			$featured_query = new \WP_Query(
				array(
					'post_type'      => 'any',
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'posts_per_page' => 5,
					'fields'         => 'ids',
					// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Attachment usage context needs a bounded featured-image lookup by _thumbnail_id.
					'meta_key'       => '_thumbnail_id',
					'meta_value'     => $attachment_id,
					// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$featured_count = (int) $featured_query->found_posts;
			foreach ( (array) $featured_query->posts as $post_id ) {
				$post_id = absint( $post_id );
				if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
					$featured_posts[] = array(
						'post_id'    => $post_id,
						'post_type'  => sanitize_key( (string) get_post_type( $post_id ) ),
						'post_status' => sanitize_key( (string) get_post_status( $post_id ) ),
						'post_title' => sanitize_text_field( (string) get_the_title( $post_id ) ),
					);
				}
			}
		} else {
			$featured_posts = $this->find_media_featured_image_references( $attachment_id, 5 );
			$featured_count = count( $featured_posts );
		}

		$content_posts = $this->find_media_content_references( $attachment_id, 10 );

		return array(
			'featured_image_count' => $featured_count,
			'featured_image_posts' => $featured_posts,
			'content_reference_count' => count( $content_posts ),
			'content_reference_posts' => $content_posts,
			'content_reference_scan_run' => true,
		);
	}

	/**
	 * Builds append-ready Gutenberg image blocks from uploaded media rows.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function build_inline_image_blocks( $input ) {
		$input = is_array( $input ) ? $input : array();
		$upload_rows = is_array( $input['uploaded_inline_media'] ?? null ) ? $input['uploaded_inline_media'] : array();
		$generated_upload_rows = is_array( $input['generated_inline_media'] ?? null ) ? $input['generated_inline_media'] : array();
		$plan_rows = is_array( $input['inline_plan'] ?? null ) ? $input['inline_plan'] : array();
		$blocks = array();

		$max_rows = max( count( $upload_rows ), count( $generated_upload_rows ), count( $plan_rows ) );
		for ( $index = 0; $index < $max_rows; ++$index ) {
			$upload_row = is_array( $upload_rows[ $index ] ?? null ) ? $upload_rows[ $index ] : array();
			if ( $this->absint_value( $upload_row['attachment_id'] ?? 0 ) <= 0 && is_array( $generated_upload_rows[ $index ] ?? null ) ) {
				$upload_row = $generated_upload_rows[ $index ];
			}
			$plan_row = is_array( $plan_rows[ $index ] ?? null ) ? $plan_rows[ $index ] : array();
			$attachment_id = $this->absint_value( $upload_row['attachment_id'] ?? 0 );
			$image_url = $this->esc_url_value( (string) ( $upload_row['url'] ?? '' ) );
			if ( $attachment_id <= 0 || '' === $image_url ) {
				continue;
			}

			$alt = sanitize_text_field( (string) ( $plan_row['alt'] ?? $upload_row['alt'] ?? '' ) );
			$caption = $this->sanitize_metadata_text( (string) ( $plan_row['caption'] ?? $upload_row['caption'] ?? '' ) );
			$placement_key = $this->sanitize_html_class_value( (string) ( $plan_row['placement_key'] ?? 'inline_' . max( 1, (int) $index + 1 ) ) );
			$img_tag = '<img src="' . $this->esc_attr_value( $image_url ) . '" alt="' . $this->esc_attr_value( $alt ) . '" class="wp-image-' . $attachment_id . '" />';
			$figcaption = '' !== $caption ? '<figcaption>' . $this->esc_html_value( $caption ) . '</figcaption>' : '';

			$blocks[] = array(
				'blockName'   => 'core/image',
				'attrs'       => array(
					'id'              => $attachment_id,
					'sizeSlug'        => 'large',
					'linkDestination' => 'none',
					'alt'             => $alt,
					'className'       => 'npcink-abilities-toolkit-inline-image ' . $placement_key,
				),
				'innerHTML'   => '<figure class="wp-block-image size-large npcink-abilities-toolkit-inline-image ' . $this->esc_attr_value( $placement_key ) . '">' . $img_tag . $figcaption . '</figure>',
				'innerBlocks' => array(),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'blocks'  => $blocks,
				'summary' => array(
					'count' => count( $blocks ),
					'mode'  => 'append',
				),
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Builds canonical media SEO enrichment asset rows from article media workflow outputs.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function build_media_seo_assets( $input ) {
		$input = is_array( $input ) ? $input : array();
		$article = is_array( $input['article'] ?? null ) ? $input['article'] : array();
		$resolved = is_array( $input['resolved_image_source'] ?? null ) ? $input['resolved_image_source'] : array();
		$featured = is_array( $resolved['featured'] ?? null ) ? $resolved['featured'] : array();
		$inline_plan = is_array( $resolved['inline'] ?? null ) ? $resolved['inline'] : array();
		$featured_upload = is_array( $input['featured_upload'] ?? null ) ? $input['featured_upload'] : array();
		$generated_featured_upload = is_array( $input['generated_featured_upload'] ?? null ) ? $input['generated_featured_upload'] : array();
		$inline_uploads = is_array( $input['inline_uploads'] ?? null ) ? $input['inline_uploads'] : array();
		$generated_inline_uploads = is_array( $input['generated_inline_uploads'] ?? null ) ? $input['generated_inline_uploads'] : array();
		$vision_fallback_mode = sanitize_key( (string) ( $input['vision_fallback_mode'] ?? 'auto' ) );
		if ( ! in_array( $vision_fallback_mode, array( 'off', 'auto' ), true ) ) {
			$vision_fallback_mode = 'auto';
		}

		$items = array();
		$featured_upload_row = $this->absint_value( $featured_upload['attachment_id'] ?? 0 ) > 0 ? $featured_upload : $generated_featured_upload;
		if ( $this->absint_value( $featured_upload_row['attachment_id'] ?? 0 ) > 0 ) {
			$items[] = $this->build_media_seo_asset_row( $featured, $featured_upload_row, 'featured', 'auto' === $vision_fallback_mode );
		}

		$max_inline_uploads = max( count( $inline_uploads ), count( $generated_inline_uploads ) );
		for ( $index = 0; $index < $max_inline_uploads; ++$index ) {
			$upload_row = is_array( $inline_uploads[ $index ] ?? null ) ? $inline_uploads[ $index ] : array();
			if ( $this->absint_value( $upload_row['attachment_id'] ?? 0 ) <= 0 && is_array( $generated_inline_uploads[ $index ] ?? null ) ) {
				$upload_row = $generated_inline_uploads[ $index ];
			}
			if ( $this->absint_value( $upload_row['attachment_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$descriptor = is_array( $inline_plan[ $index ] ?? null ) ? $inline_plan[ $index ] : array();
			$items[] = $this->build_media_seo_asset_row( $descriptor, $upload_row, 'inline', 'auto' === $vision_fallback_mode );
		}

		return array(
			'success' => true,
			'data'    => array(
				'items'   => $items,
				'summary' => array(
					'asset_count'            => count( $items ),
					'featured_attachment_id' => $this->absint_value( $featured_upload_row['attachment_id'] ?? 0 ),
					'inline_attachment_count' => max( 0, count( $items ) - ( $this->absint_value( $featured_upload_row['attachment_id'] ?? 0 ) > 0 ? 1 : 0 ) ),
					'article_title'          => sanitize_text_field( (string) ( $article['title'] ?? '' ) ),
					'vision_fallback_mode'   => $vision_fallback_mode,
				),
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'deterministic',
			),
		);
	}

	/**
	 * Builds media metadata optimization suggestions.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function optimize_media_metadata( $input ) {
		$input = is_array( $input ) ? $input : array();
		$article_title = sanitize_text_field( (string) ( $input['article_title'] ?? '' ) );
		$article_excerpt = sanitize_textarea_field( (string) ( $input['article_excerpt'] ?? '' ) );
		$article_content = (string) ( $input['article_content'] ?? '' );
		$focus_keyword = sanitize_text_field( (string) ( $input['focus_keyword'] ?? '' ) );
		$vision_fallback_mode = sanitize_key( (string) ( $input['vision_fallback_mode'] ?? 'auto' ) );
		if ( ! in_array( $vision_fallback_mode, array( 'off', 'auto' ), true ) ) {
			$vision_fallback_mode = 'auto';
		}
		$assets = $this->collect_media_assets_for_optimization(
			is_array( $input['media_assets'] ?? null ) ? $input['media_assets'] : array(),
			$article_content
		);

		$recommendations = array();
		$missing_alt_count = 0;
		$format_attention_count = 0;
		$vision_fallback_count = 0;
		foreach ( $assets as $index => $asset ) {
			$asset = is_array( $asset ) ? $asset : array();
			$alt = sanitize_text_field( (string) ( $asset['alt'] ?? '' ) );
			$title = sanitize_text_field( (string) ( $asset['title'] ?? '' ) );
			$caption = sanitize_textarea_field( (string) ( $asset['caption'] ?? '' ) );
			$description = sanitize_textarea_field( (string) ( $asset['description'] ?? '' ) );
			$mime_type = sanitize_text_field( (string) ( $asset['mime_type'] ?? '' ) );
			$section_heading = sanitize_text_field( (string) ( $asset['section_heading'] ?? '' ) );
			$section_summary = sanitize_textarea_field( (string) ( $asset['section_summary'] ?? '' ) );
			$generated_prompt = sanitize_textarea_field( (string) ( $asset['generated_prompt'] ?? '' ) );
			$image_profile = sanitize_key( (string) ( $asset['image_profile'] ?? '' ) );
			$provider_title = sanitize_text_field( (string) ( $asset['provider_title'] ?? '' ) );
			$provider_description = sanitize_textarea_field( (string) ( $asset['provider_description'] ?? $asset['alt_description'] ?? '' ) );
			$provider_hint = sanitize_key( (string) ( $asset['provider_hint'] ?? '' ) );
			$source_page_url = $this->esc_url_value( (string) ( $asset['source_page_url'] ?? '' ) );
			$photographer_name = sanitize_text_field( (string) ( $asset['photographer_name'] ?? '' ) );
			$attribution_text = sanitize_textarea_field( (string) ( $asset['attribution_text'] ?? '' ) );
			$license = sanitize_text_field( (string) ( $asset['license'] ?? '' ) );
			$copyright_notice = sanitize_textarea_field( (string) ( $asset['copyright_notice'] ?? '' ) );
			$file_name = $this->sanitize_file_name_value( (string) ( $asset['file_name'] ?? '' ) );
			$needs_format_attention = '' !== $mime_type && false === strpos( $mime_type, 'webp' ) && false === strpos( $mime_type, 'avif' );

			if ( '' === $alt ) {
				++$missing_alt_count;
			}
			if ( $needs_format_attention ) {
				++$format_attention_count;
			}

			$image_origin = $this->infer_media_image_origin( $asset );
			$source_type = $this->normalize_media_source_type(
				$asset['source_type'] ?? '',
				$this->infer_media_source_type( $asset, $image_origin )
			);
			$source_metadata = $this->media_source_metadata_with_defaults(
				array(
					'source_type'       => $source_type,
					'source_page_url'   => $source_page_url,
					'photographer_name' => $photographer_name,
					'attribution_text'  => $attribution_text,
					'license'           => $license,
					'copyright_notice'  => $copyright_notice,
				)
			);
			$source_type = $source_metadata['source_type'];
			$source_page_url = $source_metadata['source_page_url'];
			$photographer_name = $source_metadata['photographer_name'];
			$attribution_text = $source_metadata['attribution_text'];
			$copyright_notice = $source_metadata['copyright_notice'];
			$strategy = 'manual_context';
			if ( 'ai_generated' === $image_origin ) {
				$strategy = 'ai_context';
			} elseif ( 'public_free' === $image_origin ) {
				$strategy = 'provider_metadata';
			}

			$title_seed = '';
			$caption_seed = '';
			$description_seed = '';
			$alt_context_parts = array();
			$metadata_confidence = 'low';
			if ( 'ai_generated' === $image_origin ) {
				$title_seed = '' !== $section_heading ? $section_heading : ( '' !== $article_title ? $article_title : $provider_title );
				$caption_seed = '' !== $section_summary ? $section_summary : $article_excerpt;
				$description_seed = '' !== $generated_prompt ? $generated_prompt : $caption_seed;
				$alt_context_parts = array_filter( array( $title_seed, $caption_seed, '' !== $image_profile ? '图像档位：' . $image_profile : '', '' !== $generated_prompt ? '生成提示：' . $generated_prompt : '' ) );
				$metadata_confidence = '' !== $generated_prompt ? 'high' : 'medium';
			} elseif ( 'public_free' === $image_origin ) {
				$title_seed = '' !== $provider_title ? $provider_title : ( '' !== $section_heading ? $section_heading : $article_title );
				$caption_seed = '' !== $section_summary ? $section_summary : $article_excerpt;
				$description_seed = '' !== $provider_description ? $provider_description : $caption_seed;
				$alt_context_parts = array_filter( array( $title_seed, $description_seed, '' !== $photographer_name ? '摄影：' . $photographer_name : '', '' !== $attribution_text ? '归因：' . $attribution_text : '' ) );
				$metadata_confidence = ( '' !== $provider_title || '' !== $provider_description || '' !== $photographer_name ) ? 'high' : 'medium';
			} else {
				$title_seed = '' !== $title ? $title : ( '' !== $section_heading ? $section_heading : $article_title );
				$caption_seed = '' !== $section_summary ? $section_summary : $article_excerpt;
				$description_seed = '' !== $description ? $description : $caption_seed;
				$alt_context_parts = array_filter( array( $title_seed, $description_seed, '' !== $file_name ? '文件：' . $file_name : '' ) );
				$manual_signal_count = 0;
				if ( '' !== $title || '' !== $caption || '' !== $description ) {
					++$manual_signal_count;
				}
				if ( '' !== $section_heading || '' !== $section_summary ) {
					++$manual_signal_count;
				}
				if ( '' !== $file_name ) {
					++$manual_signal_count;
				}
				$metadata_confidence = $manual_signal_count >= 2 ? 'medium' : 'low';
			}

			$vision_fallback_recommended = 'off' !== $vision_fallback_mode && ! empty( $asset['vision_fallback_allowed'] ) && 'low' === $metadata_confidence;
			if ( $vision_fallback_recommended ) {
				++$vision_fallback_count;
			}
			$license = $this->resolve_media_license_label( $asset, $image_origin );
			$disclosure_readiness = $this->resolve_media_disclosure_readiness( $image_origin, $metadata_confidence, $license, $source_page_url, $attribution_text, $generated_prompt );
			$recommended_alt = '' !== $alt
				? $alt
				: $this->trim_media_seo_text( trim( implode( ' - ', array_filter( array( $title_seed, '' !== $focus_keyword ? $focus_keyword : '', '' !== $section_heading && $section_heading !== $title_seed ? $section_heading : '' ) ) ) ), 140 );
			$recommended_title = '' !== $title ? $title : $this->trim_media_seo_text( $title_seed, 80 );
			$recommended_caption = '' !== $caption ? $caption : $this->trim_media_seo_text( $caption_seed, 160 );
			$recommended_description = '' !== $description ? $description : $this->trim_media_seo_text( $description_seed, 220 );

			if ( 'public_free' === $image_origin ) {
				$attribution_appendix = implode( ' ', array_filter( array( '' !== $attribution_text ? '归因：' . $attribution_text : '', '' !== $source_page_url ? '来源页：' . $source_page_url : '', '' !== $copyright_notice ? $copyright_notice : '' ) ) );
				if ( '' !== $attribution_appendix && false === strpos( $recommended_description, $attribution_appendix ) ) {
					$recommended_description = $this->trim_media_seo_text( trim( $recommended_description . ' ' . $attribution_appendix ), 280 );
				}
			}

			$recommendations[] = array(
				'attachment_id'                => $this->absint_value( $asset['attachment_id'] ?? 0 ),
				'role'                         => sanitize_key( (string) ( $asset['role'] ?? ( 0 === $index ? 'featured' : 'inline' ) ) ),
				'url'                          => $this->esc_url_value( (string) ( $asset['url'] ?? '' ) ),
				'image_origin'                 => $image_origin,
				'strategy'                     => $strategy,
				'metadata_confidence'          => $metadata_confidence,
				'vision_fallback_recommended'  => $vision_fallback_recommended,
				'disclosure_readiness'         => $disclosure_readiness,
				'alt_context'                  => $this->trim_media_seo_text( implode( '；', $alt_context_parts ), 280 ),
				'provider'                     => array(
					'hint'             => $provider_hint,
					'provider_title'   => $provider_title,
					'source_type'      => $source_type,
					'source_page_url'  => $source_page_url,
					'photographer_name' => $photographer_name,
					'attribution_text' => $attribution_text,
					'license'          => $license,
					'copyright_notice' => $copyright_notice,
				),
				'vision_fallback_target'       => array(),
				'issues'                       => array_values( array_filter( array( '' === $alt ? '缺少 alt 文本。' : '', $needs_format_attention ? '仍可评估是否转为 WebP/AVIF。' : '', in_array( $disclosure_readiness, array( 'needs_review', 'disclosure_recommended' ), true ) ? '建议补充来源、归因或披露说明。' : '', $vision_fallback_recommended ? '现有上下文较弱，后续可考虑追加视觉识别补强。' : '' ) ) ),
				'suggestions'                  => array(
					'title'       => $recommended_title,
					'alt'         => $recommended_alt,
					'caption'     => $recommended_caption,
					'description' => $recommended_description,
					'source_type' => $source_type,
					'source_page_url' => $source_page_url,
					'photographer_name' => $photographer_name,
					'attribution_text' => $attribution_text,
					'copyright_notice' => $copyright_notice,
				),
				'attribution_persisted'        => in_array( $source_type, array( 'stock', 'external' ), true ) && '' !== $attribution_text,
				'format_plan'                  => array(
					'should_convert'     => $needs_format_attention,
					'recommended_format' => $needs_format_attention ? 'webp' : sanitize_key( (string) preg_replace( '#^.*/#', '', $mime_type ) ),
					'reason'             => $needs_format_attention ? '当前资源仍是传统格式，可评估更轻量编码。' : '当前格式可继续沿用。',
				),
			);
		}

		return $this->build_analysis_success_response(
			array(
				'assets'  => $recommendations,
				'summary' => array(
					'asset_count'                  => count( $recommendations ),
					'missing_alt_count'            => $missing_alt_count,
					'format_attention_count'       => $format_attention_count,
					'vision_fallback_count'        => $vision_fallback_count,
					'vision_fallback_mode'         => $vision_fallback_mode,
					'vision_fallback_target_available' => false,
					'vision_fallback_target'       => array(),
					'disclosure_ready_count'       => count( array_filter( $recommendations, static function ( array $asset ) { return in_array( sanitize_key( (string) ( $asset['disclosure_readiness'] ?? '' ) ), array( 'ready_with_attribution', 'ready_with_source_note' ), true ); } ) ),
					'needs_review_count'           => count( array_filter( $recommendations, static function ( array $asset ) { return 'needs_review' === sanitize_key( (string) ( $asset['disclosure_readiness'] ?? '' ) ); } ) ),
					'origin_breakdown'             => array(
						'ai_generated'  => count( array_filter( $recommendations, static function ( array $asset ) { return 'ai_generated' === sanitize_key( (string) ( $asset['image_origin'] ?? '' ) ); } ) ),
						'public_free'   => count( array_filter( $recommendations, static function ( array $asset ) { return 'public_free' === sanitize_key( (string) ( $asset['image_origin'] ?? '' ) ); } ) ),
						'manual_upload' => count( array_filter( $recommendations, static function ( array $asset ) { return 'manual_upload' === sanitize_key( (string) ( $asset['image_origin'] ?? '' ) ); } ) ),
					),
					'attribution_persisted_count'  => count( array_filter( $recommendations, static function ( array $asset ) { return ! empty( $asset['attribution_persisted'] ); } ) ),
				),
			),
			array(
				'source'         => 'local_media_metadata_optimizer',
				'execution_mode' => 'deterministic',
			),
			'Media metadata optimization completed.'
		);
	}

	/**
	 * Positions inline image blocks after matching heading anchors or paragraph indexes.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function position_inline_image_blocks( $input ) {
		$input = is_array( $input ) ? $input : array();
		$existing_blocks = is_array( $input['existing_blocks'] ?? null ) ? array_values( $input['existing_blocks'] ) : array();
		$inline_blocks = is_array( $input['inline_blocks'] ?? null ) ? array_values( $input['inline_blocks'] ) : array();
		$inline_plan = is_array( $input['inline_plan'] ?? null ) ? array_values( $input['inline_plan'] ) : array();
		if ( empty( $inline_blocks ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'blocks'  => $existing_blocks,
					'summary' => array(
						'count'            => 0,
						'positioned_count' => 0,
						'appended_count'   => 0,
						'strategy'         => 'after_paragraph_or_heading',
					),
				),
				'meta'    => array(
					'contract_version' => 'v1',
					'execution_mode'   => 'projection_only',
				),
			);
		}

		$placement_keys = array();
		foreach ( $inline_plan as $plan_row ) {
			$plan_row = is_array( $plan_row ) ? $plan_row : array();
			$placement_key = $this->sanitize_html_class_value( (string) ( $plan_row['placement_key'] ?? '' ) );
			if ( '' !== $placement_key ) {
				$placement_keys[] = $placement_key;
			}
		}
		$existing_blocks = $this->filter_existing_inline_image_blocks( $existing_blocks, $placement_keys );

		$insertions_by_index = array();
		$append_blocks = array();
		$positioned_count = 0;
		$appended_count = 0;
		$matched_targets = array();
		foreach ( $inline_blocks as $index => $inline_block ) {
			$inline_block = is_array( $inline_block ) ? $inline_block : array();
			if ( empty( $inline_block ) ) {
				continue;
			}
			$plan_row = is_array( $inline_plan[ $index ] ?? null ) ? $inline_plan[ $index ] : array();
			$matched = false;
			$current_paragraph_index = 0;
			foreach ( $existing_blocks as $block_index => $existing_block ) {
				$existing_block = is_array( $existing_block ) ? $existing_block : array();
				if ( empty( $existing_block ) ) {
					continue;
				}
				$block_name = sanitize_text_field( (string) ( $existing_block['blockName'] ?? '' ) );
				$is_paragraph = in_array( $block_name, array( 'core/paragraph', 'core/freeform' ), true );
				if (
					$this->block_matches_inline_paragraph_target( $existing_block, $plan_row, $current_paragraph_index )
					|| $this->block_matches_inline_target( $existing_block, $plan_row )
				) {
					if ( ! isset( $insertions_by_index[ $block_index ] ) || ! is_array( $insertions_by_index[ $block_index ] ) ) {
						$insertions_by_index[ $block_index ] = array();
					}
					$insertions_by_index[ $block_index ][] = $inline_block;
					$matched = true;
					++$positioned_count;
					$matched_targets[] = array(
						'placement_key'    => $this->sanitize_html_class_value( (string) ( $plan_row['placement_key'] ?? '' ) ),
						'placement'        => sanitize_key( (string) ( $plan_row['placement'] ?? '' ) ),
						'paragraph_index'  => array_key_exists( 'paragraph_index', $plan_row ) ? $this->absint_value( $plan_row['paragraph_index'] ) : null,
						'section_anchor'   => $this->sanitize_metadata_slug( (string) ( $plan_row['section_anchor'] ?? '' ) ),
						'section_heading'  => sanitize_text_field( (string) ( $plan_row['section_heading'] ?? '' ) ),
					);
					break;
				}
				if ( $is_paragraph ) {
					++$current_paragraph_index;
				}
			}

			if ( ! $matched ) {
				$append_blocks[] = $inline_block;
				++$appended_count;
			}
		}

		$composed_blocks = array();
		foreach ( $existing_blocks as $block_index => $existing_block ) {
			$composed_blocks[] = $existing_block;
			if ( ! empty( $insertions_by_index[ $block_index ] ) && is_array( $insertions_by_index[ $block_index ] ) ) {
				foreach ( $insertions_by_index[ $block_index ] as $inserted_block ) {
					$composed_blocks[] = $inserted_block;
				}
			}
		}
		foreach ( $append_blocks as $append_block ) {
			$composed_blocks[] = $append_block;
		}

		return array(
			'success' => true,
			'data'    => array(
				'blocks'  => array_values( $composed_blocks ),
				'summary' => array(
					'count'            => count( $inline_blocks ),
					'positioned_count' => $positioned_count,
					'appended_count'   => $appended_count,
					'strategy'         => 'after_paragraph_or_heading',
					'matched_targets'  => array_values( $matched_targets ),
				),
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Builds media cleanup opportunities.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
		public function get_media_cleanup_opportunities( $input ) {
			$input = is_array( $input ) ? $input : array();
			if ( ! current_user_can( 'upload_files' ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to read media cleanup opportunities.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$mime_type = sanitize_text_field( (string) ( $input['mime_type'] ?? '' ) );
		$per_page = max( 1, min( 100, $this->absint_value( $input['per_page'] ?? 50 ) ) );
		$page = max( 1, $this->absint_value( $input['page'] ?? 1 ) );
		$query_result = $this->query_media_inventory( $mime_type, '', $per_page, $page );
		$items = array();
		$issue_counts = array();
		foreach ( (array) ( $query_result['attachment_ids'] ?? array() ) as $attachment_id ) {
			$attachment_id = $this->absint_value( $attachment_id );
			if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}
			$row = $this->build_media_inventory_health_row( $attachment_id );
			$issues = is_array( $row['issues'] ?? null ) ? $row['issues'] : array();
			$attached_to = get_post( $attachment_id );
			if ( is_object( $attached_to ) && 0 === $this->absint_value( $attached_to->post_parent ?? 0 ) ) {
				$issues[] = 'possibly_unattached';
			}
			foreach ( $issues as $issue ) {
				$issue = sanitize_key( (string) $issue );
				if ( '' !== $issue ) {
					$issue_counts[ $issue ] = (int) ( $issue_counts[ $issue ] ?? 0 ) + 1;
				}
			}
			if ( empty( $issues ) ) {
				continue;
			}
			$row['issues'] = array_values( array_unique( $issues ) );
			$row['issue_count'] = count( $row['issues'] );
			$row['priority'] = in_array( 'missing_alt', $row['issues'], true ) || in_array( 'possibly_unattached', $row['issues'], true ) ? 'medium' : 'low';
			$items[] = $row;
		}
		arsort( $issue_counts );

		return $this->build_analysis_success_response(
			array(
				'total'        => (int) ( $query_result['total'] ?? 0 ),
				'page'         => $page,
				'per_page'     => $per_page,
				'items'        => $items,
				'issue_counts' => $issue_counts,
				'summary'      => array(
					'opportunity_count' => count( $items ),
					'mime_type'         => $mime_type,
				),
			),
			array(
				'source'         => 'local_media_cleanup_opportunities',
				'execution_mode' => 'deterministic',
			),
				'Media cleanup opportunities built.'
			);
		}

		/**
		 * Lists recorded media operation backups for one attachment.
		 *
		 * @param mixed $input Input args.
		 * @return array<string,mixed>|\WP_Error
		 */
		public function list_media_backups( $input ) {
			$input = is_array( $input ) ? $input : array();
			if ( ! current_user_can( 'upload_files' ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to read media backups.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}

			$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
			if ( $attachment_id <= 0 ) {
				return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}
			$attachment = function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;
			if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_attachment_not_found', __( 'Attachment was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to read backups for this media item.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}

			$include_rolled_back = array_key_exists( 'include_rolled_back', $input ) ? ! empty( $input['include_rolled_back'] ) : true;
			$history = function_exists( 'get_post_meta' ) ? get_post_meta( $attachment_id, '_npcink_ai_media_file_replacement_history', true ) : array();
			if ( is_array( $history ) && isset( $history['replacement_id'] ) ) {
				$history = array( $history );
			}
			$history = is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : array();
			$backups = array();
			foreach ( $history as $record ) {
				$status = sanitize_key( (string) ( $record['status'] ?? '' ) );
				if ( ! $include_rolled_back && 'rolled_back' === $status ) {
					continue;
				}
				$backup = is_array( $record['backup'] ?? null ) ? $record['backup'] : array();
				$backup_relative = $this->normalize_media_reference_relative( (string) ( $backup['relative_file'] ?? '' ) );
				if ( '' === $backup_relative ) {
					continue;
				}
				$backup_path = $this->resolve_media_file_path( '', $backup_relative );
				$backup_row = array(
					'backup_id'            => sanitize_text_field( (string) ( $record['replacement_id'] ?? '' ) ),
					'replacement_id'       => sanitize_text_field( (string) ( $record['replacement_id'] ?? '' ) ),
					'operation'            => sanitize_key( (string) ( $record['operation'] ?? 'replace_media_file' ) ),
					'status'               => '' !== $status ? $status : 'active',
					'backup_relative_file' => $backup_relative,
					'backup_url'           => $this->media_reference_upload_url( $backup_relative ),
					'mime_type'            => sanitize_text_field( (string) ( $backup['mime_type'] ?? '' ) ),
					'width'                => $this->absint_value( $backup['width'] ?? 0 ),
					'height'               => $this->absint_value( $backup['height'] ?? 0 ),
					'filesize_bytes'       => $this->absint_value( $backup['filesize_bytes'] ?? ( '' !== $backup_path && is_readable( $backup_path ) ? filesize( $backup_path ) : 0 ) ),
					'file_exists'          => '' !== $backup_path && is_readable( $backup_path ),
					'content_hashes'       => is_array( $backup['content_hashes'] ?? null ) ? $backup['content_hashes'] : $this->resolve_media_content_hashes( $backup_path ),
					'created_at_gmt'       => sanitize_text_field( (string) ( $record['replaced_at_gmt'] ?? '' ) ),
					'rolled_back_at_gmt'   => sanitize_text_field( (string) ( $record['rolled_back_at_gmt'] ?? '' ) ),
					'before'               => is_array( $record['before'] ?? null ) ? $record['before'] : array(),
					'after'                => is_array( $record['after'] ?? null ) ? $record['after'] : array(),
					'restore_action'       => array(
						'target_ability_id' => 'npcink-abilities-toolkit/restore-media-backup',
						'input'             => array(
							'attachment_id' => $attachment_id,
							'backup_id'     => sanitize_text_field( (string) ( $record['replacement_id'] ?? '' ) ),
						),
						'requires_approval' => true,
					),
				);
				$backups[] = $backup_row;
			}

			$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
			$metadata = is_array( $metadata ) ? $metadata : array();
			$current_relative = $this->normalize_media_reference_relative( function_exists( 'get_post_meta' ) ? (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) : (string) ( $metadata['file'] ?? '' ) );

			return $this->build_analysis_success_response(
				array(
					'attachment_id' => $attachment_id,
					'current_file'  => array(
						'relative_file' => $current_relative,
						'url'           => $this->media_reference_upload_url( $current_relative ),
						'mime_type'     => function_exists( 'get_post_mime_type' ) ? sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ) : '',
					),
					'backups'       => $backups,
					'summary'       => array(
						'backup_count'        => count( $backups ),
						'include_rolled_back' => $include_rolled_back,
					),
				),
				array(
					'source'         => 'local_media_backup_history',
					'execution_mode' => 'deterministic',
					'readonly'       => true,
				),
				'Media backups listed.'
			);
		}

		/**
		 * Builds a media inventory health report.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_media_inventory_health( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to read media inventory.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$mime_type = sanitize_text_field( (string) ( $input['mime_type'] ?? '' ) );
		$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
		$attachment_ids = array_slice(
			array_values(
				array_unique(
					array_filter(
						array_map( array( $this, 'absint_value' ), is_array( $input['attachment_ids'] ?? null ) ? $input['attachment_ids'] : array() )
					)
				)
			),
			0,
			20
		);
		$per_page = max( 1, min( 100, $this->absint_value( $input['per_page'] ?? 50 ) ) );
		$page = max( 1, $this->absint_value( $input['page'] ?? 1 ) );
		$stable_order = sanitize_key( (string) ( $input['stable_order'] ?? 'date_desc' ) );
		if ( ! in_array( $stable_order, array( 'date_desc', 'id_asc' ), true ) ) {
			$stable_order = 'date_desc';
		}
		$query_result = $this->query_media_inventory( $mime_type, $search, $per_page, $page, $attachment_ids, $stable_order );
		$attachment_ids = is_array( $query_result['attachment_ids'] ?? null ) ? $query_result['attachment_ids'] : array();
		$items = array();
		$issue_counts = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = $this->absint_value( $attachment_id );
			if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}
			$row = $this->build_media_inventory_health_row( $attachment_id );
			foreach ( (array) ( $row['issues'] ?? array() ) as $issue ) {
				$issue = sanitize_key( (string) $issue );
				if ( '' !== $issue ) {
					$issue_counts[ $issue ] = (int) ( $issue_counts[ $issue ] ?? 0 ) + 1;
				}
			}
			$items[] = $row;
		}

		$total_issue_instances = array_sum( array_map( 'intval', $issue_counts ) );
		$health_score = count( $items ) > 0
			? max( 0, 100 - min( 100, (int) round( ( $total_issue_instances / max( 1, count( $items ) ) ) * 14 ) ) )
			: 100;
		arsort( $issue_counts );

		return $this->build_analysis_success_response(
			array(
				'total'        => (int) ( $query_result['total'] ?? count( $attachment_ids ) ),
				'page'         => $page,
				'per_page'     => $per_page,
				'health_score' => $health_score,
				'issue_counts' => $issue_counts,
				'items'        => $items,
				'summary'      => array(
					'scanned_count'          => count( $items ),
					'assets_with_issues'     => count(
						array_filter(
							$items,
							static function ( array $item ) {
								return (int) ( $item['issue_count'] ?? 0 ) > 0;
							}
						)
					),
					'total_issue_instances' => $total_issue_instances,
					'mime_type'             => $mime_type,
					'search'                => $search,
					'stable_order'          => $stable_order,
				),
			),
			array(
				'source'         => 'local_media_inventory_health',
				'execution_mode' => 'deterministic',
			),
			'Media inventory health report built.'
		);
	}

	/**
	 * Inspects one media attachment without modifying files or metadata.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function inspect_media_asset( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to inspect media assets.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( $attachment_id <= 0 || ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_not_found', __( 'Attachment not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to inspect this media asset.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$inspection = $this->build_media_format_inspection(
			$attachment_id,
			array(
				'target_max_width'           => $input['target_max_width'] ?? 1920,
				'large_file_threshold_bytes' => $input['large_file_threshold_bytes'] ?? 524288,
				'preferred_format'           => $input['preferred_format'] ?? 'webp',
			)
		);

		return $this->build_analysis_success_response(
			$inspection,
			array(
				'source'         => 'local_media_asset_inspection',
				'execution_mode' => 'deterministic',
				'readonly'       => true,
			),
			'Media asset inspection built.'
		);
	}

	/**
	 * Builds a Cloud derivative request contract without transport side effects.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_derivative_cloud_request( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare media derivative requests.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( $attachment_id <= 0 || ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_not_found', __( 'Attachment not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare a derivative request for this media asset.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$preferred_format = sanitize_key( (string) ( $input['preferred_format'] ?? 'webp' ) );
		if ( ! in_array( $preferred_format, array( 'webp', 'avif', 'jpeg', 'png', 'original' ), true ) ) {
			$preferred_format = 'webp';
		}
		$quality = max( 1, min( 100, $this->absint_value( $input['quality'] ?? 82 ) ) );
		$inspection = $this->build_media_format_inspection(
			$attachment_id,
			array(
				'target_max_width'           => $input['target_max_width'] ?? 1920,
				'large_file_threshold_bytes' => $input['large_file_threshold_bytes'] ?? 524288,
				'preferred_format'           => $preferred_format,
			)
		);
		$format_plan = is_array( $inspection['format_plan'] ?? null ) ? $inspection['format_plan'] : array();
		$storage = is_array( $inspection['storage'] ?? null ) ? $inspection['storage'] : array();
		$target_format = 'original' === $preferred_format
			? sanitize_key( (string) ( $inspection['source_format'] ?? 'original' ) )
			: $preferred_format;
		$target_max_width = $this->absint_value( $format_plan['recommended_max_width'] ?? $input['target_max_width'] ?? 1920 );
		if ( $target_max_width <= 0 ) {
			$target_max_width = 1920;
		}
		$watermark = $this->normalize_media_derivative_watermark( $input['watermark'] ?? array() );
		if ( is_wp_error( $watermark ) ) {
			return $watermark;
		}
		$crop = $this->normalize_media_derivative_crop( $input['crop'] ?? array() );
		if ( is_wp_error( $crop ) ) {
			return $crop;
		}

		$cloud_job_payload = array(
			'job_type'        => 'generate_optimized_media_derivative',
			'target_format'   => $target_format,
			'max_width'       => max( 320, min( 7680, $target_max_width ) ),
			'quality'         => $quality,
			'source_media_type' => 'image',
			'source_asset'    => array(
				'attachment_id'     => $attachment_id,
				'title'             => sanitize_text_field( (string) ( $attachment->post_title ?? '' ) ),
				'mime_type'         => sanitize_text_field( (string) ( $inspection['mime_type'] ?? '' ) ),
				'source_format'     => sanitize_key( (string) ( $inspection['source_format'] ?? '' ) ),
				'file_basename'     => $this->sanitize_file_name_value( (string) ( $inspection['file_basename'] ?? '' ) ),
				'width'             => $this->absint_value( $inspection['width'] ?? 0 ),
				'height'            => $this->absint_value( $inspection['height'] ?? 0 ),
				'filesize_bytes'    => $this->absint_value( $inspection['filesize_bytes'] ?? 0 ),
				'metadata_available' => ! empty( $inspection['metadata_available'] ),
				'storage'           => $storage,
			),
			'requested_derivative' => array(
				'format'           => $target_format,
				'max_width'        => max( 320, min( 7680, $target_max_width ) ),
				'quality'          => $quality,
				'preserve_original' => true,
				'replace_original' => false,
			),
			'format_plan'     => $format_plan,
			'warnings'        => is_array( $inspection['warnings'] ?? null ) ? array_values( array_map( 'sanitize_key', $inspection['warnings'] ) ) : array(),
		);
		if ( ! empty( $watermark ) ) {
			$cloud_job_payload['watermark'] = $watermark;
		}
		if ( ! empty( $crop ) ) {
			$cloud_job_payload['crop'] = $crop;
			$cloud_job_payload['requested_derivative']['crop'] = $crop;
		}

		return $this->build_analysis_success_response(
			array(
				'request_contract_version' => 'media_derivative_cloud_request.v1',
				'attachment_id'            => $attachment_id,
				'readonly'                 => true,
				'proposal_only'            => true,
				'storage'                  => $storage,
				'blocked'                  => '' !== (string) ( $storage['blocked_reason'] ?? '' ),
				'blocked_reason'           => sanitize_key( (string) ( $storage['blocked_reason'] ?? '' ) ),
				'cloud_job_payload'        => $cloud_job_payload,
				'cloud_execution'          => array(
					'owner'                  => 'npcink_ai_cloud',
					'transport_owner'        => 'host_or_cloud_addon',
					'credentials_included'    => false,
					'authorization_included'  => false,
					'signed_headers_included' => false,
					'source_upload_required'  => true,
					'source_read_mode'        => sanitize_key( (string) ( $storage['source_read_mode'] ?? 'blocked' ) ),
					'callback_included'       => false,
					'artifact_ttl_required'   => true,
				),
				'local_adoption'           => array(
					'owner'                  => 'local_wordpress_host',
					'final_write_owner'      => 'local_wordpress_host',
					'approval_required'      => true,
					'wordpress_write_included' => false,
					'suggested_next_step'    => __( 'Show the Cloud derivative result as a local proposal before recording, attaching, or replacing any WordPress media file.', 'npcink-abilities-toolkit' ),
				),
				'risk'                     => array(
					'level'  => 'read',
					'reason' => __( 'This ability only prepares a bounded Cloud request contract. File generation, artifact transport, and WordPress adoption are separate host-governed steps.', 'npcink-abilities-toolkit' ),
				),
			),
			array(
				'source'         => 'local_media_derivative_cloud_request',
				'execution_mode' => 'deterministic',
				'readonly'       => true,
				'plan_only'      => true,
			),
			'Media derivative Cloud request built.'
		);
	}

	/**
	 * Builds a bounded read-only batch plan for Cloud media derivative previews.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_derivative_batch_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare media derivative batch plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

	$target_format = sanitize_key( (string) ( $input['target_format'] ?? $input['preferred_format'] ?? 'webp' ) );
	if ( ! in_array( $target_format, array( 'webp', 'avif', 'jpeg', 'png', 'original' ), true ) ) {
		return new \WP_Error(
			'npcink_abilities_toolkit_media_derivative_target_format_invalid',
			__( 'Unsupported media derivative target format.', 'npcink-abilities-toolkit' ),
			array( 'status' => 400 )
		);
	}

	$watermark = $this->normalize_media_derivative_watermark( $input['watermark'] ?? array() );
	if ( is_wp_error( $watermark ) ) {
		return $watermark;
	}
	$crop = $this->normalize_media_derivative_crop( $input['crop'] ?? array() );
	if ( is_wp_error( $crop ) ) {
		return $crop;
	}

	$max_items = max( 1, min( 50, $this->absint_value( $input['max_items'] ?? 20 ) ) );
	$page = max( 1, $this->absint_value( $input['page'] ?? 1 ) );
	$mime_type = sanitize_text_field( (string) ( $input['mime_type'] ?? 'image' ) );
	$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
	$date_from = sanitize_text_field( (string) ( $input['date_from'] ?? '' ) );
	$date_to = sanitize_text_field( (string) ( $input['date_to'] ?? '' ) );
	$exclude_formats = $this->normalize_media_derivative_format_list( $input['exclude_formats'] ?? array() );
	$target_max_width = max( 320, min( 7680, $this->absint_value( $input['target_max_width'] ?? 1920 ) ) );
	$large_file_threshold = max( 102400, min( 104857600, $this->absint_value( $input['large_file_threshold_bytes'] ?? 524288 ) ) );
	$quality = max( 1, min( 100, $this->absint_value( $input['quality'] ?? 82 ) ) );
	$min_width = $this->absint_value( $input['min_width'] ?? 0 );
	$min_height = $this->absint_value( $input['min_height'] ?? 0 );
	$min_filesize_bytes = $this->absint_value( $input['min_filesize_bytes'] ?? 0 );
	$max_filesize_bytes = $this->absint_value( $input['max_filesize_bytes'] ?? 0 );

	$explicit_ids = is_array( $input['attachment_ids'] ?? null )
		? array_slice( array_values( array_unique( array_filter( array_map( array( $this, 'absint_value' ), $input['attachment_ids'] ) ) ) ), 0, 100 )
		: array();
	$query_result = array( 'attachment_ids' => $explicit_ids, 'total' => count( $explicit_ids ) );
	if ( empty( $explicit_ids ) ) {
		$query_result = $this->query_media_derivative_batch_inventory( $mime_type, $search, $date_from, $date_to, max( $max_items * 4, 50 ), $page );
	}

	$candidates = array();
	$skipped = array();
	$scanned_count = 0;
	$attachment_ids = is_array( $query_result['attachment_ids'] ?? null )
		? array_values( array_map( array( $this, 'absint_value' ), $query_result['attachment_ids'] ) )
		: array();

	foreach ( $attachment_ids as $attachment_id ) {
		if ( count( $candidates ) >= $max_items ) {
			break;
		}
		++$scanned_count;
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			$skipped[] = $this->build_media_derivative_batch_skip_row( $attachment_id, 'attachment_not_found', array() );
			continue;
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			$skipped[] = $this->build_media_derivative_batch_skip_row( $attachment_id, 'permission_denied', array() );
			continue;
		}

		$inspection = $this->build_media_format_inspection(
			$attachment_id,
			array(
				'target_max_width'           => $target_max_width,
				'large_file_threshold_bytes' => $large_file_threshold,
				'preferred_format'           => in_array( $target_format, array( 'webp', 'avif', 'original' ), true ) ? $target_format : 'webp',
			)
		);
		$skip_reason = $this->resolve_media_derivative_batch_skip_reason(
			$inspection,
			array(
				'target_format'        => $target_format,
				'exclude_formats'      => $exclude_formats,
				'min_width'            => $min_width,
				'min_height'           => $min_height,
				'min_filesize_bytes'   => $min_filesize_bytes,
				'max_filesize_bytes'   => $max_filesize_bytes,
			)
		);
		if ( '' !== $skip_reason ) {
			$skipped[] = $this->build_media_derivative_batch_skip_row( $attachment_id, $skip_reason, $inspection );
			continue;
		}

		$cloud_request_input = array(
			'attachment_id'              => $attachment_id,
			'target_max_width'           => $target_max_width,
			'large_file_threshold_bytes' => $large_file_threshold,
			'preferred_format'           => $target_format,
			'quality'                    => $quality,
		);
		if ( ! empty( $watermark ) ) {
			$cloud_request_input['watermark'] = $watermark;
		}
		if ( ! empty( $crop ) ) {
			$cloud_request_input['crop'] = $crop;
		}

		$candidates[] = array(
			'attachment_id'          => $attachment_id,
			'status'                 => 'eligible',
			'reason'                 => 'eligible',
			'result_ref'             => 'attachment:' . (string) $attachment_id,
			'title'                  => sanitize_text_field( (string) ( $inspection['title'] ?? '' ) ),
			'mime_type'              => sanitize_text_field( (string) ( $inspection['mime_type'] ?? '' ) ),
			'source_format'          => sanitize_key( (string) ( $inspection['source_format'] ?? '' ) ),
			'target_format'          => $target_format,
			'url'                    => $this->esc_url_value( (string) ( $inspection['url'] ?? '' ) ),
			'width'                  => $this->absint_value( $inspection['width'] ?? 0 ),
			'height'                 => $this->absint_value( $inspection['height'] ?? 0 ),
			'filesize_bytes'         => $this->absint_value( $inspection['filesize_bytes'] ?? 0 ),
			'storage'                => is_array( $inspection['storage'] ?? null ) ? $this->sanitize_media_storage_inspection( $inspection['storage'] ) : array(),
			'warnings'               => is_array( $inspection['warnings'] ?? null ) ? array_values( array_map( 'sanitize_key', $inspection['warnings'] ) ) : array(),
			'cloud_request_ability'  => 'npcink-abilities-toolkit/build-media-derivative-cloud-request',
			'cloud_request_input'    => $cloud_request_input,
			'proposal_required'      => true,
			'preview_required'       => true,
		);
	}

	$total = $this->absint_value( $query_result['total'] ?? count( $attachment_ids ) );
	$truncated = count( $candidates ) >= $max_items && $total > $scanned_count;
	$blocked_items = $this->build_media_derivative_batch_blocked_items( $skipped );
	$operator_next_action = ! empty( $candidates )
		? 'Review eligible media, generate selected previews, then submit selected Core reviews.'
		: ( ! empty( $blocked_items ) ? 'Review blocked reasons or adjust filters, then rebuild the plan.' : 'Adjust media scope or filters, then rebuild the plan.' );
	$retry_guidance = ! empty( $candidates )
		? 'Change selected media or rebuild the plan after adjusting filters before generating previews again.'
		: 'Adjust scope, filters, excluded formats, or media dimensions, then rebuild the plan.';

	return $this->build_analysis_success_response(
		array(
			'plan_contract_version' => 'media_derivative_batch_plan.v1',
			'readonly'              => true,
			'plan_mode'             => 'dry_run',
			'requires_approval'     => true,
			'commit_execution'      => false,
			'filters'               => array(
				'mime_type'                  => $mime_type,
				'search'                     => $search,
				'date_from'                  => $date_from,
				'date_to'                    => $date_to,
				'target_format'              => $target_format,
				'exclude_formats'            => $exclude_formats,
				'target_max_width'           => $target_max_width,
				'large_file_threshold_bytes' => $large_file_threshold,
				'quality'                    => $quality,
				'crop'                       => $crop,
				'min_width'                  => $min_width,
				'min_height'                 => $min_height,
				'min_filesize_bytes'         => $min_filesize_bytes,
				'max_filesize_bytes'         => $max_filesize_bytes,
				'max_items'                  => $max_items,
				'page'                       => $page,
			),
			'summary'               => array(
				'total_matched'       => $total,
				'scanned_count'       => $scanned_count,
				'candidate_count'     => count( $candidates ),
				'skipped_count'       => count( $skipped ),
				'truncated'           => $truncated,
				'cloud_calls_included' => false,
			),
			'eligibility_summary'   => array(
				'total_count'          => $total,
				'scanned_count'        => $scanned_count,
				'eligible_count'       => count( $candidates ),
				'candidate_count'      => count( $candidates ),
				'blocked_count'        => count( $blocked_items ),
				'skipped_count'        => count( $skipped ),
				'selected_count'       => count( $candidates ),
				'truncated'            => $truncated,
				'cloud_calls_included' => false,
			),
			'candidates'            => $candidates,
			'skipped'               => $skipped,
			'blocked_items'         => $blocked_items,
			'retryable'             => true,
			'retry_guidance'        => $retry_guidance,
			'operator_next_action'  => $operator_next_action,
			'execution_plan'        => array(
				'steps' => array(
					'Review candidates and skipped reasons.',
					'For each reviewed candidate, call npcink-abilities-toolkit/build-media-derivative-cloud-request, then let the consuming product dispatch the validated request through its Cloud connector; signed Cloud transport and result reads stay behind that connector, and preview does not write WordPress.',
					'Preview non-expired derivative artifacts through the local same-origin preview route.',
					'Submit Core proposal payloads for npcink-abilities-toolkit/adopt-cloud-media-derivative only after review.',
					'Approve and execute through Core; run reference repair planning when hard-coded URLs or settings references remain.',
				),
				'batch_size_recommendation' => min( $max_items, 20 ),
				'proposal_strategy'         => 'small_reviewed_batches',
			),
			'boundary'              => array(
				'owner'                    => 'local_wordpress_host',
				'cloud_owner'              => 'runtime_derivative_generation_only',
				'proposal_created'         => false,
				'approval_decision_included' => false,
				'canonical_media_truth_included' => false,
			),
		),
		array(
			'source'         => 'local_media_derivative_batch_plan',
			'execution_mode' => 'deterministic',
			'readonly'       => true,
			'plan_only'      => true,
		),
			'Media derivative batch plan built.'
		);
	}

	/**
	 * Builds a read-only one-attachment media optimization plan for Core batch approval.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_optimization_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare media optimization plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_not_found', __( 'Attachment was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare an optimization plan for this media item.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$metadata_input = $this->normalize_media_optimization_metadata_input( $attachment_id, $input['media_details_input'] ?? array() );
		if ( is_wp_error( $metadata_input ) ) {
			return $metadata_input;
		}

		$artifact = $this->normalize_media_optimization_derivative_artifact( $input['derivative_artifact'] ?? array() );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}
		$reviewed_file_name = $this->normalize_media_optimization_file_name( (string) ( $input['file_name'] ?? '' ) );
		if ( is_wp_error( $reviewed_file_name ) ) {
			return $reviewed_file_name;
		}

		$current = $this->build_media_inventory_health_row( $attachment_id );
		$current_relative_file = $this->normalize_media_relative_file( (string) ( function_exists( 'get_post_meta' ) ? get_post_meta( $attachment_id, '_wp_attached_file', true ) : '' ) );
		$current_mime_type = sanitize_text_field( (string) ( $current['mime_type'] ?? ( function_exists( 'get_post_mime_type' ) ? get_post_mime_type( $attachment_id ) : '' ) ) );
		$expected_current_relative_file = $this->normalize_media_relative_file( (string) ( $input['expected_current_relative_file'] ?? $current_relative_file ) );
		$expected_current_mime_type = sanitize_text_field( (string) ( $input['expected_current_mime_type'] ?? $current_mime_type ) );
		$expected_derivative_mime_type = sanitize_text_field( (string) ( $input['expected_derivative_mime_type'] ?? ( $artifact['mime_type'] ?? '' ) ) );
		$storage_preflight = is_array( $input['storage_preflight'] ?? null ) ? $this->sanitize_media_storage_inspection( $input['storage_preflight'] ) : array();
		$expected_storage_provider = sanitize_key( (string) ( $input['expected_storage_provider'] ?? ( $storage_preflight['provider'] ?? '' ) ) );
		$expected_storage_adapter = sanitize_key( (string) ( $input['expected_storage_adapter'] ?? ( $storage_preflight['adapter'] ?? '' ) ) );

		$metadata_action = $this->build_plan_action(
			'update_media_details_' . $attachment_id,
			'npcink-abilities-toolkit/update-media-details',
			$metadata_input,
			array( 'media.write' ),
			'medium',
			'Apply reviewed media SEO and source metadata as part of one media optimization approval.'
		);
		$derivative_input = array(
			'attachment_id'       => $attachment_id,
			'derivative_artifact' => $artifact,
		);
			if ( '' !== $reviewed_file_name ) {
				$derivative_input['file_name'] = $reviewed_file_name;
			}
			if ( '' !== $expected_current_relative_file ) {
				$derivative_input['expected_current_relative_file'] = $expected_current_relative_file;
			}
			if ( '' !== $expected_current_mime_type ) {
				$derivative_input['expected_current_mime_type'] = $expected_current_mime_type;
			}
			if ( '' !== $expected_derivative_mime_type ) {
				$derivative_input['expected_derivative_mime_type'] = $expected_derivative_mime_type;
			}
			if ( '' !== $expected_storage_provider ) {
				$derivative_input['expected_storage_provider'] = $expected_storage_provider;
			}
			if ( '' !== $expected_storage_adapter ) {
				$derivative_input['expected_storage_adapter'] = $expected_storage_adapter;
			}
			if ( array() !== $storage_preflight ) {
				$derivative_input['storage_preflight'] = $storage_preflight;
			}

		$metadata_preview = array(
			'before' => array(
				'title'       => sanitize_text_field( (string) ( $current['title'] ?? '' ) ),
				'alt'         => sanitize_text_field( (string) ( $current['alt'] ?? '' ) ),
				'caption'     => $this->sanitize_metadata_text( (string) ( $current['caption'] ?? '' ) ),
				'description' => $this->sanitize_metadata_text( (string) ( $current['description'] ?? '' ) ),
				'source_type' => $this->normalize_media_source_type( $current['source_type'] ?? '' ),
			),
			'after'  => array_diff_key( $metadata_input, array( 'attachment_id' => true ) ),
		);
		$derivative_preview = array(
			'before' => array(
				'relative_file'  => $current_relative_file,
				'mime_type'      => $current_mime_type,
				'filesize_bytes' => $this->absint_value( $current['filesize_bytes'] ?? 0 ),
			),
			'after'  => array(
				'artifact_id'    => sanitize_text_field( (string) ( $artifact['artifact_id'] ?? '' ) ),
				'mime_type'      => sanitize_text_field( (string) ( $artifact['mime_type'] ?? '' ) ),
				'width'          => $this->absint_value( $artifact['width'] ?? 0 ),
				'height'         => $this->absint_value( $artifact['height'] ?? 0 ),
				'filesize_bytes' => $this->absint_value( $artifact['filesize_bytes'] ?? 0 ),
			),
		);
		$derivative_preview['content_reference_repairs'] = $this->build_media_optimization_content_reference_repairs( $attachment_id, $current_relative_file, $artifact, $reviewed_file_name );
		$content_reference_repairs = is_array( $derivative_preview['content_reference_repairs'] ?? null ) ? $derivative_preview['content_reference_repairs'] : array();
		$derivative_input['expected_content_reference_post_ids'] = array_values(
			array_map(
				array( $this, 'absint_value' ),
				array_column( (array) ( $content_reference_repairs['repairs'] ?? array() ), 'post_id' )
			)
		);
		$derivative_input['expected_content_reference_post_count'] = $this->absint_value( $content_reference_repairs['post_count'] ?? 0 );
		$derivative_input['expected_content_reference_replacement_count'] = $this->absint_value( $content_reference_repairs['replacement_count'] ?? 0 );
		$derivative_action = $this->build_plan_action(
			'adopt_cloud_media_derivative_' . $attachment_id,
			'npcink-abilities-toolkit/adopt-cloud-media-derivative',
			$derivative_input,
			array( 'media.write' ),
			'medium',
			'Adopt the reviewed Cloud derivative artifact as the attachment main file after Core approval.'
		);

		return $this->build_analysis_success_response(
				array(
					'artifact_type'       => 'media_optimization_plan',
					'version'             => 1,
					'batch_id'            => 'media_optimization_' . $attachment_id . '_' . gmdate( 'Ymd_His' ),
					'attachment_id'       => $attachment_id,
					'optimization_goal'   => 'image_seo_and_derivative_adoption',
					'requires_approval'   => true,
					'dry_run'             => true,
					'commit_execution'    => false,
					'proposal_mode'       => 'batch',
					'batch_approval'      => true,
					'action_count'        => 2,
					'metadata_preview'    => $metadata_preview,
					'derivative_preview'  => $derivative_preview,
					'content_reference_repairs_preview' => $derivative_preview['content_reference_repairs'],
					'preview'             => array(
						array(
							'attachment_id'       => $attachment_id,
							'before'              => array(
								'metadata'   => $metadata_preview['before'],
								'derivative' => $derivative_preview['before'],
							),
							'after_suggestion'    => array(
								'metadata'   => $metadata_preview['after'],
								'derivative' => $derivative_preview['after'],
							),
						),
					),
					'write_actions'       => array( $metadata_action, $derivative_action ),
					'risk'                => array(
						'level'  => 'medium',
						'reason' => __( 'One attachment metadata update and one reviewed Cloud derivative adoption share one Core approval.', 'npcink-abilities-toolkit' ),
					),
				),
				array(
					'source'         => 'local_media_optimization_plan',
					'execution_mode' => 'deterministic',
					'readonly'       => true,
					'plan_only'      => true,
				),
				'Media optimization plan built.'
			);
		}

		/**
		 * Builds a read-only adoption preflight summary for one derivative artifact.
		 *
		 * @param mixed $input Input args.
		 * @return array<string,mixed>|\WP_Error
		 */
		public function build_media_adoption_preflight_summary( $input ) {
			$input = is_array( $input ) ? $input : array();
			if ( ! current_user_can( 'upload_files' ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to inspect media adoption readiness.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}

			$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
			if ( $attachment_id <= 0 ) {
				return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}

			$attachment = get_post( $attachment_id );
			if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_attachment_not_found', __( 'Attachment was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to inspect this media item.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
			}

			$current = $this->build_media_inventory_health_row( $attachment_id );
			$format_inspection = is_array( $current['format_inspection'] ?? null ) ? $current['format_inspection'] : array();
			$current_relative_file = $this->normalize_media_relative_file( (string) ( $format_inspection['current_relative_file'] ?? ( function_exists( 'get_post_meta' ) ? get_post_meta( $attachment_id, '_wp_attached_file', true ) : '' ) ) );
			$current_size = $this->absint_value( $format_inspection['filesize_bytes'] ?? 0 );
			$current_width = $this->absint_value( $format_inspection['width'] ?? 0 );
			$current_height = $this->absint_value( $format_inspection['height'] ?? 0 );
			$current_mime_type = sanitize_text_field( (string) ( $current['mime_type'] ?? ( $format_inspection['mime_type'] ?? '' ) ) );
			$current_summary = array(
				'attachment_id'         => $attachment_id,
				'title'                 => sanitize_text_field( (string) ( $current['title'] ?? '' ) ),
				'url'                   => $this->esc_url_value( (string) ( $current['url'] ?? ( $format_inspection['url'] ?? '' ) ) ),
				'relative_file'         => $current_relative_file,
				'file_basename'         => $this->sanitize_file_name_value( (string) ( $format_inspection['file_basename'] ?? basename( $current_relative_file ) ) ),
				'mime_type'             => $current_mime_type,
				'format'                => sanitize_key( (string) ( $format_inspection['source_format'] ?? '' ) ),
				'width'                 => $current_width,
				'height'                => $current_height,
				'filesize_bytes'        => $current_size,
				'metadata_issue_count'  => $this->absint_value( $current['issue_count'] ?? 0 ),
				'format_warnings'       => array_values( array_map( 'sanitize_key', (array) ( $format_inspection['warnings'] ?? array() ) ) ),
			);

			$artifact_input = is_array( $input['derivative_artifact'] ?? null ) ? $input['derivative_artifact'] : array();
			$artifact = array();
			$reviewed_file_name = '';
			if ( ! empty( $artifact_input ) ) {
				$artifact = $this->normalize_media_optimization_derivative_artifact( $artifact_input );
				if ( is_wp_error( $artifact ) ) {
					return $artifact;
				}
				$reviewed_file_name = $this->normalize_media_optimization_file_name( (string) ( $input['file_name'] ?? '' ) );
				if ( is_wp_error( $reviewed_file_name ) ) {
					return $reviewed_file_name;
				}
			}

			$has_artifact = ! empty( $artifact );
			$derivative_summary = array(
				'available' => $has_artifact,
			);
			$comparison = array(
				'available' => false,
			);
			$content_reference_summary = array(
				'scan_available'        => $has_artifact,
				'scan_included'         => false,
				'post_count'            => 0,
				'replacement_count'     => 0,
				'replacement_rule_count' => 0,
				'repair_plan_available' => false,
				'reference_strategy'    => '',
				'repairs_preview'       => array(),
			);
			$warnings = array();

			if ( $has_artifact ) {
				$artifact_size = $this->absint_value( $artifact['filesize_bytes'] ?? 0 );
				$artifact_width = $this->absint_value( $artifact['width'] ?? 0 );
				$artifact_height = $this->absint_value( $artifact['height'] ?? 0 );
				$filesize_delta = $artifact_size - $current_size;
				$derivative_summary = array(
					'available'           => true,
					'artifact_id'         => sanitize_text_field( (string) ( $artifact['artifact_id'] ?? '' ) ),
					'expires_at'          => sanitize_text_field( (string) ( $artifact['expires_at'] ?? '' ) ),
					'mime_type'           => sanitize_text_field( (string) ( $artifact['mime_type'] ?? '' ) ),
					'format'              => sanitize_key( (string) ( $artifact['format'] ?? '' ) ),
					'width'               => $artifact_width,
					'height'              => $artifact_height,
					'filesize_bytes'      => $artifact_size,
					'sha256'              => sanitize_text_field( (string) ( $artifact['sha256'] ?? '' ) ),
					'reviewed_file_name'  => $reviewed_file_name,
					'processing_warnings' => array_values( array_map( 'sanitize_key', (array) ( $artifact['processing_warnings'] ?? array() ) ) ),
				);
				$comparison = array(
					'available'               => true,
					'mime_type_before'        => $current_mime_type,
					'mime_type_after'         => $derivative_summary['mime_type'],
					'format_before'           => $current_summary['format'],
					'format_after'            => $derivative_summary['format'],
					'width_before'            => $current_width,
					'height_before'           => $current_height,
					'width_after'             => $artifact_width,
					'height_after'            => $artifact_height,
					'filesize_before_bytes'   => $current_size,
					'filesize_after_bytes'    => $artifact_size,
					'filesize_delta_bytes'    => $filesize_delta,
					'filesize_delta_percent'  => $current_size > 0 ? round( ( $filesize_delta / $current_size ) * 100, 2 ) : null,
					'will_change_main_file'   => true,
					'will_update_wp_metadata' => false,
				);
				$content_reference_repairs = $this->build_media_optimization_content_reference_repairs( $attachment_id, $current_relative_file, $artifact, $reviewed_file_name );
				$repairs = array_values( (array) ( $content_reference_repairs['repairs'] ?? array() ) );
				$content_reference_summary = array(
					'scan_available'        => true,
					'scan_included'         => true,
					'scanned_count'         => $this->absint_value( $content_reference_repairs['scanned_count'] ?? 0 ),
					'post_count'            => $this->absint_value( $content_reference_repairs['post_count'] ?? 0 ),
					'replacement_count'     => $this->absint_value( $content_reference_repairs['replacement_count'] ?? 0 ),
					'replacement_rule_count' => $this->absint_value( $content_reference_repairs['replacement_rule_count'] ?? 0 ),
					'actual_replacement_count' => $this->absint_value( $content_reference_repairs['actual_replacement_count'] ?? 0 ),
					'unmatched_rule_count'  => count( (array) ( $content_reference_repairs['unmatched_rules'] ?? array() ) ),
					'repair_plan_available' => ! empty( $repairs ),
					'reference_strategy'    => sanitize_key( (string) ( $content_reference_repairs['reference_strategy'] ?? '' ) ),
					'repairs_preview'       => array_slice( $repairs, 0, 5 ),
				);
				$warnings = array_merge( $warnings, $derivative_summary['processing_warnings'] );
			} else {
				$warnings[] = 'derivative_artifact_missing';
			}

			$settings_reference_summary = array(
				'scan_available'        => current_user_can( 'manage_options' ),
				'scan_included'         => false,
				'action_count'          => null,
				'suggested_next_step'   => 'build-media-settings-reference-repair-plan',
				'note'                  => 'Settings and theme option references use the dedicated bounded settings repair plan ability.',
			);
			if ( ! empty( $input['include_settings_scan'] ) ) {
				$warnings[] = 'settings_reference_scan_deferred';
			}

			$next_steps = $has_artifact
				? array( 'review_preflight_summary', 'submit_media_optimization_proposal' )
				: array( 'generate_derivative_preview' );
			if ( ! empty( $content_reference_summary['repair_plan_available'] ) ) {
				$next_steps[] = 'review_content_reference_repairs';
			}
			if ( ! empty( $settings_reference_summary['scan_available'] ) ) {
				$next_steps[] = 'optionally_build_settings_reference_repair_plan';
			}

			return $this->build_analysis_success_response(
				array(
					'artifact_type'              => 'media_adoption_preflight_summary',
					'version'                    => 1,
					'attachment_id'              => $attachment_id,
					'readonly'                   => true,
					'direct_wordpress_write'     => false,
					'proposal_created'           => false,
					'cloud_call_included'        => false,
					'current'                    => $current_summary,
					'derivative'                 => $derivative_summary,
					'comparison'                 => $comparison,
					'content_reference_summary'  => $content_reference_summary,
					'settings_reference_summary' => $settings_reference_summary,
					'readiness'                  => array(
						'can_submit_core_proposal' => $has_artifact,
						'requires_core_approval'   => true,
						'requires_operator_review' => true,
						'requires_fresh_artifact'  => $has_artifact,
					),
					'next_steps'                 => array_values( array_unique( $next_steps ) ),
					'warnings'                   => array_values( array_unique( array_filter( $warnings ) ) ),
				),
				array(
					'source'              => 'local_media_adoption_preflight_summary',
					'execution_mode'      => 'deterministic',
					'readonly'            => true,
					'plan_only'           => true,
					'wordpress_write_owner' => 'core_host_approval_flow',
				),
				'Media adoption preflight summary built.'
			);
		}

		/**
		 * Builds read-only post-content repair evidence for a pending media optimization.
		 *
		 * @param int                 $attachment_id Attachment id.
		 * @param string              $current_relative_file Current uploads-relative file.
		 * @param array<string,mixed> $artifact Normalized derivative artifact.
		 * @param string              $file_name Reviewed derivative file basename.
		 * @return array<string,mixed>
		 */
		private function build_media_optimization_content_reference_repairs( $attachment_id, $current_relative_file, array $artifact, $file_name = '' ) {
			$attachment_id = $this->absint_value( $attachment_id );
			$current_relative_file = $this->normalize_media_reference_relative( $current_relative_file );
			$after = $this->media_optimization_derivative_reference_state( $attachment_id, $current_relative_file, $artifact, $file_name );
			$before = array(
				'attachment_id'  => $attachment_id,
				'relative_file' => $current_relative_file,
				'url'           => $this->media_reference_upload_url( $current_relative_file ),
			);
			$before['path'] = $this->media_reference_url_path( (string) $before['url'] );
			$pairs = $this->media_optimization_content_reference_pairs( $before, $after, $attachment_id );
			$max_posts = 50;
			$repairs = array();
			$scanned_count = 0;
			$replacement_count = 0;
			$replacement_rule_count = 0;
			$actual_replacement_count = 0;
			$unmatched_rules = array();

			foreach ( $this->media_reference_repair_candidate_posts( $max_posts * 3 ) as $post ) {
				if ( count( $repairs ) >= $max_posts ) {
					break;
				}
				if ( ! is_object( $post ) || 'attachment' === sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
					continue;
				}
				if ( ! in_array( sanitize_key( (string) ( $post->post_status ?? '' ) ), array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
					continue;
				}

				++$scanned_count;
				$post_id = $this->absint_value( $post->ID ?? 0 );
				$content = (string) ( $post->post_content ?? '' );
				if ( $post_id <= 0 || '' === $content ) {
					continue;
				}

				$post_pairs = $this->merge_media_optimization_content_reference_pairs(
					$pairs,
					$this->media_optimization_content_dynamic_sized_pairs( $content, $before, $after )
				);
				$operations = array();
				foreach ( $post_pairs as $pair ) {
					$old = (string) ( $pair['old'] ?? '' );
					$new = (string) ( $pair['new'] ?? '' );
					if ( '' === $old || '' === $new || $old === $new || false === strpos( $content, $old ) ) {
						continue;
					}
					$count = substr_count( $content, $old );
					if ( $count <= 0 ) {
						continue;
					}
					$operations[] = array(
						'op'      => 'replace',
						'find'    => $old,
						'replace' => $new,
						'limit'   => min( 20, $count ),
					);
					$replacement_count += $count;
				}
				if ( empty( $operations ) ) {
					continue;
				}

				$preview = $this->apply_media_optimization_reference_operations_preview( $content, $operations );
				$patch_preview = (array) ( $preview['patch_preview'] ?? array() );
				$repair_actual_replacement_count = 0;
				$repair_unmatched_rules = array();
				foreach ( $patch_preview as $operation_index => $patch_row ) {
					if ( ! is_array( $patch_row ) ) {
						continue;
					}
					$applied = $this->absint_value( $patch_row['applied'] ?? 0 );
					$repair_actual_replacement_count += $applied;
					if ( 0 === $applied ) {
						$operation = is_array( $operations[ $operation_index ] ?? null ) ? $operations[ $operation_index ] : array();
						$repair_unmatched_rules[] = array(
							'post_id'         => $post_id,
							'operation_index' => $this->absint_value( $operation_index ),
							'find'            => (string) ( $operation['find'] ?? ( $patch_row['find'] ?? '' ) ),
						);
					}
				}
				$replacement_rule_count += count( $operations );
				$actual_replacement_count += $repair_actual_replacement_count;
				foreach ( $repair_unmatched_rules as $unmatched_rule ) {
					$unmatched_rules[] = $unmatched_rule;
				}
				$repairs[] = array(
					'post_id'               => $post_id,
					'post_type'             => sanitize_key( (string) ( $post->post_type ?? '' ) ),
					'post_status'           => sanitize_key( (string) ( $post->post_status ?? '' ) ),
					'title'                 => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
					'operation_count'       => count( $operations ),
					'replacement_rule_count' => count( $operations ),
					'actual_replacement_count' => $repair_actual_replacement_count,
					'unmatched_rules'       => $repair_unmatched_rules,
					'operations'            => $operations,
					'content_length_before' => strlen( $content ),
					'content_length_after'  => strlen( (string) ( $preview['content'] ?? $content ) ),
					'patch_preview'         => $patch_preview,
					'updated'               => false,
				);
			}

			return array(
				'attachment_id'      => $attachment_id,
				'applied'            => false,
				'scanned_count'      => $scanned_count,
				'post_count'         => count( $repairs ),
				'replacement_count'  => $replacement_count,
				'replacement_rule_count' => $replacement_rule_count,
				'actual_replacement_count' => $actual_replacement_count,
				'unmatched_rules'    => $unmatched_rules,
				'repairs'            => $repairs,
				'reference_strategy' => 'replace_old_main_and_sized_upload_urls_with_new_main_file_url',
			);
		}

		/**
		 * Builds the future local derivative reference state used by the adoption ability.
		 *
		 * @param int                 $attachment_id Attachment id.
		 * @param string              $current_relative_file Current uploads-relative file.
		 * @param array<string,mixed> $artifact Normalized derivative artifact.
		 * @param string              $file_name Reviewed derivative file basename.
		 * @return array<string,string>
		 */
		private function media_optimization_derivative_reference_state( $attachment_id, $current_relative_file, array $artifact, $file_name = '' ) {
			$current_relative_file = $this->normalize_media_reference_relative( $current_relative_file );
			$dir = dirname( $current_relative_file );
			$dir = '.' !== $dir ? trim( $dir, '/' ) : '';
			$current_basename = $this->sanitize_file_name_value( basename( $current_relative_file ) );
			$custom_basename = $this->sanitize_file_name_value( '' !== (string) $file_name ? (string) $file_name : (string) ( $artifact['suggested_filename'] ?? '' ) );
			$stem = '' !== $custom_basename ? preg_replace( '/\.[^.]+$/', '', $custom_basename ) : preg_replace( '/\.[^.]+$/', '', $current_basename );
			$stem = '' !== (string) $stem ? $stem : 'attachment-' . $this->absint_value( $attachment_id );
			$artifact_key = substr( sanitize_key( (string) ( $artifact['artifact_id'] ?? '' ) ), 0, 16 );
			$extension = $this->media_optimization_extension_for_mime( (string) ( $artifact['mime_type'] ?? '' ) );
			$file_basename = '' !== $custom_basename
				? $this->sanitize_file_name_value( (string) $stem . '.' . $extension )
				: $this->sanitize_file_name_value( (string) $stem . '-npcink-abilities-toolkit-cloud-' . $artifact_key . '.' . $extension );
			$relative_file = '' !== $dir ? $dir . '/' . $file_basename : $file_basename;
			$url = $this->media_reference_upload_url( $relative_file );

			return array(
				'relative_file' => $relative_file,
				'url'           => $url,
				'path'          => $this->media_reference_url_path( $url ),
			);
		}

		/**
		 * Builds exact old->new reference pairs for pending derivative adoption.
		 *
		 * @param array<string,string> $before Current reference state.
		 * @param array<string,string> $after Future reference state.
		 * @param int                  $attachment_id Attachment id.
		 * @return array<int,array{old:string,new:string}>
		 */
		private function media_optimization_content_reference_pairs( array $before, array $after, $attachment_id ) {
			$pairs = array(
				array( 'old' => (string) ( $before['url'] ?? '' ), 'new' => (string) ( $after['url'] ?? '' ) ),
				array( 'old' => (string) ( $before['path'] ?? '' ), 'new' => (string) ( $after['path'] ?? '' ) ),
				array( 'old' => (string) ( $before['relative_file'] ?? '' ), 'new' => (string) ( $after['relative_file'] ?? '' ) ),
			);
			$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $this->absint_value( $attachment_id ) ) : array();
			$metadata = is_array( $metadata ) ? $metadata : array();
			foreach ( $this->media_optimization_content_source_relative_files( $metadata, (string) ( $before['relative_file'] ?? '' ) ) as $source_relative ) {
				$source_url = $this->media_reference_upload_url( $source_relative );
				$source_path = $this->media_reference_url_path( $source_url );
				$pairs[] = array( 'old' => $source_url, 'new' => (string) ( $after['url'] ?? '' ) );
				$pairs[] = array( 'old' => $source_path, 'new' => (string) ( $after['path'] ?? '' ) );
				$pairs[] = array( 'old' => $source_relative, 'new' => (string) ( $after['relative_file'] ?? '' ) );
			}
			$sizes = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
			$old_relative = $this->normalize_media_reference_relative( (string) ( $before['relative_file'] ?? '' ) );
			$old_dir = dirname( $old_relative );
			$old_dir = '.' !== $old_dir ? trim( $old_dir, '/' ) : '';
			foreach ( $sizes as $size ) {
				$size = is_array( $size ) ? $size : array();
				$file = $this->sanitize_file_name_value( (string) ( $size['file'] ?? '' ) );
				if ( '' === $file ) {
					continue;
				}
				$size_relative = '' !== $old_dir ? $old_dir . '/' . $file : $file;
				$size_url = $this->media_reference_upload_url( $size_relative );
				$size_path = $this->media_reference_url_path( $size_url );
				$pairs[] = array( 'old' => $size_url, 'new' => (string) ( $after['url'] ?? '' ) );
				$pairs[] = array( 'old' => $size_path, 'new' => (string) ( $after['path'] ?? '' ) );
				$pairs[] = array( 'old' => $size_relative, 'new' => (string) ( $after['relative_file'] ?? '' ) );
			}

			return $this->merge_media_optimization_content_reference_pairs( $pairs, array() );
		}

		/**
		 * Adds old sized references present in content but missing from metadata.
		 *
		 * @param string               $content Post content.
		 * @param array<string,string> $before Current reference state.
		 * @param array<string,string> $after Future reference state.
		 * @return array<int,array{old:string,new:string}>
		 */
		private function media_optimization_content_dynamic_sized_pairs( $content, array $before, array $after ) {
			if ( '' === (string) ( $after['url'] ?? '' ) ) {
				return array();
			}
			$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $this->absint_value( (int) ( $before['attachment_id'] ?? 0 ) ) ) : array();
			$metadata = is_array( $metadata ) ? $metadata : array();
			$source_files = $this->media_optimization_content_source_relative_files( $metadata, (string) ( $before['relative_file'] ?? '' ) );
			if ( empty( $source_files ) ) {
				return array();
			}
			$pairs = array();
			foreach ( $source_files as $source_relative ) {
				$old_basename = basename( $source_relative );
				$stem = preg_replace( '/\.[^.]+$/', '', $old_basename );
				$extension = pathinfo( $old_basename, PATHINFO_EXTENSION );
				if ( '' === (string) $stem || '' === (string) $extension ) {
					continue;
				}
				$pattern = '/' . preg_quote( (string) $stem, '/' ) . '-[0-9]{2,5}x[0-9]{2,5}\.' . preg_quote( (string) $extension, '/' ) . '/u';
				if ( ! preg_match_all( $pattern, (string) $content, $matches ) ) {
					continue;
				}
				$old_dir = dirname( $source_relative );
				$old_dir = '.' !== $old_dir ? trim( $old_dir, '/' ) : '';
				foreach ( array_unique( (array) ( $matches[0] ?? array() ) ) as $sized_basename ) {
					$sized_basename = $this->sanitize_file_name_value( (string) $sized_basename );
					if ( '' === $sized_basename ) {
						continue;
					}
					$size_relative = '' !== $old_dir ? $old_dir . '/' . $sized_basename : $sized_basename;
					$size_url = $this->media_reference_upload_url( $size_relative );
					$size_path = $this->media_reference_url_path( $size_url );
					$pairs[] = array( 'old' => $size_url, 'new' => (string) ( $after['url'] ?? '' ) );
					$pairs[] = array( 'old' => $size_path, 'new' => (string) ( $after['path'] ?? '' ) );
					$pairs[] = array( 'old' => $size_relative, 'new' => (string) ( $after['relative_file'] ?? '' ) );
				}
			}

			return $pairs;
		}

		/**
		 * Returns original/current uploads-relative files that may appear in post content.
		 *
		 * @param array<string,mixed> $metadata Attachment metadata.
		 * @param string              $current_relative Current uploads-relative file.
		 * @return array<int,string>
		 */
		private function media_optimization_content_source_relative_files( array $metadata, $current_relative ) {
			$current_relative = $this->normalize_media_reference_relative( $current_relative );
			$base_dir = '' !== $current_relative ? dirname( $current_relative ) : '';
			$base_dir = '.' !== $base_dir ? trim( $base_dir, '/' ) : '';
			$candidates = array(
				$current_relative,
				(string) ( $metadata['file'] ?? '' ),
			);
			$original_image = $this->sanitize_file_name_value( (string) ( $metadata['original_image'] ?? '' ) );
			if ( '' !== $original_image ) {
				$candidates[] = false === strpos( $original_image, '/' ) && '' !== $base_dir ? $base_dir . '/' . $original_image : $original_image;
			}
			foreach ( $candidates as $candidate ) {
				$variant = $this->media_optimization_content_without_unique_suffix( $candidate );
				if ( '' !== $variant ) {
					$candidates[] = $variant;
				}
			}

			$files = array();
			foreach ( $candidates as $candidate ) {
				$file = $this->normalize_media_reference_relative( (string) $candidate );
				if ( '' !== $file ) {
					$files[ $file ] = $file;
				}
			}
			return array_values( $files );
		}

		/**
		 * Infers the pre-unique-suffix relative file when WordPress stored "-1".
		 *
		 * @param string $relative_file Uploads-relative file.
		 * @return string
		 */
		private function media_optimization_content_without_unique_suffix( $relative_file ) {
			$relative_file = $this->normalize_media_reference_relative( $relative_file );
			$basename = basename( $relative_file );
			if ( ! preg_match( '/^(.+)-[0-9]+(\.[^.]+)$/', $basename, $matches ) ) {
				return '';
			}
			$dir = dirname( $relative_file );
			$dir = '.' !== $dir ? trim( $dir, '/' ) : '';
			$file = $this->sanitize_file_name_value( (string) $matches[1] . (string) $matches[2] );
			if ( '' === $file ) {
				return '';
			}
			return '' !== $dir ? $dir . '/' . $file : $file;
		}

		/**
		 * Merges old->new media optimization reference pairs.
		 *
		 * @param array<int,array<string,string>> $primary Primary pairs.
		 * @param array<int,array<string,string>> $secondary Secondary pairs.
		 * @return array<int,array{old:string,new:string}>
		 */
		private function merge_media_optimization_content_reference_pairs( array $primary, array $secondary ) {
			$merged = array();
			foreach ( array_merge( $primary, $secondary ) as $pair ) {
				$old = trim( (string) ( is_array( $pair ) ? ( $pair['old'] ?? '' ) : '' ) );
				$new = trim( (string) ( is_array( $pair ) ? ( $pair['new'] ?? '' ) : '' ) );
				if ( '' === $old || '' === $new || $old === $new ) {
					continue;
				}
				$merged[ $old . "\n" . $new ] = array(
					'old' => $old,
					'new' => $new,
				);
			}

			return array_values( $merged );
		}

		/**
		 * Applies replace operations to build a bounded read-only patch preview.
		 *
		 * @param string       $content Original content.
		 * @param array<mixed> $operations Patch operations.
		 * @return array<string,mixed>
		 */
		private function apply_media_optimization_reference_operations_preview( $content, array $operations ) {
			$content = (string) $content;
			$patch_preview = array();
			foreach ( array_values( $operations ) as $index => $operation ) {
				$operation = is_array( $operation ) ? $operation : array();
				$find = (string) ( $operation['find'] ?? '' );
				$replace = (string) ( $operation['replace'] ?? '' );
				$limit = max( 1, min( 20, $this->absint_value( $operation['limit'] ?? 1 ) ) );
				$needle_len = strlen( $find );
				$offset = 0;
				$applied = 0;
				while ( '' !== $find && $applied < $limit ) {
					$position = strpos( $content, $find, $offset );
					if ( false === $position ) {
						break;
					}
					$content = substr( $content, 0, $position ) . $replace . substr( $content, $position + $needle_len );
					++$applied;
					$offset = $position + strlen( $replace );
				}
				$patch_preview[] = array(
					'operation_index' => $index,
					'op'              => 'replace',
					'find'            => $this->truncate_media_optimization_fragment( $find, 160 ),
					'replace'         => $this->truncate_media_optimization_fragment( $replace, 160 ),
					'applied'         => $applied,
					'limit'           => $limit,
					'case_sensitive'  => true,
				);
			}

			return array(
				'content'       => $content,
				'patch_preview' => $patch_preview,
			);
		}

		/**
		 * Returns a file extension for a supported derivative MIME type.
		 *
		 * @param string $mime_type MIME type.
		 * @return string
		 */
		private function media_optimization_extension_for_mime( $mime_type ) {
			$map = array(
				'image/webp' => 'webp',
				'image/avif' => 'avif',
				'image/jpeg' => 'jpg',
				'image/jpg'  => 'jpg',
				'image/png'  => 'png',
			);
			$mime_type = strtolower( sanitize_text_field( (string) $mime_type ) );
			return $map[ $mime_type ] ?? 'webp';
		}

		/**
		 * Truncates preview fragments without adding markup.
		 *
		 * @param string $text Text.
		 * @param int    $max_len Maximum length.
		 * @return string
		 */
		private function truncate_media_optimization_fragment( $text, $max_len ) {
			$text = (string) $text;
			$max_len = max( 20, (int) $max_len );
			return strlen( $text ) > $max_len ? substr( $text, 0, $max_len - 3 ) . '...' : $text;
		}

		/**
		 * Builds a read-only media rename plan for Core approval.
		 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_rename_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare media rename plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$attachment = get_post( $attachment_id );
		if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_not_found', __( 'Attachment was not found.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to prepare a rename plan for this media item.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$inspection = $this->build_media_format_inspection(
			$attachment_id,
			array(
				'target_max_width'           => 1920,
				'large_file_threshold_bytes' => 524288,
				'preferred_format'           => 'webp',
			)
		);
		$current_relative_file = $this->normalize_media_relative_file( (string) ( $inspection['current_relative_file'] ?? '' ) );
		$current_mime_type = sanitize_text_field( (string) ( $inspection['mime_type'] ?? '' ) );
		$current_basename = $this->sanitize_file_name_value( (string) ( $inspection['file_basename'] ?? basename( $current_relative_file ) ) );
		$current_extension = strtolower( pathinfo( $current_basename, PATHINFO_EXTENSION ) );
		$target_file_name = $this->normalize_media_rename_target_file_name( (string) ( $input['target_file_name'] ?? '' ), $current_extension );
		if ( is_wp_error( $target_file_name ) ) {
			return $target_file_name;
		}
		if ( $target_file_name === $current_basename ) {
			return new \WP_Error( 'npcink_abilities_toolkit_no_changes', __( 'Target file name matches the current file name.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$target_extension = strtolower( pathinfo( $target_file_name, PATHINFO_EXTENSION ) );
		if ( '' !== $current_extension && $target_extension !== $current_extension ) {
			return new \WP_Error( 'npcink_abilities_toolkit_target_extension_mismatch', __( 'Target file extension must match the current media file extension.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$content_hashes = is_array( $inspection['content_hashes'] ?? null ) ? $inspection['content_hashes'] : array();
		$raw_expected_current_md5 = (string) ( $input['expected_current_md5'] ?? ( $content_hashes['md5'] ?? '' ) );
		$raw_expected_current_sha256 = (string) ( $input['expected_current_sha256'] ?? ( $content_hashes['sha256'] ?? '' ) );
		$expected_current_md5 = $this->normalize_media_md5_value( $raw_expected_current_md5 );
		$expected_current_sha256 = $this->normalize_media_sha256_value( $raw_expected_current_sha256 );
		if ( array_key_exists( 'expected_current_md5', $input ) && '' !== trim( $raw_expected_current_md5 ) && '' === $expected_current_md5 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_expected_md5_invalid', __( 'The expected current MD5 value is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'expected_current_sha256', $input ) && '' !== trim( $raw_expected_current_sha256 ) && '' === $expected_current_sha256 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_expected_sha256_invalid', __( 'The expected current SHA-256 value is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'expected_current_md5', $input ) && '' !== $expected_current_md5 && $expected_current_md5 !== (string) ( $content_hashes['md5'] ?? '' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_current_md5_mismatch', __( 'The current media file MD5 did not match the expected value.', 'npcink-abilities-toolkit' ), array( 'status' => 409 ) );
		}
		if ( array_key_exists( 'expected_current_sha256', $input ) && '' !== $expected_current_sha256 && $expected_current_sha256 !== (string) ( $content_hashes['sha256'] ?? '' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_current_sha256_mismatch', __( 'The current media file SHA-256 did not match the expected value.', 'npcink-abilities-toolkit' ), array( 'status' => 409 ) );
		}

		$expected_current_relative_file = $this->normalize_media_relative_file( (string) ( $input['expected_current_relative_file'] ?? $current_relative_file ) );
		if ( array_key_exists( 'expected_current_relative_file', $input ) && '' !== $expected_current_relative_file && $expected_current_relative_file !== $current_relative_file ) {
			return new \WP_Error( 'npcink_abilities_toolkit_current_file_mismatch', __( 'The current media file did not match the expected value.', 'npcink-abilities-toolkit' ), array( 'status' => 409 ) );
		}
		$expected_current_mime_type = sanitize_text_field( (string) ( $input['expected_current_mime_type'] ?? $current_mime_type ) );
		if ( array_key_exists( 'expected_current_mime_type', $input ) && '' !== $expected_current_mime_type && $expected_current_mime_type !== $current_mime_type ) {
			return new \WP_Error( 'npcink_abilities_toolkit_current_mime_mismatch', __( 'The current media MIME type did not match the expected value.', 'npcink-abilities-toolkit' ), array( 'status' => 409 ) );
		}

		$conflict_mode = sanitize_key( (string) ( $input['conflict_mode'] ?? 'fail' ) );
		$conflict_mode = in_array( $conflict_mode, array( 'fail', 'unique' ), true ) ? $conflict_mode : 'fail';
		$rename_input = array(
			'attachment_id'       => $attachment_id,
			'target_file_name'    => $target_file_name,
			'conflict_mode'       => $conflict_mode,
		);
		if ( '' !== $expected_current_relative_file ) {
			$rename_input['expected_current_relative_file'] = $expected_current_relative_file;
		}
		if ( '' !== $expected_current_mime_type ) {
			$rename_input['expected_current_mime_type'] = $expected_current_mime_type;
		}
		if ( '' !== $expected_current_md5 ) {
			$rename_input['expected_current_md5'] = $expected_current_md5;
		}
		if ( '' !== $expected_current_sha256 ) {
			$rename_input['expected_current_sha256'] = $expected_current_sha256;
		}
		$rename_action = $this->build_plan_action(
			'rename_media_file_' . $attachment_id,
			'npcink-abilities-toolkit/rename-media-file',
			$rename_input,
			array( 'media.write' ),
			'medium',
			'Rename the attachment main file after reviewed host/OpenClaw filename policy approval.'
		);

			$dir = dirname( $current_relative_file );
			$dir = '.' !== $dir ? trim( $dir, '/' ) : '';
			$target_relative_file = '' !== $dir ? $dir . '/' . $target_file_name : $target_file_name;
			$write_actions = array( $rename_action );
			$reference_repair = array(
				'enabled'       => false,
				'action_count'  => 0,
				'scanned_count' => 0,
				'preview'       => array(),
				'manual_review' => array(),
			);
			$include_reference_updates = ! array_key_exists( 'include_reference_updates', $input ) || ! in_array( $input['include_reference_updates'], array( false, 0, '0', 'false', 'no' ), true );
			if ( $include_reference_updates ) {
				if ( ! current_user_can( 'edit_posts' ) ) {
					return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'Media rename plans with reference updates require permission to edit posts.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
				}
				$repair_plan = $this->build_media_reference_repair_plan(
					array(
						'attachment_id'                  => $attachment_id,
						'old_relative_file'              => $current_relative_file,
						'new_relative_file'              => $target_relative_file,
						'old_url'                        => $this->media_reference_upload_url( $current_relative_file ),
						'new_url'                        => $this->media_reference_upload_url( $target_relative_file ),
						'max_posts'                      => $this->absint_value( $input['max_reference_posts'] ?? 20 ),
						'max_replacements_per_post'      => $this->absint_value( $input['max_reference_replacements_per_post'] ?? 20 ),
					)
				);
				if ( is_wp_error( $repair_plan ) ) {
					return $repair_plan;
				}
				$repair_data = is_array( $repair_plan['data'] ?? null ) ? $repair_plan['data'] : array();
				$repair_actions = is_array( $repair_data['write_actions'] ?? null ) ? array_values( $repair_data['write_actions'] ) : array();
				$write_actions = array_merge( $write_actions, $repair_actions );
				$reference_repair = array(
					'enabled'       => true,
					'action_count'  => count( $repair_actions ),
					'scanned_count' => $this->absint_value( $repair_data['scanned_count'] ?? 0 ),
					'old'           => is_array( $repair_data['old'] ?? null ) ? $repair_data['old'] : array(),
					'new'           => is_array( $repair_data['new'] ?? null ) ? $repair_data['new'] : array(),
					'preview'       => is_array( $repair_data['preview'] ?? null ) ? array_values( $repair_data['preview'] ) : array(),
					'manual_review' => is_array( $repair_data['manual_review'] ?? null ) ? array_values( $repair_data['manual_review'] ) : array(),
				);
			}
			$action_count = count( $write_actions );
			$proposal_mode = $action_count > 1 ? 'batch' : 'single';

			return $this->build_analysis_success_response(
				array(
					'artifact_type'       => 'media_rename_plan',
					'version'             => 1,
				'batch_id'            => 'media_rename_' . $attachment_id . '_' . gmdate( 'Ymd_His' ),
				'attachment_id'       => $attachment_id,
					'requires_approval'   => true,
					'dry_run'             => true,
					'commit_execution'    => false,
					'proposal_mode'       => $proposal_mode,
					'batch_approval'      => 'batch' === $proposal_mode,
					'action_count'        => $action_count,
					'preview'             => array(
						'before' => array(
							'relative_file'  => $current_relative_file,
							'file_basename'  => $current_basename,
							'mime_type'      => $current_mime_type,
						'content_hashes' => $content_hashes,
					),
					'after_suggestion' => array(
						'relative_file' => $target_relative_file,
							'file_basename' => $target_file_name,
							'mime_type'     => $current_mime_type,
						),
						'reference_repair' => $reference_repair,
					),
					'reference_repair'    => $reference_repair,
					'write_actions'       => $write_actions,
					'risk'                => array(
						'level'  => ! empty( $reference_repair['manual_review'] ) ? 'high' : 'medium',
						'reason' => $action_count > 1 ? 'Renaming an attachment main file changes its public URL; exact post-content references are patched in the same governed proposal.' : 'Renaming an attachment main file changes its public URL and requires Core approval.',
					),
				),
			array(
				'source'         => 'local_media_rename_plan',
				'execution_mode' => 'deterministic',
				'readonly'       => true,
				'plan_only'      => true,
			),
			'Media rename plan built.'
		);
	}

	/**
	 * Normalizes a reviewed target basename for media rename plans.
	 *
	 * @param string $target_file_name Raw target file name.
	 * @param string $current_extension Current file extension.
	 * @return string|\WP_Error
	 */
	private function normalize_media_rename_target_file_name( $target_file_name, $current_extension ) {
		$raw_target = trim( (string) $target_file_name );
		if ( '' === $raw_target || basename( str_replace( '\\', '/', $raw_target ) ) !== $raw_target ) {
			return new \WP_Error( 'npcink_abilities_toolkit_target_file_name_invalid', __( 'Target file name must be a file basename, not a path.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$target_file_name = $this->sanitize_file_name_value( $raw_target );
		if ( '' === $target_file_name ) {
			return new \WP_Error( 'npcink_abilities_toolkit_target_file_name_invalid', __( 'Target file name is invalid after sanitization.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		$current_extension = strtolower( sanitize_key( (string) $current_extension ) );
		if ( '' === pathinfo( $target_file_name, PATHINFO_EXTENSION ) && '' !== $current_extension ) {
			$target_file_name .= '.' . $current_extension;
		}

		return $target_file_name;
	}

	/**
	 * Normalizes MD5 values for read-only rename plans.
	 *
	 * @param string $value Raw checksum value.
	 * @return string
	 */
	private function normalize_media_md5_value( $value ) {
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		if ( 0 === strpos( $value, 'md5:' ) ) {
			$value = substr( $value, 4 );
		}

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
	}

	/**
	 * Normalizes SHA-256 values for read-only rename plans.
	 *
	 * @param string $value Raw checksum value.
	 * @return string
	 */
	private function normalize_media_sha256_value( $value ) {
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		if ( 0 === strpos( $value, 'sha256:' ) ) {
			$value = substr( $value, 7 );
		}

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Normalizes reviewed media metadata input for a media optimization plan.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param mixed $input Raw metadata input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_media_optimization_metadata_input( $attachment_id, $input ) {
		$input = is_array( $input ) ? $input : array();
		$metadata = array( 'attachment_id' => $this->absint_value( $attachment_id ) );
		foreach ( array( 'title', 'alt', 'caption', 'description', 'source_page_url', 'photographer_name', 'attribution_text', 'copyright_notice' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = 'source_page_url' === $field
				? $this->esc_url_value( (string) $input[ $field ] )
				: $this->sanitize_metadata_text( (string) $input[ $field ] );
			if ( in_array( $field, array( 'title', 'alt', 'photographer_name' ), true ) ) {
				$value = sanitize_text_field( (string) $input[ $field ] );
			}
			if ( '' !== $value ) {
				$metadata[ $field ] = $value;
			}
		}

		if ( array_key_exists( 'source_type', $input ) ) {
			$source_type = $this->normalize_media_source_type( $input['source_type'] ?? '' );
			if ( '' !== $source_type ) {
				$metadata['source_type'] = $source_type;
			}
		}

		if ( count( $metadata ) <= 1 ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_media_optimization_metadata_required',
				__( 'Media optimization plans require at least one reviewed media metadata field.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		return $metadata;
	}

	/**
	 * Normalizes Cloud derivative artifact evidence for a media optimization plan.
	 *
	 * @param mixed $artifact Raw artifact.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_media_optimization_derivative_artifact( $artifact ) {
		return Cloud_Derivative_Artifact::normalize( $artifact );
		}

		/**
		 * Normalizes an optional reviewed derivative file name for a media optimization plan.
		 *
		 * @param string $file_name Raw reviewed file basename.
		 * @return string|\WP_Error
		 */
		private function normalize_media_optimization_file_name( $file_name ) {
			$raw_file_name = trim( (string) $file_name );
			if ( '' === $raw_file_name ) {
				return '';
			}
			if ( false !== strpos( $raw_file_name, '/' ) || false !== strpos( $raw_file_name, '\\' ) ) {
				return new \WP_Error( 'npcink_abilities_toolkit_file_name_invalid', __( 'Media optimization file_name must be a file basename, not a path.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}
			$normalized = $this->sanitize_file_name_value( $raw_file_name );
			if ( '' === $normalized ) {
				return new \WP_Error( 'npcink_abilities_toolkit_file_name_invalid', __( 'Media optimization file_name is invalid after sanitization.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
			}

			return $normalized;
		}

	/**
	 * Normalizes an optional crop plan for Cloud derivative requests.
	 *
	 * @param mixed $crop Raw crop input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_media_derivative_crop( $crop ) {
		if ( ! is_array( $crop ) || empty( $crop ) ) {
			return array();
		}

		$type = sanitize_key( (string) ( $crop['type'] ?? 'aspect_ratio' ) );
		if ( 'aspect_ratio' !== $type ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_media_derivative_crop_type_invalid',
				__( 'Only aspect-ratio crop plans are supported for Cloud media derivatives.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$aspect_ratio = trim( sanitize_text_field( (string) ( $crop['aspect_ratio'] ?? '16:9' ) ) );
		if ( 1 !== preg_match( '/^([1-9][0-9]{0,2}):([1-9][0-9]{0,2})$/', $aspect_ratio, $matches ) ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_media_derivative_crop_ratio_invalid',
				__( 'Media derivative crop aspect_ratio must use a W:H ratio such as 16:9 or 1:1.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}
		if ( (int) $matches[1] > 100 || (int) $matches[2] > 100 ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_media_derivative_crop_ratio_invalid',
				__( 'Media derivative crop aspect_ratio values must be between 1 and 100.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$position = sanitize_key( (string) ( $crop['position'] ?? 'center' ) );
		if ( ! in_array( $position, array( 'top_left', 'top', 'top_right', 'left', 'center', 'right', 'bottom_left', 'bottom', 'bottom_right' ), true ) ) {
			$position = 'center';
		}

		return array(
			'type'         => 'aspect_ratio',
			'aspect_ratio' => $aspect_ratio,
			'position'     => $position,
		);
	}

	/**
	 * Normalizes an optional watermark plan for Cloud derivative requests.
	 *
	 * @param mixed $watermark Raw watermark input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_media_derivative_watermark( $watermark ) {
		if ( ! is_array( $watermark ) || empty( $watermark ) ) {
			return array();
		}

		$type = sanitize_key( (string) ( $watermark['type'] ?? 'image' ) );
		if ( ! in_array( $type, array( 'image', 'text' ), true ) ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_media_derivative_watermark_type_invalid',
				__( 'Only image and text watermarks are supported for Cloud media derivatives.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$position = sanitize_key( (string) ( $watermark['position'] ?? 'bottom_right' ) );
		if ( ! in_array( $position, array( 'top_left', 'top_right', 'bottom_left', 'bottom_right', 'center' ), true ) ) {
			$position = 'bottom_right';
		}

		$opacity = is_numeric( $watermark['opacity'] ?? null ) ? (float) $watermark['opacity'] : 0.75;
		$opacity = max( 0.0, min( 1.0, $opacity ) );
		$margin_px = max( 0, min( 1000, $this->absint_value( $watermark['margin_px'] ?? 24 ) ) );

		if ( 'image' === $type ) {
			$artifact_id = sanitize_text_field( (string) ( $watermark['artifact_id'] ?? '' ) );
			$normalized = array(
				'type'          => 'image',
				'position'      => $position,
				'opacity'       => round( $opacity, 3 ),
				'scale_percent' => max( 1, min( 100, $this->absint_value( $watermark['scale_percent'] ?? 18 ) ) ),
				'margin_px'     => $margin_px,
			);
			if ( '' !== $artifact_id ) {
				$normalized['artifact_id'] = $artifact_id;
			}

			return $normalized;
		}

		$text = $this->normalize_plain_text( $watermark['text'] ?? 'AI' );
		if ( '' === $text ) {
			$text = 'AI';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 64 );
		} else {
			$text = substr( $text, 0, 64 );
		}

		return array(
			'type'       => 'text',
			'text'       => $text,
			'position'   => $position,
			'opacity'    => round( $opacity, 3 ),
			'font_size'  => max( 8, min( 256, $this->absint_value( $watermark['font_size'] ?? 48 ) ) ),
			'color'      => $this->normalize_media_derivative_watermark_color( $watermark['color'] ?? '#FFFFFF', '#FFFFFF' ),
			'background' => $this->normalize_media_derivative_watermark_color( $watermark['background'] ?? 'rgba(0,0,0,0.35)', 'rgba(0,0,0,0.35)' ),
			'margin_px'  => $margin_px,
		);
	}

	/**
	 * Normalizes a bounded color token for Cloud text watermark rendering.
	 *
	 * @param mixed  $value Raw color.
	 * @param string $default Default color.
	 * @return string
	 */
	private function normalize_media_derivative_watermark_color( $value, $default ) {
		$color = trim( sanitize_text_field( (string) $value ) );
		if ( 'transparent' === strtolower( $color ) ) {
			return 'transparent';
		}
		if ( 1 === preg_match( '/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color ) ) {
			return strtoupper( $color );
		}
		if ( 1 === preg_match( '/^rgba?\(\\s*(\\d{1,3})\\s*,\\s*(\\d{1,3})\\s*,\\s*(\\d{1,3})(?:\\s*,\\s*(0|1|0?\\.\\d+))?\\s*\\)$/', $color, $matches ) ) {
			$r = max( 0, min( 255, (int) $matches[1] ) );
			$g = max( 0, min( 255, (int) $matches[2] ) );
			$b = max( 0, min( 255, (int) $matches[3] ) );
			if ( isset( $matches[4] ) && '' !== $matches[4] ) {
				$a = max( 0.0, min( 1.0, (float) $matches[4] ) );
				return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . rtrim( rtrim( sprintf( '%.3F', $a ), '0' ), '.' ) . ')';
			}

			return 'rgb(' . $r . ',' . $g . ',' . $b . ')';
		}

		return $default;
	}

	/**
	 * Normalizes source format exclusion input for derivative batch planning.
	 *
	 * @param mixed $formats Raw format list.
	 * @return string[]
	 */
	private function normalize_media_derivative_format_list( $formats ) {
		if ( ! is_array( $formats ) ) {
			$formats = '' !== (string) $formats ? array( $formats ) : array();
		}

		$allowed = array( 'webp', 'avif', 'jpeg', 'jpg', 'png', 'gif', 'svg', 'ico', 'pdf', 'original' );
		$normalized = array();
		foreach ( $formats as $format ) {
			$format = sanitize_key( (string) $format );
			if ( 'jpg' === $format ) {
				$format = 'jpeg';
			}
			if ( in_array( $format, $allowed, true ) && ! in_array( $format, $normalized, true ) ) {
				$normalized[] = $format;
			}
		}

		return $normalized;
	}

	/**
	 * Queries media attachment ids for a bounded derivative batch plan.
	 *
	 * @param string $mime_type Mime type filter.
	 * @param string $search Search term.
	 * @param string $date_from Date lower bound.
	 * @param string $date_to Date upper bound.
	 * @param int    $per_page Per page.
	 * @param int    $page Page.
	 * @return array<string,mixed>
	 */
	private function query_media_derivative_batch_inventory( $mime_type, $search, $date_from, $date_to, $per_page, $page ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, min( 200, $this->absint_value( $per_page ) ) ),
			'paged'          => max( 1, $this->absint_value( $page ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( '' !== $mime_type ) {
			$args['post_mime_type'] = $mime_type;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( '' !== $date_from || '' !== $date_to ) {
			$args['date_query'] = array();
			if ( '' !== $date_from ) {
				$args['date_query']['after'] = $date_from;
			}
			if ( '' !== $date_to ) {
				$args['date_query']['before'] = $date_to;
			}
			$args['date_query']['inclusive'] = true;
		}

		if ( class_exists( '\WP_Query' ) ) {
			$query = new \WP_Query( $args );
			return array(
				'attachment_ids' => is_array( $query->posts ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $query->posts ) ) : array(),
				'total'          => (int) ( $query->found_posts ?? 0 ),
			);
		}

		$attachments = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			? array_values( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			: array();
		$filtered = array();
		$date_from_ts = '' !== $date_from ? strtotime( $date_from ) : false;
		$date_to_ts = '' !== $date_to ? strtotime( $date_to ) : false;
		foreach ( $attachments as $attachment ) {
			if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
				continue;
			}
			$current_mime = sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) );
			if ( '' !== $mime_type && false === strpos( $current_mime, $mime_type ) ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $attachment->post_title ?? '' ) );
			if ( '' !== $search && false === stripos( $title, $search ) ) {
				continue;
			}
			$post_date = sanitize_text_field( (string) ( $attachment->post_date ?? '' ) );
			$post_ts = '' !== $post_date ? strtotime( $post_date ) : false;
			if ( false !== $date_from_ts && ( false === $post_ts || $post_ts < $date_from_ts ) ) {
				continue;
			}
			if ( false !== $date_to_ts && ( false === $post_ts || $post_ts > $date_to_ts ) ) {
				continue;
			}
			$filtered[] = $this->absint_value( $attachment->ID ?? 0 );
		}

		$offset = ( max( 1, $this->absint_value( $page ) ) - 1 ) * max( 1, $this->absint_value( $per_page ) );
		return array(
			'attachment_ids' => array_slice( array_filter( $filtered ), $offset, max( 1, $this->absint_value( $per_page ) ) ),
			'total'          => count( $filtered ),
		);
	}

	/**
	 * Resolves why a media derivative batch row should be skipped.
	 *
	 * @param array<string,mixed> $inspection Media inspection.
	 * @param array<string,mixed> $policy Batch policy.
	 * @return string
	 */
	private function resolve_media_derivative_batch_skip_reason( array $inspection, array $policy ) {
		$mime_type = sanitize_text_field( (string) ( $inspection['mime_type'] ?? '' ) );
		$source_format = sanitize_key( (string) ( $inspection['source_format'] ?? '' ) );
		$target_format = sanitize_key( (string) ( $policy['target_format'] ?? '' ) );
		$exclude_formats = is_array( $policy['exclude_formats'] ?? null ) ? $policy['exclude_formats'] : array();
		$width = $this->absint_value( $inspection['width'] ?? 0 );
		$height = $this->absint_value( $inspection['height'] ?? 0 );
		$filesize_bytes = $this->absint_value( $inspection['filesize_bytes'] ?? 0 );
		$storage = is_array( $inspection['storage'] ?? null ) ? $inspection['storage'] : array();
		$storage_blocked_reason = sanitize_key( (string) ( $storage['blocked_reason'] ?? '' ) );

		if ( '' !== $storage_blocked_reason ) {
			return $storage_blocked_reason;
		}
		if ( 0 !== strpos( $mime_type, 'image/' ) ) {
			return 'not_image';
		}
		if ( ! in_array( $source_format, array( 'jpeg', 'png', 'webp', 'avif' ), true ) ) {
			return 'unsupported_source_format';
		}
		if ( in_array( $source_format, $exclude_formats, true ) ) {
			return 'source_format_excluded';
		}
		if ( 'original' !== $target_format && $source_format === $target_format ) {
			return 'already_target_format';
		}
		if ( $this->absint_value( $policy['min_width'] ?? 0 ) > 0 && $width < $this->absint_value( $policy['min_width'] ?? 0 ) ) {
			return 'below_min_width';
		}
		if ( $this->absint_value( $policy['min_height'] ?? 0 ) > 0 && $height < $this->absint_value( $policy['min_height'] ?? 0 ) ) {
			return 'below_min_height';
		}
		if ( $this->absint_value( $policy['min_filesize_bytes'] ?? 0 ) > 0 && $filesize_bytes < $this->absint_value( $policy['min_filesize_bytes'] ?? 0 ) ) {
			return 'below_min_filesize';
		}
		if ( $this->absint_value( $policy['max_filesize_bytes'] ?? 0 ) > 0 && $filesize_bytes > $this->absint_value( $policy['max_filesize_bytes'] ?? 0 ) ) {
			return 'above_max_filesize';
		}

		return '';
	}

	/**
	 * Builds one skipped row for derivative batch plans.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $reason Skip reason.
	 * @param array<string,mixed> $inspection Media inspection.
	 * @return array<string,mixed>
	 */
	private function build_media_derivative_batch_skip_row( $attachment_id, $reason, array $inspection ) {
		return array(
			'attachment_id'  => $this->absint_value( $attachment_id ),
			'reason'         => sanitize_key( (string) $reason ),
			'title'          => sanitize_text_field( (string) ( $inspection['title'] ?? '' ) ),
			'mime_type'      => sanitize_text_field( (string) ( $inspection['mime_type'] ?? '' ) ),
			'source_format'  => sanitize_key( (string) ( $inspection['source_format'] ?? '' ) ),
			'width'          => $this->absint_value( $inspection['width'] ?? 0 ),
			'height'         => $this->absint_value( $inspection['height'] ?? 0 ),
			'filesize_bytes' => $this->absint_value( $inspection['filesize_bytes'] ?? 0 ),
			'storage'        => is_array( $inspection['storage'] ?? null ) ? $this->sanitize_media_storage_inspection( $inspection['storage'] ) : array(),
		);
	}

	/**
	 * Builds blocked-item rows from skipped derivative batch rows.
	 *
	 * @param array<int,array<string,mixed>> $skipped Skipped rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_media_derivative_batch_blocked_items( array $skipped ) {
		$blocked_items = array();
		foreach ( $skipped as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$reason = sanitize_key( (string) ( $item['reason'] ?? 'skipped' ) );
			$blocked_items[] = array(
				'attachment_id'        => $this->absint_value( $item['attachment_id'] ?? 0 ),
				'status'               => 'blocked',
				'reason'               => $reason,
				'blocked_reason'       => $reason,
				'operator_next_action' => $this->media_derivative_batch_blocked_next_action( $reason ),
				'title'                => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'mime_type'            => sanitize_text_field( (string) ( $item['mime_type'] ?? '' ) ),
				'source_format'        => sanitize_key( (string) ( $item['source_format'] ?? '' ) ),
				'width'                => $this->absint_value( $item['width'] ?? 0 ),
				'height'               => $this->absint_value( $item['height'] ?? 0 ),
				'filesize_bytes'       => $this->absint_value( $item['filesize_bytes'] ?? 0 ),
				'storage'              => is_array( $item['storage'] ?? null ) ? $this->sanitize_media_storage_inspection( $item['storage'] ) : array(),
			);
		}
		return $blocked_items;
	}

	/**
	 * Returns operator recovery guidance for a blocked derivative batch item.
	 *
	 * @param string $reason Block reason.
	 * @return string
	 */
	private function media_derivative_batch_blocked_next_action( $reason ) {
		switch ( sanitize_key( (string) $reason ) ) {
			case 'already_target_format':
			case 'source_format_excluded':
				return 'Adjust target or excluded formats, then rebuild the plan.';
			case 'below_min_width':
			case 'below_min_height':
			case 'below_min_filesize':
			case 'above_max_filesize':
				return 'Adjust size filters or choose a different media scope.';
			case 'permission_denied':
				return 'Use an account that can edit this attachment or remove it from scope.';
			case 'attachment_not_found':
				return 'Remove the missing attachment from the explicit batch scope.';
			case 'remote_storage_requires_adapter':
				return 'Install or enable a host storage adapter that can read and write this remote media asset, then rebuild the plan.';
			case 'remote_storage_write_requires_adapter':
				return 'Confirm the host storage adapter can write the remote media asset and purge cache, then rebuild the plan.';
			case 'not_image':
			case 'unsupported_source_format':
				return 'Remove unsupported media from scope or choose a supported image attachment.';
			default:
				return 'Review this media item before rebuilding the plan.';
		}
	}

	/**
	 * Builds a read-only fix plan for media inventory issues.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_inventory_fix_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to build media inventory fix plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_ids = is_array( $input['attachment_ids'] ?? null ) ? array_values( array_filter( array_map( array( $this, 'absint_value' ), $input['attachment_ids'] ) ) ) : array();
		$attachment_ids = array_slice( array_values( array_unique( $attachment_ids ) ), 0, 50 );
		if ( empty( $attachment_ids ) ) {
			$mime_type = sanitize_text_field( (string) ( $input['mime_type'] ?? '' ) );
			$search = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
			$per_page = max( 1, min( 50, $this->absint_value( $input['per_page'] ?? 20 ) ) );
			$page = max( 1, $this->absint_value( $input['page'] ?? 1 ) );
			$query_result = $this->query_media_inventory( $mime_type, $search, $per_page, $page );
			$attachment_ids = is_array( $query_result['attachment_ids'] ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $query_result['attachment_ids'] ) ) : array();
		}

		$issue_types = $this->normalize_media_fix_issue_types( $input['issue_types'] ?? array() );
		$max_actions = max( 1, min( 100, $this->absint_value( $input['max_actions'] ?? 50 ) ) );
		$include_delete_candidates = ! empty( $input['include_delete_candidates'] );
		$include_trash_parent_media = ! empty( $input['include_trash_parent_media'] );
		$include_unattached_nonproduction_media = ! empty( $input['include_unattached_nonproduction_media'] );
		$nonproduction_content_patterns = $this->normalize_nonproduction_content_patterns( array() );
		$context = array(
			'article_title'   => sanitize_text_field( (string) ( $input['article_title'] ?? '' ) ),
			'article_excerpt' => $this->sanitize_metadata_text( (string) ( $input['article_excerpt'] ?? '' ) ),
			'focus_keyword'   => sanitize_text_field( (string) ( $input['focus_keyword'] ?? '' ) ),
		);
		$actions = array();
		$preview = array();
		$manual_review = array();
		$skipped_destructive_candidates = array();
		$issue_counts = array();

		foreach ( $attachment_ids as $attachment_id ) {
			if ( count( $actions ) >= $max_actions ) {
				break;
			}
			$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
			if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}

			$row = $this->build_media_inventory_health_row( $attachment_id );
			$parent_id = $this->absint_value( $attachment->post_parent ?? 0 );
			if ( 0 === $parent_id ) {
				$row['issues'][] = 'possibly_unattached';
			}
			$parent = $parent_id > 0 ? get_post( $parent_id ) : null;
			$parent_status = is_object( $parent ) ? sanitize_key( (string) ( $parent->post_status ?? '' ) ) : '';
			if ( 'trash' === $parent_status ) {
				$row['issues'][] = 'possibly_unattached';
			}
			$row['issues'] = array_values( array_unique( array_map( 'sanitize_key', (array) ( $row['issues'] ?? array() ) ) ) );
			$row['issue_count'] = count( $row['issues'] );
			$row['parent_post_id'] = $parent_id;
			$row['parent_post_status'] = $parent_status;
			$row['parent_post_title'] = is_object( $parent ) ? sanitize_text_field( (string) ( $parent->post_title ?? '' ) ) : '';
			$row['post_name'] = sanitize_title( (string) ( $attachment->post_name ?? '' ) );

			$post_plan = $this->build_media_inventory_fix_plan_rows( $attachment_id, $row, $issue_types, $context, $max_actions - count( $actions ), $include_delete_candidates, $include_trash_parent_media, $include_unattached_nonproduction_media, $nonproduction_content_patterns );
			foreach ( (array) ( $post_plan['issues'] ?? array() ) as $issue ) {
				$issue = sanitize_key( (string) $issue );
				if ( '' !== $issue ) {
					$issue_counts[ $issue ] = (int) ( $issue_counts[ $issue ] ?? 0 ) + 1;
				}
			}
			$actions = array_merge( $actions, (array) ( $post_plan['actions'] ?? array() ) );
			if ( ! empty( $post_plan['preview'] ) ) {
				$preview[] = $post_plan['preview'];
			}
			$manual_review = array_merge( $manual_review, (array) ( $post_plan['manual_review'] ?? array() ) );
			$skipped_destructive_candidates = array_merge( $skipped_destructive_candidates, (array) ( $post_plan['skipped_destructive_candidates'] ?? array() ) );
		}

		arsort( $issue_counts );

		return $this->build_analysis_success_response(
			array(
				'batch_id'                       => 'media_inventory_fix_' . gmdate( 'Ymd_His' ),
				'issue_types'                    => $issue_types,
				'attachment_ids'                 => $attachment_ids,
				'requires_approval'              => true,
				'commit_execution'               => false,
				'dry_run'                        => true,
				'action_count'                   => count( $actions ),
				'issue_counts'                   => $issue_counts,
				'write_actions'                  => $actions,
				'preview'                        => $preview,
				'manual_review'                  => $manual_review,
				'skipped_destructive_candidates' => $skipped_destructive_candidates,
				'risk'                           => array(
					'level'  => $include_delete_candidates ? 'high' : 'medium',
					'reason' => $include_delete_candidates ? 'Metadata updates are reversible but media deletion is permanent and must be approved by the host.' : 'Default plan only proposes metadata updates and records permanent delete candidates without mapping them to write actions.',
				),
			),
			array(
				'source'         => 'local_media_inventory_fix_plan',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
			),
			'Media inventory fix plan built.'
		);
	}

	/**
	 * Builds a governed content patch plan for hard-coded media URLs.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_reference_repair_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to build media reference repair plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$replacement = $this->media_reference_repair_replacement_context( $attachment_id, $input );
		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}

		$max_posts = max( 1, min( 50, $this->absint_value( $input['max_posts'] ?? 20 ) ) );
		$max_replacements = max( 1, min( 20, $this->absint_value( $input['max_replacements_per_post'] ?? 20 ) ) );
		$ref_pairs = $this->media_reference_repair_ref_pairs( $replacement );
		if ( empty( $ref_pairs ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_reference_repair_urls_missing', __( 'Old and new media URLs are required for reference repair planning.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$actions = array();
		$preview = array();
		$manual_review = array();
		$scanned_count = 0;
		foreach ( $this->media_reference_repair_candidate_posts( $max_posts * 3 ) as $post ) {
			if ( count( $actions ) >= $max_posts ) {
				break;
			}
			if ( ! is_object( $post ) || 'attachment' === sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			if ( ! in_array( sanitize_key( (string) ( $post->post_status ?? '' ) ), array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
				continue;
			}
			++$scanned_count;
			$post_id = $this->absint_value( $post->ID ?? 0 );
			$content = (string) ( $post->post_content ?? '' );
			if ( '' === $content || $post_id <= 0 ) {
				continue;
			}

			$operations = array();
			$matches = array();
			foreach ( $ref_pairs as $pair ) {
				$old_ref = (string) ( $pair['old'] ?? '' );
				$new_ref = (string) ( $pair['new'] ?? '' );
				if ( '' === $old_ref || '' === $new_ref || false === strpos( $content, $old_ref ) ) {
					continue;
				}
				$count = substr_count( $content, $old_ref );
				$operations[] = array(
					'op'      => 'replace',
					'find'    => $old_ref,
					'replace' => $new_ref,
					'limit'   => min( $max_replacements, max( 1, $count ) ),
				);
				$matches[] = array(
					'old'   => $old_ref,
					'new'   => $new_ref,
					'count' => $count,
				);
			}

			$sized_variant_matches = $this->media_reference_repair_sized_variant_matches( $content, $replacement );
			if ( ! empty( $sized_variant_matches ) ) {
				$manual_review[] = array(
					'post_id' => $post_id,
					'title'   => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
					'reason'  => 'old_sized_variant_reference_detected',
					'matches' => $sized_variant_matches,
				);
			}

			if ( empty( $operations ) ) {
				continue;
			}
			$actions[] = array(
				'action_id'         => 'repair_media_reference_' . $post_id,
				'target_ability_id' => 'npcink-abilities-toolkit/patch-post-content',
				'input'             => array(
					'post_id'    => $post_id,
					'operations' => $operations,
					'dry_run'    => true,
					'commit'     => false,
				),
				'requires_approval' => true,
				'commit_execution'  => false,
				'risk'              => 'medium',
				'reason'            => __( 'Replace hard-coded media URLs in post content after media file adoption.', 'npcink-abilities-toolkit' ),
			);
			$preview[] = array(
				'post_id'     => $post_id,
				'post_type'   => sanitize_key( (string) ( $post->post_type ?? '' ) ),
				'post_status' => sanitize_key( (string) ( $post->post_status ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
				'matches'     => $matches,
			);
		}

		return $this->build_analysis_success_response(
			array(
				'batch_id'          => 'media_reference_repair_' . gmdate( 'Ymd_His' ),
				'plan_kind'         => 'media_reference_repair',
				'attachment_id'     => $attachment_id,
				'replacement_id'    => sanitize_text_field( (string) ( $replacement['replacement_id'] ?? '' ) ),
				'old'               => is_array( $replacement['old'] ?? null ) ? $replacement['old'] : array(),
				'new'               => is_array( $replacement['new'] ?? null ) ? $replacement['new'] : array(),
				'requires_approval' => true,
				'commit_execution'  => false,
				'dry_run'           => true,
				'action_count'      => count( $actions ),
				'scanned_count'     => $scanned_count,
				'write_actions'     => $actions,
				'preview'           => $preview,
				'manual_review'     => $manual_review,
				'risk'              => array(
					'level'  => ! empty( $manual_review ) ? 'high' : 'medium',
					'reason' => ! empty( $manual_review ) ? 'Exact hard-coded URLs can be patched, but sized image variants need human review.' : 'Only exact hard-coded media URLs and uploads-relative paths are patched.',
				),
			),
			array(
				'source'         => 'local_media_reference_repair_plan',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
			),
			'Media reference repair plan built.'
		);
	}

	/**
	 * Builds a review-only artifact for already retrieved image candidates.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_image_candidate_review_artifact( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to review image candidates.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$raw_candidates = is_array( $input['image_candidates'] ?? null ) ? $input['image_candidates'] : array();
		$candidate_limit = max( 1, min( 12, $this->absint_value( $input['candidate_limit'] ?? 8 ) ) );
		$target_field = sanitize_key( (string) ( $input['target_field'] ?? 'featured_image' ) );
		if ( ! in_array( $target_field, array( 'featured_image', 'paragraph_image', 'inline_image', 'setting_image' ), true ) ) {
			$target_field = 'featured_image';
		}

		$items = array();
		foreach ( array_slice( $raw_candidates, 0, $candidate_limit ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$normalized = $this->normalize_image_candidate_adoption_contract( $candidate );
			$image_url = $this->image_candidate_first_non_empty_url(
				array(
					$normalized['download_url'] ?? '',
					$normalized['regular_url'] ?? '',
					$normalized['small_url'] ?? '',
					$normalized['url'] ?? '',
				)
			);
			$title = sanitize_text_field( (string) ( $normalized['title'] ?? $normalized['alt_description'] ?? $normalized['description'] ?? $normalized['prompt'] ?? '' ) );
			if ( '' === $title && '' === $image_url ) {
				continue;
			}
			$items[] = $normalized;
		}

		$recommendation_candidates = $this->build_image_candidate_recommendation_projections( $items, $target_field );

		$data = array(
			'artifact_type'              => 'image_candidate_review.v1',
			'candidate_type'             => 'image_candidates',
			'candidate_contract'         => 'image_candidate.v1',
			'projection_contract'        => 'recommendation_candidate.v1',
			'write_posture'              => 'suggestion_only',
			'final_write_path'           => 'core_proposal_required',
			'direct_wordpress_write'     => false,
			'source_ability_id'          => 'npcink-abilities-toolkit/build-image-candidate-review-artifact',
			'adoption_plan_ability_id'   => 'npcink-abilities-toolkit/build-image-candidate-adoption-plan',
			'items'                      => array_values( $items ),
			'recommendation_candidates'  => $recommendation_candidates,
			'review_policy'              => array(
				'image_retrieval_owner'      => 'host_or_provider_surface',
				'provider_runtime_included'  => false,
				'download_or_import_included' => false,
				'automatic_media_import'     => false,
				'license_review_required'    => true,
				'attribution_preserved'      => true,
			),
			'handoff'                    => array(
				'plan_ability_id'        => 'npcink-abilities-toolkit/build-image-candidate-adoption-plan',
				'final_write_path'       => 'core_proposal_required',
				'direct_wordpress_write' => false,
				'blocked_actions'        => array(
					'no_provider_search_in_toolkit',
					'no_ai_image_generation_in_toolkit',
					'no_media_import_in_review_artifact',
					'no_featured_image_write_in_review_artifact',
				),
			),
			'summary'                    => array(
				'candidate_count'                => count( $items ),
				'recommendation_candidate_count' => count( $recommendation_candidates ),
				'target_field'                   => $target_field,
			),
		);

		return $this->build_analysis_success_response(
			$data,
			array(
				'source'         => 'local_image_candidate_review_artifact',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
				'readonly'       => true,
			),
			'Image candidate review artifact built.'
		);
	}

	/**
	 * Builds one proposal-ready plan for adopting a reviewed image candidate.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_image_candidate_adoption_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to build image candidate adoption plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$raw_candidate = $input['image_candidate'] ?? ( $input['candidate'] ?? array() );
		if ( is_string( $raw_candidate ) ) {
			$decoded = json_decode( $raw_candidate, true );
			$raw_candidate = is_array( $decoded ) ? $decoded : array();
		}
		$candidate = is_array( $raw_candidate ) ? $raw_candidate : array();
		if ( empty( $candidate ) ) {
			$direct_url = $this->image_candidate_first_non_empty_url(
				array(
					$input['download_url'] ?? '',
					$input['image_url'] ?? '',
					$input['url'] ?? '',
				)
			);
			if ( '' !== $direct_url ) {
				$candidate = array(
					'download_url'          => $direct_url,
					'thumbnail_url'         => $input['thumbnail_url'] ?? '',
					'source_url'            => $input['source_url'] ?? '',
					'source_type'           => $input['source_type'] ?? 'external',
					'provider'              => $input['provider'] ?? 'manual',
					'provider_origin'       => $input['provider_origin'] ?? 'toolkit',
					'title'                 => $input['title'] ?? '',
					'description'           => $input['description'] ?? ( $input['alt'] ?? '' ),
					'alt_description'       => $input['alt'] ?? '',
					'attribution'           => $input['attribution_text'] ?? '',
					'photographer'          => $input['photographer_name'] ?? '',
					'prompt'                => $input['prompt'] ?? '',
					'model'                 => $input['model'] ?? '',
					'license_review_status' => $input['license_review_status'] ?? '',
					'warnings'              => $this->image_candidate_sanitize_string_list( $input['warnings'] ?? array() ),
				);
			}
		}
		if ( empty( $candidate ) ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_image_candidate_required',
				__( 'A selected image URL or image_candidate object is required before building an adoption plan.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$candidate = $this->normalize_image_candidate_adoption_contract( $candidate );
		$image_url = $this->image_candidate_first_non_empty_url(
			array(
				$candidate['download_url'] ?? '',
				$candidate['regular_url'] ?? '',
				$candidate['small_url'] ?? '',
				$candidate['url'] ?? '',
			)
		);
		if ( '' === $image_url ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_image_candidate_url_missing',
				__( 'The selected image candidate must include a download_url, regular_url, small_url, or url.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}
		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $image_url ) ) {
			return new \WP_Error(
				'npcink_abilities_toolkit_image_candidate_url_blocked',
				__( 'The selected image candidate URL is not allowed.', 'npcink-abilities-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$post_id = $this->absint_value( $input['post_id'] ?? 0 );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to attach image candidate media to this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$set_featured_image = $post_id > 0 && ! empty( $input['set_featured_image'] );
		$title             = trim( sanitize_text_field( (string) ( $input['title'] ?? $candidate['title'] ?? $candidate['description'] ?? __( 'Selected image candidate', 'npcink-abilities-toolkit' ) ) ) );
		$alt               = trim( sanitize_textarea_field( (string) ( $input['alt'] ?? $candidate['alt_description'] ?? $candidate['description'] ?? $title ) ) );
		$description       = trim( sanitize_textarea_field( (string) ( $input['description'] ?? $candidate['description'] ?? $alt ) ) );
		$attribution       = trim( sanitize_textarea_field( (string) ( $input['attribution_text'] ?? $candidate['attribution'] ?? '' ) ) );
		$source_type       = sanitize_key( (string) ( $candidate['source_type'] ?? 'external' ) );
		if ( ! in_array( $source_type, array( 'owned', 'ai_generated', 'stock', 'external', 'manual_upload', 'test' ), true ) ) {
			$source_type = 'external';
		}
		if ( 'manual_upload' === $source_type ) {
			$source_type = 'owned';
		}

		$source_url        = $this->esc_url_value( (string) ( $candidate['source_url'] ?? $candidate['html_url'] ?? '' ) );
		$photographer      = sanitize_text_field( (string) ( $candidate['photographer'] ?? $candidate['photographer_name'] ?? '' ) );
		$file_name         = $this->sanitize_file_name_value( (string) ( $input['file_name'] ?? $candidate['file_name'] ?? $candidate['suggested_filename'] ?? '' ) );
		$asset_persistence = is_array( $candidate['asset_persistence'] ?? null )
			? $this->image_candidate_sanitize_payload( $candidate['asset_persistence'] )
			: $this->image_candidate_asset_persistence_policy( $image_url, $candidate );
		$is_temporary_generated_url = 'temporary_provider_url' === (string) ( $asset_persistence['status'] ?? '' );
		$adoption_risk              = $is_temporary_generated_url ? 'high' : 'medium';
		$adoption_notes             = $this->image_candidate_sanitize_string_list( $candidate['warnings'] ?? array() );
		if ( $is_temporary_generated_url ) {
			$adoption_notes[] = __( 'The selected generated image URL may expire before delayed approval. Approve promptly or regenerate before import.', 'npcink-abilities-toolkit' );
		}
		$filename_policy = array(
			'owner'                          => 'wordpress_write_ability_final',
			'proposed_filename'              => $file_name,
			'final_sanitize_unique_required' => true,
			'preserve_attachment_metadata'   => true,
			'source'                         => '' !== $file_name ? 'reviewed_or_candidate_suggestion' : 'wordpress_default',
		);

		$upload_id   = 'upload_image_candidate';
		$metadata_id = 'update_image_candidate_details';
		$upload_input = array(
			'url'               => $image_url,
			'title'             => $title,
			'file_name'         => $file_name,
			'alt'               => $alt,
			'caption'           => $attribution,
			'description'       => $description,
			'source_type'       => $source_type,
			'source_page_url'   => $source_url,
			'photographer_name' => $photographer,
			'attribution_text'  => $attribution,
			'copyright_notice'  => sanitize_text_field( (string) ( $input['copyright_notice'] ?? $candidate['copyright_notice'] ?? '' ) ),
			'dry_run'           => true,
			'commit'            => false,
			'idempotency_key'   => 'image-candidate-upload-' . substr( md5( $image_url . '|' . $post_id ), 0, 12 ),
		);
		if ( $post_id > 0 ) {
			$upload_input['attach_to_post_id'] = $post_id;
		}

		$write_actions = array(
			array(
				'action_id'           => $upload_id,
				'target_ability_id'   => 'npcink-abilities-toolkit/upload-media-from-url',
				'recipe_step'         => 'host_governed_upload_image_candidate',
				'input'               => $upload_input,
				'source_asset_policy' => $asset_persistence,
				'adoption_notes'      => array_values( array_unique( $adoption_notes ) ),
				'risk'                => $adoption_risk,
				'requires_approval'   => true,
				'commit_execution'    => false,
				'proposal_ready'      => true,
				'reason'              => __( 'Import the reviewed image candidate into the media library after Core approval.', 'npcink-abilities-toolkit' ),
			),
			array(
				'action_id'           => $metadata_id,
				'target_ability_id'   => 'npcink-abilities-toolkit/update-media-details',
				'recipe_step'         => 'host_governed_update_image_candidate_metadata',
				'depends_on'          => array( $upload_id ),
				'input'               => array(
					'attachment_id'     => '$outputs.' . $upload_id . '.attachment_id',
					'title'             => $title,
					'alt'               => $alt,
					'caption'           => $attribution,
					'description'       => $description,
					'source_type'       => $source_type,
					'source_page_url'   => $source_url,
					'photographer_name' => $photographer,
					'attribution_text'  => $attribution,
					'copyright_notice'  => sanitize_text_field( (string) ( $input['copyright_notice'] ?? $candidate['copyright_notice'] ?? '' ) ),
					'dry_run'           => true,
					'commit'            => false,
					'idempotency_key'   => 'image-candidate-details-' . substr( md5( $image_url . '|' . $post_id ), 0, 12 ),
				),
				'source_asset_policy' => $asset_persistence,
				'adoption_notes'      => array_values( array_unique( $adoption_notes ) ),
				'risk'                => $adoption_risk,
				'requires_approval'   => true,
				'commit_execution'    => false,
				'proposal_ready'      => true,
				'reason'              => __( 'Apply reviewed image candidate metadata after media import.', 'npcink-abilities-toolkit' ),
			),
		);

		if ( $set_featured_image ) {
			$write_actions[] = array(
				'action_id'         => 'set_image_candidate_featured_image',
				'target_ability_id' => 'npcink-abilities-toolkit/set-post-featured-image',
				'recipe_step'       => 'host_governed_set_image_candidate_featured_image',
				'depends_on'        => array( $upload_id ),
				'input'             => array(
					'post_id'         => $post_id,
					'attachment_id'   => '$outputs.' . $upload_id . '.attachment_id',
					'dry_run'         => true,
					'commit'          => false,
					'idempotency_key' => 'image-candidate-featured-' . substr( md5( $image_url . '|' . $post_id ), 0, 12 ),
				),
				'risk'              => 'medium',
				'requires_approval' => true,
				'commit_execution'  => false,
				'proposal_ready'    => true,
				'reason'            => __( 'Set the imported image candidate as the post featured image after Core approval.', 'npcink-abilities-toolkit' ),
			);
		}

		$data = array(
			'artifact_type'              => 'image_candidate_adoption_plan',
			'composition_role'           => 'core_image_candidate_adoption_plan',
			'version'                    => 1,
			'candidate_contract_version' => 'image_candidate.v1',
			'source_recipe_id'           => 'image_candidate_adoption_v1',
			'source_recipe_ref'          => 'workflow/image_candidate_adoption',
			'source_recipe_provider'     => 'npcink-abilities-toolkit',
			'recipe_execution'           => 'local_operator_orchestration',
			'write_posture'              => 'core_proposal_handoff',
			'direct_wordpress_write'     => false,
			'proposed_filename'          => $file_name,
			'filename_policy'            => $filename_policy,
			'source_asset_policy'        => $asset_persistence,
			'adoption_notes'             => array_values( array_unique( $adoption_notes ) ),
			'batch_id'                   => 'image_candidate_adoption_' . substr( md5( $image_url . '|' . $post_id . '|' . wp_json_encode( $write_actions ) ), 0, 12 ),
			'requires_approval'          => true,
			'dry_run'                    => true,
			'commit_execution'           => false,
			'proposal_mode'              => 'batch',
			'batch_approval'             => true,
			'action_count'               => count( $write_actions ),
			'selected_image_candidate'   => $this->image_candidate_sanitize_payload( $candidate ),
			'preview'                    => array(
				array(
					'action_id'           => $upload_id,
					'image_url'           => $image_url,
					'thumbnail_url'       => $this->esc_url_value( (string) ( $candidate['thumbnail_url'] ?? $image_url ) ),
					'source_type'         => $source_type,
					'provider'            => sanitize_key( (string) ( $candidate['provider'] ?? 'external' ) ),
					'provider_origin'     => sanitize_key( (string) ( $candidate['provider_origin'] ?? 'toolkit' ) ),
					'proposed_filename'   => $file_name,
					'filename_policy'     => $filename_policy,
					'post_id'             => $post_id,
					'set_featured_image'  => $set_featured_image,
					'attribution'         => $attribution,
					'source_asset_policy' => $asset_persistence,
				),
			),
			'write_actions'              => $write_actions,
			'handoff'                    => array(
				'plan_ability_id'        => 'npcink-abilities-toolkit/build-image-candidate-adoption-plan',
				'recipe_id'              => 'image_candidate_adoption_v1',
				'recipe_ref'             => 'workflow/image_candidate_adoption',
				'core_route'             => '/wp-json/npcink-governance-core/v1/proposals/from-plan',
				'final_write_path'       => 'core_proposal_required',
				'direct_wordpress_write' => false,
			),
		);

		return $this->build_analysis_success_response(
			$data,
			array(
				'source'         => 'local_image_candidate_adoption_plan',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
				'readonly'       => true,
			),
			'Image candidate adoption plan built.'
		);
	}

	/**
	 * Normalizes one reviewed image candidate into the shared image_candidate.v1 shape.
	 *
	 * @param array<string,mixed> $candidate Raw candidate.
	 * @return array<string,mixed>
	 */
	private function normalize_image_candidate_adoption_contract( array $candidate ): array {
		$provider = sanitize_key( (string) ( $candidate['provider'] ?? 'external' ) );
		$source_type = sanitize_key( (string) ( $candidate['source_type'] ?? '' ) );
		if ( '' === $source_type ) {
			if ( 'ai_generated' === $provider ) {
				$source_type = 'ai_generated';
			} elseif ( in_array( $provider, array( 'unsplash', 'pixabay', 'pexels' ), true ) ) {
				$source_type = 'stock';
			} else {
				$source_type = 'external';
			}
		}

		$download_url = $this->image_candidate_first_non_empty_url(
			array(
				$candidate['download_url'] ?? '',
				$candidate['regular_url'] ?? '',
				$candidate['url'] ?? '',
				$candidate['image_url'] ?? '',
				$candidate['generated_image_url'] ?? '',
				$candidate['output_url'] ?? '',
				$candidate['small_url'] ?? '',
				$candidate['urls']['regular'] ?? '',
				$candidate['urls']['full'] ?? '',
				$candidate['src']['large'] ?? '',
				$candidate['src']['original'] ?? '',
			)
		);
		$thumbnail_url = $this->image_candidate_first_non_empty_url(
			array(
				$candidate['thumbnail_url'] ?? '',
				$candidate['thumb_url'] ?? '',
				$candidate['small_url'] ?? '',
				$candidate['urls']['small'] ?? '',
				$candidate['urls']['thumb'] ?? '',
				$candidate['src']['medium'] ?? '',
				$candidate['src']['tiny'] ?? '',
				$download_url,
			)
		);
		$source_url = $this->esc_url_value( (string) ( $candidate['source_url'] ?? $candidate['html_url'] ?? $candidate['links']['html'] ?? $candidate['url'] ?? '' ) );
		$prompt = trim( sanitize_textarea_field( (string) ( $candidate['prompt'] ?? $candidate['generation_prompt'] ?? '' ) ) );
		$model = sanitize_text_field( (string) ( $candidate['model'] ?? $candidate['generation_model'] ?? '' ) );
		$license_review_status = $this->normalize_image_candidate_license_review_status( (string) ( $candidate['license_review_status'] ?? '' ), $source_type );
		$provider_origin = sanitize_key( (string) ( $candidate['provider_origin'] ?? 'toolkit' ) );
		$warnings = $this->image_candidate_sanitize_string_list( $candidate['warnings'] ?? array() );
		$match_reason = sanitize_textarea_field( (string) ( $candidate['match_reason'] ?? $candidate['reason'] ?? $candidate['recommendation_reason'] ?? '' ) );
		$match_score = is_numeric( $candidate['match_score'] ?? null ) ? (float) $candidate['match_score'] : null;
		$recommended_use = sanitize_key( (string) ( $candidate['recommended_use'] ?? $candidate['image_use'] ?? $candidate['best_use'] ?? '' ) );
		if ( ! in_array( $recommended_use, array( 'featured_image', 'paragraph_image', 'inline_image', 'setting_image', 'not_recommended' ), true ) ) {
			$recommended_use = '';
		}
		$visual_keywords = $this->image_candidate_sanitize_string_list( $candidate['visual_keywords'] ?? $candidate['keywords'] ?? array() );
		$quality_tags = $this->image_candidate_sanitize_string_list( $candidate['quality_tags'] ?? $candidate['match_tags'] ?? array() );
		$risk_flags = $this->image_candidate_sanitize_string_list( $candidate['risk_flags'] ?? $candidate['review_flags'] ?? array() );
		$seo_suggestions = is_array( $candidate['seo_suggestions'] ?? null )
			? $this->image_candidate_sanitize_payload( $candidate['seo_suggestions'] )
			: ( is_array( $candidate['media_seo'] ?? null ) ? $this->image_candidate_sanitize_payload( $candidate['media_seo'] ) : array() );
		$asset_persistence = is_array( $candidate['asset_persistence'] ?? null )
			? $this->image_candidate_sanitize_payload( $candidate['asset_persistence'] )
			: array();
		$file_name = $this->sanitize_file_name_value( (string) ( $candidate['file_name'] ?? '' ) );
		$suggested_filename = $this->sanitize_file_name_value( (string) ( $candidate['suggested_filename'] ?? $file_name ) );
		if ( '' === $file_name && '' !== $suggested_filename ) {
			$file_name = $suggested_filename;
		}
		$filename_basis = is_array( $candidate['filename_basis'] ?? null )
			? $this->image_candidate_sanitize_payload( $candidate['filename_basis'] )
			: array(
				'owner'                          => 'wordpress_write_ability_final',
				'strategy'                       => 'candidate_suggested_filename',
				'final_sanitize_unique_required' => true,
			);

		$candidate['contract_version']              = 'image_candidate.v1';
		$candidate['source_type']                   = $source_type;
		$candidate['provider']                      = $provider;
		$candidate['provider_origin']               = '' !== $provider_origin ? $provider_origin : 'toolkit';
		$candidate['download_url']                  = $download_url;
		$candidate['thumbnail_url']                 = $thumbnail_url;
		$candidate['source_url']                    = $source_url;
		$candidate['regular_url']                   = $this->esc_url_value( (string) ( $candidate['regular_url'] ?? $candidate['urls']['regular'] ?? $download_url ) );
		$candidate['small_url']                     = $this->esc_url_value( (string) ( $candidate['small_url'] ?? $candidate['urls']['small'] ?? $thumbnail_url ) );
		$candidate['html_url']                      = $this->esc_url_value( (string) ( $candidate['html_url'] ?? $candidate['links']['html'] ?? $source_url ) );
		$candidate['download_location']             = $this->esc_url_value( (string) ( $candidate['download_location'] ?? $candidate['links']['download_location'] ?? '' ) );
		$candidate['photographer']                  = sanitize_text_field( (string) ( $candidate['photographer'] ?? $candidate['user']['name'] ?? '' ) );
		$candidate['photographer_url']              = $this->esc_url_value( (string) ( $candidate['photographer_url'] ?? $candidate['user']['links']['html'] ?? '' ) );
		$candidate['prompt']                        = $prompt;
		$candidate['model']                         = $model;
		$candidate['license_review_status']         = $license_review_status;
		$candidate['requires_human_license_review'] = 'not_required' !== $license_review_status;
		$candidate['warnings']                      = $warnings;
		$candidate['match_reason']                  = $match_reason;
		$candidate['match_score']                   = $match_score;
		$candidate['recommended_use']               = $recommended_use;
		$candidate['visual_keywords']               = $visual_keywords;
		$candidate['quality_tags']                  = array_slice( $quality_tags, 0, 6 );
		$candidate['risk_flags']                    = array_slice( $risk_flags, 0, 6 );
		$candidate['seo_suggestions']               = $seo_suggestions;
		if ( array() !== $asset_persistence ) {
			$candidate['asset_persistence'] = $asset_persistence;
		}
		$candidate['file_name']          = $file_name;
		$candidate['suggested_filename'] = '' !== $suggested_filename ? $suggested_filename : $file_name;
		$candidate['filename_basis']     = $filename_basis;
		$candidate['provenance']         = array(
			'provider'            => $provider,
			'provider_origin'     => $candidate['provider_origin'],
			'source_type'         => $source_type,
			'source_url'          => $source_url,
			'download_location'   => $candidate['download_location'],
			'photographer'        => $candidate['photographer'],
			'generation_provider' => sanitize_key( (string) ( $candidate['generation_provider'] ?? $candidate['provider_name'] ?? '' ) ),
			'generation_model'    => $model,
		);

		return $candidate;
	}

	/**
	 * Builds lightweight recommendation projections for image candidate review.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized image candidates.
	 * @param string                         $target_field Target field.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_image_candidate_recommendation_projections( array $items, string $target_field ): array {
		$candidates = array();
		foreach ( array_slice( $items, 0, 12 ) as $index => $item ) {
			$title = sanitize_text_field( (string) ( $item['title'] ?? $item['alt_description'] ?? $item['description'] ?? $item['prompt'] ?? '' ) );
			$url = $this->image_candidate_first_non_empty_url(
				array(
					$item['download_url'] ?? '',
					$item['regular_url'] ?? '',
					$item['small_url'] ?? '',
					$item['url'] ?? '',
				)
			);
			if ( '' === $title && '' === $url ) {
				continue;
			}

			$warnings = array();
			foreach ( array( 'warnings', 'risk_flags', 'quality_tags' ) as $key ) {
				$warnings = array_merge( $warnings, $this->image_candidate_sanitize_string_list( $item[ $key ] ?? array() ) );
			}
			$license_status = sanitize_key( (string) ( $item['license_review_status'] ?? '' ) );
			if ( '' !== $license_status && 'not_required' !== $license_status && 'reviewed' !== $license_status ) {
				$warnings[] = __( 'Image license or source needs human confirmation.', 'npcink-abilities-toolkit' );
			}

			$match_score = is_numeric( $item['match_score'] ?? null ) ? (float) $item['match_score'] : 0.0;
			$quality_score = $match_score > 0
				? ( $match_score <= 1 ? 55 + (int) round( max( 0, min( 1, $match_score ) ) * 35 ) : max( 0, min( 90, (int) round( $match_score ) ) ) )
				: 65;
			if ( ! empty( $warnings ) ) {
				$quality_score = min( $quality_score, 65 );
			}
			if ( '' === $url ) {
				$quality_score = min( $quality_score, 45 );
				$warnings[] = __( 'A usable image URL is missing.', 'npcink-abilities-toolkit' );
			}
			if ( empty( $warnings ) ) {
				$warnings[] = __( 'Keep the full image_candidate.v1 evidence for license, attribution, and adoption-plan review.', 'npcink-abilities-toolkit' );
			}

			$source_ref = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
			if ( '' === $source_ref ) {
				$source_ref = '' !== $url ? $url : 'image_candidate_' . ( $index + 1 );
			}

			$candidates[] = array(
				'id'                   => 'image_candidate_' . ( $index + 1 ),
				'kind'                 => 'image',
				'label'                => '' !== $title ? $title : __( 'Image candidate', 'npcink-abilities-toolkit' ),
				'value'                => '' !== $url ? $url : $title,
				'reason'               => sanitize_text_field( (string) ( $item['match_reason'] ?? $item['reason'] ?? '' ) ),
				'confidence'           => $match_score > 0 && $match_score <= 1 ? $match_score : null,
				'target_field'         => $target_field,
				'action_policy'        => 'core_proposal_required',
				'quality_status'       => $quality_score >= 50 ? 'review' : 'weak',
				'quality_score'        => $quality_score,
				'quality_issues'       => array_values( array_unique( $warnings ) ),
				'evidence_refs'        => array_values(
					array_filter(
						array(
							'' !== (string) ( $item['provider'] ?? '' ) ? 'image_provider:' . sanitize_key( (string) $item['provider'] ) : '',
							'' !== (string) ( $item['source_type'] ?? '' ) ? 'image_source_type:' . sanitize_key( (string) $item['source_type'] ) : '',
						)
					)
				),
				'source_candidate_ref' => $source_ref,
			);
		}

		return $candidates;
	}

	/**
	 * Normalizes image candidate license review status.
	 *
	 * @param string $status Raw status.
	 * @param string $source_type Candidate source type.
	 * @return string
	 */
	private function normalize_image_candidate_license_review_status( string $status, string $source_type ): string {
		$status = sanitize_key( $status );
		if ( in_array( $status, array( 'required', 'reviewed', 'not_required' ), true ) ) {
			return $status;
		}
		if ( in_array( $status, array( 'needs_human_review', 'needs_review', 'human_review_required' ), true ) ) {
			return 'required';
		}
		if ( 'owned' === $source_type ) {
			return 'not_required';
		}
		return 'required';
	}

	/**
	 * Returns the first non-empty sanitized URL.
	 *
	 * @param array<int,mixed> $urls Candidate URLs.
	 * @return string
	 */
	private function image_candidate_first_non_empty_url( array $urls ): string {
		foreach ( $urls as $url ) {
			$clean = $this->esc_url_value( (string) $url );
			if ( '' !== $clean ) {
				return $clean;
			}
		}

		return '';
	}

	/**
	 * Builds source persistence policy for image candidate adoption.
	 *
	 * @param string              $url Candidate URL.
	 * @param array<string,mixed> $candidate Candidate data.
	 * @return array<string,mixed>
	 */
	private function image_candidate_asset_persistence_policy( string $url, array $candidate ): array {
		$expires_at = sanitize_text_field( (string) ( $candidate['expires_at'] ?? $candidate['url_expires_at'] ?? '' ) );
		$is_temporary = $this->is_temporary_image_candidate_url( $url );
		$status = $is_temporary ? 'temporary_provider_url' : 'remote_url';
		if ( '' !== $expires_at ) {
			$status = 'temporary_provider_url';
		}

		return array(
			'status'             => $status,
			'expires_at'         => $expires_at,
			'requires_local_copy' => true,
			'adoption_timing'    => 'temporary_provider_url' === $status ? 'adopt_promptly_or_regenerate' : 'core_import_on_approval',
			'owner'              => 'core_upload_ability_final',
		);
	}

	/**
	 * Returns whether an image candidate URL appears temporary.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_temporary_image_candidate_url( string $url ): bool {
		$url = strtolower( trim( $url ) );
		if ( '' === $url ) {
			return false;
		}
		foreach ( array( 'xai-tmp', '/tmp-', 'tmp-imgen', 'temporary', 'expires=' ) as $needle ) {
			if ( false !== strpos( $url, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitizes a list of text strings.
	 *
	 * @param mixed $value Raw list or newline text.
	 * @return array<int,string>
	 */
	private function image_candidate_sanitize_string_list( $value ): array {
		$items = is_array( $value ) ? $value : array_filter( array_map( 'trim', explode( "\n", (string) $value ) ) );
		return array_values(
			array_filter(
				array_map(
					static fn( $item ): string => sanitize_textarea_field( (string) $item ),
					$items
				),
				static fn( string $item ): bool => '' !== $item
			)
		);
	}

	/**
	 * Sanitizes bounded payload trees for plan evidence.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private function image_candidate_sanitize_payload( $value, int $depth = 0 ) {
		if ( $depth >= 6 ) {
			return is_array( $value ) ? array() : $this->image_candidate_bounded_text( (string) $value, 2000 );
		}

		if ( is_array( $value ) ) {
			$sanitized = array();
			$count = 0;
			foreach ( $value as $key => $child ) {
				if ( $count >= 80 ) {
					break;
				}
				$sanitized[ is_string( $key ) ? sanitize_key( $key ) : $key ] = $this->image_candidate_sanitize_payload( $child, $depth + 1 );
				++$count;
			}
			return $sanitized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return $this->image_candidate_bounded_text( (string) $value, 2000 );
	}

	/**
	 * Sanitizes and truncates one text value.
	 *
	 * @param string $value Raw value.
	 * @param int    $max_chars Max characters.
	 * @return string
	 */
	private function image_candidate_bounded_text( string $value, int $max_chars ): string {
		$value = sanitize_textarea_field( $value );
		$max_chars = max( 1, $max_chars );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $value ) > $max_chars ) {
			return mb_substr( $value, 0, $max_chars );
		}
		if ( strlen( $value ) > $max_chars ) {
			return substr( $value, 0, $max_chars );
		}

		return $value;
	}

	/**
	 * Builds one proposal-ready plan for importing, optimizing, and optionally repairing page media references.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_adoption_enhancement_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to build media adoption enhancement plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_required', __( 'Media URL is required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_blocked', __( 'Media URL is not allowed.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$post_id = $this->absint_value( $input['post_id'] ?? 0 );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to repair media references for this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}
		$attach_to_post_id = $this->absint_value( $input['attach_to_post_id'] ?? $post_id );
		if ( $attach_to_post_id > 0 && ! current_user_can( 'edit_post', $attach_to_post_id ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to attach media to this post.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$file_name = $this->normalize_media_optimization_file_name( (string) ( $input['file_name'] ?? '' ) );
		if ( is_wp_error( $file_name ) ) {
			return $file_name;
		}
		$preferred_format = sanitize_key( (string) ( $input['preferred_format'] ?? 'webp' ) );
		if ( ! in_array( $preferred_format, array( 'webp', 'jpeg', 'png' ), true ) ) {
			$preferred_format = 'webp';
		}
		$target_max_width = max( 320, min( 7680, $this->absint_value( $input['target_max_width'] ?? 1920 ) ) );
		$quality = max( 1, min( 100, $this->absint_value( $input['quality'] ?? 82 ) ) );
		$derivative_suffix = sanitize_key( (string) ( $input['derivative_suffix'] ?? 'optimized' ) );
		if ( '' === $derivative_suffix ) {
			$derivative_suffix = 'optimized';
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$alt = sanitize_text_field( (string) ( $input['alt'] ?? '' ) );
		$caption = sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) );
		$description = sanitize_textarea_field( (string) ( $input['description'] ?? '' ) );
		$source_metadata = $this->media_source_metadata_with_defaults(
			array(
				'source_type'       => $this->normalize_media_source_type( $input['source_type'] ?? 'external', 'external' ),
				'source_page_url'   => $this->esc_url_value( (string) ( $input['source_page_url'] ?? $url ) ),
				'photographer_name' => sanitize_text_field( (string) ( $input['photographer_name'] ?? '' ) ),
				'attribution_text'  => sanitize_textarea_field( (string) ( $input['attribution_text'] ?? '' ) ),
				'copyright_notice'  => sanitize_textarea_field( (string) ( $input['copyright_notice'] ?? '' ) ),
			)
		);

		$batch_seed = wp_json_encode( array( $url, $post_id, $attach_to_post_id, $file_name, $preferred_format, $target_max_width, $quality ) );
		$batch_id = 'media_adoption_enhancement_' . substr( md5( is_string( $batch_seed ) ? $batch_seed : $url ), 0, 12 );
		$upload_action_id = 'upload-media-asset';
		$optimize_action_id = 'optimize-media-asset';

		$upload_input = array(
			'url'               => $url,
			'title'             => $title,
			'file_name'         => $file_name,
			'alt'               => $alt,
			'caption'           => $caption,
			'description'       => $description,
			'source_type'       => $source_metadata['source_type'],
			'source_page_url'   => $source_metadata['source_page_url'],
			'photographer_name' => $source_metadata['photographer_name'],
			'attribution_text'  => $source_metadata['attribution_text'],
			'copyright_notice'  => $source_metadata['copyright_notice'],
			'dry_run'           => true,
			'commit'            => false,
		);
		if ( $attach_to_post_id > 0 ) {
			$upload_input['attach_to_post_id'] = $attach_to_post_id;
		}

		$actions = array(
			array(
				'action_id'         => $upload_action_id,
				'target_ability_id' => 'npcink-abilities-toolkit/upload-media-from-url',
				'depends_on'        => array(),
				'input'             => $upload_input,
				'requires_approval' => true,
				'commit_execution'  => false,
				'proposal_ready'    => true,
				'reason'            => __( 'Import the reviewed remote media asset into the local WordPress media library.', 'npcink-abilities-toolkit' ),
			),
			array(
				'action_id'         => $optimize_action_id,
				'target_ability_id' => 'npcink-abilities-toolkit/optimize-media-asset',
				'depends_on'        => array( $upload_action_id ),
				'input'             => array(
					'attachment_id'     => '$outputs.' . $upload_action_id . '.attachment_id',
					'target_max_width'  => $target_max_width,
					'preferred_format'  => $preferred_format,
					'quality'           => $quality,
					'derivative_suffix' => $derivative_suffix,
					'dry_run'           => true,
					'commit'            => false,
				),
				'requires_approval' => true,
				'commit_execution'  => false,
				'proposal_ready'    => true,
				'reason'            => __( 'Generate an optimized derivative while preserving the imported original media file.', 'npcink-abilities-toolkit' ),
			),
		);

		$manual_review = array();
		$reference_preview = array();
		$old_url = esc_url_raw( (string) ( $input['old_url'] ?? '' ) );
		$include_reference_repair = ! array_key_exists( 'include_reference_repair', $input ) || ! empty( $input['include_reference_repair'] );
		if ( $include_reference_repair && $post_id > 0 && '' !== $old_url ) {
			$post = get_post( $post_id );
			$content = is_object( $post ) ? (string) ( $post->post_content ?? '' ) : '';
			$old_url_count = '' !== $content ? substr_count( $content, $old_url ) : 0;
			if ( $old_url_count > 0 ) {
				$max_replacements = max( 1, min( 20, $this->absint_value( $input['max_replacements_per_post'] ?? 20 ) ) );
				$actions[] = array(
					'action_id'         => 'repair-post-media-reference',
					'target_ability_id' => 'npcink-abilities-toolkit/patch-post-content',
					'depends_on'        => array( $optimize_action_id ),
					'input'             => array(
						'post_id'    => $post_id,
						'operations' => array(
							array(
								'op'      => 'replace',
								'find'    => $old_url,
								'replace' => '$outputs.' . $optimize_action_id . '.derivative_url',
								'limit'   => min( $max_replacements, $old_url_count ),
							),
						),
						'dry_run'    => true,
						'commit'     => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
					'reason'            => __( 'Replace reviewed hard-coded post media URLs with the optimized derivative URL after approval.', 'npcink-abilities-toolkit' ),
				);
				$reference_preview[] = array(
					'post_id'       => $post_id,
					'old_url'       => $old_url,
					'new_url_ref'   => '$outputs.' . $optimize_action_id . '.derivative_url',
					'match_count'   => $old_url_count,
					'planned_limit' => min( $max_replacements, $old_url_count ),
				);
			} else {
				$manual_review[] = array(
					'post_id' => $post_id,
					'reason'  => 'old_url_not_found_in_post_content',
					'old_url' => $old_url,
				);
			}
		}

		$data = array(
			'artifact_type'          => 'media_adoption_enhancement_plan',
			'plan_contract_version'  => '1.0',
			'batch_id'               => $batch_id,
			'proposal_mode'          => 'batch',
			'batch_approval'         => true,
			'requires_approval'      => true,
			'commit_execution'       => false,
			'dry_run'                => true,
			'direct_wordpress_write' => false,
			'action_count'           => count( $actions ),
			'write_actions'          => $actions,
			'media'                  => array(
				'url'               => $url,
				'file_name'         => $file_name,
				'title'             => $title,
				'alt'               => $alt,
				'source_type'       => $source_metadata['source_type'],
				'attach_to_post_id' => $attach_to_post_id,
			),
			'optimization'           => array(
				'preferred_format'  => $preferred_format,
				'target_max_width'  => $target_max_width,
				'quality'           => $quality,
				'derivative_suffix' => $derivative_suffix,
			),
			'reference_repair'       => array(
				'enabled' => $include_reference_repair,
				'post_id' => $post_id,
				'old_url' => $old_url,
				'preview' => $reference_preview,
			),
			'manual_review'          => $manual_review,
			'proposal_ready'         => true,
			'handoff'                => array(
				'plan_ability_id'      => 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
				'preferred_core_route' => 'POST /proposals/from-plan',
			),
			'risk'                   => array(
				'level'  => ! empty( $manual_review ) ? 'medium' : 'low',
				'reason' => ! empty( $manual_review ) ? 'The media can be imported and optimized, but one requested reference repair needs review.' : 'All planned writes are explicit governed media or exact post-content actions.',
			),
		);

		return $this->build_analysis_success_response(
			$data,
			array(
				'source'         => 'local_media_adoption_enhancement_plan',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
				'readonly'       => true,
			),
			'Media adoption enhancement plan built.'
		);
	}

	/**
	 * Builds a governed settings patch plan for hard-coded media URLs.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build_media_settings_reference_repair_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_permission_denied', __( 'You do not have permission to build media settings reference repair plans.', 'npcink-abilities-toolkit' ), array( 'status' => 403 ) );
		}

		$attachment_id = $this->absint_value( $input['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'npcink_abilities_toolkit_attachment_invalid', __( 'Attachment ID is invalid.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$replacement = $this->media_reference_repair_replacement_context( $attachment_id, $input );
		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}

		$ref_pairs = $this->media_reference_repair_ref_pairs( $replacement );
		if ( empty( $ref_pairs ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_reference_repair_urls_missing', __( 'Old and new media URLs are required for settings reference repair planning.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$policy = $this->media_settings_reference_repair_policy( $attachment_id, $replacement, $input );
		$max_settings = max( 1, min( 100, $this->absint_value( $input['max_settings'] ?? 50 ) ) );
		$max_replacements = max( 1, min( 20, $this->absint_value( $input['max_replacements_per_setting'] ?? 20 ) ) );
		$candidates = $this->media_settings_reference_repair_candidates( $input, $max_settings );

		$actions = array();
		$preview = array();
		$manual_review = array();
		$scanned_count = 0;
		foreach ( $candidates as $candidate ) {
			if ( count( $actions ) >= $max_settings ) {
				break;
			}
			$candidate = is_array( $candidate ) ? $candidate : array();
			$target_type = sanitize_key( (string) ( $candidate['target_type'] ?? '' ) );
			$target_name = sanitize_key( (string) ( $candidate['target_name'] ?? '' ) );
			if ( ! in_array( $target_type, array( 'option', 'theme_mod' ), true ) || '' === $target_name ) {
				continue;
			}
			++$scanned_count;
			$value = $candidate['value'] ?? null;
			$serialized_string = is_string( $value ) && $this->media_settings_reference_looks_serialized( $value );
			$haystack = $this->media_settings_reference_value_text( $value );
			if ( '' === $haystack ) {
				continue;
			}

			$operations = array();
			$matches = array();
			foreach ( $ref_pairs as $pair ) {
				$old_ref = (string) ( $pair['old'] ?? '' );
				$new_ref = (string) ( $pair['new'] ?? '' );
				if ( '' === $old_ref || '' === $new_ref || false === strpos( $haystack, $old_ref ) ) {
					continue;
				}
				$count = substr_count( $haystack, $old_ref );
				$operations[] = array(
					'op'      => 'replace',
					'find'    => $old_ref,
					'replace' => $new_ref,
					'limit'   => min( $max_replacements, max( 1, $count ) ),
				);
				$matches[] = array(
					'old'   => $old_ref,
					'new'   => $new_ref,
					'count' => $count,
				);
			}

			$sized_variant_matches = $this->media_reference_repair_sized_variant_matches( $haystack, $replacement );
			if ( ! empty( $sized_variant_matches ) ) {
				$manual_review[] = array(
					'target_type' => $target_type,
					'target_name' => $target_name,
					'reason'      => 'old_sized_variant_reference_detected',
					'matches'     => $sized_variant_matches,
				);
			}
			if ( $serialized_string && ! empty( $matches ) ) {
				$manual_review[] = array(
					'target_type' => $target_type,
					'target_name' => $target_name,
					'reason'      => 'serialized_string_setting_requires_manual_review',
					'matches'     => $matches,
				);
				continue;
			}
			if ( ! empty( $policy['excluded'] ) && ( ! empty( $matches ) || ! empty( $sized_variant_matches ) ) ) {
				$manual_review[] = array(
					'target_type' => $target_type,
					'target_name' => $target_name,
					'reason'      => (string) ( $policy['reason'] ?? 'source_media_excluded_by_policy' ),
					'policy'      => $policy,
					'matches'     => $matches,
				);
				continue;
			}
			if ( empty( $operations ) ) {
				continue;
			}

			$actions[] = array(
				'action_id'         => 'repair_media_setting_reference_' . $target_type . '_' . md5( $target_name ),
				'target_ability_id' => 'npcink-abilities-toolkit/patch-setting-value',
				'input'             => array(
					'target_type' => $target_type,
					'target_name' => $target_name,
					'operations'  => $operations,
					'dry_run'     => true,
					'commit'      => false,
				),
				'requires_approval' => true,
				'commit_execution'  => false,
				'risk'              => 'high',
				'reason'            => __( 'Replace exact hard-coded media URLs in a WordPress setting after local media adoption.', 'npcink-abilities-toolkit' ),
			);
			$preview[] = array(
				'target_type' => $target_type,
				'target_name' => $target_name,
				'value_type'   => $this->media_settings_reference_value_type( $value ),
				'matches'      => $matches,
			);
		}

		return $this->build_analysis_success_response(
			array(
				'batch_id'          => 'media_settings_reference_repair_' . gmdate( 'Ymd_His' ),
				'plan_kind'         => 'media_settings_reference_repair',
				'attachment_id'     => $attachment_id,
				'replacement_id'    => sanitize_text_field( (string) ( $replacement['replacement_id'] ?? '' ) ),
				'old'               => is_array( $replacement['old'] ?? null ) ? $replacement['old'] : array(),
				'new'               => is_array( $replacement['new'] ?? null ) ? $replacement['new'] : array(),
				'policy'            => $policy,
				'requires_approval' => true,
				'commit_execution'  => false,
				'dry_run'           => true,
				'action_count'      => count( $actions ),
				'scanned_count'     => $scanned_count,
				'write_actions'     => $actions,
				'preview'           => $preview,
				'manual_review'     => $manual_review,
				'risk'              => array(
					'level'  => ! empty( $manual_review ) ? 'high' : 'medium',
					'reason' => __( 'Settings repairs are limited to exact URL replacements and require Core approval before any WordPress setting write.', 'npcink-abilities-toolkit' ),
				),
			),
			array(
				'source'         => 'local_media_settings_reference_repair_plan',
				'execution_mode' => 'deterministic',
				'plan_only'      => true,
			),
			'Media settings reference repair plan built.'
		);
	}

	/**
	 * Collects featured and attached media for a post context payload.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private function collect_post_media_context( $post_id ) {
		$featured_id = function_exists( 'get_post_thumbnail_id' ) ? $this->absint_value( get_post_thumbnail_id( $post_id ) ) : 0;
		$featured = null;
		if ( $featured_id > 0 ) {
			$featured = $this->build_media_context_row( $featured_id, 'featured' );
		}

		$attachments = array();
		if ( function_exists( 'get_attached_media' ) ) {
			foreach ( (array) get_attached_media( '', $post_id ) as $attachment ) {
				$attachment_id = is_object( $attachment ) ? $this->absint_value( $attachment->ID ?? 0 ) : $this->absint_value( $attachment );
				if ( $attachment_id <= 0 ) {
					continue;
				}
				$attachments[] = $this->build_media_context_row( $attachment_id, 'attachment' );
				if ( count( $attachments ) >= 20 ) {
					break;
				}
			}
		}

		return array(
			'featured'    => $featured,
			'attachments' => $attachments,
			'total'       => count( $attachments ) + ( $featured ? 1 : 0 ),
		);
	}

	/**
	 * Normalizes requested media fix issue types.
	 *
	 * @param mixed $issue_types Raw issue types.
	 * @return string[]
	 */
	private function normalize_media_fix_issue_types( $issue_types ) {
		$allowed = array( 'missing_alt', 'missing_caption', 'missing_description', 'missing_source', 'format_attention', 'possibly_unattached' );
		$issue_types = is_array( $issue_types ) ? $issue_types : array();
		if ( empty( $issue_types ) ) {
			$issue_types = $allowed;
		}
		$normalized = array();
		foreach ( $issue_types as $issue ) {
			$issue = sanitize_key( (string) $issue );
			if ( in_array( $issue, $allowed, true ) ) {
				$normalized[] = $issue;
			}
		}

		return ! empty( $normalized ) ? array_values( array_unique( $normalized ) ) : array( 'missing_alt', 'missing_caption', 'missing_description' );
	}

	/**
	 * Builds action rows for one media inventory fix plan.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $row Media inventory row.
	 * @param string[]            $issue_types Requested issue types.
	 * @param array<string,mixed> $context Planning context.
	 * @param int                 $remaining_slots Remaining action slots.
	 * @param bool                $include_delete_candidates Whether to map delete candidates.
	 * @param bool                $include_trash_parent_media Whether trash-parent nonproduction media can map to delete actions.
	 * @param bool                $include_unattached_nonproduction_media Whether parentless nonproduction media can map to delete actions.
	 * @param string[]            $nonproduction_content_patterns Nonproduction content patterns.
	 * @return array<string,mixed>
	 */
	private function build_media_inventory_fix_plan_rows( $attachment_id, array $row, array $issue_types, array $context, $remaining_slots, $include_delete_candidates, $include_trash_parent_media = false, $include_unattached_nonproduction_media = false, array $nonproduction_content_patterns = array() ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$remaining_slots = max( 0, (int) $remaining_slots );
		$issues = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $row['issues'] ?? array() ) ),
				static function ( $issue ) use ( $issue_types ) {
					return in_array( $issue, $issue_types, true );
				}
			)
		);
		$actions = array();
		$manual_review = array();
		$skipped_destructive_candidates = array();
		if ( empty( $issues ) || $attachment_id <= 0 ) {
			return array(
				'issues'                         => array(),
				'actions'                        => array(),
				'preview'                        => array(),
				'manual_review'                  => array(),
				'skipped_destructive_candidates' => array(),
			);
		}

		$suggestions = $this->build_media_metadata_suggestions( $attachment_id, $row, $context );
		$update_input = array( 'attachment_id' => $attachment_id );
		$after = array();
		if ( in_array( 'missing_alt', $issues, true ) && '' !== (string) ( $suggestions['alt'] ?? '' ) ) {
			$update_input['alt'] = $suggestions['alt'];
			$after['alt'] = $suggestions['alt'];
		}
		if ( in_array( 'missing_caption', $issues, true ) && '' !== (string) ( $suggestions['caption'] ?? '' ) ) {
			$update_input['caption'] = $suggestions['caption'];
			$after['caption'] = $suggestions['caption'];
		}
		if ( in_array( 'missing_description', $issues, true ) && '' !== (string) ( $suggestions['description'] ?? '' ) ) {
			$update_input['description'] = $suggestions['description'];
			$after['description'] = $suggestions['description'];
		}
		if ( in_array( 'missing_source', $issues, true ) ) {
			$source_metadata = $this->media_source_metadata_with_defaults(
				array(
					'source_type'       => $row['source_type'] ?? '',
					'source_page_url'   => $row['source_page_url'] ?? '',
					'photographer_name' => $row['photographer_name'] ?? '',
					'attribution_text'  => $row['attribution_text'] ?? '',
					'copyright_notice'  => $row['copyright_notice'] ?? '',
				)
			);
			if ( $this->media_source_metadata_has_coverage( $source_metadata ) && count( $actions ) < $remaining_slots ) {
				foreach ( array( 'source_type', 'source_page_url', 'photographer_name', 'attribution_text', 'copyright_notice' ) as $field ) {
					if ( '' !== (string) ( $source_metadata[ $field ] ?? '' ) ) {
						$update_input[ $field ] = $source_metadata[ $field ];
						$after[ $field ] = $source_metadata[ $field ];
					}
				}
			} else {
				$manual_review[] = array(
					'action_id'         => 'review_media_source_' . $attachment_id,
					'attachment_id'     => $attachment_id,
					'issue'             => 'missing_source',
					'target_ability_id' => 'npcink-abilities-toolkit/update-media-details',
					'requires_input'    => array( 'source_type', 'source_page_url_or_attribution_text' ),
					'proposal_ready'    => false,
					'reason'            => __( 'Source and attribution metadata must come from a verified source, not a deterministic guess.', 'npcink-abilities-toolkit' ),
				);
			}
		}

		if ( count( $update_input ) > 1 && count( $actions ) < $remaining_slots ) {
			$summary = array_key_exists( 'source_type', $update_input )
				? __( 'Fill missing media SEO and source metadata.', 'npcink-abilities-toolkit' )
				: __( 'Fill missing media SEO metadata.', 'npcink-abilities-toolkit' );
			$actions[] = $this->build_plan_action( 'update_media_details_' . $attachment_id, 'npcink-abilities-toolkit/update-media-details', $update_input, array( 'media.write' ), 'medium', $summary );
		}

		if ( in_array( 'format_attention', $issues, true ) ) {
			$format_inspection = is_array( $row['format_inspection'] ?? null ) ? $row['format_inspection'] : $this->build_media_format_inspection( $attachment_id, array() );
			$format_plan = is_array( $format_inspection['format_plan'] ?? null ) ? $format_inspection['format_plan'] : array();
			$warnings = is_array( $format_inspection['warnings'] ?? null ) ? $format_inspection['warnings'] : array();
			$manual_review[] = array(
				'action_id'         => 'review_media_format_' . $attachment_id,
				'attachment_id'     => $attachment_id,
				'issue'             => 'format_attention',
				'format_plan'       => $format_plan,
				'warnings'          => $warnings,
				'format_governance' => $this->build_media_format_manual_review_context( $format_plan, $warnings ),
				'requires_input'    => array( 'optimized_derivative_or_operator_decision' ),
				'proposal_ready'    => false,
				'reason'            => __( 'Format attention is a file-asset concern. The media inventory fix plan records read-only diagnostics and does not map it to a write action.', 'npcink-abilities-toolkit' ),
			);
		}

		if ( in_array( 'possibly_unattached', $issues, true ) ) {
			$delete_policy = $include_delete_candidates
				? $this->media_delete_candidate_policy( $attachment_id, $row, $include_trash_parent_media, $include_unattached_nonproduction_media, $nonproduction_content_patterns )
				: array( 'allowed' => false, 'blocked_reason' => 'delete_candidates_not_enabled', 'checks' => array() );
			if ( $include_delete_candidates && ! empty( $delete_policy['allowed'] ) && count( $actions ) < $remaining_slots ) {
				$actions[] = $this->build_plan_action( 'delete_unattached_media_' . $attachment_id, 'npcink-abilities-toolkit/delete-media-permanently', array( 'attachment_id' => $attachment_id ), array( 'media.write' ), 'high', 'Permanently delete detected unattached media only after explicit host approval.' );
			} else {
				$skipped_destructive_candidates[] = array(
					'attachment_id'     => $attachment_id,
					'target_ability_id' => 'npcink-abilities-toolkit/delete-media-permanently',
					'issue'             => 'possibly_unattached',
					'blocked_reason'    => $include_delete_candidates ? (string) ( $delete_policy['blocked_reason'] ?? 'media_delete_policy_not_satisfied' ) : 'delete_candidates_not_enabled',
					'policy_checks'     => is_array( $delete_policy['checks'] ?? null ) ? $delete_policy['checks'] : array(),
					'reason'            => $include_delete_candidates ? 'Permanent deletion is limited to explicitly opted-in nonproduction media with no live content references.' : 'Permanent deletion is excluded by default. Re-run with include_delete_candidates=true plus include_trash_parent_media=true or include_unattached_nonproduction_media=true to include eligible nonproduction media in write_actions.',
				);
			}
		}

		return array(
			'issues'  => $issues,
			'actions' => $actions,
			'preview' => empty( $actions ) && empty( $manual_review ) && empty( $skipped_destructive_candidates ) ? array() : array(
				'attachment_id'       => $attachment_id,
				'title'               => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'mime_type'           => sanitize_text_field( (string) ( $row['mime_type'] ?? '' ) ),
				'url'                 => $this->esc_url_value( (string) ( $row['url'] ?? '' ) ),
				'parent_post_id'      => $this->absint_value( $row['parent_post_id'] ?? 0 ),
				'parent_post_status' => sanitize_key( (string) ( $row['parent_post_status'] ?? '' ) ),
				'parent_post_title'  => sanitize_text_field( (string) ( $row['parent_post_title'] ?? '' ) ),
				'before'              => array(
					'title'            => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
					'alt'              => sanitize_text_field( (string) ( $row['alt'] ?? '' ) ),
					'caption'          => $this->sanitize_metadata_text( (string) ( $row['caption'] ?? '' ) ),
					'description'      => $this->sanitize_metadata_text( (string) ( $row['description'] ?? '' ) ),
					'source_type'      => $this->normalize_media_source_type( $row['source_type'] ?? '' ),
					'source_page_url'  => $this->esc_url_value( (string) ( $row['source_page_url'] ?? '' ) ),
					'photographer_name' => sanitize_text_field( (string) ( $row['photographer_name'] ?? '' ) ),
					'attribution_text' => $this->sanitize_metadata_text( (string) ( $row['attribution_text'] ?? '' ) ),
					'copyright_notice' => $this->sanitize_metadata_text( (string) ( $row['copyright_notice'] ?? '' ) ),
					'parent_post_id'   => $this->absint_value( $row['parent_post_id'] ?? 0 ),
				),
				'after_suggestion' => $after,
				'issues'           => $issues,
			),
			'manual_review'                  => $manual_review,
			'skipped_destructive_candidates' => $skipped_destructive_candidates,
		);
	}

	/**
	 * Evaluates the narrow permanent media delete policy.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $row Media row.
	 * @param bool                $include_trash_parent_media Whether trash-parent nonproduction media can be deleted.
	 * @param bool                $include_unattached_nonproduction_media Whether parentless nonproduction media can be deleted.
	 * @param string[]            $nonproduction_content_patterns Nonproduction content patterns.
	 * @return array<string,mixed>
	 */
	private function media_delete_candidate_policy( $attachment_id, array $row, $include_trash_parent_media, $include_unattached_nonproduction_media, array $nonproduction_content_patterns ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$nonproduction_content_patterns = ! empty( $nonproduction_content_patterns ) ? $nonproduction_content_patterns : $this->normalize_nonproduction_content_patterns( array() );
		$parent_title = sanitize_text_field( (string) ( $row['parent_post_title'] ?? '' ) );
		$media_haystack = implode(
			' ',
			array(
				sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				sanitize_title( (string) ( $row['post_name'] ?? '' ) ),
				$this->esc_url_value( (string) ( $row['url'] ?? '' ) ),
			)
		);
		$usage = $this->build_media_usage_context( $attachment_id );
		$live_reference_count = (int) ( $usage['featured_image_count'] ?? 0 ) + (int) ( $usage['content_reference_count'] ?? 0 );
		$checks = array(
			'include_trash_parent_media' => (bool) $include_trash_parent_media,
			'include_unattached_nonproduction_media' => (bool) $include_unattached_nonproduction_media,
			'parent_post_id'             => $this->absint_value( $row['parent_post_id'] ?? 0 ),
			'parent_status'              => sanitize_key( (string) ( $row['parent_post_status'] ?? '' ) ),
			'parent_matches_nonproduction_content' => $this->text_matches_nonproduction_content_patterns( $parent_title, $nonproduction_content_patterns ),
			'media_matches_nonproduction_content' => $this->text_matches_nonproduction_content_patterns( $media_haystack, $nonproduction_content_patterns ),
			'live_reference_count'       => $live_reference_count,
			'usage'                      => $usage,
		);

		if ( $checks['parent_post_id'] <= 0 ) {
			if ( empty( $checks['include_unattached_nonproduction_media'] ) ) {
				return array( 'allowed' => false, 'blocked_reason' => 'unattached_nonproduction_media_not_enabled', 'checks' => $checks );
			}
			if ( empty( $checks['media_matches_nonproduction_content'] ) ) {
				return array( 'allowed' => false, 'blocked_reason' => 'media_not_nonproduction_content', 'checks' => $checks );
			}
			if ( $live_reference_count > 0 ) {
				return array( 'allowed' => false, 'blocked_reason' => 'referenced_by_live_content', 'checks' => $checks );
			}

			return array( 'allowed' => true, 'blocked_reason' => '', 'checks' => $checks );
		}

		if ( empty( $checks['include_trash_parent_media'] ) ) {
			return array( 'allowed' => false, 'blocked_reason' => 'trash_parent_media_not_enabled', 'checks' => $checks );
		}
		if ( 'trash' !== $checks['parent_status'] ) {
			return array( 'allowed' => false, 'blocked_reason' => 'parent_not_trash', 'checks' => $checks );
		}
		if ( empty( $checks['parent_matches_nonproduction_content'] ) ) {
			return array( 'allowed' => false, 'blocked_reason' => 'parent_not_nonproduction_content', 'checks' => $checks );
		}
		if ( empty( $checks['media_matches_nonproduction_content'] ) ) {
			return array( 'allowed' => false, 'blocked_reason' => 'media_not_nonproduction_content', 'checks' => $checks );
		}
		if ( $live_reference_count > 0 ) {
			return array( 'allowed' => false, 'blocked_reason' => 'referenced_by_live_content', 'checks' => $checks );
		}

		return array( 'allowed' => true, 'blocked_reason' => '', 'checks' => $checks );
	}

	/**
	 * Checks whether text contains one of the default nonproduction-content patterns.
	 *
	 * @param string   $text Text to inspect.
	 * @param string[] $patterns Nonproduction content patterns.
	 * @return bool
	 */
	private function text_matches_nonproduction_content_patterns( $text, array $patterns ) {
		$text = strtolower( $this->sanitize_metadata_text( (string) $text ) );
		if ( '' === $text ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( $this->sanitize_metadata_text( (string) $pattern ) );
			if ( '' !== $pattern && false !== strpos( $text, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds live content references to an attachment in post bodies.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function find_media_content_references( $attachment_id, $limit ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$limit = max( 1, (int) $limit );
		$url = function_exists( 'wp_get_attachment_url' ) ? $this->esc_url_value( (string) wp_get_attachment_url( $attachment_id ) ) : '';
		$posts = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			? array_values( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			: ( function_exists( 'get_posts' ) ? get_posts( array( 'post_type' => 'any', 'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'posts_per_page' => 50 ) ) : array() );
		$references = array();

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || 'attachment' === sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			if ( ! in_array( sanitize_key( (string) ( $post->post_status ?? '' ) ), array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
				continue;
			}
			$content = (string) ( $post->post_content ?? '' );
			if ( ! $this->media_content_contains_reference( $content, $attachment_id, $url ) ) {
				continue;
			}
			$references[] = $this->media_reference_post_row( $post );
			if ( count( $references ) >= $limit ) {
				break;
			}
		}

		return $references;
	}

	/**
	 * Finds live featured-image references to an attachment for unit fallback.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function find_media_featured_image_references( $attachment_id, $limit ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$limit = max( 1, (int) $limit );
		$references = array();
		$posts = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) ? array_values( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) : array();

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || 'attachment' === sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			if ( ! in_array( sanitize_key( (string) ( $post->post_status ?? '' ) ), array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
				continue;
			}
			$post_id = $this->absint_value( $post->ID ?? 0 );
			if ( $attachment_id !== $this->absint_value( $GLOBALS['npcink_abilities_toolkit_unit_post_meta'][ $post_id ]['_thumbnail_id'] ?? 0 ) ) {
				continue;
			}
			$references[] = $this->media_reference_post_row( $post );
			if ( count( $references ) >= $limit ) {
				break;
			}
		}

		return $references;
	}

	/**
	 * Checks common WordPress content references for an attachment.
	 *
	 * @param string $content Post content.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Attachment URL.
	 * @return bool
	 */
	private function media_content_contains_reference( $content, $attachment_id, $url ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$content = (string) $content;
		if ( $attachment_id <= 0 || '' === $content ) {
			return false;
		}

		foreach ( array( 'wp-image-' . $attachment_id, '"id":' . $attachment_id, "'id':" . $attachment_id, 'data-id="' . $attachment_id . '"', 'data-id=\'' . $attachment_id . '\'' ) as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				return true;
			}
		}

		return '' !== $url && false !== strpos( $content, $url );
	}

	/**
	 * Builds a safe post row for media reference diagnostics.
	 *
	 * @param object $post Post object.
	 * @return array<string,mixed>
	 */
	private function media_reference_post_row( $post ) {
		$post_id = $this->absint_value( $post->ID ?? 0 );
		return array(
			'post_id'     => $post_id,
			'post_type'   => sanitize_key( (string) ( $post->post_type ?? '' ) ),
			'post_status' => sanitize_key( (string) ( $post->post_status ?? '' ) ),
			'post_title'  => sanitize_text_field( (string) ( $post->post_title ?? ( function_exists( 'get_the_title' ) ? get_the_title( $post_id ) : '' ) ) ),
		);
	}

	/**
	 * Builds before/after media URL context for content reference repair.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function media_reference_repair_replacement_context( $attachment_id, array $input ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$replacement_id = sanitize_text_field( (string) ( $input['replacement_id'] ?? '' ) );
		$history = array();
		if ( function_exists( 'get_post_meta' ) ) {
			$latest = get_post_meta( $attachment_id, '_npcink_ai_media_latest_file_replacement', true );
			if ( is_array( $latest ) ) {
				$history[] = $latest;
			}
			$all = get_post_meta( $attachment_id, '_npcink_ai_media_file_replacement_history', true );
			if ( is_array( $all ) ) {
				if ( isset( $all['replacement_id'] ) ) {
					$history[] = $all;
				} else {
					$history = array_merge( $history, array_values( array_filter( $all, 'is_array' ) ) );
				}
			}
		}

		$record = array();
		foreach ( $history as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( '' !== $replacement_id && $replacement_id !== (string) ( $candidate['replacement_id'] ?? '' ) ) {
				continue;
			}
			$record = $candidate;
			break;
		}

		$before = is_array( $record['before'] ?? null ) ? $record['before'] : array();
		$after = is_array( $record['after'] ?? null ) ? $record['after'] : array();
		$old_relative = $this->normalize_media_reference_relative( (string) ( $input['old_relative_file'] ?? $before['relative_file'] ?? '' ) );
		$new_relative = $this->normalize_media_reference_relative( (string) ( $input['new_relative_file'] ?? $after['relative_file'] ?? '' ) );
		$old_url = $this->esc_url_value( (string) ( $input['old_url'] ?? $before['url'] ?? '' ) );
		$new_url = $this->esc_url_value( (string) ( $input['new_url'] ?? $after['url'] ?? '' ) );
		if ( '' === $old_url && '' !== $old_relative ) {
			$old_url = $this->media_reference_upload_url( $old_relative );
		}
		if ( '' === $new_url && '' !== $new_relative ) {
			$new_url = $this->media_reference_upload_url( $new_relative );
		}
		if ( '' === $old_relative && '' !== $old_url ) {
			$old_relative = $this->media_reference_relative_from_url( $old_url );
		}
		if ( '' === $new_relative && '' !== $new_url ) {
			$new_relative = $this->media_reference_relative_from_url( $new_url );
		}
		if ( '' === $old_url || '' === $new_url ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_replacement_reference_missing', __( 'No completed media replacement history or explicit old/new URLs were found for this attachment.', 'npcink-abilities-toolkit' ), array( 'status' => 404 ) );
		}

		return array(
			'attachment_id'  => $attachment_id,
			'replacement_id' => '' !== $replacement_id ? $replacement_id : sanitize_text_field( (string) ( $record['replacement_id'] ?? '' ) ),
			'old'            => array(
				'url'           => $old_url,
				'relative_file' => $old_relative,
				'path'          => $this->media_reference_url_path( $old_url ),
			),
			'new'            => array(
				'url'           => $new_url,
				'relative_file' => $new_relative,
				'path'          => $this->media_reference_url_path( $new_url ),
			),
		);
	}

	/**
	 * Returns candidate posts for reference repair scanning.
	 *
	 * @param int $limit Candidate limit.
	 * @return array<int,object>
	 */
	private function media_reference_repair_candidate_posts( $limit ) {
		$limit = max( 1, min( 150, (int) $limit ) );
		if ( isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) ) {
			return array_values( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] );
		}
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
			)
		);
	}

	/**
	 * Builds candidate settings for media reference repair scanning.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @param int                 $limit Candidate limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function media_settings_reference_repair_candidates( array $input, $limit ) {
		$limit = max( 1, min( 100, (int) $limit ) );
		$candidates = array();
		$option_names = $this->media_settings_reference_names( $input['option_names'] ?? array(), 50 );
		if ( empty( $option_names ) && isset( $GLOBALS['npcink_abilities_toolkit_unit_options'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_options'] ) ) {
			$option_names = array_slice( array_map( 'strval', array_keys( $GLOBALS['npcink_abilities_toolkit_unit_options'] ) ), 0, $limit );
		}
		if ( empty( $option_names ) ) {
			$option_names = $this->media_settings_reference_option_names_from_db( $input, $limit );
		}
		foreach ( $option_names as $option_name ) {
			if ( count( $candidates ) >= $limit ) {
				break;
			}
			$candidates[] = array(
				'target_type' => 'option',
				'target_name' => $option_name,
				'value'       => function_exists( 'get_option' ) ? get_option( $option_name, null ) : null,
			);
		}

		if ( ! array_key_exists( 'include_theme_mods', $input ) || ! empty( $input['include_theme_mods'] ) ) {
			$theme_mod_names = $this->media_settings_reference_names( $input['theme_mod_names'] ?? array(), 50 );
			$theme_mods = array();
			if ( function_exists( 'get_theme_mods' ) ) {
				$theme_mods = get_theme_mods();
				$theme_mods = is_array( $theme_mods ) ? $theme_mods : array();
			}
			if ( isset( $GLOBALS['npcink_abilities_toolkit_unit_theme_mods'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_theme_mods'] ) ) {
				$theme_mods = array_merge( $theme_mods, $GLOBALS['npcink_abilities_toolkit_unit_theme_mods'] );
			}
			if ( empty( $theme_mod_names ) ) {
				$theme_mod_names = array_slice( array_map( 'strval', array_keys( $theme_mods ) ), 0, $limit );
			}
			foreach ( $theme_mod_names as $theme_mod_name ) {
				if ( count( $candidates ) >= $limit ) {
					break;
				}
				$candidates[] = array(
					'target_type' => 'theme_mod',
					'target_name' => $theme_mod_name,
					'value'       => array_key_exists( $theme_mod_name, $theme_mods ) ? $theme_mods[ $theme_mod_name ] : ( function_exists( 'get_theme_mod' ) ? get_theme_mod( $theme_mod_name, null ) : null ),
				);
			}
		}

		return $candidates;
	}

	/**
	 * Sanitizes bounded setting names.
	 *
	 * @param mixed $names Raw names.
	 * @param int   $limit Max names.
	 * @return string[]
	 */
	private function media_settings_reference_names( $names, $limit ) {
		$names = is_array( $names ) ? $names : array();
		$clean = array();
		foreach ( $names as $name ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name && ! in_array( $name, $clean, true ) ) {
				$clean[] = $name;
			}
			if ( count( $clean ) >= $limit ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Queries bounded option names likely to contain media references.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @param int                 $limit Max names.
	 * @return string[]
	 */
	private function media_settings_reference_option_names_from_db( array $input, $limit ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return array();
		}
		$needle = '';
		foreach ( array( 'old_url', 'old_relative_file' ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$needle = basename( $value );
				break;
			}
		}
		if ( '' === $needle ) {
			return array();
		}
		$limit     = max( 1, min( 100, (int) $limit ) );
		$cache_key = 'media_settings_reference_options_' . md5( $needle . '|' . $limit );
		$cached    = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'npcink_abilities_toolkit' ) : false;
		if ( is_array( $cached ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $cached ) ) );
		}

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded options search is the feature; options table name is provided by WordPress.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT %d", $like, $limit ) );
		$rows = array_values( array_filter( array_map( 'sanitize_key', is_array( $rows ) ? $rows : array() ) ) );
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $cache_key, $rows, 'npcink_abilities_toolkit', MINUTE_IN_SECONDS );
		}

		return $rows;
	}

	/**
	 * Builds the conservative settings reference repair policy.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $replacement Replacement context.
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>
	 */
	private function media_settings_reference_repair_policy( $attachment_id, array $replacement, array $input ) {
		$old = is_array( $replacement['old'] ?? null ) ? $replacement['old'] : array();
		$relative = (string) ( $old['relative_file'] ?? '' );
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$excluded_formats = $this->media_settings_reference_keys( $input['excluded_formats'] ?? array( 'svg', 'gif', 'ico', 'pdf' ), 20 );
		$patterns = $this->media_settings_reference_keys( $input['excluded_filename_patterns'] ?? array( 'logo', 'favicon', 'icon', 'brand', 'payment', 'placeholder' ), 20 );
		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		$metadata = is_array( $metadata ) ? $metadata : array();
		$width = $this->absint_value( $metadata['width'] ?? 0 );
		$height = $this->absint_value( $metadata['height'] ?? 0 );
		$filesize = $this->absint_value( $metadata['filesize'] ?? $metadata['filesize_bytes'] ?? 0 );
		$min_width = max( 0, min( 7680, $this->absint_value( $input['min_width'] ?? 64 ) ) );
		$min_height = max( 0, min( 7680, $this->absint_value( $input['min_height'] ?? 64 ) ) );
		$min_filesize = max( 0, $this->absint_value( $input['min_filesize_bytes'] ?? 0 ) );
		$basename = strtolower( basename( $relative ) );
		$excluded = false;
		$reason = '';
		if ( '' !== $extension && in_array( $extension, $excluded_formats, true ) ) {
			$excluded = true;
			$reason = 'source_format_excluded';
		}
		if ( ! $excluded && $width > 0 && $height > 0 && ( $width < $min_width || $height < $min_height ) ) {
			$excluded = true;
			$reason = 'source_dimensions_below_policy';
		}
		if ( ! $excluded && $min_filesize > 0 && $filesize > 0 && $filesize < $min_filesize ) {
			$excluded = true;
			$reason = 'source_filesize_below_policy';
		}
		if ( ! $excluded ) {
			foreach ( $patterns as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $basename, $pattern ) ) {
					$excluded = true;
					$reason = 'source_filename_pattern_excluded';
					break;
				}
			}
		}

		return array(
			'excluded'                   => $excluded,
			'reason'                     => $reason,
			'excluded_formats'           => $excluded_formats,
			'excluded_filename_patterns' => $patterns,
			'min_width'                  => $min_width,
			'min_height'                 => $min_height,
			'min_filesize_bytes'         => $min_filesize,
			'source_format'              => $extension,
			'source_width'               => $width,
			'source_height'              => $height,
			'source_filesize_bytes'      => $filesize,
		);
	}

	/**
	 * Sanitizes simple policy keys.
	 *
	 * @param mixed $values Raw values.
	 * @param int   $limit Max values.
	 * @return string[]
	 */
	private function media_settings_reference_keys( $values, $limit ) {
		$values = is_array( $values ) ? $values : array();
		$clean = array();
		foreach ( $values as $value ) {
			$value = sanitize_key( (string) $value );
			if ( '' !== $value && ! in_array( $value, $clean, true ) ) {
				$clean[] = $value;
			}
			if ( count( $clean ) >= $limit ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Converts a setting value to searchable text.
	 *
	 * @param mixed $value Setting value.
	 * @return string
	 */
	private function media_settings_reference_value_text( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value, defined( 'JSON_UNESCAPED_SLASHES' ) ? JSON_UNESCAPED_SLASHES : 0 );
			return is_string( $encoded ) ? $encoded : '';
		}
		return '';
	}

	/**
	 * Returns a compact setting value type label.
	 *
	 * @param mixed $value Setting value.
	 * @return string
	 */
	private function media_settings_reference_value_type( $value ) {
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_object( $value ) ) {
			return 'object';
		}
		return gettype( $value );
	}

	/**
	 * Detects raw serialized strings that should not be patched by text replacement.
	 *
	 * @param string $value Setting value.
	 * @return bool
	 */
	private function media_settings_reference_looks_serialized( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
			return true;
		}
		return (bool) preg_match( '/^(a|O|s|i|b|d):[0-9]+[:;]/', $value );
	}

	/**
	 * Returns paired old/new reference strings to scan.
	 *
	 * @param array<string,mixed> $replacement Replacement context.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function media_reference_repair_ref_pairs( array $replacement ) {
		$old = is_array( $replacement['old'] ?? null ) ? $replacement['old'] : array();
		$new = is_array( $replacement['new'] ?? null ) ? $replacement['new'] : array();
		$pairs = array(
			array(
				'old' => (string) ( $old['url'] ?? '' ),
				'new' => (string) ( $new['url'] ?? '' ),
			),
			array(
				'old' => (string) ( $old['path'] ?? '' ),
				'new' => (string) ( $new['path'] ?? '' ),
			),
		);
		$clean = array();
		foreach ( $pairs as $pair ) {
			$old_ref = trim( (string) ( $pair['old'] ?? '' ) );
			$new_ref = trim( (string) ( $pair['new'] ?? '' ) );
			if ( '' === $old_ref || '' === $new_ref ) {
				continue;
			}
			$key = $old_ref . "\n" . $new_ref;
			if ( isset( $clean[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'old' => $old_ref,
				'new' => $new_ref,
			);
		}
		return array_values( $clean );
	}

	/**
	 * Detects old sized image variant references for manual review.
	 *
	 * @param string              $content Post content.
	 * @param array<string,mixed> $replacement Replacement context.
	 * @return array<int,string>
	 */
	private function media_reference_repair_sized_variant_matches( $content, array $replacement ) {
		$old = is_array( $replacement['old'] ?? null ) ? $replacement['old'] : array();
		$path = (string) ( $old['path'] ?? '' );
		$basename = basename( $path );
		if ( '' === $basename || false === strpos( $basename, '.' ) ) {
			return array();
		}
		$stem = preg_replace( '/\.[^.]+$/', '', $basename );
		$extension = pathinfo( $basename, PATHINFO_EXTENSION );
		if ( '' === (string) $stem || '' === $extension ) {
			return array();
		}
		$pattern = '/' . preg_quote( (string) $stem, '/' ) . '-[0-9]{2,5}x[0-9]{2,5}\.' . preg_quote( $extension, '/' ) . '/';
		preg_match_all( $pattern, (string) $content, $matches );
		return array_slice( array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $matches[0] ?? array() ) ) ) ), 0, 10 );
	}

	/**
	 * Returns unique non-empty string values.
	 *
	 * @param array<int,string> $values Values.
	 * @return array<int,string>
	 */
	private function media_reference_unique_strings( array $values ) {
		$clean = array();
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value && ! in_array( $value, $clean, true ) ) {
				$clean[] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Normalizes uploads-relative media paths.
	 *
	 * @param string $relative_file Relative file.
	 * @return string
	 */
	private function normalize_media_reference_relative( $relative_file ) {
		$relative_file = ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $relative_file ) ), '/' );
		if ( '' === $relative_file || false !== strpos( $relative_file, '../' ) || '..' === $relative_file ) {
			return '';
		}
		return $relative_file;
	}

	/**
	 * Builds an uploads URL for a relative file.
	 *
	 * @param string $relative_file Relative file.
	 * @return string
	 */
	private function media_reference_upload_url( $relative_file ) {
		$relative_file = $this->normalize_media_reference_relative( $relative_file );
		if ( '' === $relative_file || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		$baseurl = is_array( $upload_dir ) ? $this->esc_url_value( (string) ( $upload_dir['baseurl'] ?? '' ) ) : '';
		return '' !== $baseurl ? rtrim( $baseurl, '/' ) . '/' . $relative_file : '';
	}

	/**
	 * Returns a URL path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function media_reference_url_path( $url ) {
		$path = '' !== (string) $url && function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( (string) $url, PHP_URL_PATH ) : '';
		return '' !== $path ? sanitize_text_field( $path ) : '';
	}

	/**
	 * Infers an uploads-relative file from an uploads URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function media_reference_relative_from_url( $url ) {
		$path = $this->media_reference_url_path( $url );
		if ( '' === $path || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		$baseurl = is_array( $upload_dir ) ? $this->esc_url_value( (string) ( $upload_dir['baseurl'] ?? '' ) ) : '';
		$base_path = $this->media_reference_url_path( $baseurl );
		if ( '' !== $base_path && 0 === strpos( $path, rtrim( $base_path, '/' ) . '/' ) ) {
			return $this->normalize_media_reference_relative( substr( $path, strlen( rtrim( $base_path, '/' ) ) + 1 ) );
		}
		return '';
	}

	/**
	 * Builds deterministic media metadata suggestions.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $row Media row.
	 * @param array<string,mixed> $context Planning context.
	 * @return array<string,string>
	 */
	private function build_media_metadata_suggestions( $attachment_id, array $row, array $context ) {
		$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$url = $this->esc_url_value( (string) ( $row['url'] ?? '' ) );
			$url_path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
		$file_name = '' !== $url_path ? $this->sanitize_file_name_value( (string) basename( $url_path ) ) : '';
		$file_label = '' !== $file_name ? preg_replace( '/\.[^.]+$/', '', $file_name ) : '';
		$file_label = sanitize_text_field( (string) str_replace( array( '-', '_' ), ' ', (string) $file_label ) );
		$article_title = sanitize_text_field( (string) ( $context['article_title'] ?? '' ) );
		$article_excerpt = $this->sanitize_metadata_text( (string) ( $context['article_excerpt'] ?? '' ) );
		$focus_keyword = sanitize_text_field( (string) ( $context['focus_keyword'] ?? '' ) );
		$seed = '' !== $title ? $title : ( '' !== $file_label ? $file_label : 'Media asset ' . $this->absint_value( $attachment_id ) );
		$alt_parts = array_filter( array( $seed, '' !== $focus_keyword ? $focus_keyword : '', '' !== $article_title && $article_title !== $seed ? $article_title : '' ) );
		$caption_seed = '' !== $article_excerpt ? $article_excerpt : ( '' !== $article_title ? $article_title : $seed );
		$description_seed = trim( implode( ' ', array_filter( array( $caption_seed, '' !== $seed && false === strpos( $caption_seed, $seed ) ? $seed : '' ) ) ) );

		return array(
			'alt'         => $this->trim_media_seo_text( implode( ' - ', $alt_parts ), 140 ),
			'caption'     => $this->trim_media_seo_text( $caption_seed, 160 ),
			'description' => $this->trim_media_seo_text( $description_seed, 220 ),
		);
	}

	/**
	 * Normalizes and validates a media URL resolver input.
	 *
	 * @param string $raw_url Raw URL.
	 * @return array<string,string>|\WP_Error
	 */
	private function normalize_media_attachment_resolution_url( $raw_url ) {
		$raw_url = trim( (string) $raw_url );
		if ( '' === $raw_url ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_required', __( 'A media URL is required.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$uploads_base_url = $this->media_upload_base_url();
		$base_parts = $this->parse_url_value( $uploads_base_url );
		if ( empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) || empty( $base_parts['path'] ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_upload_base_unavailable', __( 'The local uploads base URL is unavailable.', 'npcink-abilities-toolkit' ), array( 'status' => 500 ) );
		}

		$normalized_url = $raw_url;
		if ( 0 === strpos( $raw_url, '/' ) && 0 !== strpos( $raw_url, '//' ) ) {
			$normalized_url = (string) $base_parts['scheme'] . '://' . (string) $base_parts['host'] . $raw_url;
		}
		$normalized_url = $this->esc_url_value( $normalized_url );
		$url_parts = $this->parse_url_value( $normalized_url );
		if ( empty( $url_parts['scheme'] ) || empty( $url_parts['host'] ) || empty( $url_parts['path'] ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_invalid', __( 'The media URL must be an absolute same-site uploads URL or a root-relative uploads URL.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$base_host = strtolower( (string) $base_parts['host'] );
		$url_host = strtolower( (string) $url_parts['host'] );
		if ( $base_host !== $url_host ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_external', __( 'Only local uploads URLs can be resolved to media attachments.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$base_path = rtrim( $this->normalize_url_path( (string) $base_parts['path'] ), '/' ) . '/';
		$url_path = $this->normalize_url_path( (string) $url_parts['path'] );
		if ( 0 !== strpos( $url_path, $base_path ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_not_uploads', __( 'Only URLs under the local uploads directory can be resolved.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		$relative_file = ltrim( rawurldecode( substr( $url_path, strlen( $base_path ) ) ), '/' );
		$relative_file = $this->normalize_media_relative_file( $relative_file );
		if ( '' === $relative_file || false !== strpos( $relative_file, '..' ) ) {
			return new \WP_Error( 'npcink_abilities_toolkit_media_url_path_invalid', __( 'The uploads URL path is not a valid media file path.', 'npcink-abilities-toolkit' ), array( 'status' => 400 ) );
		}

		return array(
			'normalized_url'          => (string) $url_parts['scheme'] . '://' . (string) $url_parts['host'] . $url_path,
			'uploads_base_url'        => rtrim( $uploads_base_url, '/' ),
			'requested_relative_file' => $relative_file,
		);
	}

	/**
	 * Returns the local uploads base URL.
	 *
	 * @return string
	 */
	private function media_upload_base_url() {
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();
			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ) {
				return $this->esc_url_value( (string) $uploads['baseurl'] );
			}
		}
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ) {
				return $this->esc_url_value( (string) $uploads['baseurl'] );
			}
		}

		return 'https://example.test/wp-content/uploads';
	}

	/**
	 * Parses a URL with a WordPress fallback.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private function parse_url_value( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Normalizes a URL path for comparisons.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private function normalize_url_path( $path ) {
		$path = '/' . ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		return preg_replace( '#/+#', '/', $path );
	}

	/**
	 * Normalizes an uploads-relative media file path.
	 *
	 * @param string $file Relative file path.
	 * @return string
	 */
	private function normalize_media_relative_file( $file ) {
		$file = ltrim( str_replace( '\\', '/', (string) $file ), '/' );
		return preg_replace( '#/+#', '/', $file );
	}

	/**
	 * Finds bounded attachment candidates for an uploads-relative file path.
	 *
	 * @param string $relative_file Requested uploads-relative file.
	 * @param int    $max_candidates Maximum candidates.
	 * @return array<int,array<string,mixed>>
	 */
	private function find_media_attachment_url_resolution_candidates( $relative_file, $max_candidates ) {
		$relative_file = $this->normalize_media_relative_file( $relative_file );
		$max_candidates = max( 1, min( 20, $this->absint_value( $max_candidates ) ) );
		$ids = $this->media_attachment_ids_for_relative_file( $relative_file );
		$search_ids = $this->media_attachment_ids_for_url_filename( $relative_file, max( $max_candidates * 3, 20 ) );
		$ids = array_values( array_unique( array_filter( array_merge( $ids, $search_ids ) ) ) );

		$candidates = array();
		foreach ( $ids as $attachment_id ) {
			$attachment_id = $this->absint_value( $attachment_id );
			if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}
			$candidate = $this->build_media_attachment_url_resolution_candidate( $attachment_id, $relative_file );
			if ( ! empty( $candidate ) ) {
				$candidates[] = $candidate;
			}
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) ( $b['match_score'] ?? 0 ) <=> (int) ( $a['match_score'] ?? 0 );
			}
		);

		return array_slice( $candidates, 0, $max_candidates );
	}

	/**
	 * Finds attachment ids whose primary attached file exactly matches.
	 *
	 * @param string $relative_file Relative file.
	 * @return int[]
	 */
	private function media_attachment_ids_for_relative_file( $relative_file ) {
		$relative_file = $this->normalize_media_relative_file( $relative_file );
		if ( '' === $relative_file ) {
			return array();
		}

		if ( class_exists( '\WP_Query' ) ) {
			$query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact attachment lookup by canonical _wp_attached_file for a bounded read-only resolver.
					'meta_query'     => array(
						array(
							'key'   => '_wp_attached_file',
							'value' => $relative_file,
						),
					),
				)
			);
			return is_array( $query->posts ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $query->posts ) ) : array();
		}

		return $this->scan_unit_attachment_ids_for_relative_file( $relative_file );
	}

	/**
	 * Finds candidate attachment ids by filename stem.
	 *
	 * @param string $relative_file Relative file.
	 * @param int    $limit Limit.
	 * @return int[]
	 */
	private function media_attachment_ids_for_url_filename( $relative_file, $limit ) {
		$limit = max( 1, min( 60, $this->absint_value( $limit ) ) );
		$basename = basename( $relative_file );
		$stem = preg_replace( '/\.[^.]+$/', '', $basename );
		$original_stem = preg_replace( '/-\d+x\d+$/', '', (string) $stem );
		$search = sanitize_text_field( '' !== (string) $original_stem ? (string) $original_stem : (string) $stem );
		$query = $this->query_media_inventory( 'image', $search, $limit, 1 );
		$ids = is_array( $query['attachment_ids'] ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $query['attachment_ids'] ) ) : array();
		if ( class_exists( '\WP_Query' ) && '' !== $search ) {
			$file_query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded read-only filename-stem lookup for resolving uploads size variants to parent attachments.
					'meta_query'     => array(
						array(
							'key'     => '_wp_attached_file',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				)
			);
			$ids = array_values( array_unique( array_merge( $ids, is_array( $file_query->posts ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $file_query->posts ) ) : array() ) ) );
		} else {
			$ids = array_values( array_unique( array_merge( $ids, $this->scan_unit_attachment_ids_for_filename( $basename, $search, $limit ) ) ) );
		}
		return array_slice( array_filter( $ids ), 0, $limit );
	}

	/**
	 * Builds one URL resolution candidate row.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $requested_relative_file Requested relative file.
	 * @return array<string,mixed>
	 */
	private function build_media_attachment_url_resolution_candidate( $attachment_id, $requested_relative_file ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$attachment = get_post( $attachment_id );
		if ( $attachment_id <= 0 || ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
			return array();
		}

		$current_relative = function_exists( 'get_post_meta' ) ? $this->normalize_media_relative_file( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) : '';
		$current_url = function_exists( 'wp_get_attachment_url' ) ? $this->esc_url_value( (string) wp_get_attachment_url( $attachment_id ) ) : '';
		$metadata_paths = $this->media_attachment_metadata_relative_paths( $attachment_id, $current_relative );

		$match_type = 'filename_candidate';
		$match_score = 40;
		$matched_relative = $current_relative;
		if ( $requested_relative_file === $current_relative ) {
			$match_type = 'attached_file';
			$match_score = 100;
		} elseif ( in_array( $requested_relative_file, (array) ( $metadata_paths['original'] ?? array() ), true ) ) {
			$match_type = 'metadata_original_file';
			$match_score = 95;
			$matched_relative = $requested_relative_file;
		} elseif ( in_array( $requested_relative_file, (array) ( $metadata_paths['sizes'] ?? array() ), true ) ) {
			$match_type = 'metadata_size_file';
			$match_score = 90;
			$matched_relative = $requested_relative_file;
		} elseif ( basename( $requested_relative_file ) === basename( $current_relative ) ) {
			$match_type = 'basename_match';
			$match_score = 60;
		}

		return array(
			'attachment_id'         => $attachment_id,
			'title'                 => sanitize_text_field( (string) get_the_title( $attachment_id ) ),
			'mime_type'             => function_exists( 'get_post_mime_type' ) ? sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ) : sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) ),
			'url'                   => $current_url,
			'relative_file'         => $current_relative,
			'matched_relative_file' => $matched_relative,
			'match_type'            => $match_type,
			'match_score'           => $match_score,
			'evidence'              => array(
				'requested_relative_file' => $requested_relative_file,
				'primary_attached_file'   => $current_relative,
				'metadata_original_files' => array_values( (array) ( $metadata_paths['original'] ?? array() ) ),
				'metadata_size_files'     => array_slice( array_values( (array) ( $metadata_paths['sizes'] ?? array() ) ), 0, 20 ),
				'edit_allowed'            => current_user_can( 'edit_post', $attachment_id ),
			),
			'edit_link'             => function_exists( 'get_edit_post_link' ) ? $this->esc_url_value( (string) get_edit_post_link( $attachment_id, 'raw' ) ) : '',
		);
	}

	/**
	 * Returns original and size variant relative paths from attachment metadata.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $current_relative Current attached file.
	 * @return array<string,array<int,string>>
	 */
	private function media_attachment_metadata_relative_paths( $attachment_id, $current_relative ) {
		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : false;
		$metadata = is_array( $metadata ) ? $metadata : array();
		$current_relative = $this->normalize_media_relative_file( $current_relative );
		$base_dir = '' !== $current_relative ? dirname( $current_relative ) : '';
		$base_dir = '.' === $base_dir ? '' : $base_dir;
		$original = array_filter(
			array_unique(
				array_map(
					array( $this, 'normalize_media_relative_file' ),
					array(
						$current_relative,
						(string) ( $metadata['file'] ?? '' ),
						(string) ( $metadata['original_image'] ?? '' ),
					)
				)
			)
		);
		$sizes = array();
		foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}
			$file = $this->normalize_media_relative_file( (string) $size['file'] );
			$sizes[] = '' !== $base_dir ? $base_dir . '/' . $file : $file;
		}

		return array(
			'original' => array_values( $original ),
			'sizes'   => array_values( array_unique( array_filter( $sizes ) ) ),
		);
	}

	/**
	 * Test fallback for exact relative file lookups.
	 *
	 * @param string $relative_file Relative file.
	 * @return int[]
	 */
	private function scan_unit_attachment_ids_for_relative_file( $relative_file ) {
		$posts = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) ? $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] : array();
		$ids = array();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || 'attachment' !== sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			$attachment_id = $this->absint_value( $post->ID ?? 0 );
			$current = function_exists( 'get_post_meta' ) ? $this->normalize_media_relative_file( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) : '';
			if ( $relative_file === $current ) {
				$ids[] = $attachment_id;
			}
		}
		return $ids;
	}

	/**
	 * Test fallback for filename candidate lookups.
	 *
	 * @param string $basename Basename.
	 * @param string $search Search stem.
	 * @param int    $limit Limit.
	 * @return int[]
	 */
	private function scan_unit_attachment_ids_for_filename( $basename, $search, $limit ) {
		$posts = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) ? $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] : array();
		$ids = array();
		foreach ( $posts as $post ) {
			if ( count( $ids ) >= $limit || ! is_object( $post ) || 'attachment' !== sanitize_key( (string) ( $post->post_type ?? '' ) ) ) {
				continue;
			}
			$attachment_id = $this->absint_value( $post->ID ?? 0 );
			$current = function_exists( 'get_post_meta' ) ? $this->normalize_media_relative_file( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) : '';
			$title = sanitize_text_field( (string) ( $post->post_title ?? '' ) );
			if ( basename( $current ) === $basename || ( '' !== $search && false !== stripos( $title . ' ' . $current, $search ) ) ) {
				$ids[] = $attachment_id;
			}
		}
		return $ids;
	}

	/**
	 * Queries media attachment ids for a bounded inventory scan.
	 *
	 * @param string $mime_type Mime type filter.
	 * @param string $search Search term.
	 * @param int    $per_page Per page.
	 * @param int    $page Page.
	 * @param int[]  $attachment_ids Optional bounded attachment identities.
	 * @param string $stable_order Stable ordering mode for resumable scans.
	 * @return array<string,mixed>
	 */
	private function query_media_inventory( $mime_type, $search, $per_page, $page, $attachment_ids = array(), $stable_order = 'date_desc' ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'id_asc' === $stable_order ? 'ID' : 'date',
			'order'          => 'id_asc' === $stable_order ? 'ASC' : 'DESC',
			'fields'         => 'ids',
		);
		if ( '' !== $mime_type ) {
			$args['post_mime_type'] = $mime_type;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! empty( $attachment_ids ) ) {
			$args['post__in']       = array_values( array_map( array( $this, 'absint_value' ), $attachment_ids ) );
			$args['orderby']        = 'post__in';
			$args['posts_per_page'] = min( 20, count( $args['post__in'] ) );
			$args['paged']          = 1;
		}

		if ( class_exists( '\WP_Query' ) ) {
			$query = new \WP_Query( $args );
			return array(
				'attachment_ids' => is_array( $query->posts ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $query->posts ) ) : array(),
				'total'          => (int) ( $query->found_posts ?? 0 ),
			);
		}

		$attachments = isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			? array_values( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] )
			: array();
		$filtered = array();
		foreach ( $attachments as $attachment ) {
			if ( ! is_object( $attachment ) || 'attachment' !== sanitize_key( (string) ( $attachment->post_type ?? '' ) ) ) {
				continue;
			}
			$current_mime = sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) );
			$current_id = $this->absint_value( $attachment->ID ?? 0 );
			if ( ! empty( $attachment_ids ) && ! in_array( $current_id, $attachment_ids, true ) ) {
				continue;
			}
			if ( '' !== $mime_type && false === strpos( $current_mime, $mime_type ) ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $attachment->post_title ?? '' ) );
			if ( '' !== $search && false === stripos( $title, $search ) ) {
				continue;
			}
			$filtered[] = $current_id;
		}
		if ( 'id_asc' === $stable_order ) {
			sort( $filtered, SORT_NUMERIC );
		}

		return array(
			'attachment_ids' => array_slice( array_filter( $filtered ), ( $page - 1 ) * $per_page, $per_page ),
			'total'          => count( $filtered ),
		);
	}

	/**
	 * Builds one media inventory health row.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>
	 */
	private function build_media_inventory_health_row( $attachment_id ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$attachment = get_post( $attachment_id );
		$title = is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_title ?? '' ) ) : '';
		$caption = is_object( $attachment ) ? $this->sanitize_metadata_text( (string) ( $attachment->post_excerpt ?? '' ) ) : '';
		$description = is_object( $attachment ) ? $this->sanitize_metadata_text( (string) ( $attachment->post_content ?? '' ) ) : '';
		$mime_type = function_exists( 'get_post_mime_type' )
			? sanitize_text_field( (string) get_post_mime_type( $attachment_id ) )
			: ( is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) ) : '' );
		$alt = function_exists( 'get_post_meta' ) ? sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) : '';
		$source_type = function_exists( 'get_post_meta' ) ? $this->normalize_media_source_type( get_post_meta( $attachment_id, '_npcink_ai_media_source_type', true ) ) : '';
		$source_url = function_exists( 'get_post_meta' ) ? $this->esc_url_value( (string) get_post_meta( $attachment_id, '_npcink_ai_source_page_url', true ) ) : '';
		if ( '' === $source_url && function_exists( 'get_post_meta' ) ) {
			$source_url = $this->esc_url_value( (string) get_post_meta( $attachment_id, '_npcink_ai_media_source_page_url', true ) );
		}
		$photographer_name = function_exists( 'get_post_meta' ) ? sanitize_text_field( (string) get_post_meta( $attachment_id, '_npcink_ai_media_photographer_name', true ) ) : '';
		$attribution = function_exists( 'get_post_meta' ) ? $this->sanitize_metadata_text( (string) get_post_meta( $attachment_id, '_npcink_ai_attribution_text', true ) ) : '';
		if ( '' === $attribution && function_exists( 'get_post_meta' ) ) {
			$attribution = $this->sanitize_metadata_text( (string) get_post_meta( $attachment_id, '_npcink_ai_media_attribution_text', true ) );
		}
		$copyright_notice = function_exists( 'get_post_meta' ) ? $this->sanitize_metadata_text( (string) get_post_meta( $attachment_id, '_npcink_ai_media_copyright_notice', true ) ) : '';
		$issues = array();
		if ( '' === $alt && 0 === strpos( $mime_type, 'image/' ) ) {
			$issues[] = 'missing_alt';
		}
		if ( '' === $caption ) {
			$issues[] = 'missing_caption';
		}
		if ( '' === $description ) {
			$issues[] = 'missing_description';
		}
		if ( ! $this->media_source_metadata_has_coverage(
			array(
				'source_type'      => $source_type,
				'source_page_url'  => $source_url,
				'attribution_text' => $attribution,
				'copyright_notice' => $copyright_notice,
			)
		) ) {
			$issues[] = 'missing_source';
		}
		$format_inspection = $this->build_media_format_inspection( $attachment_id, array() );
		if ( ! empty( $format_inspection['format_plan']['needs_attention'] ) ) {
			$issues[] = 'format_attention';
		}

		$modified_gmt = is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_modified_gmt ?? '' ) ) : '';
		$url = function_exists( 'wp_get_attachment_url' ) ? $this->esc_url_value( (string) wp_get_attachment_url( $attachment_id ) ) : '';
		$file_path = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
		if ( '' !== $file_path && ! is_readable( $file_path ) && function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir = (string) ( $upload_dir['basedir'] ?? '' );
			if ( '' !== $base_dir && 0 !== strpos( $file_path, '/' ) ) {
				$file_path = rtrim( $base_dir, '/\\' ) . '/' . ltrim( $file_path, '/' );
			}
		}
		$media_fingerprint = '' !== $file_path && is_readable( $file_path ) ? 'sha256:' . strtolower( (string) hash_file( 'sha256', $file_path ) ) : '';
		$metadata_fingerprint = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'attachment_id' => $attachment_id,
					'modified_gmt'  => $modified_gmt,
					'mime_type'     => $mime_type,
					'url'           => $url,
					'title'         => $title,
					'alt'           => $alt,
					'caption'       => $caption,
					'description'   => $description,
				)
			)
		);

		return array(
			'attachment_id'    => $attachment_id,
			'title'            => $title,
			'mime_type'        => $mime_type,
			'url'              => $url,
			'modified_gmt'     => $modified_gmt,
			'media_fingerprint' => $media_fingerprint,
			'metadata_fingerprint' => $metadata_fingerprint,
			'alt'              => $alt,
			'caption'          => $caption,
			'description'      => $description,
			'source_type'      => $source_type,
			'source_page_url'  => $source_url,
			'photographer_name' => $photographer_name,
			'attribution_text' => $attribution,
			'copyright_notice' => $copyright_notice,
			'format_inspection' => $format_inspection,
			'issue_count'   => count( $issues ),
			'issues'        => $issues,
			'edit_link'     => function_exists( 'get_edit_post_link' ) ? $this->esc_url_value( (string) get_edit_post_link( $attachment_id, 'raw' ) ) : '',
		);
	}

	/**
	 * Builds a read-only media format inspection payload.
	 *
	 * @param int                 $attachment_id Attachment id.
	 * @param array<string,mixed> $args Inspection options.
	 * @return array<string,mixed>
	 */
	private function build_media_format_inspection( $attachment_id, array $args ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		$mime_type = function_exists( 'get_post_mime_type' )
			? sanitize_text_field( (string) get_post_mime_type( $attachment_id ) )
			: ( is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) ) : '' );
		$url = function_exists( 'wp_get_attachment_url' ) ? $this->esc_url_value( (string) wp_get_attachment_url( $attachment_id ) ) : '';
		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		if ( ! is_array( $metadata ) && function_exists( 'get_post_meta' ) ) {
			$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		}
			$metadata = is_array( $metadata ) ? $metadata : array();
			$attached_file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
			$current_relative_file = $this->normalize_media_relative_file( (string) ( function_exists( 'get_post_meta' ) ? get_post_meta( $attachment_id, '_wp_attached_file', true ) : ( $metadata['file'] ?? '' ) ) );
			if ( '' === $current_relative_file ) {
				$current_relative_file = $this->normalize_media_relative_file( (string) ( $metadata['file'] ?? '' ) );
			}
			$media_file_path = $this->resolve_media_file_path( $attached_file, $current_relative_file );
			$file_basename = $this->resolve_media_file_basename( $metadata, $attached_file, $url );
			$width = $this->absint_value( $metadata['width'] ?? 0 );
			$height = $this->absint_value( $metadata['height'] ?? 0 );
			$filesize_bytes = $this->resolve_media_filesize_bytes( $metadata, $media_file_path );
			$target_max_width = max( 320, min( 7680, $this->absint_value( $args['target_max_width'] ?? 1920 ) ) );
			$large_file_threshold = max( 102400, min( 104857600, $this->absint_value( $args['large_file_threshold_bytes'] ?? 524288 ) ) );
			$preferred_format = sanitize_key( (string) ( $args['preferred_format'] ?? 'webp' ) );
		if ( ! in_array( $preferred_format, array( 'webp', 'avif', 'original' ), true ) ) {
			$preferred_format = 'webp';
		}
		$source_format = $this->resolve_media_source_format( $mime_type, $file_basename );
		$is_image = 0 === strpos( $mime_type, 'image/' );
		$is_modern_format = in_array( $source_format, array( 'webp', 'avif' ), true );
		$is_convertible_raster = $is_image && in_array( $source_format, array( 'jpeg', 'jpg', 'png' ), true );
		$should_convert = $is_convertible_raster && ! $is_modern_format && 'original' !== $preferred_format;
		$should_resize = $is_image && $width > $target_max_width;
		$should_compress = $is_image && $filesize_bytes >= $large_file_threshold && in_array( $source_format, array( 'jpeg', 'jpg', 'png', 'webp' ), true );
		$warnings = array();
		if ( $should_convert ) {
			$warnings[] = 'legacy_image_format';
		}
		if ( $should_resize ) {
			$warnings[] = 'image_too_wide';
		}
		if ( $should_compress ) {
			$warnings[] = 'large_file';
		}
		if ( $is_image && ( 0 === $width || 0 === $height ) ) {
			$warnings[] = 'missing_dimensions';
		}
		if ( $is_image && 0 === $filesize_bytes ) {
			$warnings[] = 'missing_filesize';
		}
		$storage = $this->build_media_storage_inspection( $attachment_id, $current_relative_file, $attached_file, $media_file_path, $url );
		if ( '' !== (string) ( $storage['blocked_reason'] ?? '' ) ) {
			$warnings[] = sanitize_key( (string) $storage['blocked_reason'] );
		}
		$needs_attention = $should_convert || $should_resize || $should_compress;
		$recommended_format = $should_convert ? $preferred_format : ( '' !== $source_format ? $source_format : 'original' );

		return array(
			'attachment_id'     => $attachment_id,
			'title'             => is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_title ?? '' ) ) : '',
				'mime_type'         => $mime_type,
				'source_format'     => $source_format,
				'url'               => $url,
				'current_relative_file' => $current_relative_file,
				'file_basename'     => $file_basename,
				'width'             => $width,
				'height'            => $height,
				'filesize_bytes'    => $filesize_bytes,
				'content_hashes'    => $this->resolve_media_content_hashes( $media_file_path ),
				'metadata_available' => ! empty( $metadata ),
				'storage'           => $storage,
			'format_plan'       => array(
				'needs_attention'            => $needs_attention,
				'should_convert'             => $should_convert,
				'should_resize'              => $should_resize,
				'should_compress'            => $should_compress,
				'should_generate_derivative' => $needs_attention,
				'recommended_format'         => $recommended_format,
				'recommended_max_width'      => $should_resize ? $target_max_width : $width,
				'preserve_original'          => true,
				'replace_original'           => false,
				'recommended_operation'      => $needs_attention ? 'generate_optimized_derivative' : 'keep_current_asset',
				'reason'                     => $this->build_media_format_reason( $should_convert, $should_resize, $should_compress, $source_format, $filesize_bytes, $width, $target_max_width ),
			),
			'warnings'          => $warnings,
			'summary'           => array(
				'is_image'                   => $is_image,
				'target_max_width'           => $target_max_width,
				'large_file_threshold_bytes' => $large_file_threshold,
				'preferred_format'           => $preferred_format,
			),
		);
	}

	/**
	 * Resolves a safe media file basename for reporting.
	 *
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @param string              $attached_file Attached file path.
	 * @param string              $url Attachment URL.
	 * @return string
	 */
	private function resolve_media_file_basename( array $metadata, $attached_file, $url ) {
		$file = sanitize_text_field( (string) ( $metadata['file'] ?? '' ) );
		if ( '' === $file && '' !== (string) $attached_file ) {
			$file = (string) $attached_file;
		}
		if ( '' === $file && '' !== (string) $url ) {
			$file = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		}

		return $this->sanitize_file_name_value( (string) basename( $file ) );
	}

	/**
	 * Resolves media file size without exposing the file path.
	 *
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @param string              $attached_file Attached file path.
	 * @return int
	 */
		private function resolve_media_filesize_bytes( array $metadata, $attached_file ) {
			$metadata_size = $this->absint_value( $metadata['filesize'] ?? $metadata['file_size'] ?? 0 );
			if ( $metadata_size > 0 ) {
				return $metadata_size;
			}
		if ( '' !== (string) $attached_file && is_readable( (string) $attached_file ) ) {
			$size = filesize( (string) $attached_file );
			return false === $size ? 0 : $this->absint_value( $size );
		}

			return 0;
		}

		/**
		 * Resolves a readable media path without exposing it in ability output.
		 *
		 * @param string $attached_file Attached file path or relative file.
		 * @param string $relative_file Uploads-relative file.
		 * @return string
		 */
		private function resolve_media_file_path( $attached_file, $relative_file ) {
			$attached_file = (string) $attached_file;
			if ( '' !== $attached_file && is_readable( $attached_file ) ) {
				return $attached_file;
			}
			$relative_file = $this->normalize_media_relative_file( $relative_file );
			if ( '' === $relative_file || ! function_exists( 'wp_upload_dir' ) ) {
				return '';
			}
			$upload_dir = wp_upload_dir();
			$basedir = is_array( $upload_dir ) ? (string) ( $upload_dir['basedir'] ?? '' ) : '';
			if ( '' === $basedir ) {
				return '';
			}
			$candidate = rtrim( $basedir, "/\\" ) . '/' . $relative_file;
			return is_readable( $candidate ) ? $candidate : '';
		}

		/**
		 * Builds conservative storage-readiness evidence without exposing paths or secrets.
		 *
		 * @param int    $attachment_id Attachment id.
		 * @param string $relative_file Uploads-relative file.
		 * @param string $attached_file Raw attached file value.
		 * @param string $media_file_path Resolved readable local path, if any.
		 * @param string $url Public attachment URL.
		 * @return array<string,mixed>
		 */
		private function build_media_storage_inspection( $attachment_id, $relative_file, $attached_file, $media_file_path, $url ) {
			$relative_file       = $this->normalize_media_relative_file( $relative_file );
			$url                 = $this->esc_url_value( (string) $url );
			$local_file_readable = '' !== (string) $media_file_path && is_readable( (string) $media_file_path );
			$remote_url          = $this->media_url_looks_remote_storage( $url );
			$provider            = $remote_url ? 'remote_object_storage' : 'local_uploads';
			$source_read_mode    = $local_file_readable ? 'local_file' : 'blocked';
			$write_mode          = 'local_uploads';
			$restore_mode        = 'local_backup';
			$blocked_reason      = '';

			if ( 'remote_object_storage' === $provider ) {
				$write_mode   = 'blocked';
				$restore_mode = 'blocked';
				$blocked_reason = $local_file_readable ? 'remote_storage_write_requires_adapter' : 'remote_storage_requires_adapter';
			}

			$storage = array(
				'provider'              => $provider,
				'adapter'               => 'none',
				'attachment_id'         => $this->absint_value( $attachment_id ),
				'current_relative_file' => $relative_file,
				'canonical_url'         => $url,
				'local_file_readable'   => $local_file_readable,
				'source_read_mode'      => $source_read_mode,
				'write_mode'            => $write_mode,
				'restore_mode'          => $restore_mode,
				'cache_purge_required'  => 'remote_object_storage' === $provider,
				'blocked_reason'        => $blocked_reason,
			);

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'npcink_abilities_toolkit_media_storage_inspection', $storage, $this->absint_value( $attachment_id ), $relative_file, $url, (string) $attached_file );
				if ( is_array( $filtered ) ) {
					$storage = array_merge( $storage, $filtered );
				}
			}

			return $this->sanitize_media_storage_inspection( $storage );
		}

		/**
		 * Returns whether a public URL no longer points at the current uploads base URL.
		 *
		 * @param string $url Public attachment URL.
		 * @return bool
		 */
		private function media_url_looks_remote_storage( $url ) {
			$url = (string) $url;
			if ( '' === $url || ! function_exists( 'wp_upload_dir' ) ) {
				return false;
			}
			$upload_dir = wp_upload_dir();
			$baseurl    = is_array( $upload_dir ) ? esc_url_raw( (string) ( $upload_dir['baseurl'] ?? '' ) ) : '';
			if ( '' === $baseurl ) {
				return false;
			}

			$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$base_host = strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) );
			if ( '' !== $url_host && '' !== $base_host && $url_host !== $base_host ) {
				return true;
			}

			return 0 !== strpos( rtrim( $url, '/' ) . '/', rtrim( $baseurl, '/' ) . '/' );
		}

		/**
		 * Sanitizes media storage evidence after host filters run.
		 *
		 * @param array<string,mixed> $storage Storage evidence.
		 * @return array<string,mixed>
		 */
		private function sanitize_media_storage_inspection( array $storage ) {
			$provider = sanitize_key( (string) ( $storage['provider'] ?? 'unknown' ) );
			if ( ! in_array( $provider, array( 'local_uploads', 'remote_object_storage', 'unknown' ), true ) ) {
				$provider = 'unknown';
			}
			$source_read_mode = sanitize_key( (string) ( $storage['source_read_mode'] ?? 'blocked' ) );
			if ( ! in_array( $source_read_mode, array( 'local_file', 'signed_url', 'public_url', 'blocked' ), true ) ) {
				$source_read_mode = 'blocked';
			}
			$write_mode = sanitize_key( (string) ( $storage['write_mode'] ?? 'blocked' ) );
			if ( ! in_array( $write_mode, array( 'local_uploads', 'local_upload_then_offload', 'provider_api', 'blocked' ), true ) ) {
				$write_mode = 'blocked';
			}
			$restore_mode = sanitize_key( (string) ( $storage['restore_mode'] ?? 'blocked' ) );
			if ( ! in_array( $restore_mode, array( 'local_backup', 'provider_backup', 'blocked' ), true ) ) {
				$restore_mode = 'blocked';
			}

			return array(
				'provider'              => $provider,
				'adapter'               => sanitize_key( (string) ( $storage['adapter'] ?? 'none' ) ),
				'attachment_id'         => $this->absint_value( $storage['attachment_id'] ?? 0 ),
				'current_relative_file' => $this->normalize_media_relative_file( (string) ( $storage['current_relative_file'] ?? '' ) ),
				'canonical_url'         => $this->esc_url_value( (string) ( $storage['canonical_url'] ?? '' ) ),
				'local_file_readable'   => ! empty( $storage['local_file_readable'] ),
				'source_read_mode'      => $source_read_mode,
				'write_mode'            => $write_mode,
				'restore_mode'          => $restore_mode,
				'cache_purge_required'  => ! empty( $storage['cache_purge_required'] ),
				'blocked_reason'        => sanitize_key( (string) ( $storage['blocked_reason'] ?? '' ) ),
			);
		}

		/**
		 * Resolves bounded content hashes for the current media file.
		 *
		 * @param string $file_path Internal file path.
		 * @return array<string,mixed>
		 */
		private function resolve_media_content_hashes( $file_path ) {
			$file_path = (string) $file_path;
			if ( '' === $file_path || ! is_readable( $file_path ) ) {
				return array(
					'available' => false,
					'md5'       => '',
					'sha256'    => '',
				);
			}

			return array(
				'available' => true,
				'md5'       => (string) md5_file( $file_path ),
				'sha256'    => (string) hash_file( 'sha256', $file_path ),
			);
		}

		/**
		 * Resolves source format from MIME type or file extension.
		 *
	 * @param string $mime_type MIME type.
	 * @param string $file_basename File basename.
	 * @return string
	 */
	private function resolve_media_source_format( $mime_type, $file_basename ) {
		$mime_type = strtolower( sanitize_text_field( (string) $mime_type ) );
		$map = array(
			'image/jpeg'    => 'jpeg',
			'image/jpg'     => 'jpeg',
			'image/png'     => 'png',
			'image/webp'    => 'webp',
			'image/avif'    => 'avif',
			'image/gif'     => 'gif',
			'image/svg+xml' => 'svg',
		);
		if ( isset( $map[ $mime_type ] ) ) {
			return $map[ $mime_type ];
		}
		$extension = strtolower( pathinfo( (string) $file_basename, PATHINFO_EXTENSION ) );
		$extension = sanitize_key( $extension );
		return 'jpg' === $extension ? 'jpeg' : $extension;
	}

	/**
	 * Builds a concise human-readable format recommendation reason.
	 *
	 * @param bool   $should_convert Whether conversion is recommended.
	 * @param bool   $should_resize Whether resizing is recommended.
	 * @param bool   $should_compress Whether compression is recommended.
	 * @param string $source_format Source format.
	 * @param int    $filesize_bytes File size.
	 * @param int    $width Width.
	 * @param int    $target_max_width Target max width.
	 * @return string
	 */
	private function build_media_format_reason( $should_convert, $should_resize, $should_compress, $source_format, $filesize_bytes, $width, $target_max_width ) {
		$reasons = array();
		if ( $should_convert ) {
			$reasons[] = 'Current image uses legacy ' . sanitize_key( (string) $source_format ) . ' encoding.';
		}
		if ( $should_resize ) {
			$reasons[] = 'Image width ' . $this->absint_value( $width ) . ' exceeds target max width ' . $this->absint_value( $target_max_width ) . '.';
		}
		if ( $should_compress ) {
			$reasons[] = 'File size ' . $this->absint_value( $filesize_bytes ) . ' bytes exceeds the configured threshold.';
		}
		if ( empty( $reasons ) ) {
			return 'Current asset format can continue to be used.';
		}

		return implode( ' ', $reasons );
	}

	/**
	 * Builds read-only governance guidance for format-attention manual review.
	 *
	 * @param array<string,mixed> $format_plan Format plan.
	 * @param string[]            $warnings Format warning keys.
	 * @return array<string,mixed>
	 */
	private function build_media_format_manual_review_context( array $format_plan, array $warnings ) {
		$warnings = array_values(
			array_filter(
				array_map( 'sanitize_key', $warnings ),
				static function ( $warning ) {
					return '' !== $warning;
				}
			)
		);
		$should_resize = ! empty( $format_plan['should_resize'] );
		$should_compress = ! empty( $format_plan['should_compress'] );
		$should_convert = ! empty( $format_plan['should_convert'] );
		$target_future_ability = 'manual_review';
		if ( $should_resize || $should_compress ) {
			$target_future_ability = 'npcink-abilities-toolkit/build-media-derivative-cloud-request';
		} elseif ( $should_convert ) {
			$target_future_ability = 'npcink-abilities-toolkit/build-media-derivative-cloud-request';
		}

		return array(
			'detected_reason'        => $warnings[0] ?? 'format_review_requested',
			'detected_reasons'       => $warnings,
			'suggested_operation'    => $this->suggest_media_format_operation( $format_plan ),
			'target_future_ability'  => $target_future_ability,
			'future_ability_options' => array(
				'npcink-abilities-toolkit/build-media-derivative-cloud-request',
				'npcink-abilities-toolkit/optimize-media-asset',
				'npcink-abilities-toolkit/replace-media-file',
			),
			'write_action_generated' => false,
			'estimated_risk'         => 'high',
			'boundary'               => 'read_only_manual_review',
			'reason'                 => __( 'Asset resize, compression, conversion, or replacement changes files and must stay out of the media inventory metadata fix action path.', 'npcink-abilities-toolkit' ),
		);
	}

	/**
	 * Chooses a concise operation label for format-attention review.
	 *
	 * @param array<string,mixed> $format_plan Format plan.
	 * @return string
	 */
	private function suggest_media_format_operation( array $format_plan ) {
		$should_resize = ! empty( $format_plan['should_resize'] );
		$should_compress = ! empty( $format_plan['should_compress'] );
		$should_convert = ! empty( $format_plan['should_convert'] );
		if ( $should_resize || ( $should_compress && $should_convert ) ) {
			return 'generate_optimized_derivative';
		}
		if ( $should_compress ) {
			return 'compress';
		}
		if ( $should_convert ) {
			return 'consider_format_conversion';
		}

		return 'manual_review';
	}

	/**
	 * Builds one normalized media context row.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $role Media role.
	 * @return array<string,mixed>
	 */
	private function build_media_context_row( $attachment_id, $role ) {
		$attachment_id = $this->absint_value( $attachment_id );
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		$alt = function_exists( 'get_post_meta' ) ? sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) : '';

		return array(
			'id'          => $attachment_id,
			'role'        => sanitize_key( (string) $role ),
			'title'       => is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_title ?? '' ) ) : '',
			'mime_type'   => is_object( $attachment ) ? sanitize_text_field( (string) ( $attachment->post_mime_type ?? '' ) ) : '',
			'url'         => function_exists( 'wp_get_attachment_url' ) ? $this->esc_url_value( (string) wp_get_attachment_url( $attachment_id ) ) : '',
			'alt'         => $alt,
			'caption'     => is_object( $attachment ) ? $this->sanitize_metadata_text( (string) ( $attachment->post_excerpt ?? '' ) ) : '',
			'description' => is_object( $attachment ) ? $this->sanitize_metadata_text( (string) ( $attachment->post_content ?? '' ) ) : '',
		);
	}

	/**
	 * Collects media assets from explicit rows or inline image HTML.
	 *
	 * @param array<int,array<string,mixed>> $media_assets Media assets.
	 * @param string                         $article_content Article content.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_media_assets_for_optimization( array $media_assets, $article_content ) {
		$assets = array();
		foreach ( $media_assets as $asset ) {
			$asset = is_array( $asset ) ? $asset : array();
			$assets[] = array(
				'attachment_id'           => $this->absint_value( $asset['attachment_id'] ?? 0 ),
				'url'                     => $this->esc_url_value( (string) ( $asset['url'] ?? '' ) ),
				'title'                   => sanitize_text_field( (string) ( $asset['title'] ?? '' ) ),
				'alt'                     => sanitize_text_field( (string) ( $asset['alt'] ?? '' ) ),
				'caption'                 => sanitize_textarea_field( (string) ( $asset['caption'] ?? '' ) ),
				'description'             => sanitize_textarea_field( (string) ( $asset['description'] ?? '' ) ),
				'source_type'             => $this->normalize_media_source_type( $asset['source_type'] ?? '' ),
				'image_origin'            => sanitize_key( (string) ( $asset['image_origin'] ?? '' ) ),
				'generated_prompt'        => sanitize_textarea_field( (string) ( $asset['generated_prompt'] ?? '' ) ),
				'image_profile'           => sanitize_key( (string) ( $asset['image_profile'] ?? '' ) ),
				'mime_type'               => sanitize_text_field( (string) ( $asset['mime_type'] ?? '' ) ),
				'role'                    => sanitize_key( (string) ( $asset['role'] ?? '' ) ),
				'provider_hint'           => sanitize_key( (string) ( $asset['provider_hint'] ?? '' ) ),
				'provider_title'          => sanitize_text_field( (string) ( $asset['provider_title'] ?? '' ) ),
				'provider_description'    => sanitize_textarea_field( (string) ( $asset['provider_description'] ?? '' ) ),
				'source_page_url'         => $this->esc_url_value( (string) ( $asset['source_page_url'] ?? '' ) ),
				'photographer_name'       => sanitize_text_field( (string) ( $asset['photographer_name'] ?? '' ) ),
				'attribution_text'        => sanitize_textarea_field( (string) ( $asset['attribution_text'] ?? '' ) ),
				'license'                 => sanitize_text_field( (string) ( $asset['license'] ?? $asset['license_policy'] ?? '' ) ),
				'copyright_notice'        => sanitize_textarea_field( (string) ( $asset['copyright_notice'] ?? '' ) ),
				'section_heading'         => sanitize_text_field( (string) ( $asset['section_heading'] ?? '' ) ),
				'section_summary'         => sanitize_textarea_field( (string) ( $asset['section_summary'] ?? '' ) ),
				'file_name'               => sanitize_text_field( (string) ( $asset['file_name'] ?? '' ) ),
				'vision_fallback_allowed' => ! empty( $asset['vision_fallback_allowed'] ),
			);
		}
		if ( ! empty( $assets ) ) {
			return $assets;
		}

		if ( preg_match_all( '/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/iu', (string) $article_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$html = (string) ( $match[0] ?? '' );
				$url = $this->esc_url_value( (string) ( $match[1] ?? '' ) );
				$alt = '';
				if ( preg_match( '/alt=["\']([^"\']*)["\']/iu', $html, $alt_match ) ) {
					$alt = sanitize_text_field( (string) ( $alt_match[1] ?? '' ) );
				}
				if ( '' === $url ) {
					continue;
				}
				$assets[] = array(
					'attachment_id' => 0,
					'url'           => $url,
					'title'         => '',
					'alt'           => $alt,
					'caption'       => '',
					'description'   => '',
					'mime_type'     => '',
					'role'          => 'inline',
				);
			}
		}

		return $assets;
	}

	/**
	 * Trims media SEO text.
	 *
	 * @param string $value Value.
	 * @param int    $max_len Max length.
	 * @return string
	 */
	private function trim_media_seo_text( $value, $max_len = 180 ) {
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) ?? '' );
		if ( '' === $value ) {
			return '';
		}

		return $this->strlen_value( $value ) > $max_len ? trim( $this->substr_value( $value, 0, max( 0, $max_len - 1 ) ) ) : $value;
	}

	/**
	 * Resolves media license label.
	 *
	 * @param array<string,mixed> $asset Asset.
	 * @param string              $image_origin Image origin.
	 * @return string
	 */
	private function resolve_media_license_label( array $asset, $image_origin ) {
		$license = sanitize_text_field( (string) ( $asset['license'] ?? $asset['license_policy'] ?? '' ) );
		if ( '' !== $license ) {
			return $license;
		}
		$source_page_url = $this->esc_url_value( (string) ( $asset['source_page_url'] ?? '' ) );
		$attribution_text = sanitize_textarea_field( (string) ( $asset['attribution_text'] ?? '' ) );
		$photographer_name = sanitize_text_field( (string) ( $asset['photographer_name'] ?? '' ) );
		if ( 'public_free' === $image_origin && ( '' !== $source_page_url || '' !== $attribution_text || '' !== $photographer_name ) ) {
			return 'attribution_required';
		}
		if ( 'ai_generated' === $image_origin ) {
			return 'generated_content';
		}

		return '';
	}

	/**
	 * Resolves media disclosure readiness.
	 *
	 * @param string $image_origin Image origin.
	 * @param string $metadata_confidence Metadata confidence.
	 * @param string $license License.
	 * @param string $source_page_url Source page URL.
	 * @param string $attribution_text Attribution text.
	 * @param string $generated_prompt Generated prompt.
	 * @return string
	 */
	private function resolve_media_disclosure_readiness( $image_origin, $metadata_confidence, $license, $source_page_url, $attribution_text, $generated_prompt ) {
		$image_origin = sanitize_key( (string) $image_origin );
		$metadata_confidence = sanitize_key( (string) $metadata_confidence );
		if ( 'public_free' === $image_origin ) {
			return '' !== $license && '' !== $attribution_text ? 'ready_with_attribution' : 'needs_review';
		}
		if ( 'ai_generated' === $image_origin ) {
			return '' !== $generated_prompt || in_array( $metadata_confidence, array( 'high', 'medium' ), true ) ? 'disclosure_recommended' : 'needs_review';
		}
		if ( 'manual_upload' === $image_origin ) {
			if ( 'low' === $metadata_confidence ) {
				return 'needs_review';
			}
			return '' !== $license && '' !== $source_page_url ? 'ready_with_source_note' : 'needs_review';
		}

		return '';
	}

	/**
	 * Normalizes media source type.
	 *
	 * @param mixed  $value Source type.
	 * @param string $fallback Fallback source type.
	 * @return string
	 */
	private function normalize_media_source_type( $value, $fallback = '' ) {
		$value    = sanitize_key( (string) $value );
		$fallback = sanitize_key( (string) $fallback );
		$allowed  = array( 'owned', 'ai_generated', 'stock', 'external', 'test' );
		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}
		return in_array( $fallback, $allowed, true ) ? $fallback : '';
	}

	/**
	 * Infers canonical source type from explicit fields and provider hints.
	 *
	 * @param array<string,mixed> $asset Media descriptor.
	 * @param string              $image_origin Image origin.
	 * @return string
	 */
	private function infer_media_source_type( array $asset, $image_origin ) {
		$explicit = $this->normalize_media_source_type( $asset['source_type'] ?? '' );
		if ( '' !== $explicit ) {
			return $explicit;
		}
		$image_origin = sanitize_key( (string) $image_origin );
		if ( 'ai_generated' === $image_origin ) {
			return 'ai_generated';
		}
		$provider_hint = sanitize_key( (string) ( $asset['provider_hint'] ?? '' ) );
		if ( in_array( $provider_hint, array( 'pexels', 'openverse', 'unsplash', 'pixabay', 'public_free', 'stock' ), true ) ) {
			return 'stock';
		}
		$source_page_url = $this->esc_url_value( (string) ( $asset['source_page_url'] ?? '' ) );
		if ( '' !== $source_page_url ) {
			return 'external';
		}
		return 'owned';
	}

	/**
	 * Adds conservative source metadata defaults for known source types.
	 *
	 * @param array<string,mixed> $metadata Source metadata.
	 * @return array<string,string>
	 */
	private function media_source_metadata_with_defaults( array $metadata ) {
		$source_type = $this->normalize_media_source_type( $metadata['source_type'] ?? '' );
		$source_page_url = $this->esc_url_value( (string) ( $metadata['source_page_url'] ?? '' ) );
		$photographer_name = sanitize_text_field( (string) ( $metadata['photographer_name'] ?? '' ) );
		$attribution_text = $this->sanitize_metadata_text( (string) ( $metadata['attribution_text'] ?? '' ) );
		$license = sanitize_text_field( (string) ( $metadata['license'] ?? $metadata['license_policy'] ?? '' ) );
		$copyright_notice = $this->sanitize_metadata_text( (string) ( $metadata['copyright_notice'] ?? '' ) );

		if ( 'ai_generated' === $source_type ) {
			if ( '' === $attribution_text ) {
				$attribution_text = 'AI-generated by site operator';
			}
			if ( '' === $copyright_notice ) {
				$copyright_notice = 'Generated asset for this site';
			}
		} elseif ( in_array( $source_type, array( 'stock', 'external' ), true ) && '' === $attribution_text ) {
			$parts = array();
			if ( '' !== $source_page_url ) {
				$parts[] = 'Source: ' . $source_page_url;
			}
			if ( '' !== $photographer_name ) {
				$parts[] = 'Author: ' . $photographer_name;
			}
			if ( '' !== $license ) {
				$parts[] = 'License: ' . $license;
			}
			$attribution_text = implode( '; ', $parts );
		} elseif ( 'owned' === $source_type && '' === $copyright_notice ) {
			$copyright_notice = 'Owned asset for this site';
		} elseif ( 'test' === $source_type && '' === $copyright_notice ) {
			$copyright_notice = 'Test media asset for this site';
		}

		return array(
			'source_type'       => $source_type,
			'source_page_url'   => $source_page_url,
			'photographer_name' => $photographer_name,
			'attribution_text'  => $attribution_text,
			'copyright_notice'  => $copyright_notice,
		);
	}

	/**
	 * Returns whether source metadata is sufficient for inventory health.
	 *
	 * @param array<string,mixed> $metadata Source metadata.
	 * @return bool
	 */
	private function media_source_metadata_has_coverage( array $metadata ) {
		$source_type = $this->normalize_media_source_type( $metadata['source_type'] ?? '' );
		$source_page_url = $this->esc_url_value( (string) ( $metadata['source_page_url'] ?? '' ) );
		$attribution_text = $this->sanitize_metadata_text( (string) ( $metadata['attribution_text'] ?? '' ) );
		$copyright_notice = $this->sanitize_metadata_text( (string) ( $metadata['copyright_notice'] ?? '' ) );

		if ( in_array( $source_type, array( 'stock', 'external' ), true ) ) {
			return '' !== $source_page_url || '' !== $attribution_text;
		}
		if ( in_array( $source_type, array( 'ai_generated', 'owned', 'test' ), true ) ) {
			return '' !== $attribution_text || '' !== $copyright_notice;
		}

		return '' !== $source_page_url || '' !== $attribution_text || '' !== $copyright_notice;
	}

	/**
	 * Builds one canonical media SEO asset row.
	 *
	 * @param array<string,mixed> $descriptor Media descriptor.
	 * @param array<string,mixed> $upload_row Upload result.
	 * @param string              $fallback_role Fallback asset role.
	 * @param bool                $vision_allowed Whether vision fallback may be used.
	 * @return array<string,mixed>
	 */
	private function build_media_seo_asset_row( array $descriptor, array $upload_row, $fallback_role, $vision_allowed ) {
		$image_origin = $this->infer_media_image_origin( $descriptor );
		$source_type = $this->normalize_media_source_type(
			$descriptor['source_type'] ?? '',
			$this->infer_media_source_type( $descriptor, $image_origin )
		);
		$source_metadata = $this->media_source_metadata_with_defaults(
			array(
				'source_type'       => $source_type,
				'source_page_url'   => $this->esc_url_value( (string) ( $descriptor['source_page_url'] ?? '' ) ),
				'photographer_name' => sanitize_text_field( (string) ( $descriptor['photographer_name'] ?? '' ) ),
				'attribution_text'  => $this->sanitize_metadata_text( (string) ( $descriptor['attribution_text'] ?? '' ) ),
				'license'           => sanitize_text_field( (string) ( $descriptor['license'] ?? $descriptor['license_policy'] ?? '' ) ),
				'copyright_notice'  => $this->sanitize_metadata_text( (string) ( $descriptor['copyright_notice'] ?? '' ) ),
			)
		);

		return array(
			'attachment_id'           => $this->absint_value( $upload_row['attachment_id'] ?? 0 ),
			'url'                     => $this->esc_url_value( (string) ( $descriptor['source_url'] ?? $upload_row['url'] ?? '' ) ),
			'title'                   => sanitize_text_field( (string) ( $descriptor['title'] ?? '' ) ),
			'alt'                     => sanitize_text_field( (string) ( $descriptor['alt'] ?? '' ) ),
			'caption'                 => $this->sanitize_metadata_text( (string) ( $descriptor['caption'] ?? '' ) ),
			'description'             => $this->sanitize_metadata_text( (string) ( $descriptor['description'] ?? '' ) ),
			'source_type'             => $source_metadata['source_type'],
			'image_origin'            => $image_origin,
			'generated_prompt'        => $this->sanitize_metadata_text( (string) ( $descriptor['prompt'] ?? '' ) ),
			'image_profile'           => sanitize_key( (string) ( $descriptor['model_profile'] ?? '' ) ),
			'role'                    => sanitize_key( (string) ( $descriptor['role'] ?? $fallback_role ) ),
			'provider_hint'           => sanitize_key( (string) ( $descriptor['provider_hint'] ?? '' ) ),
			'provider_title'          => sanitize_text_field( (string) ( $descriptor['provider_title'] ?? '' ) ),
			'provider_description'    => $this->sanitize_metadata_text( (string) ( $descriptor['provider_description'] ?? '' ) ),
			'source_page_url'         => $source_metadata['source_page_url'],
			'photographer_name'       => $source_metadata['photographer_name'],
			'attribution_text'        => $source_metadata['attribution_text'],
			'copyright_notice'        => $source_metadata['copyright_notice'],
			'section_heading'         => sanitize_text_field( (string) ( $descriptor['section_heading'] ?? '' ) ),
			'section_summary'         => $this->sanitize_metadata_text( (string) ( $descriptor['section_summary'] ?? '' ) ),
			'file_name'               => $this->sanitize_file_name_value( (string) ( $upload_row['file_name'] ?? '' ) ),
			'vision_fallback_allowed' => (bool) $vision_allowed && 'manual_upload' === $image_origin,
		);
	}

	/**
	 * Infers one canonical media origin from descriptor fields.
	 *
	 * @param array<string,mixed> $asset Media descriptor.
	 * @return string
	 */
	private function infer_media_image_origin( array $asset ) {
		$image_origin = sanitize_key( (string) ( $asset['image_origin'] ?? '' ) );
		if ( in_array( $image_origin, array( 'ai_generated', 'public_free', 'manual_upload' ), true ) ) {
			return $image_origin;
		}
		$source_type = $this->normalize_media_source_type( $asset['source_type'] ?? '' );
		if ( 'ai_generated' === $source_type ) {
			return 'ai_generated';
		}
		if ( in_array( $source_type, array( 'stock', 'external' ), true ) ) {
			return 'public_free';
		}

		$generated_prompt = $this->sanitize_metadata_text( (string) ( $asset['generated_prompt'] ?? $asset['prompt'] ?? '' ) );
		$provider_hint = sanitize_key( (string) ( $asset['provider_hint'] ?? '' ) );
		$source_page_url = $this->esc_url_value( (string) ( $asset['source_page_url'] ?? '' ) );
		$photographer_name = sanitize_text_field( (string) ( $asset['photographer_name'] ?? '' ) );
		$attribution_text = $this->sanitize_metadata_text( (string) ( $asset['attribution_text'] ?? '' ) );
		if ( '' !== $source_page_url || '' !== $photographer_name || '' !== $attribution_text || in_array( $provider_hint, array( 'pexels', 'openverse', 'unsplash', 'pixabay', 'public_free' ), true ) ) {
			return 'public_free';
		}
		if ( '' !== sanitize_text_field( (string) ( $asset['model_profile'] ?? $asset['image_profile'] ?? '' ) ) || '' !== $generated_prompt || in_array( $provider_hint, array( 'generated', 'local_model', 'image_generate', 'ai' ), true ) ) {
			return 'ai_generated';
		}

		return 'manual_upload';
	}

	/**
	 * Filters previously inserted inline image blocks for matching placement keys.
	 *
	 * @param array<int,mixed>  $blocks Existing blocks.
	 * @param array<int,string> $placement_keys Placement keys.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_existing_inline_image_blocks( array $blocks, array $placement_keys ) {
		$placement_keys = array_values(
			array_filter(
				array_map(
					array( $this, 'sanitize_html_class_value' ),
					$placement_keys
				)
			)
		);
		if ( empty( $placement_keys ) ) {
			return array_values(
				array_filter(
					$blocks,
					'is_array'
				)
			);
		}

		$filtered = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$class_names = $this->extract_inline_block_class_names( $block );
			$is_inline_image = in_array( 'npcink-abilities-toolkit-inline-image', $class_names, true );
			$matches_key = false;
			foreach ( $placement_keys as $placement_key ) {
				if ( in_array( $placement_key, $class_names, true ) ) {
					$matches_key = true;
					break;
				}
			}
			if ( $is_inline_image && $matches_key ) {
				continue;
			}
			$filtered[] = $block;
		}

		return array_values( $filtered );
	}

	/**
	 * Extracts class names from one block.
	 *
	 * @param array<string,mixed> $block Block payload.
	 * @return array<int,string>
	 */
	private function extract_inline_block_class_names( array $block ) {
		$class_name = '';
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( '' !== sanitize_text_field( (string) ( $attrs['className'] ?? '' ) ) ) {
			$class_name = (string) $attrs['className'];
		} elseif ( '' !== sanitize_text_field( (string) ( $attrs['classname'] ?? '' ) ) ) {
			$class_name = (string) $attrs['classname'];
		} else {
			$inner_html = (string) ( $block['innerHTML'] ?? '' );
			if ( '' !== $inner_html && preg_match( '/class="([^"]+)"/', $inner_html, $matches ) ) {
				$class_name = (string) ( $matches[1] ?? '' );
			}
		}

		$parts = preg_split( '/\s+/', trim( (string) $class_name ) );
		return array_values(
			array_filter(
				array_map(
					array( $this, 'sanitize_html_class_value' ),
					is_array( $parts ) ? $parts : array()
				)
			)
		);
	}

	/**
	 * Returns whether a block matches one paragraph-index inline image target.
	 *
	 * @param array<string,mixed> $block Block payload.
	 * @param array<string,mixed> $target Target descriptor.
	 * @param int                 $paragraph_index Current paragraph index.
	 * @return bool
	 */
	private function block_matches_inline_paragraph_target( array $block, array $target, $paragraph_index ) {
		if ( 'after_paragraph' !== sanitize_key( (string) ( $target['placement'] ?? '' ) ) ) {
			return false;
		}
		if ( ! array_key_exists( 'paragraph_index', $target ) ) {
			return false;
		}
		$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
		if ( ! in_array( $block_name, array( 'core/paragraph', 'core/freeform' ), true ) ) {
			return false;
		}
		return (int) $paragraph_index === $this->absint_value( $target['paragraph_index'] );
	}

	/**
	 * Returns whether a heading block matches one anchor or heading target.
	 *
	 * @param array<string,mixed> $block Block payload.
	 * @param array<string,mixed> $target Target descriptor.
	 * @return bool
	 */
	private function block_matches_inline_target( array $block, array $target ) {
		$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
		if ( 'core/heading' !== $block_name ) {
			return false;
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$block_anchor = $this->sanitize_metadata_slug( (string) ( $attrs['anchor'] ?? '' ) );
		$target_anchor = $this->sanitize_metadata_slug( (string) ( $target['section_anchor'] ?? '' ) );
		if ( '' !== $target_anchor && '' !== $block_anchor && $target_anchor === $block_anchor ) {
			return true;
		}

		$target_heading = html_entity_decode( sanitize_text_field( (string) ( $target['section_heading'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
		$target_heading = preg_replace( '/\s+/u', ' ', trim( (string) $target_heading ) );
		$target_heading = is_string( $target_heading ) ? $target_heading : '';
		$heading_text = $this->extract_heading_text_from_block( $block );
		return '' !== $target_heading && '' !== $heading_text && $target_heading === $heading_text;
	}

	/**
	 * Extracts normalized heading text from one block.
	 *
	 * @param array<string,mixed> $block Block payload.
	 * @return string
	 */
	private function extract_heading_text_from_block( array $block ) {
		$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
		if ( 'core/heading' !== $block_name ) {
			return '';
		}
		$heading_text = html_entity_decode( $this->strip_all_tags_value( (string) ( $block['innerHTML'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
		$heading_text = preg_replace( '/\s+/u', ' ', trim( (string) $heading_text ) );
		return is_string( $heading_text ) ? $heading_text : '';
	}

}
