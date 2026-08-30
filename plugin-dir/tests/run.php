<?php

declare( strict_types=1 );

$phpunit = dirname( __DIR__ ) . '/vendor/bin/phpunit';
$arguments = array_slice( $_SERVER['argv'], 1 );
$forceCompat = in_array( '--compat', $arguments, true );
$arguments = array_values( array_diff( $arguments, [ '--compat' ] ) );
$missingExtensions = array_values(
	array_filter(
		[ 'dom', 'mbstring', 'xmlwriter' ],
		static fn( string $extension ): bool => ! extension_loaded( $extension )
	)
);

if ( ! $forceCompat && is_file( $phpunit ) && empty( $missingExtensions ) ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $phpunit );

	foreach ( $arguments as $argument ) {
		$command .= ' ' . escapeshellarg( $argument );
	}

	passthru( $command, $status );
	exit( $status );
}

if ( ! $forceCompat ) {
	$reason = ! is_file( $phpunit )
		? 'PHPUnit is not installed'
		: 'PHPUnit platform extensions are missing: ' . implode( ', ', $missingExtensions );
	fwrite( STDERR, $reason . ". Running compatibility test harness.\n" );
}

require_once __DIR__ . '/bootstrap.php';

$testFiles = glob( __DIR__ . '/*Test.php' );
sort( $testFiles );

foreach ( $testFiles as $testFile ) {
	require_once $testFile;
}

$testClasses = array_values(
	array_filter(
		get_declared_classes(),
		static fn( string $class ): bool => is_subclass_of( $class, 'Cf7tg_TestCase' ) && str_ends_with( $class, 'Test' )
	)
);

$failures = [];
$count = 0;

foreach ( $testClasses as $class ) {
	$reflection = new ReflectionClass( $class );

	foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( ! str_starts_with( $method->getName(), 'test' ) ) {
			continue;
		}

		$count++;
		$test = $reflection->newInstance();
		$setUp = $reflection->getMethod( 'setUp' );
		$setUp->setAccessible( true );

		try {
			$setUp->invoke( $test );
			$method->invoke( $test );
			fwrite( STDOUT, '.' );
		} catch ( Throwable $e ) {
			$failures[] = [
				'test'    => $class . '::' . $method->getName(),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			];
			fwrite( STDOUT, 'F' );
		}

		if ( $reflection->hasMethod( 'tearDown' ) ) {
			$tearDown = $reflection->getMethod( 'tearDown' );
			$tearDown->setAccessible( true );
			$tearDown->invoke( $test );
		}
	}
}

fwrite( STDOUT, PHP_EOL . PHP_EOL . sprintf( 'Tests: %d, Failures: %d', $count, count( $failures ) ) . PHP_EOL );

foreach ( $failures as $failure ) {
	fwrite(
		STDOUT,
		sprintf(
			PHP_EOL . "%s\n%s\n%s:%d\n",
			$failure['test'],
			$failure['message'],
			$failure['file'],
			$failure['line']
		)
	);
}

exit( empty( $failures ) ? 0 : 1 );
