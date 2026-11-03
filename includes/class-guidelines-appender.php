<?php
/**
 * Guideline text appender.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

/**
 * Appends a rule to a guideline row without duplicating or overflowing.
 */
class Guidelines_Appender {

	public const DEFAULT_MAX_LENGTH = 5000;

	/**
	 * Character length using UTF-8 when mbstring is available.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	public static function char_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	/**
	 * Whether $existing already contains $rule_text (case-insensitive, trimmed).
	 *
	 * @param string $existing  Current guideline content.
	 * @param string $rule_text Candidate rule.
	 * @return bool
	 */
	public static function already_contains( string $existing, string $rule_text ): bool {
		$needle = strtolower( trim( $rule_text ) );
		if ( '' === $needle ) {
			return true;
		}

		return str_contains( strtolower( $existing ), $needle );
	}

	/**
	 * Append $rule_text to $existing when it is new and fits under $max_length.
	 *
	 * @param string $existing   Current guideline content.
	 * @param string $rule_text  Candidate rule.
	 * @param int    $max_length Character cap.
	 * @return array{ok: bool, reason: string, content: string}
	 */
	public static function append( string $existing, string $rule_text, int $max_length = self::DEFAULT_MAX_LENGTH ): array {
		$rule     = trim( $rule_text );
		$existing = rtrim( $existing );

		if ( '' === $rule ) {
			return array(
				'ok'      => false,
				'reason'  => 'empty',
				'content' => $existing,
			);
		}

		if ( self::already_contains( $existing, $rule ) ) {
			return array(
				'ok'      => true,
				'reason'  => 'duplicate',
				'content' => $existing,
			);
		}

		$separator = '' === $existing ? '' : "\n\n";
		$next      = $existing . $separator . $rule;

		if ( self::char_length( $next ) > $max_length ) {
			return array(
				'ok'      => false,
				'reason'  => 'overflow',
				'content' => $existing,
			);
		}

		return array(
			'ok'      => true,
			'reason'  => 'appended',
			'content' => $next,
		);
	}
}
