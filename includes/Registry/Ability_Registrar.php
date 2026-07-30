<?php
/**
 * Ability registrar.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and registers Abilities API abilities.
 */
final class Ability_Registrar {
	const CATALOG_STATE_OPTION      = 'npcink_abilities_toolkit_catalog_observability_state';
	const CATALOG_RATE_LIMIT_PREFIX = 'npcink_abilities_toolkit_catalog_emit_';
	const CATALOG_RATE_LIMIT_TTL    = 86400;

	/**
	 * Category registrar.
	 *
	 * @var Category_Registrar
	 */
	private $categories;

	/**
	 * Contract normalizer.
	 *
	 * @var Contract_Normalizer
	 */
	private $contract_normalizer;

	/**
	 * Ability definitions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $abilities = array();

	/**
	 * Cached catalog fingerprint for the current request.
	 *
	 * @var string
	 */
	private $catalog_fingerprint_cache = '';

	/**
	 * Whether the cached catalog fingerprint needs to be recomputed.
	 *
	 * @var bool
	 */
	private $catalog_fingerprint_dirty = true;

	/**
	 * Whether this request already checked the catalog snapshot.
	 *
	 * @var bool
	 */
	private $catalog_snapshot_checked = false;

	/**
	 * Constructor.
	 *
	 * @param Category_Registrar  $categories Category registrar.
	 * @param Contract_Normalizer $contract_normalizer Contract normalizer.
	 */
	public function __construct( Category_Registrar $categories, Contract_Normalizer $contract_normalizer ) {
		$this->categories           = $categories;
		$this->contract_normalizer = $contract_normalizer;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_with_wordpress' ), 10 );
		add_action(
			'npcink_abilities_toolkit_refresh_catalog_observability',
			function ( $reason = 'manual_refresh' ) {
				$this->emit_manual_catalog_refresh( $reason );
			},
			10,
			1
		);
		add_action(
			'shutdown',
			function () {
				$this->emit_catalog_snapshot_if_changed();
			},
			100
		);
	}

	/**
	 * Adds a read-only ability.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return bool
	 */
	public function add_readonly( $ability_id, array $definition ) {
		return $this->add( $ability_id, $definition, 'readonly' );
	}

	/**
	 * Adds a write proposal ability.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return bool
	 */
	public function add_write_proposal( $ability_id, array $definition ) {
		return $this->add( $ability_id, $definition, 'write_proposal' );
	}

	/**
	 * Adds a host-governed write ability.
	 *
	 * Host-governed write abilities may expose dry-run previews directly, but
	 * commits must be approved by a host plugin through the package commit filter.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return bool
	 */
	public function add_write_host_governed( $ability_id, array $definition ) {
		return $this->add( $ability_id, $definition, 'write_host' );
	}

	/**
	 * Adds a host-governed destructive ability.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @return bool
	 */
	public function add_destructive_host_governed( $ability_id, array $definition ) {
		return $this->add( $ability_id, $definition, 'destructive_host' );
	}

	/**
	 * Returns all normalized ability definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		return $this->abilities;
	}

	/**
	 * Returns a stable fingerprint for the currently registered ability catalog.
	 *
	 * @return string
	 */
	public function catalog_fingerprint() {
		if ( ! $this->catalog_fingerprint_dirty && '' !== $this->catalog_fingerprint_cache ) {
			return $this->catalog_fingerprint_cache;
		}

		$snapshot = array();
		$abilities = $this->abilities;
		ksort( $abilities, SORT_STRING );

		foreach ( $abilities as $ability_id => $definition ) {
			$snapshot[ $ability_id ] = $this->stable_catalog_definition( $ability_id, $definition );
		}

		$this->catalog_fingerprint_cache = hash( 'sha256', $this->stable_json_encode( $snapshot ) );
		$this->catalog_fingerprint_dirty = false;

		return $this->catalog_fingerprint_cache;
	}

	/**
	 * Emits a catalog changed event for explicit refresh requests.
	 *
	 * @param string $reason Refresh reason.
	 * @return bool
	 */
	public function emit_manual_catalog_refresh( $reason = 'manual_refresh' ) {
		$reason = sanitize_key( (string) $reason );
		if ( '' === $reason ) {
			$reason = 'manual_refresh';
		}

		return $this->emit_catalog_changed_if_needed( $reason, true );
	}

