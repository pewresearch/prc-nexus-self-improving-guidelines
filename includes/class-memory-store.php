<?php
/**
 * Knowledge memory store.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and lists EditorialMemory rows as private wp_knowledge type=memory.
 */
class Memory_Store {

	public const POST_TYPE            = 'wp_knowledge';
	public const TYPE_TAXONOMY        = 'wp_knowledge_type';
	public const TYPE_TERM            = 'memory';
	public const META_STATUS          = '_prc_nexus_sig_status';
	public const META_SCOPE           = '_prc_nexus_sig_scope';
	public const META_BLOCK_NAME      = '_prc_nexus_sig_block_name';
	public const META_SOURCE_POST     = '_prc_nexus_sig_source_post_id';
	public const META_SOURCE_COMMENTS = '_prc_nexus_sig_source_comment_ids';
	public const META_INJECTED_ID     = '_prc_nexus_sig_injected_guideline_id';
	public const META_HARVESTED_AT    = '_prc_nexus_sig_harvested_at';

	/**
	 * Register harvest meta on init.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_meta', 20 );
	}

	/**
	 * Register memory meta on wp_knowledge.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$keys = array(
			self::META_STATUS       => array(
				'type'    => 'string',
				'default' => Memory_Status::PENDING,
			),
			self::META_SCOPE        => array(
				'type'    => 'string',
				'default' => 'additional',
			),
			self::META_BLOCK_NAME   => array(
				'type'    => 'string',
				'default' => '',
			),
			self::META_SOURCE_POST  => array(
				'type'    => 'integer',
				'default' => 0,
			),
			self::META_INJECTED_ID  => array(
				'type'    => 'integer',
				'default' => 0,
			),
			self::META_HARVESTED_AT => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		foreach ( $keys as $meta_key => $args ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => $args['type'],
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => static fn(): bool => current_user_can( 'manage_options' ),
					'sanitize_callback' => 'integer' === $args['type']
						? 'absint'
						: 'sanitize_text_field',
					'default'           => $args['default'],
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_SOURCE_COMMENTS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'auth_callback'     => static fn(): bool => current_user_can( 'manage_options' ),
				'sanitize_callback' => static function ( $value ): array {
					if ( ! is_array( $value ) ) {
						return array();
					}
					$ids = array_map( 'absint', $value );
					return array_values( array_unique( array_filter( $ids ) ) );
				},
				'default'           => array(),
			)
		);
	}

	/**
	 * Persist a pending memory. Returns 0 when wp_knowledge is missing.
	 *
	 * @param array<string, mixed> $proposed Parsed ProposedMemory.
	 * @return int|WP_Error
	 */
	public static function create_pending( array $proposed ): int|WP_Error {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new WP_Error(
				'prc_nexus_sig_missing_knowledge',
				__( 'The wp_knowledge post type is not registered.', 'prc-nexus-self-improving-guidelines' )
			);
		}

		$title       = sanitize_text_field( (string) ( $proposed['title'] ?? '' ) );
		$rule_text   = sanitize_textarea_field( (string) ( $proposed['rule_text'] ?? '' ) );
		$rationale   = sanitize_textarea_field( (string) ( $proposed['rationale'] ?? '' ) );
		$scope       = Proposed_Memory::sanitize_scope( (string) ( $proposed['scope'] ?? 'additional' ) );
		$block_name  = 'blocks' === $scope
			? sanitize_text_field( (string) ( $proposed['block_name'] ?? '' ) )
			: '';
		$post_id     = absint( $proposed['source_post_id'] ?? 0 );
		$comment_ids = array();
		if ( isset( $proposed['source_comment_ids'] ) && is_array( $proposed['source_comment_ids'] ) ) {
			foreach ( $proposed['source_comment_ids'] as $comment_id ) {
				$comment_ids[] = absint( $comment_id );
			}
		}
		$comment_ids = array_values( array_unique( array_filter( $comment_ids ) ) );
		sort( $comment_ids );

		if ( '' === $title || '' === $rule_text ) {
			return new WP_Error(
				'prc_nexus_sig_invalid_memory',
				__( 'A memory needs a title and rule text.', 'prc-nexus-self-improving-guidelines' )
			);
		}

