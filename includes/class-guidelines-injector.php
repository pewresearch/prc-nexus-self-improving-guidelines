<?php
/**
 * Guidelines injector.
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
 * Appends approved rule text into published guideline-{scope} knowledge rows.
 */
class Guidelines_Injector {

	public const POST_TYPE = 'wp_knowledge';

	/**
	 * Guideline slug for a scope, or a per-block slug when scope is blocks.
	 *
	 * @param string $scope      Guideline scope.
	 * @param string $block_name Block name when scope is blocks.
	 * @return string
	 */
	public static function slug_for( string $scope, string $block_name = '' ): string {
		$scope = Proposed_Memory::sanitize_scope( $scope );
		if ( 'blocks' === $scope ) {
			$block = str_replace( '/', '_', $block_name );
			if ( function_exists( 'sanitize_title' ) ) {
				$block = sanitize_title( $block );
			}
			return 'guideline-block-' . $block;
		}

		return 'guideline-' . $scope;
	}

	/**
	 * Inject a pending memory into its guideline row.
	 *
	 * @param array<string, mixed> $memory Serialized memory.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function inject( array $memory ): array|WP_Error {
		$id = absint( $memory['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
		}

		$status = (string) ( $memory['status'] ?? '' );
		if ( ! Memory_Status::can_inject( $status ) ) {
			return new WP_Error(
				'prc_nexus_sig_invalid_transition',
				__( 'Only pending memories can be injected.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 409 )
			);
		}

		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new WP_Error(
				'prc_nexus_sig_missing_knowledge',
				__( 'The wp_knowledge post type is not registered.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 500 )
			);
		}

		$scope      = Proposed_Memory::sanitize_scope( (string) ( $memory['scope'] ?? 'additional' ) );
		$block_name = (string) ( $memory['block_name'] ?? '' );
		if ( 'blocks' === $scope && '' === $block_name ) {
			return new WP_Error(
				'prc_nexus_sig_missing_block',
				__( 'Block memories need a block name.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 400 )
			);
		}

		$rule_text = sanitize_textarea_field( (string) ( $memory['rule_text'] ?? '' ) );
		if ( '' === $rule_text ) {
			return new WP_Error(
				'prc_nexus_sig_invalid_memory',
				__( 'Rule text cannot be empty.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 400 )
			);
		}

		$guideline_id = self::get_or_create_guideline( $scope, $block_name );
		if ( is_wp_error( $guideline_id ) ) {
			return $guideline_id;
		}

		$guideline = get_post( $guideline_id );
		if ( ! $guideline instanceof WP_Post ) {
			return new WP_Error(
				'prc_nexus_sig_missing_guideline',
				__( 'Could not load the guideline row.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 500 )
			);
		}

		$existing = (string) $guideline->post_content;
		if ( '' === trim( $existing ) ) {
			$existing = self::legacy_meta_content( $scope, $block_name );
		}

		$max    = function_exists( 'wp_guideline_max_length' ) ? wp_guideline_max_length() : Guidelines_Appender::DEFAULT_MAX_LENGTH;
		$result = Guidelines_Appender::append( $existing, $rule_text, $max );
		if ( ! $result['ok'] ) {
			if ( 'overflow' === $result['reason'] ) {
				return new WP_Error(
					'prc_nexus_sig_overflow',
					__( 'This rule does not fit in the guideline (5000 character limit).', 'prc-nexus-self-improving-guidelines' ),
					array( 'status' => 409 )
				);
			}
			return new WP_Error(
				'prc_nexus_sig_invalid_memory',
				__( 'Could not append this rule.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 400 )
			);
		}

		if ( 'appended' === $result['reason'] ) {
			$updated = wp_update_post(
				array(
					'ID'           => $guideline_id,
					'post_content' => $result['content'],
					'post_status'  => 'publish',
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		return Memory_Store::transition( $id, Memory_Status::INJECTED, $guideline_id );
	}

	/**
	 * Get or create the published guideline row for a scope.
	 *
	 * @param string $scope      Guideline scope.
	 * @param string $block_name Block name when scope is blocks.
	 * @return int|WP_Error
	 */
	public static function get_or_create_guideline( string $scope, string $block_name = '' ): int|WP_Error {
		$slug  = self::slug_for( $scope, $block_name );
		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'name'                   => $slug,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! empty( $query->posts ) && $query->posts[0] instanceof WP_Post ) {
			$existing = $query->posts[0];
			if ( 'publish' !== $existing->post_status ) {
				wp_update_post(
					array(
						'ID'          => $existing->ID,
						'post_status' => 'publish',
					)
				);
			}
			return (int) $existing->ID;
		}

		$title = self::title_for( $scope, $block_name );
		$id    = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => self::legacy_meta_content( $scope, $block_name ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;
		self::assign_guideline_type( $id );

		return $id;
	}

	/**
	 * Copy the current meta-backed guideline text for a scope.
	 *
	 * New `wp_knowledge` rows start empty. Packet builders prefer any non-empty
	 * knowledge content over `wp_guideline` meta, so the first inject must copy
	 * that meta or existing rules drop out of AI packets.
	 *
	 * @param string $scope      Guideline scope.
	 * @param string $block_name Block name when scope is blocks.
	 * @return string
	 */
	public static function legacy_meta_content( string $scope, string $block_name = '' ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		if ( ! function_exists( 'PRC\Platform\AI\Utils\get_content_guidelines_post' ) ) {
			return '';
		}

		$guidelines_post = \PRC\Platform\AI\Utils\get_content_guidelines_post();
		if ( ! $guidelines_post ) {
			return '';
		}

		$scope  = Proposed_Memory::sanitize_scope( $scope );
		$suffix = 'blocks' === $scope
			? 'block_' . str_replace( '/', '_', $block_name )
			: $scope;

		$post_id = (int) $guidelines_post->ID;
		foreach ( array( '_guideline_' . $suffix, '_content_guideline_' . $suffix ) as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Force the knowledge type term to `guideline`.
	 *
	 * @param int $knowledge_id Knowledge post ID.
	 * @return void
	 */
	public static function assign_guideline_type( int $knowledge_id ): void {
		if ( $knowledge_id <= 0 || ! taxonomy_exists( 'wp_knowledge_type' ) ) {
			return;
		}

		if ( function_exists( 'wp_knowledge_get_or_create_type_term' ) ) {
			$term_id = wp_knowledge_get_or_create_type_term( 'guideline' );
			if ( null !== $term_id ) {
				wp_set_object_terms( $knowledge_id, $term_id, 'wp_knowledge_type' );
			}
		}
	}

	/**
	 * Human title for a guideline row.
	 *
	 * @param string $scope      Guideline scope.
	 * @param string $block_name Block name when scope is blocks.
	 * @return string
	 */
	public static function title_for( string $scope, string $block_name = '' ): string {
		if ( 'blocks' === $scope && '' !== $block_name ) {
			return $block_name;
		}

		if ( function_exists( 'wp_guideline_scopes' ) ) {
			$scopes = wp_guideline_scopes();
			if ( isset( $scopes[ $scope ]['title'] ) ) {
				return (string) $scopes[ $scope ]['title'];
			}
		}

		return ucfirst( $scope );
	}
}
