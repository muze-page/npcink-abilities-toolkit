<?php
/**
 * Shared Cloud media derivative artifact contract.
 *
 * @package NpcinkAbilitiesToolkit
 */

namespace Npcink_Abilities_Toolkit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes exact, reviewable evidence for one short-lived media artifact.
 */
final class Cloud_Derivative_Artifact {
	public const MAX_FILESIZE_BYTES = 26214400;
	public const MAX_SOURCE_FILESIZE_BYTES = 52428800;
	public const MAX_DIMENSION       = 8192;
	public const MAX_PIXEL_AREA      = 16777216;

	private const ARTIFACT_ID_PATTERN = '/^art_[0-9a-f]{32}$/D';
	private const DELIVERY_ID_PATTERN = '/^mdl_[0-9a-f]{32}$/D';
	private const TRANSFER_CONTRACT    = 'media_artifact_verified_transfer.v1';
	private const ACK_CONTRACT         = 'media_artifact_delivery_ack.v1';

	private const DESCRIPTOR_KEYS = array(
		'artifact_id',
		'expires_at',
		'mime_type',
		'format',
		'width',
		'height',
		'filesize_bytes',
		'sha256',
		'suggested_filename',
		'filename_basis',
		'processing_warnings',
		'transform_facts',
	);

	private const RECEIVED_PAYLOAD_KEYS = array(
		'artifact_id',
		'contents',
		'mime_type',
		'width',
		'height',
		'filesize_bytes',
		'sha256',
		'expires_at',
		'transfer_evidence',
		'delivery_ack',
	);

	private const TRANSFORM_FACT_KEYS = array(
		'source_checksum',
		'output_checksum',
		'source_format',
		'output_format',
		'source_mime_type',
		'output_mime_type',
		'source_width',
		'source_height',
		'output_width',
		'output_height',
		'source_filesize_bytes',
		'output_filesize_bytes',
		'source_frame_count',
		'output_frame_count',
		'source_has_alpha',
		'output_has_alpha',
		'alpha_preserved',
		'decodable',
		'crop_applied',
		'watermark_applied',
		'resize_applied',
		'encoding_mode',
		'savings_basis_points',
	);

	private const TRANSFER_EVIDENCE_KEYS = array(
		'contract_version',
		'artifact_id',
		'delivery_id',
		'received_byte_size',
		'received_checksum',
		'byte_size_verified',
		'checksum_verified',
		'content_type_verified',
		'image_decoded',
		'dimensions_verified',
		'ack_deadline_at',
	);

	private const DELIVERY_ACK_KEYS = array(
		'contract_version',
		'delivery_id',
		'artifact_id',
		'status',
		'received_byte_size',
		'received_checksum',
		'byte_size_verified',
		'checksum_verified',
		'acknowledged_at',
		'artifact_expires_at',
		'idempotent_replay',
		'acknowledgement_scope',
	);

