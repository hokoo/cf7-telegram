<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

foreach ( glob( __DIR__ . '/*Test.php' ) as $test_file ) {
	require_once $test_file;
}

$test_classes = array_values(
	array_filter(
		get_declared_classes(),
		static fn( string $class ): bool => is_subclass_of( $class, 'Cf7tg_TestCase' ) && str_ends_with( $class, 'Test' )
	)
);

$failures = [];
$count = 0;

foreach ( $test_classes as $class ) {
	$reflection = new ReflectionClass( $class );

	foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( ! str_starts_with( $method->getName(), 'test' ) ) {
			continue;
		}

		$count++;
		$test = $reflection->newInstance();
		$set_up = $reflection->getMethod( 'setUp' );
		$set_up->setAccessible( true );

		try {
			$set_up->invoke( $test );
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
			$tear_down = $reflection->getMethod( 'tearDown' );
			$tear_down->setAccessible( true );
			$tear_down->invoke( $test );
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
