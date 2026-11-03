<?php
/**
 * Gutenberg notes collector.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

/**
 * Collects Gutenberg editor notes (`comment_type = note`) and skips harvested IDs.
 */
class Notes_Collector {

	public const HARVESTED_META_KEY = '_prc_nexus_sig_harvested_comment_ids';

	/**
	 * Whether a comment array is a Gutenberg note (not a public comment).
	 *
	 * @param array<string, mixed> $comment Normalized comment.
	 * @return bool
	 */
	public static function is_note_comment( array $comment ): bool {
		$type = isset( $comment['type'] ) ? (string) $comment['type'] : '';
		return 'note' === $type;
	}

	/**
	 * Drop public comments and IDs already harvested.
	 *
	 * @param array<int, array<string, mixed>> $comments      Normalized comments.
	 * @param int[]                            $harvested_ids Previously harvested comment IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_unharvested( array $comments, array $harvested_ids ): array {
		$harvested = array_fill_keys( array_map( 'intval', $harvested_ids ), true );
		$kept      = array();

		foreach ( $comments as $comment ) {
			if ( ! self::is_note_comment( $comment ) ) {
				continue;
			}
			$id = isset( $comment['id'] ) ? (int) $comment['id'] : 0;
			if ( $id <= 0 || isset( $harvested[ $id ] ) ) {
				continue;
			}
			$kept[] = $comment;
		}

		return $kept;
	}

	/**
	 * Drop comments marked `skip_harvest` and every reply under them.
	 *
	 * Replies are dropped with their parent so group_threads() does not turn a
	 * reply under a skipped Note into a root thread with no context.
	 *
	 * @param array<int, array<string, mixed>> $comments Normalized comments.
	 * @return array<int, array<string, mixed>>
	 */
	public static function drop_skipped_threads( array $comments ): array {
		$parents = array();
		$skipped = array();
		foreach ( $comments as $comment ) {
			$id             = (int) ( $comment['id'] ?? 0 );
			$parents[ $id ] = (int) ( $comment['parent_id'] ?? 0 );
			if ( ! empty( $comment['skip_harvest'] ) ) {
				$skipped[ $id ] = true;
			}
		}

		if ( array() === $skipped ) {
			return $comments;
		}

		$kept = array();
		foreach ( $comments as $comment ) {
			$id   = (int) ( $comment['id'] ?? 0 );
			$seen = array();
			while ( $id > 0 && ! isset( $skipped[ $id ] ) && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$id          = $parents[ $id ] ?? 0;
			}
			if ( $id > 0 && isset( $skipped[ $id ] ) ) {
				continue;
			}
			$kept[] = $comment;
		}

		return $kept;
	}

	/**
	 * Group notes into threads by parent_id.
	 *
	 * @param array<int, array<string, mixed>> $comments Normalized notes.
	 * @return array<int, array<string, mixed>>
	 */
	public static function group_threads( array $comments ): array {
		$by_id = array();
		foreach ( $comments as $comment ) {
			$id = (int) ( $comment['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$comment['replies'] = array();
			$by_id[ $id ]       = $comment;
		}

		$roots = array();
		foreach ( $by_id as $id => $comment ) {
			$parent_id = (int) ( $comment['parent_id'] ?? 0 );
			if ( $parent_id > 0 && isset( $by_id[ $parent_id ] ) ) {
				$by_id[ $parent_id ]['replies'][] = $comment;
				continue;
			}
			$roots[] = $id;
		}

		$threads = array();
		foreach ( $roots as $root_id ) {
			$threads[] = $by_id[ $root_id ];
		}

		return $threads;
	}

	/**
	 * Collect unharvested Gutenberg note threads for a post.
	 *
	 * @param int $post_id Source post ID.
	 * @return array{threads: array<int, array<string, mixed>>, comment_ids: int[]}
	 */
	public static function collect( int $post_id ): array {
		$empty = array(
			'threads'     => array(),
			'comment_ids' => array(),
		);

		if ( $post_id <= 0 || ! function_exists( 'get_comments' ) ) {
			return $empty;
		}

		$raw = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'note',
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => 0,
			)
		);

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $empty;
		}