		$knowledge_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => $title,
				'post_content' => $rule_text,
				'post_excerpt' => $rationale,
			),
			true
		);

		if ( is_wp_error( $knowledge_id ) ) {
			return $knowledge_id;
		}

		$knowledge_id = (int) $knowledge_id;
		self::assign_memory_type( $knowledge_id );
		update_post_meta( $knowledge_id, self::META_STATUS, Memory_Status::PENDING );
		update_post_meta( $knowledge_id, self::META_SCOPE, $scope );
		update_post_meta( $knowledge_id, self::META_BLOCK_NAME, $block_name );
		update_post_meta( $knowledge_id, self::META_SOURCE_POST, $post_id );
		update_post_meta( $knowledge_id, self::META_SOURCE_COMMENTS, $comment_ids );
		update_post_meta( $knowledge_id, self::META_HARVESTED_AT, gmdate( 'c' ) );

		return $knowledge_id;
	}

	/**
	 * Force the knowledge type term to `memory`.
	 *
	 * @param int $knowledge_id Knowledge post ID.
	 * @return void
	 */
	public static function assign_memory_type( int $knowledge_id ): void {
		if ( $knowledge_id <= 0 || ! taxonomy_exists( self::TYPE_TAXONOMY ) ) {
			return;
		}

		if ( function_exists( 'wp_knowledge_get_or_create_type_term' ) ) {
			$term_id = wp_knowledge_get_or_create_type_term( self::TYPE_TERM );
			if ( null !== $term_id ) {
				wp_set_object_terms( $knowledge_id, $term_id, self::TYPE_TAXONOMY );
				return;
			}
		}

		wp_set_object_terms( $knowledge_id, self::TYPE_TERM, self::TYPE_TAXONOMY );
	}

	/**
	 * Load one memory by knowledge ID.
	 *
	 * @param int $knowledge_id Knowledge post ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $knowledge_id ): ?array {
		$post = get_post( $knowledge_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return self::to_array( $post );
	}

	/**
	 * List memories by status.
	 *
	 * @param string $status Status filter.
	 * @param int    $limit  Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( string $status = Memory_Status::PENDING, int $limit = 100 ): array {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return array();
		}

		$status = Memory_Status::sanitize( $status );
		$query  = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'private', 'draft', 'publish' ),
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Status is stored in post meta by design.
				'meta_query'             => array(
					array(
						'key'     => self::META_STATUS,
						'value'   => $status,
						'compare' => '=',
					),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = self::to_array( $post );
			}
		}

		return $items;
	}

	/**
	 * Patch mutable fields on a pending memory.
	 *
	 * @param int                  $knowledge_id Knowledge post ID.
	 * @param array<string, mixed> $fields       Fields to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $knowledge_id, array $fields ): array|WP_Error {
		$memory = self::get( $knowledge_id );
		if ( null === $memory ) {
			return new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
		}

		if ( Memory_Status::PENDING !== $memory['status'] ) {
			return new WP_Error( 'prc_nexus_sig_not_pending', __( 'Only pending memories can be edited.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 409 ) );
		}

		$update = array( 'ID' => $knowledge_id );
		if ( isset( $fields['title'] ) ) {
			$title = sanitize_text_field( (string) $fields['title'] );
			if ( '' !== $title ) {
				$update['post_title'] = $title;
			}
		}
		if ( isset( $fields['rule_text'] ) ) {
			$rule_text = sanitize_textarea_field( (string) $fields['rule_text'] );
			if ( '' === $rule_text ) {
				return new WP_Error( 'prc_nexus_sig_invalid_memory', __( 'Rule text cannot be empty.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 400 ) );
			}
			$update['post_content'] = $rule_text;
		}
		if ( isset( $fields['rationale'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $fields['rationale'] );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $fields['scope'] ) ) {
			$scope = Proposed_Memory::sanitize_scope( (string) $fields['scope'] );
			update_post_meta( $knowledge_id, self::META_SCOPE, $scope );
			if ( 'blocks' !== $scope ) {
				update_post_meta( $knowledge_id, self::META_BLOCK_NAME, '' );
			}
		}

		if ( isset( $fields['block_name'] ) ) {
			$scope = (string) get_post_meta( $knowledge_id, self::META_SCOPE, true );
			if ( 'blocks' === $scope ) {
				update_post_meta(
					$knowledge_id,
					self::META_BLOCK_NAME,
					sanitize_text_field( (string) $fields['block_name'] )
				);
			}
		}

		$fresh = self::get( $knowledge_id );
		return $fresh ?? new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
	}

	/**
	 * Mark a pending memory injected or rejected.
	 *
	 * @param int    $knowledge_id           Knowledge post ID.
	 * @param string $to                     Target status.
	 * @param int    $injected_guideline_id  Guideline row ID when injecting.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function transition( int $knowledge_id, string $to, int $injected_guideline_id = 0 ): array|WP_Error {
		$memory = self::get( $knowledge_id );
		if ( null === $memory ) {
			return new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
		}

		if ( ! Memory_Status::can_transition( $memory['status'], $to ) ) {
			return new WP_Error(
				'prc_nexus_sig_invalid_transition',
				__( 'This memory can no longer change status.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 409 )
			);
		}

		update_post_meta( $knowledge_id, self::META_STATUS, $to );
		if ( Memory_Status::INJECTED === $to && $injected_guideline_id > 0 ) {
			update_post_meta( $knowledge_id, self::META_INJECTED_ID, $injected_guideline_id );
		}

		$fresh = self::get( $knowledge_id );
		return $fresh ?? new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
	}

	/**
	 * Serialize a knowledge post into an EditorialMemory array.
	 *
	 * @param WP_Post $post Knowledge post.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Post $post ): array {
		$source_post_id = (int) get_post_meta( $post->ID, self::META_SOURCE_POST, true );
		$comment_ids    = get_post_meta( $post->ID, self::META_SOURCE_COMMENTS, true );
		if ( ! is_array( $comment_ids ) ) {
			$comment_ids = array();
		}

		$source_title = '';
		$source_url   = '';
		if ( $source_post_id > 0 ) {
			$source_title = (string) get_the_title( $source_post_id );
			$source_url   = (string) get_edit_post_link( $source_post_id, 'raw' );
		}

		return array(
			'id'                    => (int) $post->ID,
			'title'                 => (string) $post->post_title,
			'rule_text'             => (string) $post->post_content,
			'rationale'             => (string) $post->post_excerpt,
			'scope'                 => Proposed_Memory::sanitize_scope( (string) get_post_meta( $post->ID, self::META_SCOPE, true ) ),
			'block_name'            => (string) get_post_meta( $post->ID, self::META_BLOCK_NAME, true ),
			'status'                => Memory_Status::sanitize( (string) get_post_meta( $post->ID, self::META_STATUS, true ) ),
			'source_post_id'        => $source_post_id,
			'source_post_title'     => $source_title,
			'source_post_url'       => $source_url,
			'source_comment_ids'    => array_values( array_map( 'intval', $comment_ids ) ),
			'injected_guideline_id' => (int) get_post_meta( $post->ID, self::META_INJECTED_ID, true ),
			'harvested_at'          => (string) get_post_meta( $post->ID, self::META_HARVESTED_AT, true ),
		);
	}
}