	/**
	 * Returns the public ability input schema for a derivative artifact.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'artifact_id'        => array( 'type' => 'string', 'pattern' => '^art_[0-9a-f]{32}$' ),
				'expires_at'         => array( 'type' => 'string', 'format' => 'date-time' ),
				'mime_type'          => array( 'type' => 'string', 'enum' => array_keys( self::format_by_mime() ) ),
				'format'             => array( 'type' => 'string', 'enum' => array_values( self::format_by_mime() ) ),
				'width'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'height'             => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'filesize_bytes'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_FILESIZE_BYTES ),
				'sha256'             => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'suggested_filename' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ),
				'filename_basis'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'owner'                          => array( 'type' => 'string', 'maxLength' => 80 ),
						'strategy'                       => array( 'type' => 'string', 'maxLength' => 80 ),
						'final_sanitize_unique_required' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'owner', 'strategy', 'final_sanitize_unique_required' ),
					'additionalProperties' => false,
				),
				'processing_warnings' => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array( 'type' => 'string', 'maxLength' => 200 ),
				),
				'transform_facts'    => self::transform_facts_schema(),
			),
			'required'             => self::DESCRIPTOR_KEYS,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the exact verified-transfer evidence schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function transfer_evidence_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract_version'     => array( 'type' => 'string', 'enum' => array( self::TRANSFER_CONTRACT ) ),
				'artifact_id'          => array( 'type' => 'string', 'pattern' => '^art_[0-9a-f]{32}$' ),
				'delivery_id'          => array( 'type' => 'string', 'pattern' => '^mdl_[0-9a-f]{32}$' ),
				'received_byte_size'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_FILESIZE_BYTES ),
				'received_checksum'    => array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' ),
				'byte_size_verified'   => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'checksum_verified'    => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'content_type_verified' => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'image_decoded'        => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'dimensions_verified'  => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'ack_deadline_at'      => array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'required'             => self::TRANSFER_EVIDENCE_KEYS,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the exact Cloud delivery ACK projection schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function delivery_ack_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract_version'      => array( 'type' => 'string', 'enum' => array( self::ACK_CONTRACT ) ),
				'delivery_id'           => array( 'type' => 'string', 'pattern' => '^mdl_[0-9a-f]{32}$' ),
				'artifact_id'           => array( 'type' => 'string', 'pattern' => '^art_[0-9a-f]{32}$' ),
				'status'                => array( 'type' => 'string', 'enum' => array( 'acknowledged' ) ),
				'received_byte_size'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_FILESIZE_BYTES ),
				'received_checksum'     => array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' ),
				'byte_size_verified'    => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'checksum_verified'     => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'acknowledged_at'       => array( 'type' => 'string', 'format' => 'date-time' ),
				'artifact_expires_at'   => array( 'type' => 'string', 'format' => 'date-time' ),
				'idempotent_replay'     => array( 'type' => 'boolean' ),
				'acknowledgement_scope' => array( 'type' => 'string', 'enum' => array( 'verified_transfer_only' ) ),
			),
			'required'             => self::DELIVERY_ACK_KEYS,
			'additionalProperties' => false,
		);
	}

	/**
	 * Validates and normalizes one derivative artifact descriptor.
	 *
	 * This is intentionally a hard contract. Direct PHP callers receive the same
	 * additional-property and type checks as REST callers; no legacy aliases are
	 * accepted.
	 *
	 * @param mixed $artifact Raw artifact descriptor.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize( $artifact ) {
		if ( ! is_array( $artifact ) ) {
			return self::error( 'descriptor_invalid', __( 'The derivative artifact descriptor must be an object.', 'npcink-abilities-toolkit' ) );
		}
		$keys_valid = self::validate_exact_keys( $artifact, self::DESCRIPTOR_KEYS, 'descriptor_fields_invalid', __( 'The derivative artifact descriptor does not match the required 12-field contract.', 'npcink-abilities-toolkit' ) );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		$artifact_id = is_string( $artifact['artifact_id'] ?? null ) ? $artifact['artifact_id'] : '';
		if ( 1 !== preg_match( self::ARTIFACT_ID_PATTERN, $artifact_id ) ) {
			return self::error( 'id_invalid', __( 'A canonical derivative artifact_id is required.', 'npcink-abilities-toolkit' ) );
		}

		$expires_at = is_string( $artifact['expires_at'] ?? null ) ? $artifact['expires_at'] : '';
		$expires_ts = self::expiry_timestamp( $expires_at );
		if ( $expires_ts <= 0 ) {
			return self::error( 'expiry_required', __( 'A valid derivative artifact expiry is required.', 'npcink-abilities-toolkit' ) );
		}
		if ( $expires_ts <= time() ) {
			return self::error( 'expired', __( 'The derivative artifact has expired. Generate a new preview before adoption.', 'npcink-abilities-toolkit' ), 410 );
		}

		$format_by_mime = self::format_by_mime();
		$mime_type = is_string( $artifact['mime_type'] ?? null ) ? $artifact['mime_type'] : '';
		if ( ! isset( $format_by_mime[ $mime_type ] ) ) {
			return self::error( 'mime_invalid', __( 'The derivative artifact MIME type is not supported for media replacement.', 'npcink-abilities-toolkit' ) );
		}

		$format = is_string( $artifact['format'] ) ? $artifact['format'] : '';
		if ( $format !== $format_by_mime[ $mime_type ] ) {
			return self::error( 'format_mismatch', __( 'The derivative artifact format does not match its MIME type.', 'npcink-abilities-toolkit' ) );
		}

		$width  = is_int( $artifact['width'] ?? null ) ? $artifact['width'] : 0;
		$height = is_int( $artifact['height'] ?? null ) ? $artifact['height'] : 0;
		if ( ! self::dimensions_within_limits( $width, $height ) ) {
			return self::error( 'dimensions_invalid', __( 'Derivative artifact dimensions must be present and within the local limit.', 'npcink-abilities-toolkit' ) );
		}

		$filesize_bytes = is_int( $artifact['filesize_bytes'] ?? null ) ? $artifact['filesize_bytes'] : 0;
		if ( $filesize_bytes <= 0 || $filesize_bytes > self::MAX_FILESIZE_BYTES ) {
			return self::error( 'filesize_invalid', __( 'Derivative artifact size must be present and within the local limit.', 'npcink-abilities-toolkit' ) );
		}

		$sha256 = is_string( $artifact['sha256'] ?? null ) ? $artifact['sha256'] : '';
		if ( '' === self::normalize_sha256( $sha256 ) ) {
			return self::error( 'sha256_required', __( 'A canonical derivative artifact SHA-256 digest is required.', 'npcink-abilities-toolkit' ) );
		}

		$normalized = array(
			'artifact_id'    => $artifact_id,
			'expires_at'     => $expires_at,
			'mime_type'      => $mime_type,
			'format'         => $format,
			'width'          => $width,
			'height'         => $height,
			'filesize_bytes' => $filesize_bytes,
			'sha256'         => $sha256,
		);

		if ( ! is_string( $artifact['suggested_filename'] ) || '' === $artifact['suggested_filename'] || strlen( $artifact['suggested_filename'] ) > 120 ) {
			return self::error( 'suggested_filename_invalid', __( 'The derivative artifact suggested filename is invalid.', 'npcink-abilities-toolkit' ) );
		}
		$suggested_filename = self::sanitize_file_name( $artifact['suggested_filename'] );
		if ( '' === $suggested_filename || $suggested_filename !== $artifact['suggested_filename'] ) {
			return self::error( 'suggested_filename_invalid', __( 'The derivative artifact suggested filename is invalid.', 'npcink-abilities-toolkit' ) );
		}
		$normalized['suggested_filename'] = $suggested_filename;

		$filename_basis = self::normalize_filename_basis( $artifact['filename_basis'] ?? null );
		if ( is_wp_error( $filename_basis ) ) {
			return $filename_basis;
		}
		$normalized['filename_basis'] = $filename_basis;

		$warnings = self::normalize_processing_warnings( $artifact['processing_warnings'] ?? null );
		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}
		$normalized['processing_warnings'] = $warnings;
		$transform_facts = self::normalize_transform_facts( $artifact['transform_facts'] ?? null, $normalized );
		if ( is_wp_error( $transform_facts ) ) {
			return $transform_facts;
		}
		$normalized['transform_facts'] = $transform_facts;

		return $normalized;
	}

	/**
	 * Verifies the exact Addon receive payload against proposal evidence and the
	 * actual image bytes.
	 *
	 * @param mixed               $payload Raw Addon payload.
	 * @param array<string,mixed> $proposal_artifact Normalized proposal descriptor.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function verify_received_payload( $payload, array $proposal_artifact ) {
		if ( ! is_array( $payload ) ) {
			return self::error( 'received_payload_invalid', __( 'The Cloud Addon receive payload must be an object.', 'npcink-abilities-toolkit' ), 502 );
		}
		$keys_valid = self::validate_exact_keys( $payload, self::RECEIVED_PAYLOAD_KEYS, 'received_payload_fields_invalid', __( 'The Cloud Addon receive payload does not match the required contract.', 'npcink-abilities-toolkit' ), 502 );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		$proposal = self::normalize( $proposal_artifact );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$received_facts = $proposal;
		foreach ( array( 'artifact_id', 'mime_type', 'width', 'height', 'filesize_bytes', 'sha256', 'expires_at' ) as $field ) {
			$received_facts[ $field ] = $payload[ $field ];
		}
		$received = self::normalize( $received_facts );
		if ( is_wp_error( $received ) ) {
			return self::error( 'received_facts_invalid', __( 'The Cloud Addon receive facts are invalid.', 'npcink-abilities-toolkit' ), 502, array( 'cause' => $received->get_error_code() ) );
		}

		foreach ( array( 'artifact_id', 'mime_type', 'width', 'height', 'filesize_bytes', 'sha256' ) as $field ) {
			if ( $received[ $field ] !== $proposal[ $field ] ) {
				return self::error( 'received_fact_mismatch', __( 'The Cloud Addon receive facts did not match the reviewed artifact.', 'npcink-abilities-toolkit' ), 409, array( 'field' => $field ) );
			}
		}
		if ( self::expiry_timestamp( $received['expires_at'] ) !== self::expiry_timestamp( $proposal['expires_at'] ) ) {
			return self::error( 'received_expiry_invalid', __( 'The received derivative expiry must preserve the reviewed artifact lifetime.', 'npcink-abilities-toolkit' ), 409 );
		}

		$contents = is_string( $payload['contents'] ) ? $payload['contents'] : '';
		if ( '' === $contents || strlen( $contents ) > self::MAX_FILESIZE_BYTES ) {
			return self::error( 'received_contents_invalid', __( 'The received derivative contents are empty or exceed the local limit.', 'npcink-abilities-toolkit' ), 502 );
		}
		$actual_filesize = strlen( $contents );
		$actual_sha256 = hash( 'sha256', $contents );
		if ( $actual_filesize !== $received['filesize_bytes'] ) {
			return self::error( 'filesize_mismatch', __( 'The received derivative size did not match its declared facts.', 'npcink-abilities-toolkit' ), 409 );
		}
		if ( ! hash_equals( $received['sha256'], $actual_sha256 ) ) {
			return self::error( 'checksum_mismatch', __( 'The received derivative checksum did not match its bytes.', 'npcink-abilities-toolkit' ), 409 );
		}

		if ( ! function_exists( 'getimagesizefromstring' ) ) {
			return self::error( 'image_decoder_unavailable', __( 'The local PHP image inspector is unavailable.', 'npcink-abilities-toolkit' ), 500 );
		}
		$image = @getimagesizefromstring( $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed untrusted image bytes must fail closed without emitting warnings.
		if ( ! is_array( $image ) ) {
			return self::error( 'image_decode_failed', __( 'The received derivative could not be decoded as an image.', 'npcink-abilities-toolkit' ), 409 );
		}
		$actual_width = is_int( $image[0] ?? null ) ? $image[0] : 0;
		$actual_height = is_int( $image[1] ?? null ) ? $image[1] : 0;
		$actual_mime = is_string( $image['mime'] ?? null ) ? $image['mime'] : '';
		if ( ! self::dimensions_within_limits( $actual_width, $actual_height ) ) {
			return self::error( 'decoded_dimensions_invalid', __( 'The decoded image dimensions exceed the local processing limit.', 'npcink-abilities-toolkit' ), 409 );
		}
		if ( $actual_mime !== $received['mime_type'] ) {
			return self::error( 'decoded_mime_mismatch', __( 'The decoded image MIME type did not match the reviewed artifact.', 'npcink-abilities-toolkit' ), 409 );
		}
		if ( $actual_width !== $received['width'] || $actual_height !== $received['height'] ) {
			return self::error( 'decoded_dimensions_mismatch', __( 'The decoded image dimensions did not match the reviewed artifact.', 'npcink-abilities-toolkit' ), 409 );
		}

		$checksum = 'sha256:' . $actual_sha256;
		$transfer_evidence = self::verify_transfer_evidence(
			$payload['transfer_evidence'],
			$received['artifact_id'],
			$actual_filesize,
			$checksum
		);
		if ( is_wp_error( $transfer_evidence ) ) {
			return $transfer_evidence;
		}
		$delivery_ack = self::verify_delivery_ack(
			$payload['delivery_ack'],
			$transfer_evidence,
			$proposal,
			$actual_filesize,
			$checksum
		);
		if ( is_wp_error( $delivery_ack ) ) {
			return $delivery_ack;
		}
		if ( $payload['expires_at'] !== $delivery_ack['artifact_expires_at'] ) {
			return self::error( 'received_expiry_ack_mismatch', __( 'The received derivative expiry did not match the acknowledged original artifact lifetime.', 'npcink-abilities-toolkit' ), 409 );
		}

		return array(
			'artifact_id'      => $received['artifact_id'],
			'contents'         => $contents,
			'mime_type'        => $received['mime_type'],
			'width'            => $actual_width,
			'height'           => $actual_height,
			'filesize_bytes'   => $actual_filesize,
			'sha256'           => $actual_sha256,
			'expires_at'       => $received['expires_at'],
			'transfer_evidence' => $transfer_evidence,
			'delivery_ack'     => $delivery_ack,
		);
	}

	/**
	 * Normalizes one canonical SHA-256 value.
	 *
	 * @param string $value Raw digest.
	 * @return string
	 */
	public static function normalize_sha256( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
	}

