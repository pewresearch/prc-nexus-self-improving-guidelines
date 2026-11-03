<?php
/**
 * Nexus Self Improving Guidelines AI feature.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the summarize-notes ability with WordPress AI.
 */
class Nexus_Self_Improving_Guidelines_AI_Feature extends Abstract_Feature {

	/**
	 * Feature identifier.
	 *
	 * @return string
	 */
	public static function get_id(): string {
		return 'prc-nexus-self-improving-guidelines-ai';
	}

	/**
	 * Feature metadata for the WP AI settings screen.
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Editorial Memories', 'prc-nexus-self-improving-guidelines' ),
			'description' => __( 'Summarizes Gutenberg editor notes into proposed editorial guidelines.', 'prc-nexus-self-improving-guidelines' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * Register the summarize-notes ability.
	 *
	 * @return void
	 */
	public function register(): void {
		$ability = new Summarize_Notes_Ability();
		add_action( 'wp_abilities_api_init', array( $ability, 'register_ability' ) );
	}
}
