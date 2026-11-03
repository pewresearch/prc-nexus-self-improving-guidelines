<?php
/**
 * Summarize Gutenberg notes into proposed editorial memories.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and executes prc-nexus-self-improving-guidelines/summarize-notes.
 */
class Summarize_Notes_Ability {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static string $ability_name = 'prc-nexus-self-improving-guidelines/summarize-notes';

	/**
	 * Allowed block names for this ability (none; harvest is post-level).
	 *
	 * @var array<int, string>
	 */
	public static array $allowed_blocks = array();

	/**
	 * Register the summarize-notes ability.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Summarize editor notes into editorial memories', 'prc-nexus-self-improving-guidelines' ),
				'description'         => __( 'Turns Gutenberg editor notes into generalizable editorial rules for human review.', 'prc-nexus-self-improving-guidelines' ),
				'category'            => 'data-retrieval',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'             => array(
							'type'        => 'integer',
							'description' => 'Source post ID.',
						),
						'notes'               => array(
							'type'        => 'array',
							'description' => 'Optional pre-collected Gutenberg note threads.',
							'items'       => array( 'type' => 'object' ),
						),
						'existing_guidelines' => array(
							'type'        => 'string',
							'description' => 'Current guideline packet text so the model does not duplicate rules.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'error'    => array( 'type' => 'string' ),
						'memories' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'              => array( 'type' => 'string' ),
									'rule_text'          => array( 'type' => 'string' ),
									'scope'              => array( 'type' => 'string' ),
									'block_name'         => array( 'type' => 'string' ),
									'rationale'          => array( 'type' => 'string' ),
									'source_comment_ids' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
									'source_post_id'     => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => array(
					'annotations'    => array(
						'instructions' => 'Call after publish to propose editorial memories from Gutenberg notes. Discard one-off and logistics notes.',
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => true,
					),
					'show_in_rest'   => true,
					'allowed_blocks' => self::$allowed_blocks,
					'mcp'            => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * REST/MCP gate: editors may list the ability, but a specific post requires edit access.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public function permission_callback( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$post_id = absint( is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0 );
		if ( $post_id <= 0 ) {
			return true;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Run the summarizer against collected notes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{error: string, memories: array<int, array<string, mixed>>}
	 */
	public function execute( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return array(
				'error'    => 'Invalid post ID.',
				'memories' => array(),
			);
		}

		$threads = array();
		if ( isset( $input['notes'] ) && is_array( $input['notes'] ) && ! empty( $input['notes'] ) ) {
			$threads = $input['notes'];
		} else {
			$collected = Notes_Collector::collect( $post_id );
			$threads   = $collected['threads'];
		}

		if ( empty( $threads ) ) {
			return array(
				'error'    => '',
				'memories' => array(),
			);
		}

		$existing = isset( $input['existing_guidelines'] ) ? (string) $input['existing_guidelines'] : '';
		$prompt   = $this->build_prompt( $post_id, $threads, $existing );

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array(
				'error'    => 'WordPress AI client is not available.',
				'memories' => array(),
			);
		}

		try {
			$builder = wp_ai_client_prompt( $prompt );
			if ( is_wp_error( $builder ) ) {
				return array(
					'error'    => $builder->get_error_message(),
					'memories' => array(),
				);
			}

			// Do not set temperature. Current default models (Fable 5, GPT-5,
			// native Opus) reject temperature/top_p with HTTP 400.
			$builder = $builder
				->using_system_instruction( $this->get_system_instructions() )
				->as_json_response();

			if ( function_exists( '\WordPress\AI\get_preferred_models_for_text_generation' ) ) {
				$models = \WordPress\AI\get_preferred_models_for_text_generation();
				if ( is_array( $models ) && array() !== $models ) {
					$builder = $builder->using_model_preference( ...$models );
				}
			}

			if ( class_exists( RequestOptions::class ) ) {
				$request_options = new RequestOptions();
				$timeout         = 60;
				if ( function_exists( '\WordPress\AI\get_default_request_timeout' ) ) {
					$timeout = \WordPress\AI\get_default_request_timeout(
						Nexus_Self_Improving_Guidelines_AI_Feature::get_id(),
						60
					);
				}
				$request_options->setTimeout( $timeout );
				$builder = $builder->using_request_options( $request_options );
			}

			$response = $builder->generate_text();
			if ( is_wp_error( $response ) ) {
				return array(
					'error'    => $response->get_error_message(),
					'memories' => array(),
				);
			}

			$parsed = Proposed_Memory::from_json_response( (string) $response, $post_id );
			if ( ! $parsed['ok'] ) {
				return array(
					'error'    => $parsed['error'],
					'memories' => array(),
				);
			}

			return array(
				'error'    => '',
				'memories' => $parsed['memories'],
			);
		} catch ( \Exception $e ) {
			return array(
				'error'    => $e->getMessage(),
				'memories' => array(),
			);
		}
	}

	/**
	 * Build the user prompt from note threads and existing guidelines.
	 *
	 * @param int                              $post_id              Source post ID.
	 * @param array<int, array<string, mixed>> $threads              Note threads.
	 * @param string                           $existing_guidelines Existing guideline packet text.
	 * @return string
	 */
	private function build_prompt( int $post_id, array $threads, string $existing_guidelines ): string {
		$post  = get_post( $post_id );
		$title = $post ? (string) $post->post_title : '';
		$notes = wp_json_encode( $threads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $notes ) {
			$notes = '[]';
		}

		$prompt  = "Source post ID: {$post_id}\n";
		$prompt .= "Source post title: {$title}\n\n";
		$prompt .= "Gutenberg editor notes (JSON threads):\n{$notes}\n";

		if ( '' !== trim( $existing_guidelines ) ) {
			$prompt .= "\nExisting site guidelines (do not duplicate these rules):\n{$existing_guidelines}\n";
		}

		$prompt .= "\nReturn JSON: {\"memories\":[...]} with zero or more memories.";

		return $prompt;
	}

	/**
	 * System instructions for the summarizer.
	 *
	 * @return string
	 */
	private function get_system_instructions(): string {
		return <<<'INSTRUCTIONS'
You extract editorial memories from Gutenberg editor notes on a published post.

A memory is a reusable rule that would apply to a future post. Write each rule in the imperative mood. Do not include post-specific facts, URLs, person names, dates, or ticket numbers.

Discard:
- one-off corrections that only apply to this post
- logistics (deadlines, assignments, "please ping me")
- person-specific notes
- resolved chatter that does not state a lasting rule

For each memory return:
- title: short label
- rule_text: the rule itself
- scope: one of site, copy, images, additional, blocks
- block_name: required when scope is blocks (example: core/paragraph)
- rationale: why this is a lasting rule
- source_comment_ids: comment IDs that support the rule

If nothing generalizes, return {"memories":[]}.
Return JSON only.
INSTRUCTIONS;
	}
}
