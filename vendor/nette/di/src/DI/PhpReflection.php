<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\DI;

use Nette;


/**
 * PHP reflection helpers.
 * @internal
 * @deprecated
 */
class PhpReflection
{
	use Nette\StaticClass;

	/**
	 * Returns declaring class or trait.
	 * @return \ReflectionClass
	 */
	public static function getDeclaringClass(\ReflectionProperty $prop)
	{
		foreach ($prop->getDeclaringClass()->getTraits() as $trait) {
			if ($trait->hasProperty($prop->getName())) {
				return self::getDeclaringClass($trait->getProperty($prop->getName()));
			}
		}
		return $prop->getDeclaringClass();
	}

	/**
	 * @return string|null
	 */
	public static function getParameterType(\ReflectionParameter $param)
	{
		if (PHP_VERSION_ID >= 70000) {
			$type = $param->hasType() ? (string) $param->getType() : null;
			return strtolower($type) === 'self' ? $param->getDeclaringClass()->getName() : $type;
		} elseif ($param->isArray() || $param->isCallable()) {
			return $param->isArray() ? 'array' : 'callable';
		} else {
			try {
				return ($ref = $param->getClass()) ? $ref->getName() : null;
			} catch (\ReflectionException $e) {
				if (preg_match('#Class (.+) does not exist#', $e->getMessage(), $m)) {
					return $m[1];
				}
				throw $e;
			}
		}
	}

	/**
	 * @return string|null
	 */
	public static function getReturnType(\ReflectionFunctionAbstract $func)
	{
		if (PHP_VERSION_ID >= 70000 && $func->hasReturnType()) {
			$type = (string) $func->getReturnType();
			return strtolower($type) === 'self' ? $func->getDeclaringClass()->getName() : $type;
		}
		$type = preg_replace('#[|\s].*#', '', (string) self::parseAnnotation($func, 'return'));
		if ($type) {
			return $func instanceof \ReflectionMethod
				? self::expandClassName($type, $func->getDeclaringClass())
				: ltrim($type, '\\');
		}
	}

	/**
	 * Returns an annotation value.
	 * @return string|null
	 */
	public static function parseAnnotation(\Reflector $ref, $name)
	{
		static $ok;
		if (!$ok) {
			if (!(new \ReflectionMethod(__METHOD__))->getDocComment()) {
				throw new Nette\InvalidStateException('You have to enable phpDoc comments in opcode cache.');
			}
			$ok = true;
		}
		$name = preg_quote($name, '#');
		if ($ref->getDocComment() && preg_match("#[\\s*]@$name(?:\\s++([^@]\\S*)?|$)#", trim($ref->getDocComment(), '/*'), $m)) {
			return isset($m[1]) ? $m[1] : '';
		}
	}

	/**
	 * Expands class name into full name.
	 * @param  string
	 * @return string  full name
	 * @throws Nette\InvalidArgumentException
	 */
	public static function expandClassName($name, \ReflectionClass $rc)
	{
		$lower = strtolower($name);
		if (empty($name)) {
			throw new Nette\InvalidArgumentException('Class name must not be empty.');

		} elseif (self::isBuiltinType($lower)) {
			return $lower;

		} elseif ($lower === 'self' || $lower === 'static' || $lower === '$this') {
			return $rc->getName();

		} elseif ($name[0] === '\\') { // fully qualified name
			return ltrim($name, '\\');
		}

		$uses = self::getUseStatements($rc);
		$parts = explode('\\', $name, 2);
		if (isset($uses[$parts[0]])) {
			$parts[0] = $uses[$parts[0]];
			return implode('\\', $parts);

		} elseif ($rc->inNamespace()) {
			return $rc->getNamespaceName() . '\\' . $name;

		} else {
			return $name;
		}
	}

	/**
	 * @param  string
	 * @return bool
	 */
	public static function isBuiltinType($type)
	{
		return in_array(strtolower($type), ['string', 'int', 'float', 'bool', 'array', 'callable'], true);
	}

	/**
	 * @return array of [alias => class]
	 */
	public static function getUseStatements(\ReflectionClass $class)
	{
		static $cache = [];
		if (!isset($cache[$name = $class->getName()])) {
			if ($class->isInternal()) {
				$cache[$name] = [];
			} else {
				$code = file_get_contents($class->getFileName());
				$cache = self::parseUseStatements($code, $name) + $cache;
			}
		}
		return $cache[$name];
	}

	/**
	 * Parses PHP code.
	 * @param  string
	 * @return array of [class => [alias => class, ...]]
	 */
	public static function parseUseStatements($code, $forClass = null)
	{
		$tokens = token_get_all($code);
		$namespace = $class = $classLevel = $level = null;
		$res = $uses = [];

		while ($token = current($tokens)) {
			next($tokens);
			switch (is_array($token) ? $token[0] : $token) {
				case T_NAMESPACE:
					$namespace = ltrim(self::fetch($tokens, [T_STRING, T_NS_SEPARATOR]) . '\\', '\\');
					$uses = [];
					break;

				case T_CLASS:
				case T_INTERFACE:
				case T_TRAIT:
					if ($name = self::fetch($tokens, T_STRING)) {
						$class = $namespace . $name;
						$classLevel = $level + 1;
						$res[$class] = $uses;
						if ($class === $forClass) {
							return $res;
						}
					}
					break;

				case T_USE:
					while (!$class && ($name = self::fetch($tokens, [T_STRING, T_NS_SEPARATOR]))) {
						$name = ltrim($name, '\\');
						if (self::fetch($tokens, '{')) {
							while ($suffix = self::fetch($tokens, [T_STRING, T_NS_SEPARATOR])) {
								if (self::fetch($tokens, T_AS)) {
									$uses[self::fetch($tokens, T_STRING)] = $name . $suffix;
								} else {
									$tmp = explode('\\', $suffix);
									$uses[end($tmp)] = $name . $suffix;
								}
								if (!self::fetch($tokens, ',')) {
									break;
								}
							}

						} elseif (self::fetch($tokens, T_AS)) {
							$uses[self::fetch($tokens, T_STRING)] = $name;

						} else {
							$tmp = explode('\\', $name);
							$uses[end($tmp)] = $name;
						}
						if (!self::fetch($tokens, ',')) {
							break;
						}
					}
					break;

				case T_CURLY_OPEN:
				case T_DOLLAR_OPEN_CURLY_BRACES:
				case '{':
					$level++;
					break;

				case '}':
					if ($level === $classLevel) {
						$class = $classLevel = null;
					}
					$level--;
			}
		}

		return $res;
	}

	private static function fetch(&$tokens, $take)
	{
		$res = null;
		while ($token = current($tokens)) {
			list($token, $s) = is_array($token) ? $token : [$token, $token];
			if (in_array($token, (array) $take, true)) {
				$res .= $s;
			} elseif (!in_array($token, [T_DOC_COMMENT, T_WHITESPACE, T_COMMENT], true)) {
				break;
			}
			next($tokens);
		}
		return $res;
	}

	/**
	 * Returns class and all its descendants.
	 * @return string[]
	 */
	public static function getClassTree(\ReflectionClass $class)
	{
		$addTraits = function ($types) use (&$addTraits) {
			if ($traits = array_merge(...array_map('class_uses', array_values($types)))) {
				$types += $traits + $addTraits($traits);
			}
			return $types;
		};
		$class = $class->getName();
		return array_values($addTraits([$class] + class_parents($class) + class_implements($class)));
	}
}
