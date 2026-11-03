<?php
/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin classes and registers hooks.
 */
class Bootstrap {
	/**
	 * Hook loader.
	 *
	 * @var Loader
	 */
	protected Loader $loader;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	protected string $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected string $version;

	/**
	 * Construct the plugin bootstrap.
	 */
	public function __construct() {
		$this->version     = PRC_NEXUS_SELF_IMPROVING_GUIDELINES_VERSION;
		$this->plugin_name = 'prc-nexus-self-improving-guidelines';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Require plugin files.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-memory-status.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-proposed-memory.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-guidelines-appender.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-notes-collector.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-memory-store.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-guidelines-injector.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-harvest-job.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-settings.php';

		$this->loader = new Loader();
	}

	/**
	 * Instantiate feature classes.
	 *
	 * @return void
	 */
	private function init_dependencies(): void {
		new Memory_Store( $this->get_loader() );
		new Harvest_Job( $this->get_loader() );
		new Settings( $this->get_loader() );
		add_action( 'plugins_loaded', array( $this, 'register_wp_ai_features' ), 11 );
	}

	/**
	 * Load AI classes after WP AI autoload and register the feature.
	 *
	 * @return void
	 */
	public function register_wp_ai_features(): void {
		if ( ! class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' ) ) {
			return;
		}

		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-summarize-notes-ability.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-nexus-self-improving-guidelines-ai-feature.php';

		add_action(
			'wpai_register_features',
			static function ( $registry ): void {
				$registry->register_feature( new Nexus_Self_Improving_Guidelines_AI_Feature() );
			}
		);
	}

	/**
	 * Register hooks with WordPress.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->loader->run();
	}

	/**
	 * Plugin slug.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	/**
	 * Hook loader.
	 *
	 * @return Loader
	 */
	public function get_loader(): Loader {
		return $this->loader;
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}
}