	/**
	 * Enforces both axis and decoded-pixel limits without integer overflow.
	 *
	 * @param mixed $width Width in pixels.
	 * @param mixed $height Height in pixels.
	 * @return bool
	 */
	public static function dimensions_within_limits( $width, $height ) {
		if ( ! is_int( $width ) || ! is_int( $height ) || $width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION ) {
			return false;
		}

		return $width <= intdiv( self::MAX_PIXEL_AREA, $height );
	}

	/**
	 * Parses one strict RFC3339 timestamp without relative-date fallbacks.
	 *
	 * @param string $value Raw timestamp.
	 * @return int
	 */
	public static function expiry_timestamp( $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?(?:Z|\+00:00)$/D', $value ) ) {
			return 0;
		}
		$normalized = 'Z' === substr( $value, -1 ) ? substr( $value, 0, -1 ) . '+00:00' : $value;
		$format = false !== strpos( $normalized, '.' ) ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
		$parsed = \DateTimeImmutable::createFromFormat( $format, $normalized );
		$errors = \DateTimeImmutable::getLastErrors();
		$has_errors = is_array( $errors ) && ( (int) ( $errors['warning_count'] ?? 0 ) > 0 || (int) ( $errors['error_count'] ?? 0 ) > 0 );
		$roundtrip_format = false !== strpos( $normalized, '.' ) ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP';
		if ( $parsed instanceof \DateTimeImmutable && ! $has_errors && $parsed->format( $roundtrip_format ) === $normalized ) {
			return $parsed->getTimestamp();
		}

