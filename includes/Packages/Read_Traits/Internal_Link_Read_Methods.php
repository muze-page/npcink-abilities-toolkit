<?php
/**
 * Internal Link Read Methods for Core_Read_Package.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides internal-link target discovery callbacks.
 */
trait Internal_Link_Read_Methods {
	/**
	 * Resolves local published posts that can be used as internal-link targets.
	 *
	 * @param mixed $input Input args.
	 * @return array<string,mixed>
	 */
	public function resolve_internal_link_targets( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current_post_id = $this->absint_value( $input['current_post_id'] ?? 0 );
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'any' ) );
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$content = $this->normalize_plain_text( $input['content_text'] ?? ( $input['content'] ?? '' ) );
		$excerpt = $this->normalize_plain_text( $input['excerpt'] ?? '' );
		$selected_text = $this->normalize_plain_text( $input['selected_text'] ?? ( $input['selected_block_text'] ?? '' ) );
		$query_text = $this->normalize_plain_text( $input['query'] ?? '' );
		$user_instruction = $this->normalize_plain_text( $input['user_instruction'] ?? '' );
		$focus_keyword = sanitize_text_field( (string) ( $input['focus_keyword'] ?? '' ) );
		$keywords = is_array( $input['keywords'] ?? null ) ? $input['keywords'] : array();
		$content_blocks = $this->normalize_internal_link_content_blocks( $input['content_blocks'] ?? array() );
		$supplied_evidence = $this->normalize_supplied_internal_link_evidence( $input['related_content_evidence'] ?? array(), $current_post_id );
		$candidate_source = sanitize_key( (string) ( $input['candidate_source'] ?? ( empty( $supplied_evidence ) ? 'local_fallback' : 'supplied_evidence' ) ) );
		$terms = $this->collect_focus_terms( $title, $focus_keyword, array_merge( $keywords, array( $query_text, $selected_text, $excerpt, $user_instruction ) ) );
		$max_targets = max( 1, min( 6, $this->absint_value( $input['max_targets'] ?? 3 ) ) );
		$candidate_limit = max( 1, min( 8, $this->absint_value( $input['candidate_limit'] ?? $max_targets ) ) );

		$targets = array();
		if ( empty( $supplied_evidence ) && class_exists( '\WP_Query' ) ) {
			foreach ( array_slice( $terms, 0, 4 ) as $term ) {
				$query = new \WP_Query(
					array(
						'post_type'      => '' !== $post_type ? $post_type : 'any',
						'post_status'    => array( 'publish' ),
						'posts_per_page' => $candidate_limit,
						's'              => $term,
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Bounded candidate search must exclude the source post from its own internal-link targets.
						'post__not_in'   => $current_post_id > 0 ? array( $current_post_id ) : array(),
						'fields'         => 'ids',
					)
				);
				foreach ( (array) $query->posts as $post_id ) {
					$post_id = $this->absint_value( $post_id );
					if ( $post_id <= 0 ) {
						continue;
					}
					$targets[ $post_id ] = array(
						'post_id'         => $post_id,
						'title'           => sanitize_text_field( (string) get_the_title( $post_id ) ),
						'url'             => esc_url_raw( (string) get_permalink( $post_id ) ),
						'anchor_text'     => $term,
						/* translators: %s: Related search term. */
						'reason'          => sprintf( __( 'Existing site content related to "%s" can be used as supplemental reading.', 'npcink-abilities-toolkit' ), $term ),
						'relevance_score' => 0.72,
						'source'          => 'local_internal_link_inventory',
						'evidence_refs'   => array( 'local_post:' . $post_id ),
					);
					if ( count( $targets ) >= $candidate_limit ) {
						break 2;
					}
				}
			}
		}

		foreach ( $supplied_evidence as $evidence_item ) {
			$post_id = $this->absint_value( $evidence_item['post_id'] ?? 0 );
			$key = 0 < $post_id ? $post_id : 'url:' . (string) ( $evidence_item['url'] ?? '' );
			if ( '' === (string) $key ) {
				continue;
			}
			if ( isset( $targets[ $key ] ) ) {
				$targets[ $key ] = array_merge( $targets[ $key ], $evidence_item );
				continue;
			}

			$targets[ $key ] = $evidence_item;
			if ( count( $targets ) >= $candidate_limit ) {
				break;
			}
		}
		uasort(
			$targets,
			static function ( array $left, array $right ): int {
				return (float) ( $right['priority_score'] ?? $right['relevance_score'] ?? 0 ) <=> (float) ( $left['priority_score'] ?? $left['relevance_score'] ?? 0 );
			}
		);

		$placement_plan = array();
		$candidate_items = array();
		foreach ( array_slice( array_values( $targets ), 0, $candidate_limit ) as $index => $target ) {
			$anchor_text = sanitize_text_field( (string) ( $target['anchor_text'] ?? '' ) );
			if ( '' === $anchor_text ) {
				$anchor_text = $this->internal_link_anchor_from_title( (string) ( $target['title'] ?? '' ) );
			}
			$reason = $this->sanitize_metadata_text( (string) ( $target['reason'] ?? '' ) );
			if ( '' === $reason ) {
				$reason = __( 'Supplied related-content evidence can be reviewed as a manual internal-link target.', 'npcink-abilities-toolkit' );
			}
			$target_url = esc_url_raw( (string) ( $target['url'] ?? '' ) );
			$target_post_id = $this->absint_value( $target['post_id'] ?? 0 );
			$link_graph_issues = is_array( $target['link_graph_issues'] ?? null ) ? $target['link_graph_issues'] : array();
			$shared_terms = is_array( $target['shared_terms'] ?? null ) ? $target['shared_terms'] : array();
			$source_match = $this->internal_link_source_match( $content_blocks, $selected_text, $anchor_text, (string) ( $target['title'] ?? '' ) );
			if ( ! empty( $source_match['matched_text'] ) ) {
				$anchor_text = (string) $source_match['matched_text'];
			}

			$placement_plan[] = array(
				'target_post_id' => $target_post_id,
				'target_url'     => $target_url,
				'anchor_text'    => $anchor_text,
				'placement_hint' => __( 'Insert one internal link after the first paragraph that explains the core concept.', 'npcink-abilities-toolkit' ),
				'reason'         => $reason,
			);

			$candidate_items[] = array(
				'title'                 => sanitize_text_field( (string) ( $target['title'] ?? __( 'Related internal target', 'npcink-abilities-toolkit' ) ) ),
				'target_post_id'        => $target_post_id,
				'target_url'            => $target_url,
				'suggested_anchor_text' => $anchor_text,
				'placement_hint'        => __( 'Review near the paragraph where this topic is mentioned; the ability does not insert the link.', 'npcink-abilities-toolkit' ),
				'reason'                => $reason,
				'priority_reason'       => in_array( 'orphan_post', $link_graph_issues, true )
					? __( 'Prioritized because the related target currently has no detected incoming internal links.', 'npcink-abilities-toolkit' )
					: ( ! empty( $shared_terms ) ? __( 'Prioritized because the current and target articles share topic terms.', 'npcink-abilities-toolkit' ) : '' ),
				'link_graph_issues'     => $link_graph_issues,
				'shared_terms'          => $shared_terms,
				'incoming_count'        => $this->absint_value( $target['incoming_count'] ?? 0 ),
				'evidence_refs'         => is_array( $target['evidence_refs'] ?? null ) ? array_values( array_map( 'sanitize_text_field', $target['evidence_refs'] ) ) : array( 'internal_link_candidate:' . ( $index + 1 ) ),
				'score'                 => is_numeric( $target['relevance_score'] ?? null ) ? (float) $target['relevance_score'] : null,
				'source_match'          => $source_match,
				'source'                => sanitize_key( (string) ( $target['source'] ?? 'local_internal_link_inventory' ) ),
				'candidate_source'      => $candidate_source,
				'status'                => 'review_only_candidate',
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'targets'        => array_slice( array_values( $targets ), 0, $candidate_limit ),
				'placement_plan' => $placement_plan,
				'no_link_zones'  => '' !== $content ? array( __( 'Keep titles, opening summaries, and CTA paragraphs link-free to avoid diluting the main answer.', 'npcink-abilities-toolkit' ) ) : array(),
				'internal_link_candidates' => array(
					'artifact_type'          => 'internal_link_candidates.v1',
					'candidate_type'         => 'internal_link_candidates',
					'candidate_contract'     => 'recommendation_candidate.v1',
					'write_posture'          => 'suggestion_only',
					'final_write_path'       => 'native_editor_commit',
					'direct_wordpress_write' => false,
					'source_ability_id'      => 'npcink-abilities-toolkit/resolve-internal-link-targets',
					'candidate_source'       => $candidate_source,
					'items'                  => $candidate_items,
					'review_policy'          => array(
						'link_insertion_owner'       => 'human_editor',
						'automatic_anchor_insert'    => false,
						'visible_editor_apply'       => true,
						'post_content_patch_handoff' => false,
						'current_post_excluded'      => true,
					),
					'handoff'                => array(
						'final_writes'           => 'native_editor_commit',
						'direct_wordpress_write' => false,
						'blocked_actions'        => array(
							'no_backend_post_content_patch',
							'no_patch_post_content_handoff_yet',
							'no_automatic_anchor_insertion',
						),
					),
				),
				'summary'        => array(
					'candidate_count' => count( $candidate_items ),
					'placement_count' => count( $placement_plan ),
					'focus_terms'     => $terms,
				),
			),
			'meta'    => array(
				'source'         => 'local_internal_link_inventory',
				'execution_mode' => 'deterministic',
			),
			'message' => __( 'Internal link targets resolved.', 'npcink-abilities-toolkit' ),
		);
	}

	private function normalize_internal_link_content_blocks( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return array();
		}
		$normalized = array();
		foreach ( array_slice( $blocks, 0, 80 ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$client_id = sanitize_text_field( (string) ( $block['client_id'] ?? '' ) );
			$block_name = sanitize_key( str_replace( '/', '-', (string) ( $block['block_name'] ?? '' ) ) );
			$text = $this->normalize_plain_text( $block['text'] ?? '' );
			if ( '' !== $client_id && '' !== $text ) {
				$normalized[] = array( 'client_id' => $client_id, 'block_name' => $block_name, 'text' => $text );
			}
		}
		return $normalized;
	}

	private function internal_link_source_match( array $blocks, $selected_text, $anchor_text, $title ) {
		$phrases = array_filter( array_unique( array_map( 'trim', array( $selected_text, $anchor_text, $title ) ) ) );
		foreach ( $blocks as $block ) {
			if ( $this->internal_link_is_heading_block( $block['block_name'] ?? '' ) ) {
				continue;
			}
			foreach ( $phrases as $phrase ) {
				$phrase_length = $this->internal_link_text_length( $phrase );
				$is_explicit_selection = $phrase === trim( (string) $selected_text );
				if ( ( ! $is_explicit_selection && $phrase_length < 4 ) || $phrase_length > 80 ) {
					continue;
				}
				$offset = function_exists( 'mb_stripos' ) ? mb_stripos( $block['text'], $phrase ) : stripos( $block['text'], $phrase );
				if ( false !== $offset ) {
					return array(
						'block_client_id' => $block['client_id'],
						'block_name'      => $block['block_name'],
						'matched_text'    => $phrase,
						'text_offset'     => (int) $offset,
						'expected_text'   => $block['text'],
						'match_basis'     => $phrase === trim( (string) $selected_text ) ? 'editor_selection' : 'existing_article_phrase',
					);
				}
			}
		}
		return array();
	}

	private function internal_link_is_heading_block( $block_name ) {
		$name = sanitize_key( str_replace( '/', '-', (string) $block_name ) );
		return in_array( $name, array( 'core-heading', 'core-post-title', 'core-query-title' ), true );
	}

	private function internal_link_text_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}

	/**
	 * Normalizes host-supplied related content into internal-link target rows.
	 *
	 * @param mixed $evidence Raw evidence rows.
	 * @param int   $current_post_id Current post id.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_supplied_internal_link_evidence( $evidence, $current_post_id ) {
		if ( ! is_array( $evidence ) ) {
			return array();
		}

		$items = array();
		foreach ( array_slice( $evidence, 0, 12 ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$post_id = $this->absint_value( $item['post_id'] ?? ( $item['id'] ?? 0 ) );
			if ( 0 < $post_id && $post_id === $current_post_id ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $item['title'] ?? $item['name'] ?? '' ) );
			$url = esc_url_raw( (string) ( $item['url'] ?? ( $item['permalink'] ?? ( $item['link'] ?? ( $item['source_url'] ?? '' ) ) ) ) );
			if ( '' === $title && '' === $url ) {
				continue;
			}

			$score = is_numeric( $item['score'] ?? null ) ? (float) $item['score'] : null;
			$link_graph_issues = array_values( array_filter( array_map( 'sanitize_key', is_array( $item['link_graph_issues'] ?? null ) ? $item['link_graph_issues'] : array() ) ) );
			$shared_terms = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', is_array( $item['shared_terms'] ?? null ) ? $item['shared_terms'] : array() ) ) ), 0, 3 );
			$priority_score = null === $score ? 0.0 : $score;
			if ( in_array( 'orphan_post', $link_graph_issues, true ) ) {
				$priority_score += 0.12;
			}
			if ( ! empty( $shared_terms ) ) {
				$priority_score += 0.08;
			}
			$evidence_ref = sanitize_key( (string) ( $item['evidence_ref'] ?? ( 0 < $post_id ? 'supplied_post_' . $post_id : 'supplied_related_' . ( $index + 1 ) ) ) );
			$items[] = array(
				'post_id'         => $post_id,
				'title'           => $title,
				'url'             => $url,
				'anchor_text'     => $this->internal_link_anchor_from_title( $title ),
				'reason'          => $this->sanitize_metadata_text( (string) ( $item['reason'] ?? $item['excerpt'] ?? $item['snippet'] ?? '' ) ),
				'relevance_score' => $score,
				'priority_score'  => $priority_score,
				'incoming_count'  => $this->absint_value( $item['incoming_count'] ?? 0 ),
				'link_graph_issues' => $link_graph_issues,
				'shared_terms'    => $shared_terms,
				'source'          => 'supplied_related_content_evidence',
				'evidence_refs'   => array( $evidence_ref ),
			);
		}

		return $items;
	}

	/**
	 * Builds bounded anchor text from a target title.
	 *
	 * @param string $title Target title.
	 * @return string
	 */
	private function internal_link_anchor_from_title( $title ) {
		$anchor = trim( sanitize_text_field( (string) $title ) );
		$max_chars = 80;
		if ( $this->internal_link_text_length( $anchor ) > $max_chars ) {
			$cut = function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, $max_chars, 'UTF-8' ) : substr( $anchor, 0, $max_chars );
			$next = function_exists( 'mb_substr' ) ? mb_substr( $anchor, $max_chars, 1, 'UTF-8' ) : substr( $anchor, $max_chars, 1 );
			// Do not leave a partial ASCII word at the end of a mixed-language anchor.
			if ( preg_match( '/[A-Za-z0-9]$/', $cut ) && preg_match( '/^[A-Za-z0-9]/', $next ) ) {
				$cut = preg_replace( '/[A-Za-z0-9]+$/', '', $cut ) ?? $cut;
			}
			$anchor = trim( $cut, " \t\n\r\0\x0B,.;:!?，。；：、-" );
		}
		return '' !== $anchor ? $anchor : __( 'Related article', 'npcink-abilities-toolkit' );
	}
}
