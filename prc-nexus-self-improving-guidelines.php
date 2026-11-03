<?php
/**
 * PRC Nexus Self Improving Guidelines
 *
 * @package           PRC_NEXUS_SELF_IMPROVING_GUIDELINES
 * @author            Seth Rubenstein
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Nexus Self Improving Guidelines
 * Plugin URI:        https://github.com/pewresearch/prc-platform
 * Description:       Harvest Gutenberg editor notes after publish and inject approved memories into Gutenberg Guidelines.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-nexus-self-improving-guidelines
 * Requires Plugins:  prc-shared
 */

declare(strict_types=1);

namespace PRC\Platform\Nexus_Self_Improving_Guidelines;

if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRC_NEXUS_SELF_IMPROVING_GUIDELINES_FILE', __FILE__ );
define( 'PRC_NEXUS_SELF_IMPROVING_GUIDELINES_DIR', __DIR__ );
define( 'PRC_NEXUS_SELF_IMPROVING_GUIDELINES_VERSION', '1.0.0' );

require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Begins execution of the plugin.
 *
 * @return void
 */
function run_prc_nexus_self_improving_guidelines(): void {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_nexus_self_improving_guidelines();
