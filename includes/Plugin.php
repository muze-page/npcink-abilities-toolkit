<?php
/**
 * Plugin composition root.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit;

use Npcink_Abilities_Toolkit\Admin\Test_Page;
use Npcink_Abilities_Toolkit\Integration\Npcink_Catalog_Bridge;
use Npcink_Abilities_Toolkit\Packages\Core_Comment_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Destructive_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Read_Package;
use Npcink_Abilities_Toolkit\Packages\Core_Write_Package;
use Npcink_Abilities_Toolkit\Registry\Ability_Registrar;
use Npcink_Abilities_Toolkit\Registry\Annotation_Normalizer;
use Npcink_Abilities_Toolkit\Registry\Category_Registrar;
use Npcink_Abilities_Toolkit\Registry\Contract_Normalizer;
use Npcink_Abilities_Toolkit\Registry\Schema_Normalizer;
use Npcink_Abilities_Toolkit\Rest\Contract_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the public API to WordPress hooks.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Category registrar.
	 *
	 * @var Category_Registrar
	 */
	private $categories;

	/**
	 * Ability registrar.
	 *
	 * @var Ability_Registrar
	 */
	private $abilities;

	/**
	 * Npcink catalog bridge.
	 *
	 * @var Npcink_Catalog_Bridge
	 */
	private $catalog_bridge;

	/**
	 * Core WordPress read package.
	 *
	 * @var Core_Read_Package|null
	 */
	private $core_read_package;

	/**
	 * Core WordPress write package.
	 *
	 * @var Core_Write_Package|null
	 */
	private $core_write_package;

	/**
	 * Core WordPress destructive package.
	 *
	 * @var Core_Destructive_Package|null
	 */
	private $core_destructive_package;

	/**
	 * Core WordPress comment helper package.
	 *
	 * @var Core_Comment_Package|null
	 */
	private $core_comment_package;

	/**
	 * Admin page.
	 *
	 * @var Test_Page|null
	 */
	private $test_page;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$schema_normalizer   = new Schema_Normalizer();
		$contract_normalizer = new Contract_Normalizer( $schema_normalizer, new Annotation_Normalizer() );

		$this->categories               = new Category_Registrar();
		$this->abilities                = new Ability_Registrar( $this->categories, $contract_normalizer );
		$this->catalog_bridge           = new Npcink_Catalog_Bridge( $this->abilities );
		$this->core_read_package        = null;
		$this->core_write_package       = null;
		$this->core_destructive_package = null;
		$this->core_comment_package     = null;
		$this->test_page                = null;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		$this->load_textdomain();
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( new Contract_Controller(), 'register_routes' ) );
		}
		$this->categories->boot();
		$this->abilities->boot();
		if ( $this->is_package_enabled( 'core_read' ) ) {
			$this->core_read_package()->boot();
		}
		if ( $this->is_package_enabled( 'core_write' ) ) {
			$this->core_write_package()->boot();
			$this->schedule_media_backup_cleanup();
		} else {
			$this->clear_media_backup_cleanup_schedule();
		}
		if ( $this->is_package_enabled( 'core_destructive' ) ) {
			$this->core_destructive_package()->boot();
		}
		if ( $this->is_package_enabled( 'core_comment' ) ) {
			$this->core_comment_package()->boot();
		}
		if ( $this->is_package_enabled( 'npcink_catalog_bridge' ) ) {
			$this->catalog_bridge->boot();
		}
		if ( $this->is_package_enabled( 'admin_test_page' ) ) {
			$this->test_page()->boot();
			if ( function_exists( 'add_filter' ) && function_exists( 'plugin_basename' ) && defined( 'NPCINK_ABILITIES_TOOLKIT_FILE' ) ) {
				add_filter( 'plugin_action_links_' . plugin_basename( (string) constant( 'NPCINK_ABILITIES_TOOLKIT_FILE' ) ), array( $this, 'filter_plugin_action_links' ) );
			}
		}
		if ( $this->is_package_enabled( 'read_cache_hooks' ) ) {
			$this->register_cache_invalidation_hooks();
		}
	}

	private function schedule_media_backup_cleanup() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$hook = 'npcink_abilities_toolkit_cleanup_media_backups';
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ), 'daily', $hook );
		}
	}

	private function clear_media_backup_cleanup_schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}
		$hook = 'npcink_abilities_toolkit_cleanup_media_backups';
		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Returns the lazily-loaded core read package.
	 *
	 * @return Core_Read_Package
	 */
	private function core_read_package() {
		if ( ! $this->core_read_package instanceof Core_Read_Package ) {
			$this->core_read_package = new Core_Read_Package( $this->categories, $this->abilities );
		}

		return $this->core_read_package;
	}

	/**
	 * Returns the lazily-loaded core write package.
	 *
	 * @return Core_Write_Package
	 */
	private function core_write_package() {
		if ( ! $this->core_write_package instanceof Core_Write_Package ) {
			$this->core_write_package = new Core_Write_Package( $this->categories, $this->abilities );
		}

		return $this->core_write_package;
	}

	/**
	 * Returns the lazily-loaded core destructive package.
	 *
	 * @return Core_Destructive_Package
	 */
	private function core_destructive_package() {
		if ( ! $this->core_destructive_package instanceof Core_Destructive_Package ) {
			$this->core_destructive_package = new Core_Destructive_Package( $this->categories, $this->abilities );
		}

		return $this->core_destructive_package;
	}

	/**
	 * Returns the lazily-loaded core comment package.
	 *
	 * @return Core_Comment_Package
	 */
	private function core_comment_package() {
		if ( ! $this->core_comment_package instanceof Core_Comment_Package ) {
			$this->core_comment_package = new Core_Comment_Package( $this->categories, $this->abilities );
		}

		return $this->core_comment_package;
	}

	/**
	 * Returns the lazily-loaded admin test page.
	 *
	 * @return Test_Page
	 */
	private function test_page() {
		if ( ! $this->test_page instanceof Test_Page ) {
			$this->test_page = new Test_Page( $this->abilities, $this->categories );
		}

		return $this->test_page;
	}

	/**
	 * Loads bundled translations from the plugin languages directory.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		if ( ! function_exists( 'load_plugin_textdomain' ) || ! function_exists( 'plugin_basename' ) || ! defined( 'NPCINK_ABILITIES_TOOLKIT_FILE' ) ) {
			return;
		}

		load_plugin_textdomain(
			'npcink-abilities-toolkit',
			false,
			dirname( plugin_basename( (string) constant( 'NPCINK_ABILITIES_TOOLKIT_FILE' ) ) ) . '/languages'
		);
	}

	/**
	 * Adds an abilities shortcut on the WordPress plugins screen.
	 *
	 * @param array<int|string,string> $links Existing plugin action links.
	 * @return array<int|string,string>
	 */
	public function filter_plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->plugin_abilities_url() ),
				esc_html__( 'View Abilities', 'npcink-abilities-toolkit' )
			)
		);

		return $links;
	}

	/**
	 * Returns the best admin URL for the abilities page.
	 *
	 * @return string
	 */
	private function plugin_abilities_url(): string {
		if ( function_exists( 'menu_page_url' ) ) {
			$url = menu_page_url( 'npcink-abilities-toolkit', false );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return admin_url( $this->has_npcink_parent_menu() ? 'admin.php?page=npcink-abilities-toolkit' : 'tools.php?page=npcink-abilities-toolkit' );
	}

	/**
	 * Returns whether a Npcink AI parent menu was registered by a host plugin.
	 *
	 * @return bool
	 */
	private function has_npcink_parent_menu(): bool {
		global $menu;

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && 'npcink-ai' === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the category registrar.
	 *
	 * @return Category_Registrar
	 */
	public function categories() {
		return $this->categories;
	}

	/**
	 * Returns the ability registrar.
	 *
	 * @return Ability_Registrar
	 */
	public function abilities() {
		return $this->abilities;
	}

	/**
	 * Checks whether a built-in package should boot.
	 *
	 * Supported package slugs are core_read, core_write, core_destructive,
	 * core_comment, npcink_catalog_bridge, admin_test_page, and read_cache_hooks.
	 *
	 * @param string $package Package slug.
	 * @return bool
	 */
	private function is_package_enabled( $package ) {
		$defaults = array(
			'core_read'             => true,
			'core_write'            => true,
			'core_destructive'      => true,
			'core_comment'          => true,
			'npcink_catalog_bridge' => true,
			'admin_test_page'       => true,
			'read_cache_hooks'      => true,
		);

		/**
		 * Filters the built-in package boot map.
		 *
		 * The default keeps the plugin's existing behavior. Hosts can disable
		 * optional packages when they only need the generic Abilities API surface.
		 *
		 * @param array<string,bool> $defaults Package enable map.
		 */
		$enabled = apply_filters( 'npcink_abilities_toolkit_enabled_packages', $defaults );
		$enabled = is_array( $enabled ) ? $enabled : $defaults;
		$package = sanitize_key( $package );

		return ! empty( $enabled[ $package ] );
	}

	/**
	 * Registers read-cache invalidation hooks when WordPress hooks are available.
	 *
	 * @return void
	 */
	private function register_cache_invalidation_hooks() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'save_post', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'deleted_post', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'transition_post_status', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'created_term', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'edited_term', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'delete_term', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'add_attachment', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'edit_attachment', array( $this, 'bump_read_cache_version' ), 20 );
		add_action( 'delete_attachment', array( $this, 'bump_read_cache_version' ), 20 );
	}

	/**
	 * Bumps the read-cache version for bounded read-only report transients.
	 *
	 * @return void
	 */
	public function bump_read_cache_version() {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$current = max( 1, (int) get_option( 'npcink_abilities_toolkit_read_cache_version', 1 ) );
		update_option( 'npcink_abilities_toolkit_read_cache_version', $current + 1, false );
	}
}
