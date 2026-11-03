<?php
/**
 * Proposed memory parser.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

/**
 * Parses and validates ProposedMemory objects from the AI JSON boundary.
 */
class Proposed_Memory {

	/**
	 * Guideline scopes a proposed memory may target.
	 *
	 * @var string[]
	 */
	public const SCOPES = array( 'site', 'copy', 'images', 'additional', 'blocks' );

	/**
	 * Strip optional markdown fences from a model JSON payload.
	 *
	 * @param string $raw Model output.
	 * @return string
	 */
	public static function unwrap_json( string $raw ): string {
		$json = trim( $raw );
		if ( str_starts_with( $json, '```' ) ) {
			$json = (string) preg_replace( '/^```(?:json)?\s*/i', '', $json );
			$json = (string) preg_replace( '/\s*```$/', '', $json );
		}

		return trim( $json );
	}

	/**
	 * Parse an ability JSON response into proposed memories.
	 *
	 * @param string $raw            Model output.
	 * @param int    $source_post_id Source post ID.
	 * @return array{ok: bool, memories: array<int, array<string, mixed>>, error: string}
	 */
	public static function from_json_response( string $raw, int $source_post_id = 0 ): array {
		$decoded = json_decode( self::unwrap_json( $raw ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'       => false,
				'memories' => array(),
				'error'    => 'Response is not valid JSON.',
			);
		}

		$list = array();
		if ( isset( $decoded['memories'] ) ) {
			if ( ! is_array( $decoded['memories'] ) ) {
				return array(
					'ok'       => false,
					'memories' => array(),
					'error'    => 'memories must be an array.',
				);
			}
			$list = $decoded['memories'];
		} elseif ( isset( $decoded['rule_text'] ) ) {
			$list = array( $decoded );
		}

		$memories = array();
		foreach ( $list as $item ) {
			$parsed = self::parse_one( $item, $source_post_id );
			if ( null !== $parsed ) {
				$memories[] = $parsed;
			}
		}

		return array(
			'ok'       => true,
			'memories' => $memories,
			'error'    => '',
		);
	}

	/**
	 * Validate one proposed memory. Returns null when required fields are missing.
	 *
	 * @param mixed $raw            Raw item from the model.
	 * @param int   $source_post_id Source post ID.
	 * @return array<string, mixed>|null
	 */
	public static function parse_one( mixed $raw, int $source_post_id = 0 ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$rule_text = isset( $raw['rule_text'] ) ? trim( (string) $raw['rule_text'] ) : '';
		if ( '' === $rule_text ) {
			return null;
		}

		$scope = isset( $raw['scope'] ) ? strtolower( trim( (string) $raw['scope'] ) ) : '';
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return null;
		}

		$block_name = isset( $raw['block_name'] ) ? trim( (string) $raw['block_name'] ) : '';
		if ( 'blocks' === $scope && '' === $block_name ) {
			return null;
		}
		if ( 'blocks' !== $scope ) {
			$block_name = '';
		}

		$title = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
		if ( '' === $title ) {
			$title = self::title_from_rule( $rule_text );
		}

		$comment_ids = array();
		if ( isset( $raw['source_comment_ids'] ) && is_array( $raw['source_comment_ids'] ) ) {
			foreach ( $raw['source_comment_ids'] as $comment_id ) {
				$comment_ids[] = self::positive_int( $comment_id );
			}
		}
		$comment_ids = array_values( array_unique( array_filter( $comment_ids ) ) );
		sort( $comment_ids );

		$post_id = isset( $raw['source_post_id'] ) ? self::positive_int( $raw['source_post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			$post_id = $source_post_id;
		}

		return array(
			'title'              => $title,
			'rule_text'          => $rule_text,
			'scope'              => $scope,
			'block_name'         => $block_name,
			'rationale'          => isset( $raw['rationale'] ) ? trim( (string) $raw['rationale'] ) : '',
			'source_comment_ids' => $comment_ids,
			'source_post_id'     => $post_id,
		);
	}

	/**
	 * Build a short title from rule text.
	 *
	 * @param string $rule_text Rule text.
	 * @return string
	 */
	public static function title_from_rule( string $rule_text ): string {
		$first = strtok( $rule_text, "\n" );
		$line  = trim( false === $first ? $rule_text : $first );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $line, 0, 80, 'UTF-8' );
		}

		return substr( $line, 0, 80 );
	}

	/**
	 * Sanitize a scope string for storage.
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	public static function sanitize_scope( string $scope ): string {
		$scope = strtolower( trim( $scope ) );
		return in_array( $scope, self::SCOPES, true ) ? $scope : 'additional';
	}

	/**
	 * Coerce a value to a non-negative integer without WordPress helpers.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function positive_int( mixed $value ): int {
		return max( 0, (int) $value );
	}
}
