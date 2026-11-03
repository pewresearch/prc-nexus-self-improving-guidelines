<?php
/**
 * Plugin loader.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

/**
 * Collects actions and filters, then registers them with WordPress.
 */
class Loader {

	/**
	 * Actions registered with WordPress.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $actions;

	/**
	 * Filters registered with WordPress.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $filters;

	/**
	 * Construct the loader.
	 */
	public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	/**
	 * Add a WordPress action.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Instance that owns the callback.
	 * @param string $callback      Method name on $component.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Accepted argument count.
	 * @return void
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ): void {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a WordPress filter.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Instance that owns the callback.
	 * @param string $callback      Method name on $component.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Accepted argument count.
	 * @return void
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ): void {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Store a hook definition.
	 *
	 * @param array<int, array<string, mixed>> $hooks         Existing collection.
	 * @param string                           $hook          Hook name.
	 * @param object                           $component     Instance that owns the callback.
	 * @param string                           $callback      Method name.
	 * @param int                              $priority      Priority.
	 * @param int                              $accepted_args Accepted argument count.
	 * @return array<int, array<string, mixed>>
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ): array {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return $hooks;
	}

	/**
	 * Register collected hooks with WordPress.
	 *
	 * @return void
	 */
	public function run(): void {
		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}

		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}
}
