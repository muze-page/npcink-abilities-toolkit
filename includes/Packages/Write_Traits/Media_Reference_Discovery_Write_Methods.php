<?php
/**
 * Media content-reference discovery helpers for Core write abilities.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Packages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps bounded media-reference discovery separate from Core write orchestration.
 */
trait Media_Reference_Discovery_Write_Methods {
	/**
	 * Builds exact old->new reference pairs for a replacement plan.
	 *
	 * @param array<string,mixed> $plan Replacement plan.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function media_content_reference_pairs_for_plan( array $plan ) {
		$before = is_array( $plan['before'] ?? null ) ? $plan['before'] : array();
		$after = is_array( $plan['after'] ?? null ) ? $plan['after'] : array();
		$old_relative = $this->normalize_media_relative_file( (string) ( $before['relative_file'] ?? '' ) );
		$new_relative = $this->normalize_media_relative_file( (string) ( $after['relative_file'] ?? '' ) );
		$old_url = esc_url_raw( (string) ( $before['url'] ?? '' ) );
		$new_url = esc_url_raw( (string) ( $after['url'] ?? '' ) );
		$old_path = $this->media_content_reference_url_path( $old_url );
		$new_path = $this->media_content_reference_url_path( $new_url );
		$pairs = array(
			array( 'old' => $old_url, 'new' => $new_url ),
			array( 'old' => $old_path, 'new' => $new_path ),
			array( 'old' => $old_relative, 'new' => $new_relative ),
		);

		$current = is_array( $plan['_current'] ?? null ) ? $plan['_current'] : array();
		$metadata = is_array( $current['metadata'] ?? null ) ? $current['metadata'] : array();
		foreach ( $this->media_content_reference_source_relative_files( $metadata, $old_relative ) as $source_relative ) {
			$source_url = $this->media_url_for_relative_file( $source_relative );
			$source_path = $this->media_content_reference_url_path( $source_url );
			$pairs[] = array( 'old' => $source_url, 'new' => $new_url );
			$pairs[] = array( 'old' => $source_path, 'new' => $new_path );
			$pairs[] = array( 'old' => $source_relative, 'new' => $new_relative );
		}
		$sizes = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		$old_dir = dirname( $old_relative );
		$old_dir = '.' !== $old_dir ? trim( $old_dir, '/' ) : '';
		foreach ( $sizes as $size ) {
			$size = is_array( $size ) ? $size : array();
			$file = $this->sanitize_media_file_name( (string) ( $size['file'] ?? '' ) );
			if ( '' === $file ) {
				continue;
			}
			$size_relative = '' !== $old_dir ? $old_dir . '/' . $file : $file;
			$size_url = $this->media_url_for_relative_file( $size_relative );
			$size_path = $this->media_content_reference_url_path( $size_url );
			$pairs[] = array( 'old' => $size_url, 'new' => $new_url );
			$pairs[] = array( 'old' => $size_path, 'new' => $new_path );
			$pairs[] = array( 'old' => $size_relative, 'new' => $new_relative );
		}

		return $this->merge_media_content_reference_pairs( $pairs, array() );
	}

	/**
	 * Adds old sized variant references found in content even when not in metadata.
	 *
	 * @param string              $content Post content.
	 * @param array<string,mixed> $plan Replacement plan.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function media_content_reference_dynamic_sized_pairs( $content, array $plan ) {
		$before = is_array( $plan['before'] ?? null ) ? $plan['before'] : array();
		$after = is_array( $plan['after'] ?? null ) ? $plan['after'] : array();
		$old_relative = $this->normalize_media_relative_file( (string) ( $before['relative_file'] ?? '' ) );
		$new_relative = $this->normalize_media_relative_file( (string) ( $after['relative_file'] ?? '' ) );
		$new_url = esc_url_raw( (string) ( $after['url'] ?? '' ) );
		$new_path = $this->media_content_reference_url_path( $new_url );
		$current = is_array( $plan['_current'] ?? null ) ? $plan['_current'] : array();
		$metadata = is_array( $current['metadata'] ?? null ) ? $current['metadata'] : array();
		if ( '' === $new_url ) {
			return array();
		}
		$pairs = array();
		foreach ( $this->media_content_reference_source_relative_files( $metadata, $old_relative ) as $source_relative ) {
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
				$sized_basename = $this->sanitize_media_file_name( (string) $sized_basename );
				if ( '' === $sized_basename ) {
					continue;
				}
				$size_relative = '' !== $old_dir ? $old_dir . '/' . $sized_basename : $sized_basename;
				$size_url = $this->media_url_for_relative_file( $size_relative );
				$size_path = $this->media_content_reference_url_path( $size_url );
				$pairs[] = array( 'old' => $size_url, 'new' => $new_url );
				$pairs[] = array( 'old' => $size_path, 'new' => $new_path );
				$pairs[] = array( 'old' => $size_relative, 'new' => $new_relative );
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
	private function media_content_reference_source_relative_files( array $metadata, $current_relative ) {
		$current_relative = $this->normalize_media_relative_file( $current_relative );
		$base_dir = '' !== $current_relative ? dirname( $current_relative ) : '';
		$base_dir = '.' !== $base_dir ? trim( $base_dir, '/' ) : '';
		$candidates = array(
			$current_relative,
			(string) ( $metadata['file'] ?? '' ),
		);
		$original_image = $this->sanitize_media_file_name( (string) ( $metadata['original_image'] ?? '' ) );
		if ( '' !== $original_image ) {
			$candidates[] = false === strpos( $original_image, '/' ) && '' !== $base_dir ? $base_dir . '/' . $original_image : $original_image;
		}
		foreach ( $candidates as $candidate ) {
			$variant = $this->media_content_reference_without_unique_suffix( $candidate );
			if ( '' !== $variant ) {
				$candidates[] = $variant;
			}
		}

		$files = array();
		foreach ( $candidates as $candidate ) {
			$file = $this->normalize_media_relative_file( (string) $candidate );
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
	private function media_content_reference_without_unique_suffix( $relative_file ) {
		$relative_file = $this->normalize_media_relative_file( $relative_file );
		$basename = basename( $relative_file );
		if ( ! preg_match( '/^(.+)-[0-9]+(\.[^.]+)$/', $basename, $matches ) ) {
			return '';
		}
		$dir = dirname( $relative_file );
		$dir = '.' !== $dir ? trim( $dir, '/' ) : '';
		$file = $this->sanitize_media_file_name( (string) $matches[1] . (string) $matches[2] );
		if ( '' === $file ) {
			return '';
		}
		return '' !== $dir ? $dir . '/' . $file : $file;
	}

	/**
	 * Merges old->new reference pairs.
	 *
	 * @param array<int,array<string,string>> $primary Primary pairs.
	 * @param array<int,array<string,string>> $secondary Secondary pairs.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function merge_media_content_reference_pairs( array $primary, array $secondary ) {
		$merged = array();
		foreach ( array_merge( $primary, $secondary ) as $pair ) {
			$old = trim( (string) ( is_array( $pair ) ? ( $pair['old'] ?? '' ) : '' ) );
			$new = trim( (string) ( is_array( $pair ) ? ( $pair['new'] ?? '' ) : '' ) );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$key = $old . "\n" . $new;
			$merged[ $key ] = array(
				'old' => $old,
				'new' => $new,
			);
		}
		return array_values( $merged );
	}

	/**
	 * Returns bounded candidate posts likely to contain old media references.
	 *
	 * @param int           $attachment_id Attachment id.
	 * @param array<string> $needles Search strings.
	 * @param int           $limit Candidate limit.
	 * @return array<int,object>
	 */
	private function media_content_reference_candidate_posts( $attachment_id, array $needles, $limit ) {
		$attachment_id = absint( $attachment_id );
		$limit = max( 1, min( 150, absint( $limit ) ) );
		if ( isset( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) && is_array( $GLOBALS['npcink_abilities_toolkit_unit_style_posts'] ) ) {
			return get_posts( array( 'posts_per_page' => $limit ) );
		}

		$candidates = array();
		foreach ( array_slice( array_values( array_filter( array_unique( $needles ) ) ), 0, 25 ) as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' === $needle ) {
				continue;
			}
			foreach (
				get_posts(
					array(
						'post_type'      => 'any',
						'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
						'posts_per_page' => $limit,
						'orderby'        => 'ID',
						'order'          => 'DESC',
						's'              => $needle,
					)
				) as $post
			) {
				if ( is_object( $post ) && 'attachment' !== (string) ( $post->post_type ?? '' ) ) {
					$candidates[ absint( $post->ID ?? 0 ) ] = $post;
				}
				if ( count( $candidates ) >= $limit ) {
					break 2;
				}
			}
		}
		if ( ! empty( $candidates ) ) {
			return array_values( $candidates );
		}

