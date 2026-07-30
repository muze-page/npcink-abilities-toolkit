<?php
/**
 * Article production read methods for Core_Read_Package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides article production, draft-result, duplicate-guard, and review callbacks.
 */
trait Article_Production_Read_Methods {
	/**
	 * Resolves one article publication handoff after review.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function resolve_article_publication_decision( $input ) {
		$input = is_array( $input ) ? $input : array();
		$policy_defaults = function_exists( 'npcink_ai_get_article_workflow_policy_defaults' )
			? npcink_ai_get_article_workflow_policy_defaults()
			: array();
		$requested_publish_mode = sanitize_key( (string) ( $input['publish_mode'] ?? ( $policy_defaults['publish_mode'] ?? 'draft' ) ) );
		if ( ! in_array( $requested_publish_mode, array( 'draft', 'review', 'schedule', 'publish' ), true ) ) {
			$requested_publish_mode = sanitize_key( (string) ( $policy_defaults['publish_mode'] ?? 'draft' ) );
			if ( ! in_array( $requested_publish_mode, array( 'draft', 'review', 'schedule', 'publish' ), true ) ) {
				$requested_publish_mode = 'draft';
			}
		}

		$review = is_array( $input['review'] ?? null ) ? $input['review'] : array();
		$duplicate_guard = is_array( $input['duplicate_guard'] ?? null ) ? $input['duplicate_guard'] : array();
		$effective_publish_mode = $requested_publish_mode;
		$publish_blocked = false;
		$gate_reason = '';
		$user_message = '';

		if ( in_array( $requested_publish_mode, array( 'schedule', 'publish' ), true ) && ! empty( $review['needs_human_review'] ) ) {
			$effective_publish_mode = 'review';
			$publish_blocked = true;
			if ( 'high' === sanitize_key( (string) ( $review['template_risk_level'] ?? '' ) ) ) {
				$gate_reason = 'template_style_requires_handoff';
				$user_message = __( 'Template style risk is high; review and polish the article before scheduling or publishing.', 'npcink-abilities-toolkit' );
			} else {
				$gate_reason = 'quality_review_requires_handoff';
				$user_message = __( 'Quality review requires human review before scheduling or publishing.', 'npcink-abilities-toolkit' );
			}
		} elseif ( in_array( $requested_publish_mode, array( 'schedule', 'publish' ), true ) && ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$effective_publish_mode = 'review';
			$publish_blocked = true;
			$gate_reason = 'duplicate_production_candidate';
			$user_message = __( 'A duplicate production candidate was detected; review before scheduling or publishing.', 'npcink-abilities-toolkit' );
		}

		return array(
			'success' => true,
			'data'    => array(
				'requested_publish_mode' => $requested_publish_mode,
				'effective_publish_mode' => $effective_publish_mode,
				'publish_blocked'        => $publish_blocked,
				'gate_reason'            => $gate_reason,
				'user_message'           => $user_message,
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'deterministic',
			),
		);
	}

	/**
	 * Builds one stable fingerprint for lightweight article production dedupe.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function build_article_production_fingerprint( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'success' => true,
			'data'    => array(
				'production_fingerprint' => $this->build_article_production_fingerprint_value( $input ),
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Checks whether one lightweight production fingerprint already exists on a post.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function check_article_production_duplicate( $input ) {
		$input = is_array( $input ) ? $input : array();
		$production_fingerprint = sanitize_text_field( (string) ( $input['production_fingerprint'] ?? '' ) );
		$write_guard_mode = sanitize_key( (string) ( $input['write_guard_mode'] ?? 'preserve_manual_edits' ) );
		$duplicate = array();

		if ( '' !== $production_fingerprint && function_exists( 'get_posts' ) ) {
			$posts = get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => array( 'draft', 'pending', 'future', 'publish', 'private' ),
					'posts_per_page'   => 1,
					'orderby'          => 'ID',
					'order'            => 'DESC',
					// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Duplicate guard uses one bounded fingerprint lookup across existing article drafts.
					'meta_key'         => '_mai_article_production_fingerprint',
					'meta_value'       => $production_fingerprint,
					// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);
			$post_id = $this->absint_value( is_array( $posts ) ? ( $posts[0] ?? 0 ) : 0 );
			if ( $post_id > 0 ) {
				$duplicate = array(
					'post_id'      => $post_id,
					'title'        => function_exists( 'get_the_title' ) ? sanitize_text_field( (string) get_the_title( $post_id ) ) : '',
					'status'       => function_exists( 'get_post_status' ) ? sanitize_key( (string) get_post_status( $post_id ) ) : '',
					'edit_link'    => function_exists( 'get_edit_post_link' ) ? $this->esc_url_value( (string) get_edit_post_link( $post_id, 'raw' ) ) : '',
					'preview_link' => function_exists( 'get_preview_post_link' ) ? $this->esc_url_value( (string) get_preview_post_link( $post_id ) ) : '',
					'public_link'  => function_exists( 'get_permalink' ) ? $this->esc_url_value( (string) get_permalink( $post_id ) ) : '',
				);
			}
		}

		$duplicate_found = ! empty( $duplicate['post_id'] );
		$skip_recommended = $duplicate_found && 'preserve_manual_edits' === $write_guard_mode;
		$summary_text = '';
		if ( $duplicate_found ) {
			$summary_text = sprintf(
				'检测到同指纹文章 #%d%s，建议先复核现有稿件。',
				$this->absint_value( $duplicate['post_id'] ?? 0 ),
				! empty( $duplicate['title'] ) ? '《' . sanitize_text_field( (string) $duplicate['title'] ) . '》' : ''
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'production_fingerprint' => $production_fingerprint,
				'duplicate_found'        => $duplicate_found,
				'skip_recommended'       => $skip_recommended,
				'soft_block_reason'      => $skip_recommended ? 'duplicate_production_candidate' : '',
				'summary_text'           => $summary_text,
				'duplicate_candidate'    => $duplicate,
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Builds lightweight local article production review signals.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function review_article_output_light( $input ) {
		$input = is_array( $input ) ? $input : array();
		$article = is_array( $input['article'] ?? null ) ? $input['article'] : array();
		$style_profile = is_array( $input['style_profile'] ?? null ) ? $input['style_profile'] : array();
		$media = is_array( $input['media'] ?? null ) ? $input['media'] : array();
		$platform_profile = sanitize_key( (string) ( $input['platform_profile'] ?? 'generic' ) );
		$human_signals = is_array( $input['human_signals'] ?? null ) ? $input['human_signals'] : array();
		$image_mode = sanitize_key( (string) ( $input['image_mode'] ?? 'featured_only' ) );
		$content = (string) ( $article['content'] ?? '' );
		$content_text = $this->normalize_plain_text( $content );
		$paragraphs = $this->extract_article_style_paragraphs( $content );
		$opening = $this->classify_article_opening_style( (string) ( $paragraphs[0] ?? '' ) );
		$voice = $this->classify_article_voice_profile( $content_text );

		$template_hits = 0;
		$template_findings = array();
		foreach ( array( '首先', '其次', '最后', '综上所述' ) as $phrase ) {
			if ( false !== strpos( $content_text, $phrase ) ) {
				++$template_hits;
				$template_findings[] = '命中套话短语：' . $phrase;
			}
		}

		$naturalness = max( 40, 92 - ( $template_hits * 12 ) );
		if ( count( $paragraphs ) <= 2 ) {
			$naturalness -= 10;
			$template_findings[] = '段落层次偏少，容易显得像模板摘要。';
		}

		$paragraph_lengths = array();
		foreach ( $paragraphs as $paragraph ) {
			$paragraph_text = trim( $this->strip_all_tags_value( (string) $paragraph ) );
			$paragraph_lengths[] = function_exists( 'mb_strlen' ) ? mb_strlen( $paragraph_text ) : strlen( $paragraph_text );
		}
		$paragraph_lengths = array_values( array_filter( $paragraph_lengths ) );
		if ( count( $paragraph_lengths ) >= 3 ) {
			$max_length = max( $paragraph_lengths );
			$min_length = min( $paragraph_lengths );
			if ( $max_length > 0 && ( $max_length - $min_length ) <= 18 ) {
				$template_findings[] = '段落长度过于整齐，缺少自然节奏变化。';
				$naturalness -= 6;
			}
		}

		$heading_count = preg_match_all( '/<h[1-6][^>]*>/i', $content, $heading_matches );
		unset( $heading_matches );
		if ( $heading_count >= 3 && count( $paragraph_lengths ) > 0 ) {
			$template_findings[] = '小标题较密，建议保留长短段交替避免教科书腔。';
		}

		$template_risk_level = 'low';
		if ( $template_hits >= 2 || count( $template_findings ) >= 3 ) {
			$template_risk_level = 'high';
		} elseif ( $template_hits >= 1 || count( $template_findings ) >= 1 ) {
			$template_risk_level = 'medium';
		}

		$style_match = 72;
		$style_findings = array();
		$target_opening = sanitize_key( (string) ( $style_profile['resolved_opening_style'] ?? '' ) );
		$target_voice = sanitize_key( (string) ( $style_profile['resolved_voice_profile'] ?? '' ) );
		if ( '' !== $target_opening && $target_opening === $opening ) {
			$style_match += 12;
		} elseif ( '' !== $target_opening ) {
			$style_match -= 12;
			$style_findings[] = '开头风格偏离目标：期望 ' . $target_opening . '，实际 ' . $opening . '。';
		}
		if ( '' !== $target_voice && $target_voice === $voice ) {
			$style_match += 10;
		} elseif ( '' !== $target_voice ) {
			$style_match -= 10;
			$style_findings[] = '语气风格偏离目标：期望 ' . $target_voice . '，实际 ' . $voice . '。';
		}
		$style_match = max( 0, $style_match );

		$image_relevance = 'none' === $image_mode ? 100 : 68;
		$position_summary = is_array( $media['position_inline_image_blocks']['summary'] ?? null ) ? $media['position_inline_image_blocks']['summary'] : array();
		$featured_success = ! empty( $media['featured_attached'] );
		if ( 'featured_only' === $image_mode ) {
			$image_relevance = $featured_success ? 88 : 56;
		} elseif ( 'featured_and_inline' === $image_mode ) {
			$image_relevance = $featured_success ? 78 : 58;
			$image_relevance += min( 12, max( 0, $this->absint_value( $position_summary['positioned_count'] ?? 0 ) ) * 4 );
		}

		$needs_human_review = $naturalness < 70 || $style_match < 70 || $image_relevance < 70;
		$next_action = $needs_human_review ? 'needs_human_review' : 'ready_for_editorial_review';
		if ( 'featured_and_inline' === $image_mode && $this->absint_value( $position_summary['appended_count'] ?? 0 ) > 0 ) {
			$next_action = 'needs_layout_review';
		}

		$ai_risk_review = $this->build_article_ai_risk_review( $content, $platform_profile, $human_signals, $template_risk_level, $template_findings, $style_findings );
		$ai_risk_review = $this->relax_article_ai_risk_review_for_reference_style( $ai_risk_review, min( 100, $style_match ), $style_findings );
		if ( 'high' === sanitize_key( (string) ( $ai_risk_review['highest_severity'] ?? '' ) ) ) {
			$needs_human_review = true;
			if ( 'ready_for_editorial_review' === $next_action ) {
				$next_action = 'needs_human_review';
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'writing_naturalness' => $naturalness,
				'style_match_score'   => min( 100, $style_match ),
				'image_relevance_score' => min( 100, $image_relevance ),
				'needs_human_review'  => $needs_human_review,
				'next_action'         => $next_action,
				'template_risk_level' => $template_risk_level,
				'platform_profile'    => $platform_profile,
				'ai_risk_review'      => $ai_risk_review,
				'signals'             => array(
					'detected_opening_style' => $opening,
					'detected_voice_profile' => $voice,
					'template_phrase_hits'   => $template_hits,
					'paragraph_count'        => count( $paragraphs ),
					'anti_template_findings' => array_values( array_unique( $template_findings ) ),
					'style_findings'         => array_values( array_unique( $style_findings ) ),
				),
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Composes lightweight production result with degraded/result-mode semantics.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function compose_article_production_result( $input ) {
		$input = is_array( $input ) ? $input : array();
		$source_input = is_array( $input['input'] ?? null ) ? $input['input'] : array();
		$article = is_array( $input['article'] ?? null ) ? $input['article'] : array();
		$draft = is_array( $input['draft'] ?? null ) ? $input['draft'] : array();
		$media = is_array( $input['media'] ?? null ) ? $input['media'] : array();
		$metadata_plan_resolution = is_array( $input['metadata_plan_resolution'] ?? null ) ? $input['metadata_plan_resolution'] : array();
		$seo_analysis = is_array( $input['seo_analysis'] ?? null ) ? $input['seo_analysis'] : array();
		$geo_analysis = is_array( $input['geo_analysis'] ?? null ) ? $input['geo_analysis'] : array();
		$review = is_array( $input['review'] ?? null ) ? $input['review'] : array();
		$publication_decision = is_array( $input['publication_decision'] ?? null ) ? $input['publication_decision'] : array();
		$duplicate_guard = is_array( $input['duplicate_guard'] ?? null ) ? $input['duplicate_guard'] : array();
		$platform_profile = sanitize_key( (string) ( $source_input['platform_profile'] ?? 'generic' ) );
		$human_signals = is_array( $source_input['human_signals'] ?? null ) ? $source_input['human_signals'] : array();
		$image_mode = sanitize_key( (string) ( $source_input['image_mode'] ?? 'featured_only' ) );
		$write_guard_mode = sanitize_key( (string) ( $source_input['write_guard_mode'] ?? 'preserve_manual_edits' ) );
		if ( ! in_array( $write_guard_mode, array( 'preserve_manual_edits', 'workflow_owned' ), true ) ) {
			$write_guard_mode = 'preserve_manual_edits';
		}

		$featured_attached = ! empty( $media['featured_attached'] );
		$position_summary = is_array( $media['position_inline_image_blocks']['summary'] ?? null ) ? $media['position_inline_image_blocks']['summary'] : array();
		$degraded_reasons = array();
		if ( 'none' !== $image_mode && ! $featured_attached ) {
			$degraded_reasons[] = 'featured_media_unavailable';
		}
		if ( 'featured_and_inline' === $image_mode && $this->absint_value( $position_summary['appended_count'] ?? 0 ) > 0 ) {
			$degraded_reasons[] = 'inline_position_partial_fallback';
		}
		if ( ! empty( $review['needs_human_review'] ) ) {
			$degraded_reasons[] = 'quality_review_requires_handoff';
		}
		if ( 'high' === sanitize_key( (string) ( $review['template_risk_level'] ?? '' ) ) ) {
			$degraded_reasons[] = 'template_style_requires_handoff';
		}
		if ( ! empty( $publication_decision['publish_blocked'] ) ) {
			$degraded_reasons[] = 'publication_blocked_by_quality_gate';
		}
		if ( ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$degraded_reasons[] = 'duplicate_production_candidate';
		}

		$media_seo_summary = is_array( $media['seo_summary'] ?? null ) ? $media['seo_summary'] : array();
		$review_signals = is_array( $review['signals'] ?? null ) ? $review['signals'] : array();
		$anti_template_findings = $this->sanitize_limited_text_list( is_array( $review_signals['anti_template_findings'] ?? null ) ? $review_signals['anti_template_findings'] : array(), 3 );
		$style_findings = $this->sanitize_limited_text_list( is_array( $review_signals['style_findings'] ?? null ) ? $review_signals['style_findings'] : array(), 3 );
		$review_summary = array(
			'next_action'            => sanitize_key( (string) ( $review['next_action'] ?? '' ) ),
			'needs_human_review'     => ! empty( $review['needs_human_review'] ),
			'template_risk_level'    => sanitize_key( (string) ( $review['template_risk_level'] ?? '' ) ),
			'platform_profile'       => $platform_profile,
			'writing_naturalness'    => max( 0, min( 100, $this->absint_value( $review['writing_naturalness'] ?? 0 ) ) ),
			'style_match_score'      => max( 0, min( 100, $this->absint_value( $review['style_match_score'] ?? 0 ) ) ),
			'image_relevance_score'  => max( 0, min( 100, $this->absint_value( $review['image_relevance_score'] ?? 0 ) ) ),
			'anti_template_findings' => $anti_template_findings,
			'style_findings'         => $style_findings,
			'ai_risk_review'         => is_array( $review['ai_risk_review'] ?? null ) ? $review['ai_risk_review'] : array(),
		);
		$primary_review_finding = '';
		if ( ! empty( $style_findings ) ) {
			$primary_review_finding = (string) $style_findings[0];
		} elseif ( ! empty( $anti_template_findings ) ) {
			$primary_review_finding = (string) $anti_template_findings[0];
		}

		$completed_stages = array( 'draft_created' );
		if ( ! empty( $draft['seo_meta'] ) ) {
			$completed_stages[] = 'seo_enriched';
		}
		if ( 'none' !== $image_mode ) {
			$completed_stages[] = $featured_attached ? 'featured_media_ready' : 'featured_media_missing';
		}
		if ( 'featured_and_inline' === $image_mode ) {
			$completed_stages[] = $this->absint_value( $position_summary['positioned_count'] ?? 0 ) > 0 ? 'inline_media_positioned' : 'inline_media_pending';
		}
		if ( ! empty( $duplicate_guard['duplicate_found'] ) ) {
			$completed_stages[] = 'duplicate_candidate_detected';
		}
		if ( ! empty( $media_seo_summary ) ) {
			$completed_stages[] = ! empty( $media_seo_summary['applied_count'] ) ? 'media_seo_applied' : 'media_seo_suggested';
		}
		$completed_stages[] = 'quality_review_ready';

		$result_mode = empty( $degraded_reasons ) ? 'full' : 'degraded';
		$production_fingerprint = sanitize_text_field( (string) ( $duplicate_guard['production_fingerprint'] ?? '' ) );
		if ( '' === $production_fingerprint ) {
			$production_fingerprint = $this->build_article_production_fingerprint_value( $source_input );
		}

		$summary_parts = array();
		$summary_parts[] = '结果：' . ( 'full' === $result_mode ? '完整完成' : '降级完成' );
		$next_action = sanitize_key( (string) ( $review['next_action'] ?? 'ready_for_editorial_review' ) );
		if ( 'ready_for_editorial_review' === $next_action ) {
			$summary_parts[] = '下一步进入编辑复核';
		} elseif ( 'needs_layout_review' === $next_action ) {
			$summary_parts[] = '下一步做版式复核';
		} elseif ( 'needs_human_review' === $next_action ) {
			$summary_parts[] = '下一步人工复核';
		}
		if ( ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$summary_parts[] = '检测到可复用旧稿，建议先停在 dry-run';
		}
		if ( ! empty( $publication_decision['publish_blocked'] ) ) {
			$summary_parts[] = '发布门控：' . sanitize_text_field( (string) ( $publication_decision['user_message'] ?? '质量评估要求人工复核，已阻止自动发布。' ) );
		}
		if ( ! empty( $media_seo_summary ) ) {
			$media_seo_parts = array_filter(
				array(
					'媒体 SEO：' . $this->absint_value( $media_seo_summary['asset_count'] ?? 0 ) . ' 张',
					$this->absint_value( $media_seo_summary['vision_fallback_count'] ?? 0 ) > 0 ? '待视觉补强 ' . $this->absint_value( $media_seo_summary['vision_fallback_count'] ?? 0 ) . ' 张' : '',
					$this->absint_value( $media_seo_summary['attribution_persisted_count'] ?? 0 ) > 0 ? '归因已保留 ' . $this->absint_value( $media_seo_summary['attribution_persisted_count'] ?? 0 ) . ' 张' : '',
				)
			);
			$summary_parts[] = implode( '，', $media_seo_parts );
		}
		if ( ! empty( $degraded_reasons ) ) {
			$summary_parts[] = '原因：' . implode( '、', array_map( 'sanitize_text_field', $degraded_reasons ) );
		}
		if ( '' !== $primary_review_finding ) {
			$summary_parts[] = '复核提示：' . $primary_review_finding;
		}
		$summary_text = implode( '；', array_filter( $summary_parts ) );
		$content_improvements = array(
			array(
				'type'     => 'production_summary',
				'priority' => 'full' === $result_mode ? 'medium' : 'high',
				'title'    => '按 production 主链检查发布前停点',
				'detail'   => $summary_text,
			),
		);
		if ( ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$content_improvements[] = array(
				'type'     => 'duplicate_guard',
				'priority' => 'high',
				'title'    => '先复核已有候选草稿或已发布文章',
				'detail'   => $this->sanitize_metadata_text( (string) ( $duplicate_guard['summary_text'] ?? '检测到可复用旧稿，建议先停在 review。' ) ),
			);
		}
		if ( ! empty( $publication_decision['publish_blocked'] ) ) {
			$content_improvements[] = array(
				'type'     => 'publication_gate',
				'priority' => 'high',
				'title'    => '发布门控要求人工继续复核',
				'detail'   => $this->sanitize_metadata_text( (string) ( $publication_decision['user_message'] ?? '质量评估要求人工复核，暂不执行自动排期或发布。' ) ),
			);
		}
		$ai_risk_items = is_array( $review_summary['ai_risk_review']['items'] ?? null ) ? $review_summary['ai_risk_review']['items'] : array();
		foreach ( array_slice( $ai_risk_items, 0, 2 ) as $risk_item ) {
			$risk_item = is_array( $risk_item ) ? $risk_item : array();
			$content_improvements[] = array(
				'type'     => 'ai_risk_review',
				'priority' => sanitize_key( (string) ( $risk_item['severity'] ?? 'medium' ) ),
				'title'    => sanitize_text_field( (string) ( $risk_item['title'] ?? 'AI 风险复核' ) ),
				'detail'   => $this->sanitize_metadata_text( (string) ( $risk_item['detail'] ?? '' ) ),
			);
		}

		$source_references = $this->extract_source_references( $human_signals );
		$evidence_notes = $this->extract_ai_risk_notes_by_key( $ai_risk_items, 'evidence_gap' );
		$claim_risk_notes = $this->extract_ai_risk_notes_by_key( $ai_risk_items, 'claim_confidence' );
		$image_attribution_summary = array(
			'provider'        => sanitize_text_field( (string) ( $media['resolved_image_source']['featured']['provider_title'] ?? $media['resolved_image_source']['featured']['provider_hint'] ?? '' ) ),
			'license'         => sanitize_text_field( (string) ( $media['resolved_image_source']['featured']['license'] ?? $media['resolved_image_source']['featured']['license_policy'] ?? '' ) ),
			'source_page_url' => $this->esc_url_value( (string) ( $media['resolved_image_source']['featured']['source_page_url'] ?? '' ) ),
		);

		$seo_improvements = $this->build_recommendation_improvements( $seo_analysis, 'seo_recommendation' );
		if ( empty( $seo_improvements ) ) {
			$seo_improvements[] = array(
				'type'     => 'seo_alignment',
				'priority' => 'medium',
				'title'    => '复核 title、excerpt、SEO metadata 与媒体 SEO',
				'detail'   => 'production 主链会保留 shared SEO output，并在需要时继续补媒体 SEO 建议或写回。',
			);
		}

		$geo_improvements = $this->build_recommendation_improvements( $geo_analysis, 'geo_recommendation' );
		if ( empty( $geo_improvements ) ) {
			$geo_improvements[] = array(
				'type'     => 'answerability',
				'priority' => 'medium',
				'title'    => '补 answer-first summary、FAQ 或可引用段落',
				'detail'   => 'production 主链继续复用 shared GEO output，不额外长第二套页面私有分析器。',
			);
		}

		$handoff = array(
			'stopping_point'    => sanitize_key( (string) ( $publication_decision['effective_publish_mode'] ?? 'review' ) ),
			'next_action'       => $next_action,
			'recommended_entry' => 'npcink-abilities-toolkit/recipes/article-production',
			'hints'             => array_values(
				array_filter(
					array(
						'draft、SEO/GEO review、媒体结果和 publish gate 都已收口到 production 主链结果里。',
						! empty( $publication_decision['publish_blocked'] ) ? '当前 publish/schedule 已被质量门控转回 review。' : '',
						! empty( $duplicate_guard['skip_recommended'] ) ? '检测到重复候选，建议先复核旧稿再继续。' : '',
						'none' !== $image_mode && ! $featured_attached ? '当前缺少可用 featured media，需人工补图或调整策略。' : '',
					)
				)
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'article'                  => $article,
				'draft'                    => array(
					'post_id'      => $this->absint_value( $draft['post_id'] ?? 0 ),
					'edit_link'    => $this->esc_url_value( (string) ( $draft['edit_link'] ?? '' ) ),
					'preview_link' => $this->esc_url_value( (string) ( $draft['preview_link'] ?? '' ) ),
					'public_link'  => $this->esc_url_value( (string) ( $draft['public_link'] ?? '' ) ),
					'seo_meta'     => is_array( $draft['seo_meta'] ?? null ) ? $draft['seo_meta'] : array(),
				),
				'media_plan'               => $media,
				'metadata_plan_resolution' => $metadata_plan_resolution,
				'content_improvements'     => $content_improvements,
				'seo_improvements'         => $seo_improvements,
				'geo_improvements'         => $geo_improvements,
				'review'                   => $review_summary,
				'handoff'                  => $handoff,
				'result_mode'              => $result_mode,
				'degraded_reasons'         => array_values( $degraded_reasons ),
				'completed_stages'         => array_values( $completed_stages ),
				'production_fingerprint'   => $production_fingerprint,
				'write_guard_mode'         => $write_guard_mode,
				'skip_recommended'         => ! empty( $duplicate_guard['skip_recommended'] ),
				'duplicate_candidate'      => is_array( $duplicate_guard['duplicate_candidate'] ?? null ) ? $duplicate_guard['duplicate_candidate'] : array(),
				'summary_text'             => $summary_text,
				'review_payload'           => $review,
				'review_summary'           => $review_summary,
				'evidence_notes'           => $evidence_notes,
				'claim_risk_notes'         => $claim_risk_notes,
				'source_references'        => $source_references,
				'image_attribution_summary' => $image_attribution_summary,
				'publication_decision'     => $publication_decision,
				'media_seo_summary'        => $media_seo_summary,
				'preview_link'             => $this->esc_url_value( (string) ( $draft['preview_link'] ?? '' ) ),
				'public_link'              => $this->esc_url_value( (string) ( $draft['public_link'] ?? '' ) ),
				'next_action'              => $next_action,
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Composes one canonical draft workflow result envelope.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function compose_article_draft_result( $input ) {
		$input = is_array( $input ) ? $input : array();
		$source_input = is_array( $input['input'] ?? null ) ? $input['input'] : array();
		$article = is_array( $input['article'] ?? null ) ? $input['article'] : array();
		$draft = is_array( $input['draft'] ?? null ) ? $input['draft'] : array();
		$generated_seo = is_array( $input['generated_seo'] ?? null ) ? $input['generated_seo'] : array();
		$metadata_plan_resolution = is_array( $input['metadata_plan_resolution'] ?? null ) ? $input['metadata_plan_resolution'] : array();
		$seo_analysis = is_array( $input['seo_analysis'] ?? null ) ? $input['seo_analysis'] : array();
		$geo_analysis = is_array( $input['geo_analysis'] ?? null ) ? $input['geo_analysis'] : array();
		$quality_scoring = is_array( $input['quality_scoring'] ?? null ) ? $input['quality_scoring'] : array();
		$ai_slop_detection = is_array( $input['ai_slop_detection'] ?? null ) ? $input['ai_slop_detection'] : array();
		$review = is_array( $input['review'] ?? null ) ? $input['review'] : array();
		$duplicate_guard = is_array( $input['duplicate_guard'] ?? null ) ? $input['duplicate_guard'] : array();
		$preview_only = ! empty( $source_input['preview_only'] );
		$platform_profile = sanitize_key( (string) ( $source_input['platform_profile'] ?? 'generic' ) );
		$human_signals = is_array( $source_input['human_signals'] ?? null ) ? $source_input['human_signals'] : array();

		$content_improvements = array(
			array(
				'type'     => $preview_only ? 'draft_preview_handoff' : 'draft_handoff',
				'priority' => ! empty( $review['needs_human_review'] ) ? 'high' : 'medium',
				'title'    => $preview_only ? '先停在 draft preview，再决定是否创建真实草稿' : '先停在 draft workbench，再进入人工编辑',
				'detail'   => $preview_only
					? 'draft workflow 当前可承接新文建议 preview，不创建真实草稿，先返回 metadata handoff 和轻量 review。'
					: 'draft workflow 默认只收口到本地草稿、metadata handoff 和轻量 review，不直接扩成 production / publish 主链。',
			),
		);
		if ( ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$content_improvements[] = array(
				'type'     => 'duplicate_guard',
				'priority' => 'high',
				'title'    => '先检查已有候选草稿',
				'detail'   => $this->sanitize_metadata_text( (string) ( $duplicate_guard['summary_text'] ?? '检测到潜在重复生产候选，建议先停在 review。' ) ),
			);
		}

		$seo_improvements = $this->build_recommendation_improvements( $seo_analysis, 'seo_recommendation' );
		$seo_overall_score = isset( $seo_analysis['overall_score'] ) ? $this->absint_value( $seo_analysis['overall_score'] ) : null;
		$seo_meta_suggestions = is_array( $seo_analysis['meta_suggestions'] ?? null ) ? $seo_analysis['meta_suggestions'] : array();
		$seo_internal_links = is_array( $seo_analysis['internal_link_suggestions'] ?? null ) ? $seo_analysis['internal_link_suggestions'] : array();
		if ( null !== $seo_overall_score ) {
			array_unshift(
				$seo_improvements,
				array(
					'type'     => 'seo_score',
					'priority' => $seo_overall_score < 60 ? 'high' : ( $seo_overall_score < 80 ? 'medium' : 'low' ),
					'title'    => sprintf( 'SEO 综合评分：%d / 100', $seo_overall_score ),
					'detail'   => $seo_overall_score >= 80
						? 'SEO 综合评分良好，建议保持当前优化水平。'
						: 'SEO 综合评分偏低，建议关注关键词密度、标题层级和内部链接。',
				)
			);
		}
		foreach ( $seo_internal_links as $link ) {
			$link = is_array( $link ) ? $link : array();
			$seo_improvements[] = array(
				'type'     => 'internal_link',
				'priority' => 'medium',
				'title'    => sanitize_text_field( (string) ( $link['anchor_text'] ?? ( $link['target_search'] ?? '内链建议' ) ) ),
				'detail'   => $this->sanitize_metadata_text( (string) ( $link['note'] ?? '' ) ),
			);
		}
		if ( ! empty( $seo_meta_suggestions ) ) {
			$meta_title = sanitize_text_field( (string) ( $seo_meta_suggestions['seo_title'] ?? '' ) );
			$meta_desc = $this->sanitize_metadata_text( (string) ( $seo_meta_suggestions['seo_description'] ?? '' ) );
			if ( '' !== $meta_title || '' !== $meta_desc ) {
				$seo_improvements[] = array(
					'type'     => 'seo_meta',
					'priority' => 'medium',
					'title'    => 'SEO 元数据建议',
					'detail'   => trim( $meta_title . ' / ' . $meta_desc, ' /' ),
				);
			}
		}
		if ( empty( $seo_improvements ) ) {
			$seo_improvements[] = array(
				'type'     => 'seo_alignment',
				'priority' => 'medium',
				'title'    => '校对 title、excerpt 与 SEO metadata',
				'detail'   => 'draft 主线默认输出 generated SEO metadata，并保留后续编辑校对停点。',
			);
		}

		$geo_improvements = $this->build_recommendation_improvements( $geo_analysis, 'geo_recommendation' );
		$geo_score = isset( $geo_analysis['geo_score'] ) ? $geo_analysis['geo_score'] : null;
		$ai_summary_block = is_array( $geo_analysis['ai_summary_block'] ?? null ) ? $geo_analysis['ai_summary_block'] : null;
		$faq_page_schema = is_array( $geo_analysis['faq_page_schema'] ?? null ) ? $geo_analysis['faq_page_schema'] : null;
		$citation_annotations = is_array( $geo_analysis['citation_annotations'] ?? null ) ? $geo_analysis['citation_annotations'] : array();
		if ( null !== $geo_score && is_numeric( $geo_score ) ) {
			$geo_score_int = $this->absint_value( $geo_score );
			$geo_improvements[] = array(
				'type'     => 'geo_score',
				'priority' => $geo_score_int < 60 ? 'high' : ( $geo_score_int < 80 ? 'medium' : 'low' ),
				'title'    => sprintf( 'GEO 优化评分：%d / 100', $geo_score_int ),
				'detail'   => $geo_score_int >= 80
					? 'GEO 优化评分良好，AI 引擎可发现性和引用结构较完整。'
					: 'GEO 优化评分偏低，建议补 answer-first 摘要和 FAQ Schema。',
			);
		}
		if ( ! empty( $citation_annotations ) ) {
			$citation_count = count( $citation_annotations );
			$geo_improvements[] = array(
				'type'     => 'citation_coverage',
				'priority' => 'medium',
				'title'    => sprintf( '引用标注：%d 处', $citation_count ),
				'detail'   => $citation_count > 0
					? 'GEO 分析已标注引用来源，建议在编辑时保留关键引用。'
					: '未检测到引用标注，建议补充来源引用以提高 GEO 评分。',
			);
		}
		if ( ! empty( $faq_page_schema ) ) {
			$geo_improvements[] = array(
				'type'     => 'faq_schema',
				'priority' => 'medium',
				'title'    => 'FAQ Schema 已生成',
				'detail'   => 'GEO 分析已生成 FAQPage Schema.org 结构化数据，可随草稿一起发布。',
			);
		}
		if ( ! empty( $ai_summary_block ) ) {
			$geo_improvements[] = array(
				'type'     => 'ai_summary_block',
				'priority' => 'medium',
				'title'    => 'AI 摘要块已生成',
				'detail'   => 'GEO 分析已生成 AI 引擎可直接引用的摘要块。',
			);
		}
		if ( empty( $geo_improvements ) ) {
			$geo_improvements[] = array(
				'type'     => 'answerability',
				'priority' => 'medium',
				'title'    => '补 answer-first summary 或 FAQ',
				'detail'   => 'GEO 改进默认作为 shared output 返回，不单独长成第二套 workflow。',
			);
		}

		$quality_overall = isset( $quality_scoring['overall_score'] ) ? $quality_scoring['overall_score'] : null;
		$quality_breakdown = is_array( $quality_scoring['breakdown'] ?? null ) ? $quality_scoring['breakdown'] : array();
		$quality_suggestions = is_array( $quality_scoring['improvement_suggestions'] ?? null ) ? $quality_scoring['improvement_suggestions'] : array();
		if ( null !== $quality_overall && is_numeric( $quality_overall ) ) {
			$quality_overall_int = $this->absint_value( $quality_overall );
			$content_improvements[] = array(
				'type'     => 'quality_score',
				'priority' => $quality_overall_int < 60 ? 'high' : ( $quality_overall_int < 80 ? 'medium' : 'low' ),
				'title'    => sprintf( '内容质量评分：%d / 100', $quality_overall_int ),
				'detail'   => $quality_overall_int >= 80
					? '内容质量评分良好。'
					: '内容质量评分偏低，建议关注主题相关性、结构、语言流畅度和平台适配性。',
			);
		}
		foreach ( array_slice( $quality_suggestions, 0, 3 ) as $suggestion ) {
			$suggestion = $this->sanitize_metadata_text( (string) $suggestion );
			if ( '' !== $suggestion ) {
				$content_improvements[] = array(
					'type'     => 'quality_suggestion',
					'priority' => 'medium',
					'title'    => $this->truncate_utf8_text( $suggestion, 40 ),
					'detail'   => $suggestion,
				);
			}
		}

		$editorial_feedback = is_array( $quality_scoring['editorial_feedback'] ?? null ) ? $quality_scoring['editorial_feedback'] : array();
		foreach ( array_slice( $editorial_feedback, 0, 5 ) as $fb ) {
			$fb = is_array( $fb ) ? $fb : array();
			$location = sanitize_text_field( (string) ( $fb['location'] ?? '' ) );
			$issue = sanitize_text_field( (string) ( $fb['issue'] ?? '' ) );
			$suggestion = $this->sanitize_metadata_text( (string) ( $fb['suggestion'] ?? '' ) );
			if ( '' !== $location && '' !== $issue ) {
				$title = sprintf( '%s：%s', $location, $issue );
				$content_improvements[] = array(
					'type'     => 'editorial_feedback',
					'priority' => 'high',
					'title'    => $this->truncate_utf8_text( $title, 50 ),
					'detail'   => '' !== $suggestion ? $suggestion : $issue,
				);
			}
		}

		$ai_slop_flags = is_array( $ai_slop_detection['ai_slop_flags'] ?? null ) ? $ai_slop_detection['ai_slop_flags'] : array();
		$slop_stats = is_array( $ai_slop_detection['stats'] ?? null ) ? $ai_slop_detection['stats'] : array();
		$high_ai_slop_flags = $this->filter_high_ai_slop_flags( $ai_slop_flags );
		if ( ! empty( $ai_slop_flags ) ) {
			$content_improvements[] = array(
				'type'     => 'ai_slop_detection',
				'priority' => ! empty( $high_ai_slop_flags ) ? 'high' : 'medium',
				'title'    => sprintf( 'AI 痕迹检测：%d 处标记', count( $ai_slop_flags ) ),
				'detail'   => ! empty( $high_ai_slop_flags )
					? sprintf( '检测到 %d 处高优先级 AI 典型表达，建议重点润色。', count( $high_ai_slop_flags ) )
					: '检测到少量 AI 典型表达，建议编辑时留意并润色。',
			);
			foreach ( array_slice( $ai_slop_flags, 0, 3 ) as $flag ) {
				$flag = is_array( $flag ) ? $flag : array();
				$severity = sanitize_key( (string) ( $flag['severity'] ?? 'medium' ) );
				$content_improvements[] = array(
					'type'     => 'ai_slop_flag',
					'priority' => in_array( $severity, array( 'high', 'critical' ), true ) ? 'high' : 'medium',
					'title'    => sanitize_text_field( (string) ( $flag['phrase'] ?? ( $flag['category'] ?? 'AI 典型表达' ) ) ),
					'detail'   => $this->sanitize_metadata_text( (string) ( $flag['suggestion'] ?? ( $flag['category'] ?? '' ) ) ),
				);
			}
		}

		$next_action = 'editorial_review';
		$recommended_entry = 'npcink-abilities-toolkit/recipes/article-draft';
		$publish_mode = sanitize_key( (string) ( $source_input['publish_mode'] ?? '' ) );
		$image_mode = sanitize_key( (string) ( $source_input['image_mode'] ?? 'none' ) );
		if ( ! empty( $duplicate_guard['skip_recommended'] ) ) {
			$next_action = 'review_existing_candidate';
		} elseif ( in_array( $publish_mode, array( 'publish', 'review', 'schedule' ), true ) || 'none' !== $image_mode ) {
			$next_action = 'handoff_to_wordpress_article_production';
			$recommended_entry = 'npcink-abilities-toolkit/recipes/article-production';
		}
		if ( $preview_only && 'npcink-abilities-toolkit/recipes/article-draft' === $recommended_entry ) {
			$next_action = 'review_preview';
		}

		$handoff_hints = array(
			'stopping_point'    => $preview_only ? 'draft_preview' : 'draft_created',
			'next_action'       => $next_action,
			'recommended_entry' => $recommended_entry,
			'hints'             => array_values(
				array_filter(
					array(
						$preview_only
							? 'draft workflow 当前在 preview 路径里返回 draft content、metadata resolution 和 shared SEO/GEO review，但不创建真实 draft。'
							: 'draft workflow 负责 draft content、metadata resolution 和 shared SEO/GEO review。',
						'需要特色图、inline 图片、schedule 或 publish handoff 时，再进入 npcink-abilities-toolkit/recipes/article-production。',
						! empty( $review['needs_human_review'] ) ? '当前轻量 review 已提示需要人工继续润色。' : '',
					)
				)
			),
		);

		$review_summary = array(
			'writing_naturalness' => max( 0, min( 100, $this->absint_value( $review['writing_naturalness'] ?? 0 ) ) ),
			'style_match_score'   => max( 0, min( 100, $this->absint_value( $review['style_match_score'] ?? 0 ) ) ),
			'template_risk_level' => sanitize_key( (string) ( $review['template_risk_level'] ?? '' ) ),
			'needs_human_review'  => ! empty( $review['needs_human_review'] ),
			'next_action'         => sanitize_key( (string) ( $review['next_action'] ?? '' ) ),
			'focus_keyword'       => sanitize_text_field( (string) ( $source_input['topic'] ?? '' ) ),
			'platform_profile'    => $platform_profile,
			'ai_risk_review'      => is_array( $review['ai_risk_review'] ?? null ) ? $review['ai_risk_review'] : array(),
		);
		$ai_risk_items = is_array( $review_summary['ai_risk_review']['items'] ?? null ) ? $review_summary['ai_risk_review']['items'] : array();
		foreach ( array_slice( $ai_risk_items, 0, 2 ) as $risk_item ) {
			$risk_item = is_array( $risk_item ) ? $risk_item : array();
			$content_improvements[] = array(
				'type'     => 'ai_risk_review',
				'priority' => sanitize_key( (string) ( $risk_item['severity'] ?? 'medium' ) ),
				'title'    => sanitize_text_field( (string) ( $risk_item['title'] ?? 'AI 风险复核' ) ),
				'detail'   => $this->sanitize_metadata_text( (string) ( $risk_item['detail'] ?? '' ) ),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'article'                  => $article,
				'draft'                    => array(
					'post_id'            => $this->absint_value( $draft['post_id'] ?? 0 ),
					'edit_link'          => $this->esc_url_value( (string) ( $draft['edit_link'] ?? '' ) ),
					'preview_link'       => $this->esc_url_value( (string) ( $draft['preview_link'] ?? '' ) ),
					'seo_meta'           => $generated_seo,
					'preview_only'       => $preview_only,
					'real_draft_created' => ! $preview_only && $this->absint_value( $draft['post_id'] ?? 0 ) > 0,
				),
				'metadata_plan_resolution' => $metadata_plan_resolution,
				'content_improvements'     => $content_improvements,
				'seo_improvements'         => $seo_improvements,
				'geo_improvements'         => $geo_improvements,
				'quality_scoring'          => array(
					'overall_score'          => $quality_overall,
					'breakdown'              => $quality_breakdown,
					'improvement_suggestions' => $quality_suggestions,
				),
				'ai_slop_detection'        => array(
					'flags_count'         => count( $ai_slop_flags ),
					'high_priority_count' => count( $high_ai_slop_flags ),
					'stats'               => $slop_stats,
				),
				'review'                   => $review_summary,
				'evidence_notes'           => $this->extract_ai_risk_notes_by_key( $ai_risk_items, 'evidence_gap' ),
				'claim_risk_notes'         => $this->extract_ai_risk_notes_by_key( $ai_risk_items, 'claim_confidence' ),
				'source_references'        => $this->extract_source_references( $human_signals ),
				'handoff'                  => $handoff_hints,
			),
			'meta'    => array(
				'contract_version' => 'v1',
				'execution_mode'   => 'projection_only',
			),
		);
	}

	/**
	 * Builds one stable fingerprint value for article production dedupe and summaries.
	 *
	 * @param array<string,mixed> $input Source input.
	 * @return string
	 */
	private function build_article_production_fingerprint_value( array $input ) {
		$reference_post_ids = is_array( $input['reference_post_ids'] ?? null ) ? array_values( array_map( array( $this, 'absint_value' ), $input['reference_post_ids'] ) ) : array();
		sort( $reference_post_ids );

		$fingerprint_seed = array(
			'topic'              => sanitize_text_field( (string) ( $input['topic'] ?? $input['prompt'] ?? '' ) ),
			'image_mode'         => sanitize_key( (string) ( $input['image_mode'] ?? 'featured_only' ) ),
			'publish_mode'       => sanitize_key( (string) ( $input['publish_mode'] ?? 'draft' ) ),
			'content_format'     => sanitize_key( (string) ( $input['content_format'] ?? 'html' ) ),
			'voice_profile'      => sanitize_key( (string) ( $input['voice_profile'] ?? '' ) ),
			'opening_style'      => sanitize_key( (string) ( $input['opening_style'] ?? '' ) ),
			'structure_style'    => sanitize_key( (string) ( $input['structure_style'] ?? '' ) ),
			'reference_post_ids' => $reference_post_ids,
		);
		$encoded_seed = function_exists( 'wp_json_encode' )
			? wp_json_encode( $fingerprint_seed )
			: json_encode( $fingerprint_seed );

		return substr( md5( (string) $encoded_seed ), 0, 16 );
	}

