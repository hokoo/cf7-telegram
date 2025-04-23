<?php

namespace iTRON\cf7Telegram\Traits;

trait PropertyInitializationChecker {
	public function isPropertyInitialized(string $propertyName): bool
	{
		$reflectionClass = new \ReflectionClass($this);

		if (!$reflectionClass->hasProperty($propertyName)) {
			return false;
		}

		$property = $reflectionClass->getProperty($propertyName);

		$property->setAccessible(true);

		return $property->isInitialized($this);
	}
}
