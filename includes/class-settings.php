<?php
/**
 * Settings page and REST routes.
 *
 * @package PRC\Platform\Nexus_Self_Improving_Guidelines
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

use PRC\Platform\Settings_Page_Boot;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Editorial Memories and REST API.
 *
 * Admin page: Settings → Editorial Memories
 * REST:       /prc-nexus-self-improving-guidelines/v1/...
 * Option key: prc_nexus_self_improving_guidelines_settings
 */
class Settings {

	public const OPTION_KEY      = 'prc_nexus_self_improving_guidelines_settings';
	public const REST_NAMESPACE  = 'prc-nexus-self-improving-guidelines/v1';
	public const ADMIN_PAGE_SLUG = 'prc-nexus-self-improving-guidelines-settings';
	public const CONTAINER_ID    = 'prc-nexus-self-improving-guidelines-settings-admin';

	/**
	 * Default harvest settings.
	 *
	 * @var array{enabled: bool}
	 */
	private static array $defaults = array(
		'enabled' => true,
	);

	/**
	 * Register the settings page and REST routes.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_admin_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Merged harvest settings.
	 *
	 * @return array{enabled: bool}
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'enabled' => array_key_exists( 'enabled', $stored )
				? (bool) $stored['enabled']
				: self::$defaults['enabled'],
		);
	}

	/**
	 * Whether harvest should run on async publish/update.
	 *
	 * @return bool
	 */
	public static function is_harvest_enabled(): bool {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Register the Settings submenu page.
	 *
	 * @hook admin_menu
	 * @return void
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Editorial Memories', 'prc-nexus-self-improving-guidelines' ),
			__( 'Editorial Memories', 'prc-nexus-self-improving-guidelines' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Print the Boot layout mount point.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		Settings_Page_Boot::render( self::CONTAINER_ID );
	}

	/**
	 * Enqueue the settings app on this plugin's page.
	 *
	 * @hook admin_enqueue_scripts
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = plugin_dir_path( PRC_NEXUS_SELF_IMPROVING_GUIDELINES_FILE ) . 'build/settings/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-nexus-self-improving-guidelines-settings';

		Settings_Page_Boot::ensure_optional_script_handles();

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/settings/index.js', PRC_NEXUS_SELF_IMPROVING_GUIDELINES_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_path = plugin_dir_path( PRC_NEXUS_SELF_IMPROVING_GUIDELINES_FILE ) . 'build/settings/style-index.css';
		if ( file_exists( $style_path ) ) {
			$style_deps = array( 'wp-components' );
			if ( in_array( 'prc-components', $asset['dependencies'], true ) ) {
				$style_deps[] = 'prc-components';
			}

			wp_enqueue_style(
				$handle,
				plugins_url( 'build/settings/style-index.css', PRC_NEXUS_SELF_IMPROVING_GUIDELINES_FILE ),
				$style_deps,
				$asset['version']
			);
		}

		Settings_Page_Boot::enqueue(
			$handle,
			(string) $asset['version'],
			self::CONTAINER_ID
		);
	}

	/**
	 * Register settings and memory REST routes.
	 *
	 * @hook rest_api_init
	 * @return void
	 */
	public function register_routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings_endpoint' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings_endpoint' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memories/inject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_inject_endpoint' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memories/(?P<id>\d+)/inject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inject_endpoint' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memories/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject_endpoint' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memories/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_memory_endpoint' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_memories_endpoint' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_memory_endpoint' ),
					'permission_callback' => $can_manage,
				),
			)
		);
	}

	/**
	 * GET /settings
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings_endpoint(): WP_REST_Response {
		return rest_ensure_response( array( 'settings' => self::get_settings() ) );
	}

	/**
	 * POST /settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings_endpoint( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		$sanitized = array(
			'enabled' => ! empty( $body['enabled'] ),
		);
		update_option( self::OPTION_KEY, $sanitized );

		return rest_ensure_response( array( 'settings' => self::get_settings() ) );
	}

	/**
	 * GET /memories
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_memories_endpoint( WP_REST_Request $request ): WP_REST_Response {
		$status = Memory_Status::sanitize( (string) $request->get_param( 'status' ) );
		if ( '' === (string) $request->get_param( 'status' ) ) {
			$status = Memory_Status::PENDING;
		}

		return rest_ensure_response(
			array(
				'memories' => Memory_Store::list( $status ),
			)
		);
	}

	/**
	 * POST /memories
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_memory_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'prc_nexus_sig_invalid_payload', 'Invalid payload.', array( 'status' => 400 ) );
		}

		$parsed = Proposed_Memory::parse_one( $body, absint( $body['source_post_id'] ?? 0 ) );
		if ( null === $parsed ) {
			return new WP_Error(
				'prc_nexus_sig_invalid_memory',
				__( 'A memory needs rule_text and a valid scope.', 'prc-nexus-self-improving-guidelines' ),
				array( 'status' => 400 )
			);
		}

		$created = Memory_Store::create_pending( $parsed );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$memory = Memory_Store::get( (int) $created );
		return rest_ensure_response( array( 'memory' => $memory ) );
	}

	/**
	 * POST /memories/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_memory_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request['id'] );
		$body   = $request->get_json_params();
		$fields = is_array( $body ) ? $body : array();
		$result = Memory_Store::update( $id, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'memory' => $result ) );
	}

	/**
	 * POST /memories/{id}/inject
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function inject_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond_inject( absint( $request['id'] ) );
	}

	/**
	 * POST /memories/{id}/reject
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Memory_Store::transition( absint( $request['id'] ), Memory_Status::REJECTED );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'memory' => $result ) );
	}

	/**
	 * POST /memories/inject
	 *
	 * @param WP_REST_Request $request Request with ids.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_inject_endpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		$ids  = isset( $body['ids'] ) && is_array( $body['ids'] ) ? $body['ids'] : array();
		$ok   = array();
		$fail = array();

		foreach ( $ids as $id ) {
			$result = $this->inject_one( absint( $id ) );
			if ( is_wp_error( $result ) ) {
				$fail[] = array(
					'id'      => absint( $id ),
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$ok[] = $result;
		}

		return rest_ensure_response(
			array(
				'memories' => $ok,
				'errors'   => $fail,
			)
		);
	}

	/**
	 * Inject one memory and wrap the result as a REST response.
	 *
	 * @param int $id Memory knowledge post ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond_inject( int $id ): WP_REST_Response|WP_Error {
		$result = $this->inject_one( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'memory'       => $result,
				'guideline_id' => (int) ( $result['injected_guideline_id'] ?? 0 ),
			)
		);
	}

	/**
	 * Append one pending memory into its guideline scope.
	 *
	 * @param int $id Memory knowledge post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private function inject_one( int $id ): array|WP_Error {
		$memory = Memory_Store::get( $id );
		if ( null === $memory ) {
			return new WP_Error( 'prc_nexus_sig_not_found', __( 'Memory not found.', 'prc-nexus-self-improving-guidelines' ), array( 'status' => 404 ) );
		}

		return Guidelines_Injector::inject( $memory );
	}
}