	/**
	 * Emits a catalog changed event when the request registered a new catalog snapshot.
	 *
	 * @return bool
	 */
	public function emit_catalog_snapshot_if_changed() {
		if ( $this->catalog_snapshot_checked ) {
			return false;
		}

		$this->catalog_snapshot_checked = true;
		return $this->emit_catalog_changed_if_needed( 'catalog_changed', false );
	}

	/**
	 * Registers queued abilities with WordPress.
	 *
	 * @return void
	 */
	public function register_with_wordpress() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->abilities as $ability_id => $definition ) {
			$this->register_single_with_wordpress( $ability_id, $definition );
		}

		$this->emit_catalog_changed_if_needed( 'bootstrap', false );
		$this->catalog_snapshot_checked = true;
	}

	/**
	 * Adds an ability definition.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Ability definition.
	 * @param string              $mode Registration mode.
	 * @return bool
	 */
	private function add( $ability_id, array $definition, $mode ) {
		$normalized = $this->contract_normalizer->normalize( $ability_id, $definition, $mode );
		$ability_id = $normalized['ability_id'];

		if ( '' === $ability_id || false === strpos( $ability_id, '/' ) ) {
			return false;
		}

		$this->abilities[ $ability_id ] = $normalized;
		$this->catalog_fingerprint_dirty = true;
		$this->catalog_snapshot_checked  = false;

		if (
			function_exists( 'wp_register_ability' )
			&& (
				( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_init' ) )
				|| ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_init' ) > 0 )
			)
		) {
			$this->register_single_with_wordpress( $ability_id, $normalized );
		}

		return true;
	}

	/**
	 * Registers one normalized ability with WordPress.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Normalized ability definition.
	 * @return void
	 */
	private function register_single_with_wordpress( $ability_id, array $definition ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_id ) ) {
			return;
		}

		$category = isset( $definition['category'] ) ? sanitize_key( (string) $definition['category'] ) : '';
		if ( '' !== $category && ! isset( $this->categories->all()[ $category ] ) ) {
			$this->categories->add(
				$category,
				array(
					'label'       => $category,
					'description' => '',
				)
			);
		}

		wp_register_ability(
			$ability_id,
			array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => $definition['category'],
				'input_schema'        => $definition['input_schema'],
				'output_schema'       => $definition['output_schema'],
				'execute_callback'    => $this->observed_execute_callback( $ability_id, $definition ),
				'permission_callback' => $definition['permission_callback'],
				'meta'                => $definition['meta'],
			)
		);
	}

	/**
	 * Emits one local catalog snapshot event when the catalog changed or refresh is explicit.
	 *
	 * @param string $reason Event trigger reason.
	 * @param bool   $force  Whether an explicit trigger should emit even when the hash is unchanged.
	 * @return bool
	 */
	private function emit_catalog_changed_if_needed( $reason, $force ) {
		if ( ! function_exists( 'npcink_abilities_toolkit_emit_observability_event' ) ) {
			return false;
		}

		$catalog_hash = $this->catalog_fingerprint();
		$state        = function_exists( 'get_option' ) ? get_option( self::CATALOG_STATE_OPTION, array() ) : array();
		$state        = is_array( $state ) ? $state : array();
		$previous_hash = isset( $state['catalog_hash'] ) ? (string) $state['catalog_hash'] : '';
		$previous_version = isset( $state['plugin_version'] ) ? (string) $state['plugin_version'] : '';
		$current_version = defined( 'NPCINK_ABILITIES_TOOLKIT_VERSION' ) ? (string) NPCINK_ABILITIES_TOOLKIT_VERSION : '';
		$version_changed = '' !== $previous_version && '' !== $current_version && $previous_version !== $current_version;

		if ( ! $force && ! $version_changed && $previous_hash === $catalog_hash ) {
			return false;
		}

		$rate_limit_key = self::CATALOG_RATE_LIMIT_PREFIX . substr( hash( 'sha256', $catalog_hash . '|' . $current_version ), 0, 40 );
		if ( ! $version_changed && function_exists( 'get_transient' ) && false !== get_transient( $rate_limit_key ) ) {
			return false;
		}

		$emitted_at = gmdate( 'c' );
		$payload    = array(
			'plugin_slug'  => 'npcink-abilities-toolkit',
			'status'       => 'ok',
			'event_kind'   => 'abilities.catalog.changed',
			'event_id'     => $this->catalog_event_id( $catalog_hash, $current_version, (string) $reason ),
			'ability_count' => count( $this->abilities ),
			'catalog_hash' => $catalog_hash,
			'source'       => 'local',
			'reason'       => sanitize_key( (string) $reason ),
		);

		if ( '' !== $previous_hash && $previous_hash !== $catalog_hash ) {
			$payload['previous_catalog_hash'] = $previous_hash;
		}

		$this->emit_observability_event( 'abilities.catalog.changed', $payload );

		$new_state = array(
			'catalog_hash'   => $catalog_hash,
			'emitted_at'     => $emitted_at,
			'plugin_version' => $current_version,
			'reason'         => sanitize_key( (string) $reason ),
		);
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CATALOG_STATE_OPTION, $new_state, false );
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $rate_limit_key, $emitted_at, self::CATALOG_RATE_LIMIT_TTL );
		}

		return true;
	}

	/**
	 * Returns the safe, stable catalog fields used for fingerprinting.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Normalized ability definition.
	 * @return array<string,mixed>
	 */
	private function stable_catalog_definition( $ability_id, array $definition ) {
		$stable = array(
			'ability_id'                => $ability_id,
			'mode'                      => (string) ( $definition['mode'] ?? '' ),
			'source'                    => (string) ( $definition['source'] ?? '' ),
			'category'                  => (string) ( $definition['category'] ?? '' ),
			'risk_level'                => (string) ( $definition['risk_level'] ?? '' ),
			'label'                     => (string) ( $definition['label'] ?? '' ),
			'description'               => (string) ( $definition['description'] ?? '' ),
			'input_schema'              => is_array( $definition['input_schema'] ?? null ) ? $definition['input_schema'] : array(),
			'output_schema'             => is_array( $definition['output_schema'] ?? null ) ? $definition['output_schema'] : array(),
			'annotations'               => is_array( $definition['annotations'] ?? null ) ? $definition['annotations'] : array(),
			'requires_confirm'          => ! empty( $definition['requires_confirm'] ),
			'requires_approval'         => ! empty( $definition['requires_approval'] ),
			'required_scope'            => (string) ( $definition['required_scope'] ?? '' ),
			'required_scopes'           => is_array( $definition['required_scopes'] ?? null ) ? array_values( $definition['required_scopes'] ) : array(),
			'contract_version'          => (string) ( $definition['contract_version'] ?? '' ),
			'deprecated'                => ! empty( $definition['deprecated'] ),
			'successor'                 => (string) ( $definition['successor'] ?? '' ),
			'project_to_npcink_catalog' => ! empty( $definition['project_to_npcink_catalog'] ),
			'implementation_posture'    => is_array( $definition['implementation_posture'] ?? null ) ? $definition['implementation_posture'] : array(),
		);

		return $this->stable_normalize_value( $stable );
	}

	/**
	 * Normalizes arrays into stable JSON-friendly values.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private function stable_normalize_value( $value ) {
		if ( is_object( $value ) ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! $this->is_list_array( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		$normalized = array();
		foreach ( $value as $key => $child ) {
			$normalized[ $key ] = $this->stable_normalize_value( $child );
		}

		return $normalized;
	}

	/**
	 * Encodes data to JSON for hashing.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private function stable_json_encode( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES )
			: json_encode( $value, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Returns whether an array is list-like.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool
	 */
	private function is_list_array( array $value ) {
		$index = 0;
		foreach ( $value as $key => $_child ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}

		return true;
	}

	/**
	 * Wraps ability execution with metadata-only local observability.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $definition Normalized ability definition.
	 * @return callable
	 */
	private function observed_execute_callback( $ability_id, array $definition ) {
		$callback = $definition['execute_callback'];
		$mode     = (string) ( $definition['mode'] ?? '' );

		return function ( ...$args ) use ( $ability_id, $callback, $mode ) {
			$started    = microtime( true );
			$started_id = $this->started_event_component( $started );

			try {
				$result  = call_user_func_array( $callback, $args );
				$is_error = function_exists( 'is_wp_error' ) && is_wp_error( $result );
				if ( $is_error ) {
					$this->emit_callback_failed_event(
						$ability_id,
						$mode,
						$started,
						$started_id,
						$this->wp_error_status_detail( $result )
					);

					return $result;
				}

				$this->emit_observability_event(
					'abilities.callback.completed',
					array(
						'ability_id' => $ability_id,
						'event_id'   => $this->callback_event_id( 'abilities.callback.completed', $ability_id, $mode, $started_id, 'ok', '' ),
						'mode'       => $mode,
						'status'     => 'ok',
						'latency_ms' => $this->elapsed_ms( $started ),
					)
				);

				return $result;
			} catch ( \Throwable $exception ) {
				$this->emit_callback_failed_event(
					$ability_id,
					$mode,
					$started,
					$started_id,
					$this->exception_status_detail( $exception )
				);

				throw $exception;
			}
		};
	}

	/**
	 * Emits a local observability event when the public helper is available.
	 *
	 * @param string              $event_kind Event kind.
	 * @param array<string,mixed> $payload Event details.
	 * @return void
	 */
	private function emit_observability_event( $event_kind, array $payload ) {
		if ( ! function_exists( 'npcink_abilities_toolkit_emit_observability_event' ) ) {
			return;
		}

		npcink_abilities_toolkit_emit_observability_event(
			array_merge(
				array(
					'event_kind' => $event_kind,
				),
				$payload
			)
		);
	}

	/**
	 * Emits a metadata-only callback failure event.
	 *
	 * @param string $ability_id    Ability id.
	 * @param string $mode          Registration mode.
	 * @param float  $started       Callback start time.
	 * @param string $started_id    Stable start-time id component.
	 * @param string $status_detail Short redacted diagnostic label.
	 * @return void
	 */
	private function emit_callback_failed_event( $ability_id, $mode, $started, $started_id, $status_detail ) {
		$error_code = 'abilities.callback_error';
		$this->emit_observability_event(
			'abilities.callback.failed',
			array(
				'ability_id'     => $ability_id,
				'event_id'       => $this->callback_event_id( 'abilities.callback.failed', $ability_id, $mode, $started_id, 'error', $error_code ),
				'mode'           => $mode,
				'status'         => 'error',
				'error_code'     => $error_code,
				'status_detail'  => $status_detail,
				'latency_ms'     => $this->elapsed_ms( $started ),
			)
		);
	}

	/**
	 * Builds a stable, metadata-only event id for catalog events.
	 *
	 * @param string $catalog_hash Catalog fingerprint.
	 * @param string $version      Plugin version.
	 * @param string $reason       Trigger reason.
	 * @return string
	 */
	private function catalog_event_id( $catalog_hash, $version, $reason ) {
		return 'catalog_' . substr( hash( 'sha256', implode( '|', array( $version, $catalog_hash, sanitize_key( (string) $reason ) ) ) ), 0, 32 );
	}

	/**
	 * Builds a stable, metadata-only event id for callback events.
	 *
	 * @param string $event_kind Event kind.
	 * @param string $ability_id Ability id.
	 * @param string $mode       Registration mode.
	 * @param string $started_id Start-time id component.
	 * @param string $status     Event status.
	 * @param string $error_code Error code, when applicable.
	 * @return string
	 */
	private function callback_event_id( $event_kind, $ability_id, $mode, $started_id, $status, $error_code ) {
		$hash = hash( 'sha256', implode( '|', array( $event_kind, $ability_id, $mode, $started_id, $status, $error_code ) ) );

		return 'ability_cb_' . substr( $hash, 0, 32 );
	}

	/**
	 * Converts a start time into an id-safe component.
	 *
	 * @param float $started Start time.
	 * @return string
	 */
	private function started_event_component( $started ) {
		return str_replace( '.', '', sprintf( '%.6F', $started ) );
	}

	/**
	 * Returns a short redacted status detail for WP_Error results.
	 *
	 * @param \WP_Error $error WordPress error object.
	 * @return string
	 */
	private function wp_error_status_detail( $error ) {
		if ( method_exists( $error, 'get_error_code' ) ) {
			return sanitize_key( (string) $error->get_error_code() );
		}

		return 'wp_error';
	}

	/**
	 * Returns a short redacted status detail for thrown exceptions.
	 *
	 * @param \Throwable $exception Exception.
	 * @return string
	 */
	private function exception_status_detail( \Throwable $exception ) {
		$class = str_replace( '\\', '_', get_class( $exception ) );
		$class = sanitize_key( $class );

		return '' !== $class ? $class : 'throwable';
	}

	/**
	 * Returns elapsed milliseconds.
	 *
	 * @param float $started Start time.
	 * @return int
	 */
	private function elapsed_ms( $started ) {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}
