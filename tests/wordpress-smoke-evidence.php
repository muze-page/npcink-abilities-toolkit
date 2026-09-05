<?php
/** Regression checks for revision-bound external Docker smoke evidence. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$head = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );
$archive_output = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' archive --format=tar HEAD | shasum -a 256' ) );
$archive_sha256 = 1 === preg_match( '/^([0-9a-f]{64})\b/', $archive_output, $archive_matches ) ? $archive_matches[1] : '';
$path = tempnam( sys_get_temp_dir(), 'npcink-toolkit-evidence-' );
if ( false === $path || 1 !== preg_match( '/^[0-9a-f]{40}$/', $head ) || '' === $archive_sha256 ) {
	fwrite( STDERR, "FAIL: Test fixture setup failed.\n" );
	exit( 1 );
}

$base = array(
	'schema_version'       => 'npcink_toolkit_wordpress_smoke_evidence.v1',
	'runner'               => 'm4-docker',
	'source_revision'      => $head,
	'source_archive_sha256' => $archive_sha256,
	'docker_server_version' => '29.7.2',
	'generated_at'         => '2026-09-05T00:00:00Z',
	'profiles'             => array(
		'wordpress-6.9.4-php-8.0' => array( 'wordpress' => '6.9.4', 'php' => '8.0', 'assertions' => 441, 'status' => 'passed' ),
		'wordpress-7.0-php-8.5'  => array( 'wordpress' => '7.0', 'php' => '8.5', 'assertions' => 441, 'status' => 'passed' ),
	),
);

$run = static function ( array $evidence ) use ( $root, $path ): int {
	file_put_contents( $path, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	$status = 0;
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/scripts/check-wordpress-smoke-evidence.php' ) . ' ' . escapeshellarg( $path ) . ' >/dev/null 2>&1', $status );
	return $status;
};

if ( 0 !== $run( $base ) ) {
	fwrite( STDERR, "FAIL: Exact revision evidence was rejected.\n" );
	exit( 1 );
}

$stale                    = $base;
$stale['source_revision'] = str_repeat( '0', 40 );
if ( 0 === $run( $stale ) ) {
	fwrite( STDERR, "FAIL: Stale revision evidence was accepted.\n" );
	exit( 1 );
}

$missing_profile = $base;
unset( $missing_profile['profiles']['wordpress-7.0-php-8.5'] );
if ( 0 === $run( $missing_profile ) ) {
	fwrite( STDERR, "FAIL: Incomplete compatibility evidence was accepted.\n" );
	exit( 1 );
}

$syntax_status = 0;
passthru( 'bash -n ' . escapeshellarg( $root . '/scripts/run-m4-wordpress-smoke.sh' ), $syntax_status );
unlink( $path );
if ( 0 !== $syntax_status ) {
	fwrite( STDERR, "FAIL: M4 smoke runner has invalid shell syntax.\n" );
	exit( 1 );
}

$runner = file_get_contents( $root . '/scripts/run-m4-wordpress-smoke.sh' );
if ( false === $runner || false === strpos( $runner, 'git ls-files -z' ) || false === strpos( $runner, 'distribution.tar' ) ) {
	fwrite( STDERR, "FAIL: M4 runner does not separate the tracked test workspace from the export-ignored distribution archive.\n" );
	exit( 1 );
}

echo "Revision-bound WordPress smoke evidence behavior: ok\n";