		$normalized = array();
		foreach ( $raw as $comment ) {
			$mapped = self::normalize_wp_comment( $comment );
			if ( null !== $mapped ) {
				$normalized[] = $mapped;
			}
		}

		$normalized  = self::drop_skipped_threads( $normalized );
		$unharvested = self::filter_unharvested( $normalized, self::get_harvested_ids( $post_id ) );
		$threads     = self::group_threads( $unharvested );
		$ids         = array();
		foreach ( $unharvested as $comment ) {
			$ids[] = (int) $comment['id'];
		}

		return array(
			'threads'     => $threads,
			'comment_ids' => $ids,
		);
	}

	/**
	 * Harvested comment IDs stored on the source post.
	 *
	 * @param int $post_id Source post ID.
	 * @return int[]
	 */
	public static function get_harvested_ids( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$stored = get_post_meta( $post_id, self::HARVESTED_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array();
		foreach ( $stored as $id ) {
			$ids[] = (int) $id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Mark comment IDs as harvested on the source post.
	 *
	 * @param int   $post_id     Source post ID.
	 * @param int[] $comment_ids Comment IDs.
	 * @return void
	 */
	public static function mark_harvested( int $post_id, array $comment_ids ): void {
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$merged = array_merge( self::get_harvested_ids( $post_id ), array_map( 'intval', $comment_ids ) );
		$merged = array_values( array_unique( array_filter( $merged ) ) );
		sort( $merged );
		update_post_meta( $post_id, self::HARVESTED_META_KEY, $merged );
	}

	/**
	 * Normalize a WP_Comment (or array) into the collector shape.
	 *
	 * @param mixed $comment WordPress comment.
	 * @return array<string, mixed>|null
	 */
	public static function normalize_wp_comment( mixed $comment ): ?array {
		if ( is_object( $comment ) ) {
			$id         = (int) ( $comment->comment_ID ?? 0 );
			$parent_id  = (int) ( $comment->comment_parent ?? 0 );
			$author     = (string) ( $comment->comment_author ?? '' );
			$content    = (string) ( $comment->comment_content ?? '' );
			$created_at = (string) ( $comment->comment_date_gmt ?? '' );
			$type       = (string) ( $comment->comment_type ?? '' );
		} elseif ( is_array( $comment ) ) {
			$id         = (int) ( $comment['comment_ID'] ?? $comment['id'] ?? 0 );
			$parent_id  = (int) ( $comment['comment_parent'] ?? $comment['parent_id'] ?? 0 );
			$author     = (string) ( $comment['comment_author'] ?? $comment['author'] ?? '' );
			$content    = (string) ( $comment['comment_content'] ?? $comment['content'] ?? '' );
			$created_at = (string) ( $comment['comment_date_gmt'] ?? $comment['created_at'] ?? '' );
			$type       = (string) ( $comment['comment_type'] ?? $comment['type'] ?? '' );
		} else {
			return null;
		}

		if ( $id <= 0 ) {
			return null;
		}

		$resolved = false;
		if ( function_exists( 'get_comment_meta' ) ) {
			$resolved = 'resolved' === get_comment_meta( $id, '_wp_note_status', true );
		}

		$skip_harvest = false;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters whether a Note is left out of the harvest, with its replies.
			 *
			 * @param bool  $skip_harvest Whether to skip. Default false.
			 * @param int   $id           Comment ID.
			 * @param mixed $comment      The WP_Comment or array being normalized.
			 */
			$skip_harvest = (bool) apply_filters( 'prc_nexus_self_improving_guidelines_skip_note', false, $id, $comment );
		}

		return array(
			'id'           => $id,
			'parent_id'    => $parent_id,
			'author'       => $author,
			'content'      => $content,
			'created_at'   => $created_at,
			'resolved'     => $resolved,
			'type'         => $type,
			'skip_harvest' => $skip_harvest,
		);
	}
}
