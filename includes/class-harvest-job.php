<?php
/**
 * Async harvest job.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harvests Gutenberg notes after async publish and published-post updates.
 */
class Harvest_Job {

	/**
	 * Register async harvest hooks.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'prc_platform_async_on_publish', $this, 'harvest', 10, 2 );
		$loader->add_action( 'prc_platform_async_on_update', $this, 'harvest', 10, 2 );
	}

	/**
	 * Resolve a post ID from the post-publish pipeline payload.
	 *
	 * The async hooks pass a stdClass from setup_extra_wp_post_object_fields(),
	 * not a WP_Post. Casting that object with absint() yields 0 or throws.
	 *
	 * @param mixed $post Pipeline post object, WP_Post, or numeric ID.
	 * @return int
	 */
	public static function resolve_post_id( mixed $post ): int {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			return max( 0, (int) $post->ID );
		}

		if ( is_array( $post ) && isset( $post['ID'] ) ) {
			return max( 0, (int) $post['ID'] );
		}

		if ( is_numeric( $post ) ) {
			return max( 0, (int) $post );
		}

		return 0;
	}

	/**
	 * Collect notes, summarize them, and store pending memories.
	 *
	 * @param mixed $post       Post object from the pipeline.
	 * @param mixed $has_blocks Unused; required by the pipeline hook signature.
	 * @return void
	 */
	public function harvest( $post, $has_blocks = false ): void {
		unset( $has_blocks );

		if ( ! Settings::is_harvest_enabled() ) {
			return;
		}

		$post_id = self::resolve_post_id( $post );
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! post_type_exists( Memory_Store::POST_TYPE ) ) {
			$this->log( 'Skipping harvest: wp_knowledge is not registered.' );
			return;
		}

		$collected = Notes_Collector::collect( $post_id );
		if ( empty( $collected['comment_ids'] ) ) {
			return;
		}

		$threads  = self::filter_reusable_threads( $collected['threads'] );
		$one_offs = self::one_off_comment_ids( $collected['threads'], $threads );
		if ( array() !== $one_offs ) {
			Notes_Collector::mark_harvested( $post_id, $one_offs );
		}
		if ( empty( $threads ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) && ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->log( 'Skipping harvest: WordPress AI client is not available.' );
			return;
		}

		if ( ! class_exists( __NAMESPACE__ . '\Summarize_Notes_Ability' ) ) {
			$ability_file = plugin_dir_path( __DIR__ ) . '/includes/ai/class-summarize-notes-ability.php';
			if ( file_exists( $ability_file ) ) {
				require_once $ability_file;
			}
		}

		if ( ! class_exists( __NAMESPACE__ . '\Summarize_Notes_Ability' ) ) {
			$this->log( 'Skipping harvest: summarize-notes ability is not loaded.' );
			return;
		}

		$ability = new Summarize_Notes_Ability();
		$result  = $ability->execute(
			array(
				'post_id'             => $post_id,
				'notes'               => $threads,
				'existing_guidelines' => self::guidelines_packet( $post_id ),
			)
		);

		if ( ! empty( $result['error'] ) ) {
			$this->log( 'Harvest failed for post ' . $post_id . ': ' . (string) $result['error'] );
			return;
		}

		$memories = isset( $result['memories'] ) && is_array( $result['memories'] )
			? $result['memories']
			: array();

		$created_ids  = array();
		$store_failed = false;
		foreach ( $memories as $proposed ) {
			if ( ! is_array( $proposed ) ) {
				continue;
			}
			$created = Memory_Store::create_pending( $proposed );
			if ( is_wp_error( $created ) ) {
				$this->log( 'Could not store memory: ' . $created->get_error_message() );
				$store_failed = true;
				continue;
			}
			$created_ids[] = (int) $created;
		}

		if ( $store_failed ) {
			foreach ( $created_ids as $created_id ) {
				if ( $created_id > 0 ) {
					wp_delete_post( $created_id, true );
				}
			}
			$this->log( 'Leaving notes unharvested for post ' . $post_id . ' after a store failure.' );
			return;
		}

		Notes_Collector::mark_harvested( $post_id, $collected['comment_ids'] );
	}

	/**
	 * Keep Gutenberg note threads that look like reusable house rules.
	 *
	 * TypeSafe down or a missing key returns every thread (today's path).
	 *
	 * @param array<int, array<string, mixed>> $threads Note threads.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_reusable_threads( array $threads ): array {
		if ( array() === $threads ) {
			return $threads;
		}

		if ( ! function_exists( 'PRC\\Platform\\AI\\Utils\\decision_engine_request' ) ) {
			return $threads;
		}

		$questions = array();
		foreach ( $threads as $index => $thread ) {
			$thread_id                         = isset( $thread['id'] ) ? (string) $thread['id'] : (string) $index;
			$questions[ 'reusable_' . $index ] = array(
				'type'         => 'noul',
				'instructions' => 'Is Gutenberg editor note ' . $thread_id . ' a reusable house rule that would apply to a future post? True means it is a lasting editorial rule. False means it is a one-off for this post, logistics, or chatter.',
				'criteria'     => array(
					'true'  => 'A reusable house rule.',
					'false' => 'A one-off correction, logistics note, or chatter.',
				),
			);
		}

		$result = \PRC\Platform\AI\Utils\decision_engine_request(
			array( 'threads' => $threads ),
			$questions
		);

		if ( is_wp_error( $result ) ) {
			return $threads;
		}

		$answers = isset( $result['answers'] ) && is_array( $result['answers'] )
			? $result['answers']
			: array();
		$kept    = array();

		foreach ( $threads as $index => $thread ) {
			$noul = \PRC\Platform\AI\Utils\decision_engine_answer_noul( $answers, 'reusable_' . $index );
			if ( null !== $noul && $noul < 0.5 ) {
				continue;
			}
			$kept[] = $thread;
		}

		return $kept;
	}

	/**
	 * Comment IDs from threads the reusable filter dropped.
	 *
	 * Includes nested replies so a one-off thread is not re-collected later.
	 *
	 * @param array<int, array<string, mixed>> $threads All collected threads.
	 * @param array<int, array<string, mixed>> $kept    Threads still going to summarize.
	 * @return int[]
	 */
	public static function one_off_comment_ids( array $threads, array $kept ): array {
		$kept_ids = array();
		foreach ( $kept as $thread ) {
			if ( ! is_array( $thread ) ) {
				continue;
			}
			foreach ( self::thread_comment_ids( $thread ) as $id ) {
				$kept_ids[ $id ] = true;
			}
		}

		$dropped = array();
		foreach ( $threads as $thread ) {
			if ( ! is_array( $thread ) ) {
				continue;
			}
			foreach ( self::thread_comment_ids( $thread ) as $id ) {
				if ( isset( $kept_ids[ $id ] ) ) {
					continue;
				}
				$dropped[] = $id;
			}
		}

		return array_values( array_unique( $dropped ) );
	}

	/**
	 * Comment IDs in one Gutenberg note thread, including replies.
	 *
	 * @param array<string, mixed> $thread Thread root.
	 * @return int[]
	 */
	public static function thread_comment_ids( array $thread ): array {
		$ids = array();
		$id  = (int) ( $thread['id'] ?? 0 );
		if ( $id > 0 ) {
			$ids[] = $id;
		}

		foreach ( (array) ( $thread['replies'] ?? array() ) as $reply ) {
			if ( ! is_array( $reply ) ) {
				continue;
			}
			$ids = array_merge( $ids, self::thread_comment_ids( $reply ) );
		}

		return $ids;
	}

	/**
	 * Existing guideline packet text so the model does not duplicate rules.
	 *
	 * @param int $post_id Source post ID.
	 * @return string
	 */
	private static function guidelines_packet( int $post_id ): string {
		if ( ! function_exists( 'PRC\Platform\AI\Utils\get_content_guidelines_for_post' ) ) {
			return '';
		}

		$packet = \PRC\Platform\AI\Utils\get_content_guidelines_for_post(
			$post_id,
			array( 'task' => 'writing' )
		);

		return isset( $packet['packet_text'] ) ? (string) $packet['packet_text'] : '';
	}

	/**
	 * Write a skip/failure message to the error log.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[prc-nexus-self-improving-guidelines] ' . $message );
	}
}