	/**
	 * Sanitizes and limits a list of text values.
	 *
	 * @param array<int,mixed> $values Raw values.
	 * @param int              $limit Maximum values.
	 * @return array<int,string>
	 */
	private function sanitize_limited_text_list( array $values, $limit ) {
		$items = array();
		foreach ( $values as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$items[] = $value;
			}
			if ( count( $items ) >= (int) $limit ) {
				break;
			}
		}
		return array_values( $items );
	}

	/**
	 * Builds normalized recommendation improvement rows.
	 *
	 * @param array<string,mixed> $analysis Analysis payload.
	 * @param string              $fallback_type Fallback type.
	 * @return array<int,array<string,string>>
	 */
	private function build_recommendation_improvements( array $analysis, $fallback_type ) {
		$improvements = array();
		$recommendations = is_array( $analysis['recommendations'] ?? null ) ? $analysis['recommendations'] : array();
		foreach ( $recommendations as $item ) {
			$item = is_array( $item ) ? $item : array();
			$improvements[] = array(
				'type'     => sanitize_key( (string) ( $item['type'] ?? $fallback_type ) ),
				'priority' => sanitize_key( (string) ( $item['priority'] ?? 'medium' ) ),
				'title'    => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'detail'   => $this->sanitize_metadata_text( (string) ( $item['detail'] ?? '' ) ),
			);
		}
		return $improvements;
	}

	/**
	 * Extracts source-reference notes from human signals.
	 *
	 * @param array<int,mixed> $human_signals Human signals.
	 * @return array<int,string>
	 */
	private function extract_source_references( array $human_signals ) {
		$references = array();
		foreach ( $human_signals as $value ) {
			$value = $this->sanitize_metadata_text( (string) $value );
			if ( false !== strpos( $value, '案例/数据来源：' ) ) {
				$reference = trim( str_replace( '案例/数据来源：', '', $value ) );
				if ( '' !== $reference ) {
					$references[] = $reference;
				}
			}
		}
		return array_values( $references );
	}

	/**
	 * Extracts AI-risk details by risk key.
	 *
	 * @param array<int,mixed> $risk_items Risk items.
	 * @param string           $key Risk key.
	 * @return array<int,string>
	 */
	private function extract_ai_risk_notes_by_key( array $risk_items, $key ) {
		$notes = array();
		$key = sanitize_key( (string) $key );
		foreach ( $risk_items as $risk_item ) {
			$risk_item = is_array( $risk_item ) ? $risk_item : array();
			if ( $key !== sanitize_key( (string) ( $risk_item['key'] ?? '' ) ) ) {
				continue;
			}
			$detail = $this->sanitize_metadata_text( (string) ( $risk_item['detail'] ?? '' ) );
			if ( '' !== $detail ) {
				$notes[] = $detail;
			}
		}
		return array_values( $notes );
	}

	/**
	 * Truncates UTF-8 text and uses the legacy Chinese ellipsis for migrated article contracts.
	 *
	 * @param string $text Raw text.
	 * @param int    $max_chars Maximum characters.
	 * @return string
	 */
	private function truncate_utf8_text( $text, $max_chars ) {
		$text = (string) $text;
		$max_chars = max( 1, (int) $max_chars );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max_chars ? mb_substr( $text, 0, $max_chars, 'UTF-8' ) . '…' : $text;
		}
		return strlen( $text ) > $max_chars ? substr( $text, 0, $max_chars ) . '...' : $text;
	}

	/**
	 * Filters high-priority AI slop flags.
	 *
	 * @param array<int,mixed> $flags Flags.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_high_ai_slop_flags( array $flags ) {
		$high_flags = array();
		foreach ( $flags as $flag ) {
			$flag = is_array( $flag ) ? $flag : array();
			if ( in_array( sanitize_key( (string) ( $flag['severity'] ?? '' ) ), array( 'high', 'critical' ), true ) ) {
				$high_flags[] = $flag;
			}
		}
		return $high_flags;
	}

	/**
	 * Builds a canonical lightweight AI-risk review payload.
	 *
	 * @param string            $content Article content.
	 * @param string            $platform_profile Platform profile.
	 * @param array<int,mixed>  $human_signals Human signal strings.
	 * @param string            $template_risk_level Template risk level.
	 * @param array<int,string> $template_findings Template findings.
	 * @param array<int,string> $style_findings Style findings.
	 * @return array<string,mixed>
	 */
	private function build_article_ai_risk_review( string $content, string $platform_profile, array $human_signals, string $template_risk_level, array $template_findings, array $style_findings ): array {
		$platform_profile = sanitize_key( $platform_profile );
		if ( ! in_array( $platform_profile, array( 'generic', 'wechat', 'xiaohongshu' ), true ) ) {
			$platform_profile = 'generic';
		}

		$items = array();
		$explicit_signal_count = 0;
		$source_reference_count = 0;
		foreach ( $human_signals as $signal ) {
			$signal = $this->sanitize_metadata_text( (string) $signal );
			if ( '' === $signal ) {
				continue;
			}
			if ( false !== strpos( $signal, '真实经历：' ) || false !== strpos( $signal, '作者观点：' ) || false !== strpos( $signal, '想强调的结论：' ) ) {
				++$explicit_signal_count;
			}
			if ( false !== strpos( $signal, '案例/数据来源：' ) ) {
				++$source_reference_count;
			}
		}

		if ( 'high' === $template_risk_level || ! empty( $template_findings ) ) {
			$items[] = array(
				'key'      => 'template_tone',
				'severity' => 'high' === $template_risk_level ? 'high' : 'medium',
				'title'    => '模板味偏重',
				'detail'   => ! empty( $template_findings ) ? $this->sanitize_metadata_text( (string) $template_findings[0] ) : '当前表达仍偏模板化，建议压缩套话并加入更具体的人类判断。',
			);
		}

		if ( 0 === $explicit_signal_count ) {
			$items[] = array(
				'key'      => 'human_signal_gap',
				'severity' => 'high',
				'title'    => '真实信息不足',
				'detail'   => '当前缺少明确的真实经历、个人观点或想强调的结论，更适合先停在编辑草稿或结构建议。',
			);
		}

		if ( 0 === $source_reference_count ) {
			$items[] = array(
				'key'      => 'evidence_gap',
				'severity' => 'medium',
				'title'    => '来源支撑不足',
				'detail'   => '当前未提供明确案例、数据或来源说明，涉及事实判断时建议先补可审来源。',
			);
		}

		if ( 'generic' !== $platform_profile && ! empty( $style_findings ) ) {
			$items[] = array(
				'key'      => 'platform_fit',
				'severity' => 'medium',
				'title'    => '平台风格匹配不足',
				'detail'   => $this->sanitize_metadata_text( (string) $style_findings[0] ),
			);
		}

		$content_text = $this->normalize_plain_text( $content );
		foreach ( array( '一定', '绝对', '全网', '爆款', '立刻', '毫无疑问' ) as $marker ) {
			$contains_marker = function_exists( 'mb_strpos' )
				? false !== mb_strpos( $content_text, $marker )
				: false !== strpos( $content_text, $marker );
			if ( $contains_marker ) {
				$items[] = array(
					'key'      => 'claim_confidence',
					'severity' => 'medium',
					'title'    => '表达可能偏夸张',
					'detail'   => '检测到较强确定性或营销化表述，建议补来源或改成更可核验的说法。',
				);
				break;
			}
		}

		$highest_severity = 'low';
		foreach ( $items as $item ) {
			$severity = sanitize_key( (string) ( $item['severity'] ?? 'low' ) );
			if ( 'high' === $severity ) {
				$highest_severity = 'high';
				break;
			}
			if ( 'medium' === $severity ) {
				$highest_severity = 'medium';
			}
		}

		return array(
			'platform_profile'       => $platform_profile,
			'human_signals_present'  => ! empty( $human_signals ),
			'explicit_signal_count'  => $explicit_signal_count,
			'source_reference_count' => $source_reference_count,
			'status'                 => empty( $items ) ? 'ok' : 'needs_review',
			'highest_severity'       => $highest_severity,
			'items'                  => array_values( $items ),
		);
	}

	/**
	 * Relaxes false-positive AI-risk blockers when the article strongly matches reference style.
	 *
	 * @param array<string,mixed> $ai_risk_review AI-risk review payload.
	 * @param int                 $style_match_score Style match score.
	 * @param array<int,string>   $style_findings Style findings.
	 * @return array<string,mixed>
	 */
	private function relax_article_ai_risk_review_for_reference_style( array $ai_risk_review, int $style_match_score, array $style_findings ): array {
		if ( $style_match_score < 90 || ! empty( $style_findings ) ) {
			return $ai_risk_review;
		}

		$items = array_values(
			array_filter(
				is_array( $ai_risk_review['items'] ?? null ) ? $ai_risk_review['items'] : array(),
				static function ( $item ): bool {
					$item = is_array( $item ) ? $item : array();
					$key = sanitize_key( (string) ( $item['key'] ?? '' ) );
					return ! in_array( $key, array( 'human_signal_gap', 'evidence_gap' ), true );
				}
			)
		);

		$highest_severity = 'low';
		foreach ( $items as $item ) {
			$severity = sanitize_key( (string) ( is_array( $item ) ? ( $item['severity'] ?? 'low' ) : 'low' ) );
			if ( 'high' === $severity ) {
				$highest_severity = 'high';
				break;
			}
			if ( 'medium' === $severity ) {
				$highest_severity = 'medium';
			}
		}

		$ai_risk_review['items'] = $items;
		$ai_risk_review['highest_severity'] = $highest_severity;
		$ai_risk_review['status'] = empty( $items ) ? 'ok' : 'needs_review';
		$ai_risk_review['reference_style_relaxed'] = true;

		return $ai_risk_review;
	}
}
