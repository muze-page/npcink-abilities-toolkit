<?php
/** Validates revision-bound external Docker compatibility evidence. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$path = (string) ( $argv[1] ?? getenv( 'NPCINK_TOOLKIT_WORDPRESS_SMOKE_EVIDENCE' ) ?: '' );

if ( '' === $path || ! is_readable( $path ) ) {
	fwrite( STDERR, "A readable M4 WordPress smoke evidence file is required.\n" );
	exit( 2 );
}

$json = file_get_contents( $path );
$data = false !== $json ? json_decode( $json, true ) : null;
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "M4 WordPress smoke evidence is not valid JSON.\n" );
	exit( 1 );
}

$head_output = array();
$head_status = 0;
exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD 2>/dev/null', $head_output, $head_status );
$head = trim( implode( "\n", $head_output ) );
$archive_output = array();
$archive_status = 0;
exec( 'git -C ' . escapeshellarg( $root ) . ' archive --format=tar HEAD 2>/dev/null | shasum -a 256', $archive_output, $archive_status );
$archive_sha256 = '';
if ( 0 === $archive_status && preg_match( '/^([0-9a-f]{64})\b/', trim( implode( "\n", $archive_output ) ), $archive_matches ) ) {
	$archive_sha256 = $archive_matches[1];
}

$failures = array();
if ( 0 !== $head_status || 1 !== preg_match( '/^[0-9a-f]{40}$/', $head ) ) {
	$failures[] = 'current Git HEAD could not be resolved';
}
if ( 'npcink_toolkit_wordpress_smoke_evidence.v1' !== ( $data['schema_version'] ?? null ) ) {
	$failures[] = 'schema_version is unsupported';
}
if ( 'm4-docker' !== ( $data['runner'] ?? null ) ) {
	$failures[] = 'runner must be m4-docker';
}
if ( $head !== ( $data['source_revision'] ?? null ) ) {
	$failures[] = 'source_revision does not match current HEAD';
}
if ( '' === $archive_sha256 || $archive_sha256 !== ( $data['source_archive_sha256'] ?? null ) ) {
	$failures[] = 'source_archive_sha256 does not match current HEAD';
}
if ( '' === trim( (string) ( $data['docker_server_version'] ?? '' ) ) ) {
	$failures[] = 'docker_server_version is missing';
}
if ( false === strtotime( (string) ( $data['generated_at'] ?? '' ) ) ) {
	$failures[] = 'generated_at is missing or invalid';
}

$required_profiles = array(
	'wordpress-6.9.4-php-8.0' => array( 'wordpress' => '6.9.4', 'php' => '8.0' ),
	'wordpress-7.0-php-8.5'  => array( 'wordpress' => '7.0', 'php' => '8.5' ),
);
$profiles = is_array( $data['profiles'] ?? null ) ? $data['profiles'] : array();
foreach ( $required_profiles as $profile_id => $expected ) {
	$profile = is_array( $profiles[ $profile_id ] ?? null ) ? $profiles[ $profile_id ] : array();
	if ( 'passed' !== ( $profile['status'] ?? null )
		|| $expected['wordpress'] !== ( $profile['wordpress'] ?? null )
		|| $expected['php'] !== ( $profile['php'] ?? null )
		|| (int) ( $profile['assertions'] ?? 0 ) < 1
	) {
		$failures[] = 'required profile failed validation: ' . $profile_id;
	}
}

if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'M4 evidence rejected: ' . $failure . "\n" );
	}
	exit( 1 );
}

printf(
	"Revision-bound M4 WordPress smoke evidence: ok (%s, Docker %s)\n",
	$head,
	(string) $data['docker_server_version']
);
