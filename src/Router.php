<?php

namespace Xenos;

use Xenos\Renderer;

class Router
{
	private static array $routes = [];
	private static string $prefix = '';
	private static ?string $parentView = null;

	// 🧩 Middleware storage
	private static array $middlewares = [];

	// ---------------------
	// Register middleware
	// ---------------------
	public static function middleware(string $name, callable $callback)
	{
		self::$middlewares[$name] = [
			'callback' => $callback,
			'only' => [],
			'except' => []
		];
	}

	// ---------------------
	// Assign middleware conditions (like prefix-based)
	// ---------------------
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

	// ---------------------
	// Run middlewares (with path filtering)
	// ---------------------
	private static function runMiddlewares(string $uri)
	{
		foreach (self::$middlewares as $name => $middleware) {
			$callback = $middleware['callback'] ?? null;
			$only = $middleware['only'] ?? [];
			$except = $middleware['except'] ?? [];

			if (!is_callable($callback)) continue;

			// 🧠 Skip if path excluded
			foreach ($except as $path) {
				if (str_starts_with($uri, $path)) {
					continue 2; // skip this middleware
				}
			}

			// ✅ Run only for matching paths (if any "only" is set)
			if (!empty($only)) {
				foreach ($only as $path) {
					if (str_starts_with($uri, $path)) {
						call_user_func($callback);
						continue 2;
					}
				}
				continue; // skip if no match
			}

			// No filter → run globally
			call_user_func($callback);
		}
	}

	public static function get(string $path, string $view, array $options = [])
	{
		$fullPath = self::normalizePath(self::$prefix . '/' . $path);

		self::$routes['GET'][$fullPath] = [
			'view' => $view,
			'parent' => self::$parentView,
			'isBlade' => $options['isBlade'] ?? false,
			'vars' => $options['vars'] ?? [],
			'middleware' => $options['middleware'] ?? [],
		];
	}

	public static function post(string $path, callable|array|string $callback, array $options = [])
	{
		$fullPath = self::normalizePath(self::$prefix . '/api/' . $path);

		self::$routes['POST'][$fullPath] = [
			'callback' => $callback,
			'parent' => self::$parentView,
			'middleware' => $options['middleware'] ?? [],
		];
	}

	// ---------------------
	// Group routes with prefix/parent
	// ---------------------
	public static function group(array $options, callable $callback)
	{
		$previousPrefix = self::$prefix;
		$previousParent = self::$parentView;

		self::$prefix .= $options['prefix'] ?? '';
		self::$parentView = $options['parent'] ?? null;

		$callback();

		self::$prefix = $previousPrefix;
		self::$parentView = $previousParent;
	}

	// ---------------------
	// Dispatch current request
	// ---------------------
	public static function dispatch()
	{
		$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$uri = self::normalizePath($uri);
		$method = $_SERVER['REQUEST_METHOD'];

		// --- Match route ---
		if (!isset(self::$routes[$method][$uri])) {
			$otherMethod = $method === 'POST' ? 'GET' : 'POST';
			if (isset(self::$routes[$otherMethod][$uri])) {
				http_response_code(405);
				echo "<h1>405 Method Not Allowed</h1>";
			} else {
				http_response_code(404);
				echo "<h1>404 Not Found</h1>";
			}
			return;
		}

		$route = self::$routes[$method][$uri];

		$callback = $route['callback'] ?? $route['view'] ?? null;
		$parentView = $route['parent'] ?? null;
		$vars = $route['vars'] ?? [];

		// --- Run global & route-specific middleware ---
		// 1. Run global path-filtered middleware
		self::runMiddlewares($uri);

		// 2. Run route-specific middleware
		$routeMiddleware = $route['middleware'] ?? [];
		foreach ($routeMiddleware as $name) {
			if (isset(self::$middlewares[$name]) && is_callable(self::$middlewares[$name]['callback'])) {
				call_user_func(self::$middlewares[$name]['callback']);
			}
		}

		// --- Collect POST/JSON data ---
		$data = [];
		if ($method === 'POST') {
			$input = file_get_contents('php://input');
			$json = json_decode($input, true);
			$data = is_array($json) && !empty($json) ? $json : $_POST;
		}

		// --- Handle callable closure ---
		if (is_callable($callback)) {
			call_user_func($callback, $data);
			return;
		}

		// --- Handle [Class::class, 'method'] ---
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

		// --- Handle Blade view ---
		if (!empty($route['isBlade']) && $route['isBlade'] && $callback) {
			self::renderBlade($callback, $vars);
			return;
		}

		// --- Handle normal view ---
		if ($parentView) {
			include $parentView;
		} elseif ($callback) {
			include $callback;
		}
	}

	// ---------------------
	// Helper: is route active
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
	// Normalize path helper
	// ---------------------
	private static function normalizePath(string $path): string
	{
		$path = '/' . trim($path, '/');
		return $path === '/' ? '/' : rtrim($path, '/');
	}

	// ---------------------
	// Define Blade view
	// ---------------------
	public static function view(string $path, string $bladeFile, array $vars = [])
	{
		$fullPath = self::normalizePath(self::$prefix . '/' . $path);

		self::$routes['GET'][$fullPath] = [
			'view' => $bladeFile,
			'vars' => $vars,
			'parent' => self::$parentView,
			'isBlade' => true
		];
	}

	private static function showError(array $errors)
	{
		self::renderBlade(__DIR__ . '/../../views/errors/blade-error.blade.php', $errors);
	}

	// ---------------------
	// Mini Blade renderer (Page and Child)
	// ---------------------
	public static function renderBlade(string $file, array $vars = [])
	{
		extract($vars);
		$content  = Renderer::preProcessBlade($file);
		$sections = Renderer::extractSections($content);
		$content  = Renderer::removeSectionBlocks($content);
		$content  = Renderer::renderLayout($content, $sections);
		$content  = Renderer::replaceErrorBlocks($content);

		// Components renderer
		// $content = Renderer::renderBladeComponents($content);

		// Remove multiple blank lines
		// For now lets test if no have this
		// $content = preg_replace("/(\r?\n){2,}/", "\n", $content);

		$content = Renderer::replaceBladeDirectives($content);

		// 🔹 Replace {{ $var }} with echo
		$content = Renderer::replaceBladeEchoes($content);

		// Remove any leftover if not compiled yet
		$content = Renderer::removeLeftOvers($content);

		// 🔹 Evaluate the final compiled PHP
		Renderer::render($content);
	}
}