		return get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				's'              => 'wp-image-' . $attachment_id,
			)
		);
	}

	/**
	 * Builds bounded search needles for candidate post lookup.
	 *
	 * @param int                                      $attachment_id Attachment id.
	 * @param array<string,mixed>                      $plan Replacement plan.
	 * @param array<int,array{old:string,new:string}> $pairs Reference pairs.
	 * @return array<int,string>
	 */
	private function media_content_reference_needles( $attachment_id, array $plan, array $pairs ) {
		$needles = array( 'wp-image-' . absint( $attachment_id ), '"id":' . absint( $attachment_id ), 'data-id="' . absint( $attachment_id ) . '"' );
		foreach ( $pairs as $pair ) {
			$needles[] = (string) ( $pair['old'] ?? '' );
		}
		$before = is_array( $plan['before'] ?? null ) ? $plan['before'] : array();
		$old_relative = $this->normalize_media_relative_file( (string) ( $before['relative_file'] ?? '' ) );
		$old_stem = preg_replace( '/\.[^.]+$/', '', basename( $old_relative ) );
		if ( strlen( (string) $old_stem ) >= 4 ) {
			$needles[] = (string) $old_stem;
		}
		return array_values( array_filter( array_unique( array_map( 'strval', $needles ) ) ) );
	}

	/**
	 * Returns the path component of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function media_content_reference_url_path( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}
}
