<?php
/**
 * Editorial memory status machine.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

/**
 * Status values and allowed transitions for EditorialMemory records.
 *
 * Transitions: pending → rejected or pending → injected. Injected and
 * rejected are terminal.
 */
class Memory_Status {

	public const PENDING  = 'pending';
	public const REJECTED = 'rejected';
	public const INJECTED = 'injected';

	/**
	 * Allowed status values.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::REJECTED, self::INJECTED );
	}

	/**
	 * Whether $from may move to $to.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( self::PENDING !== $from ) {
			return false;
		}

		return in_array( $to, array( self::REJECTED, self::INJECTED ), true );
	}

	/**
	 * Whether a memory in $status may be injected.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function can_inject( string $status ): bool {
		return self::can_transition( $status, self::INJECTED );
	}

	/**
	 * Whether a memory in $status may be rejected.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function can_reject( string $status ): bool {
		return self::can_transition( $status, self::REJECTED );
	}

	/**
	 * Normalize an incoming status string.
	 *
	 * @param string $status Status to sanitize.
	 * @return string
	 */
	public static function sanitize( string $status ): string {
		return in_array( $status, self::all(), true ) ? $status : self::PENDING;
	}
}
