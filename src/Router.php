<?php

namespace Xenos;

class Router
{
	private static array $routes = [];
	private static array $middlewares = [];
	private static string $prefix = '';

	// ---------------------
	// Middleware registration
	// ---------------------
	public static function middleware(string $name, callable $callback)
	{
		self::$middlewares[$name] = [
			'callback' => $callback,
			'only' => [],
			'except' => []
		];
	}

	public static function middlewareFor(string $name, array $options = [])
	{
		if (!isset(self::$middlewares[$name])) return;

		if (isset($options['only'])) {
			self::$middlewares[$name]['only'] = (array) $options['only'];
		}

		if (isset($options['except'])) {
			self::$middlewares[$name]['except'] = (array) $options['except'];
		}
	}

	private static function runMiddlewares(string $uri)
	{
		foreach (self::$middlewares as $middleware) {
			$callback = $middleware['callback'] ?? null;
			$only = $middleware['only'] ?? [];
			$except = $middleware['except'] ?? [];

			if (!is_callable($callback)) continue;

			// Skip excluded paths
			foreach ($except as $path) {
				if (str_starts_with($uri, $path)) {
					continue 2;
				}
			}

			// Run only for matching paths
			if (!empty($only)) {
				foreach ($only as $path) {
					if (str_starts_with($uri, $path)) {
						call_user_func($callback);
						continue 2;
					}
				}
				continue;
			}

			// Run globally
			call_user_func($callback);
		}
	}

	// ---------------------
	// Register GET route
	// ---------------------
	public static function get(string $path, callable|array|string $callback)
	{
		$fullPath = self::normalizePath(self::$prefix . '/' . $path);
		self::$routes['GET'][$fullPath] = [
			'callback' => $callback,
			'middleware' => []
		];
	}

	// ---------------------
	// Register POST route
	// ---------------------
	public static function post(string $path, callable|array|string $callback)
	{
		$fullPath = self::normalizePath(self::$prefix . '/' . $path);
		self::$routes['POST'][$fullPath] = [
			'callback' => $callback,
			'middleware' => []
		];
	}

	// ---------------------
	// Route grouping with prefix
	// ---------------------
	public static function group(array $options, callable $callback)
	{
		$previousPrefix = self::$prefix;
		self::$prefix .= $options['prefix'] ?? '';

		$callback();

		self::$prefix = $previousPrefix;
	}

	// ---------------------
	// Dispatch the current request
	// ---------------------
	public static function dispatch()
	{
		$uri = self::normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
		$method = $_SERVER['REQUEST_METHOD'];

		if (!isset(self::$routes[$method][$uri])) {
			http_response_code(404);
			echo "<h1>404 Not Found</h1>";
			return;
		}

		$route = self::$routes[$method][$uri];
		$callback = $route['callback'] ?? null;

		// Run middlewares
		self::runMiddlewares($uri);

		// Collect POST/JSON data
		$data = [];
		if ($method === 'POST') {
			$input = file_get_contents('php://input');
			$json = json_decode($input, true);
			$data = is_array($json) ? $json : [];
			$data = array_merge($_POST, $data);
		}

		// Handle callable closure
		if (is_callable($callback)) {
			call_user_func($callback, $data);
			return;
		}

		// Handle [Class::class, 'method'] style
		if (is_array($callback) && count($callback) === 2) {
			[$class, $methodName] = $callback;
			if (class_exists($class) && method_exists($class, $methodName)) {
				$controller = new $class();
				$controller->$methodName($data);
				return;
			}

			http_response_code(500);
			echo "<h1>Controller or method not found</h1>";
			return;
		}
	}

	// ---------------------
	// Helper: check if route is active
	// ---------------------
	public static function isActive(string $path = '', bool $strict = false): bool
	{
		$uri = self::normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
		$path = self::normalizePath($path);

		return $strict
			? $uri === $path
			: ($uri === $path || str_starts_with($uri, rtrim($path, '/')));
	}

	// ---------------------
	// Normalize path
	// ---------------------
	private static function normalizePath(string $path): string
	{
		$path = '/' . trim($path, '/');
		return $path === '/' ? '/' : rtrim($path, '/');
	}
}