		return 0;
	}

	/**
	 * @return array<string,string>
	 */
	private static function format_by_mime() {
		return array(
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpeg',
			'image/png'  => 'png',
		);
	}

	/**
	 * Returns the strict Cloud v2 image transformation fact schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function transform_facts_schema() {
		$formats = array_values( self::format_by_mime() );
		$mimes   = array_keys( self::format_by_mime() );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_checksum'      => array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' ),
				'output_checksum'      => array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' ),
				'source_format'        => array( 'type' => 'string', 'enum' => $formats ),
				'output_format'        => array( 'type' => 'string', 'enum' => $formats ),
				'source_mime_type'     => array( 'type' => 'string', 'enum' => $mimes ),
				'output_mime_type'     => array( 'type' => 'string', 'enum' => $mimes ),
				'source_width'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'source_height'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'output_width'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'output_height'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DIMENSION ),
				'source_filesize_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SOURCE_FILESIZE_BYTES ),
				'output_filesize_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_FILESIZE_BYTES ),
				'source_frame_count'   => array( 'type' => 'integer', 'enum' => array( 1 ) ),
				'output_frame_count'   => array( 'type' => 'integer', 'enum' => array( 1 ) ),
				'source_has_alpha'     => array( 'type' => 'boolean' ),
				'output_has_alpha'     => array( 'type' => 'boolean' ),
				'alpha_preserved'      => array( 'type' => 'boolean' ),
				'decodable'            => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'crop_applied'         => array( 'type' => 'boolean' ),
				'watermark_applied'    => array( 'type' => 'boolean' ),
				'resize_applied'       => array( 'type' => 'boolean' ),
				'encoding_mode'        => array( 'type' => 'string', 'enum' => array( 'lossless', 'lossy', 'unknown' ) ),
				'savings_basis_points' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
			),
			'required'             => self::TRANSFORM_FACT_KEYS,
			'additionalProperties' => false,
		);
	}

	/**
	 * Validates Cloud v2 transformation facts for direct PHP callers.
	 *
	 * @param mixed               $value Transformation facts.
	 * @param array<string,mixed> $artifact Normalized outer artifact facts.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize_transform_facts( $value, array $artifact ) {
		if ( ! is_array( $value ) ) {
			return self::error( 'transform_facts_invalid', __( 'Derivative transformation facts must be an object.', 'npcink-abilities-toolkit' ) );
		}
		$keys_valid = self::validate_exact_keys( $value, self::TRANSFORM_FACT_KEYS, 'transform_facts_fields_invalid', __( 'Derivative transformation facts do not match the required Cloud v2 contract.', 'npcink-abilities-toolkit' ) );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		$format_by_mime = self::format_by_mime();
		foreach ( array( 'source', 'output' ) as $side ) {
			$checksum = $value[ $side . '_checksum' ];
			$format   = $value[ $side . '_format' ];
			$mime     = $value[ $side . '_mime_type' ];
			$width    = $value[ $side . '_width' ];
			$height   = $value[ $side . '_height' ];
			$filesize = $value[ $side . '_filesize_bytes' ];
			$max_size = 'source' === $side ? self::MAX_SOURCE_FILESIZE_BYTES : self::MAX_FILESIZE_BYTES;

			if ( ! is_string( $checksum ) || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/D', $checksum ) ) {
				return self::error( 'transform_facts_checksum_invalid', __( 'Derivative transformation checksums must use canonical SHA-256 values.', 'npcink-abilities-toolkit' ) );
			}
			if ( ! is_string( $mime ) || ! is_string( $format ) || ! isset( $format_by_mime[ $mime ] ) || $format_by_mime[ $mime ] !== $format ) {
				return self::error( 'transform_facts_format_invalid', __( 'Derivative transformation MIME types and formats must agree.', 'npcink-abilities-toolkit' ) );
			}
			if ( ! self::dimensions_within_limits( $width, $height ) || ! is_int( $filesize ) || $filesize <= 0 || $filesize > $max_size ) {
				return self::error( 'transform_facts_dimensions_invalid', __( 'Derivative transformation dimensions and file sizes must stay within media limits.', 'npcink-abilities-toolkit' ) );
			}
			if ( 1 !== $value[ $side . '_frame_count' ] ) {
				return self::error( 'transform_facts_frame_count_invalid', __( 'Animated derivative transformation facts are not supported.', 'npcink-abilities-toolkit' ) );
			}
			if ( ! is_bool( $value[ $side . '_has_alpha' ] ) ) {
				return self::error( 'transform_facts_alpha_invalid', __( 'Derivative transformation alpha facts must be boolean values.', 'npcink-abilities-toolkit' ) );
			}
		}

		foreach ( array( 'alpha_preserved', 'decodable', 'crop_applied', 'watermark_applied', 'resize_applied' ) as $field ) {
			if ( ! is_bool( $value[ $field ] ) ) {
				return self::error( 'transform_facts_boolean_invalid', __( 'Derivative transformation flags must be boolean values.', 'npcink-abilities-toolkit' ) );
			}
		}
		if ( true !== $value['decodable'] || $value['alpha_preserved'] !== ( $value['source_has_alpha'] === $value['output_has_alpha'] ) ) {
			return self::error( 'transform_facts_evidence_invalid', __( 'Derivative transformation decode and alpha evidence is inconsistent.', 'npcink-abilities-toolkit' ) );
		}
		if ( ! is_string( $value['encoding_mode'] ) || ! in_array( $value['encoding_mode'], array( 'lossless', 'lossy', 'unknown' ), true ) ) {
			return self::error( 'transform_facts_encoding_invalid', __( 'Derivative transformation encoding mode is invalid.', 'npcink-abilities-toolkit' ) );
		}
		if ( ! is_int( $value['savings_basis_points'] ) || $value['savings_basis_points'] < 0 || $value['savings_basis_points'] > 10000 ) {
			return self::error( 'transform_facts_savings_invalid', __( 'Derivative transformation savings evidence is invalid.', 'npcink-abilities-toolkit' ) );
		}

		if (
			$value['output_checksum'] !== 'sha256:' . $artifact['sha256']
			|| $value['output_format'] !== $artifact['format']
			|| $value['output_mime_type'] !== $artifact['mime_type']
			|| $value['output_width'] !== $artifact['width']
			|| $value['output_height'] !== $artifact['height']
			|| $value['output_filesize_bytes'] !== $artifact['filesize_bytes']
		) {
			return self::error( 'transform_facts_output_mismatch', __( 'Derivative transformation output facts do not match the reviewed artifact.', 'npcink-abilities-toolkit' ) );
		}

		return $value;
	}

	/**
	 * @param mixed  $evidence Raw transfer evidence.
	 * @param string $artifact_id Artifact id.
	 * @param int    $filesize Byte size.
	 * @param string $checksum Prefixed checksum.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_transfer_evidence( $evidence, $artifact_id, $filesize, $checksum ) {
		if ( ! is_array( $evidence ) ) {
			return self::error( 'transfer_evidence_invalid', __( 'Verified transfer evidence is required.', 'npcink-abilities-toolkit' ), 502 );
		}
		$keys_valid = self::validate_exact_keys( $evidence, self::TRANSFER_EVIDENCE_KEYS, 'transfer_evidence_fields_invalid', __( 'Verified transfer evidence does not match the required contract.', 'npcink-abilities-toolkit' ), 502 );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}
		if ( self::TRANSFER_CONTRACT !== $evidence['contract_version'] ) {
			return self::error( 'transfer_contract_invalid', __( 'The verified transfer contract version is invalid.', 'npcink-abilities-toolkit' ), 502 );
		}
		if ( $artifact_id !== $evidence['artifact_id'] ) {
			return self::error( 'transfer_artifact_mismatch', __( 'Verified transfer evidence references a different artifact.', 'npcink-abilities-toolkit' ), 409 );
		}
		$delivery_id = is_string( $evidence['delivery_id'] ) ? $evidence['delivery_id'] : '';
		if ( 1 !== preg_match( self::DELIVERY_ID_PATTERN, $delivery_id ) ) {
			return self::error( 'transfer_delivery_id_invalid', __( 'Verified transfer evidence has an invalid delivery id.', 'npcink-abilities-toolkit' ), 502 );
		}
		if ( $filesize !== $evidence['received_byte_size'] || $checksum !== $evidence['received_checksum'] ) {
			return self::error( 'transfer_integrity_mismatch', __( 'Verified transfer evidence did not match the received bytes.', 'npcink-abilities-toolkit' ), 409 );
		}
		foreach ( array( 'byte_size_verified', 'checksum_verified', 'content_type_verified', 'image_decoded', 'dimensions_verified' ) as $field ) {
			if ( true !== $evidence[ $field ] ) {
				return self::error( 'transfer_verification_incomplete', __( 'All verified transfer checks must pass before local adoption.', 'npcink-abilities-toolkit' ), 409, array( 'field' => $field ) );
			}
		}
		if ( ! is_string( $evidence['ack_deadline_at'] ) || self::expiry_timestamp( $evidence['ack_deadline_at'] ) <= 0 ) {
			return self::error( 'transfer_ack_deadline_invalid', __( 'Verified transfer evidence has an invalid acknowledgement deadline.', 'npcink-abilities-toolkit' ), 502 );
		}

		return $evidence;
	}

	/**
	 * @param mixed               $ack Raw ACK projection.
	 * @param array<string,mixed> $transfer_evidence Verified transfer evidence.
	 * @param array<string,mixed> $proposal Proposal descriptor.
	 * @param int                 $filesize Byte size.
	 * @param string              $checksum Prefixed checksum.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_delivery_ack( $ack, array $transfer_evidence, array $proposal, $filesize, $checksum ) {
		if ( ! is_array( $ack ) ) {
			return self::error( 'delivery_ack_invalid', __( 'A Cloud delivery acknowledgement is required.', 'npcink-abilities-toolkit' ), 502 );
		}
		$keys_valid = self::validate_exact_keys( $ack, self::DELIVERY_ACK_KEYS, 'delivery_ack_fields_invalid', __( 'The Cloud delivery acknowledgement does not match the required contract.', 'npcink-abilities-toolkit' ), 502 );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}
		if ( self::ACK_CONTRACT !== $ack['contract_version'] || 'acknowledged' !== $ack['status'] || 'verified_transfer_only' !== $ack['acknowledgement_scope'] ) {
			return self::error( 'delivery_ack_contract_invalid', __( 'The Cloud delivery acknowledgement posture is invalid.', 'npcink-abilities-toolkit' ), 502 );
		}
		if ( $transfer_evidence['delivery_id'] !== $ack['delivery_id'] || $proposal['artifact_id'] !== $ack['artifact_id'] ) {
			return self::error( 'delivery_ack_identity_mismatch', __( 'The Cloud delivery acknowledgement references a different transfer.', 'npcink-abilities-toolkit' ), 409 );
		}
		if ( $filesize !== $ack['received_byte_size'] || $checksum !== $ack['received_checksum'] ) {
			return self::error( 'delivery_ack_integrity_mismatch', __( 'The Cloud delivery acknowledgement did not match the received bytes.', 'npcink-abilities-toolkit' ), 409 );
		}
		if ( true !== $ack['byte_size_verified'] || true !== $ack['checksum_verified'] || ! is_bool( $ack['idempotent_replay'] ) ) {
			return self::error( 'delivery_ack_verification_incomplete', __( 'The Cloud delivery acknowledgement integrity checks are incomplete.', 'npcink-abilities-toolkit' ), 409 );
		}

		$acknowledged_at = is_string( $ack['acknowledged_at'] ) ? self::expiry_timestamp( $ack['acknowledged_at'] ) : 0;
		$artifact_expires_at = is_string( $ack['artifact_expires_at'] ) ? self::expiry_timestamp( $ack['artifact_expires_at'] ) : 0;
		$ack_deadline_at = self::expiry_timestamp( $transfer_evidence['ack_deadline_at'] );
		$proposal_expires_at = self::expiry_timestamp( $proposal['expires_at'] );
		if ( $acknowledged_at <= 0 || $artifact_expires_at <= time() ) {
			return self::error( 'delivery_ack_timestamps_invalid', __( 'The Cloud delivery acknowledgement timestamps are invalid or expired.', 'npcink-abilities-toolkit' ), 410 );
		}
		if ( $acknowledged_at > $ack_deadline_at || $artifact_expires_at <= $acknowledged_at || $artifact_expires_at !== $proposal_expires_at ) {
			return self::error( 'delivery_ack_timeline_invalid', __( 'The Cloud delivery acknowledgement timeline is inconsistent.', 'npcink-abilities-toolkit' ), 409 );
		}

		return $ack;
	}

	/**
	 * @param mixed $value Raw filename basis.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize_filename_basis( $value ) {
		if ( ! is_array( $value ) ) {
			return self::error( 'filename_basis_invalid', __( 'The derivative filename basis must be an object.', 'npcink-abilities-toolkit' ) );
		}
		$keys_valid = self::validate_exact_keys( $value, array( 'owner', 'strategy', 'final_sanitize_unique_required' ), 'filename_basis_fields_invalid', __( 'The derivative filename basis does not match the required contract.', 'npcink-abilities-toolkit' ) );
		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		if (
			'wordpress_write_ability_final' !== $value['owner']
			|| 'format_checksum' !== $value['strategy']
			|| true !== $value['final_sanitize_unique_required']
		) {
			return self::error( 'filename_basis_value_invalid', __( 'The derivative filename basis does not preserve local WordPress naming authority.', 'npcink-abilities-toolkit' ) );
		}

		return $value;
	}

	/**
	 * @param mixed $value Raw warnings.
	 * @return array<int,string>|\WP_Error
	 */
	private static function normalize_processing_warnings( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 20 ) {
			return self::error( 'processing_warnings_invalid', __( 'Derivative processing warnings are invalid.', 'npcink-abilities-toolkit' ) );
		}
		$warnings = array();
		foreach ( $value as $warning ) {
			if ( ! is_string( $warning ) || strlen( $warning ) > 200 ) {
				return self::error( 'processing_warnings_invalid', __( 'Derivative processing warnings are invalid.', 'npcink-abilities-toolkit' ) );
			}
			$normalized_warning = sanitize_text_field( $warning );
			if ( $normalized_warning !== $warning ) {
				return self::error( 'processing_warnings_invalid', __( 'Derivative processing warnings are invalid.', 'npcink-abilities-toolkit' ) );
			}
			if ( '' !== $normalized_warning ) {
				$warnings[] = $normalized_warning;
			}
		}

		return $warnings;
	}

	/**
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected keys.
	 * @param string              $suffix Error suffix.
	 * @param string              $message Error message.
	 * @param int                 $status Status.
	 * @return true|\WP_Error
	 */
	private static function validate_exact_keys( array $value, array $expected, $suffix, $message, $status = 400 ) {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			return self::error( $suffix, $message, $status );
		}

		return true;
	}

	/**
	 * Sanitizes one optional basename in WordPress and isolated tests.
	 *
	 * @param string $value Raw basename.
	 * @return string
	 */
	private static function sanitize_file_name( $value ) {
		if ( function_exists( 'sanitize_file_name' ) ) {
			return sanitize_file_name( $value );
		}

		$value = basename( $value );

		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $value );
	}

	/**
	 * Returns one stable non-secret validation error.
	 *
	 * @param string              $suffix Error suffix.
	 * @param string              $message Error message.
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $data Additional safe data.
	 * @return \WP_Error
	 */
	private static function error( $suffix, $message, $status = 400, array $data = array() ) {
		$data['status'] = absint( $status );

		return new \WP_Error(
			'npcink_abilities_toolkit_cloud_artifact_' . sanitize_key( (string) $suffix ),
			$message,
			$data
		);
	}
}
