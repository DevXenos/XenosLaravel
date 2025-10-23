<?php

namespace Xenos;

class Router
{
    private static array $routes = [];
    private static array $middlewares = [];
    private static string $prefix = '';
    private static $notFoundHandler = null;
    private static $methodErrorHandler = null;

    // ---------------------
    // Register custom error handlers
    // ---------------------
    public static function notFound(callable|array|string $callback): void
    {
        self::$notFoundHandler = $callback;
    }

    public static function methodError(callable|array|string $callback): void
    {
        self::$methodErrorHandler = $callback;
    }

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

            foreach ($except as $path) {
                if (str_starts_with($uri, $path)) continue 2;
            }

            if (!empty($only)) {
                foreach ($only as $path) {
                    if (str_starts_with($uri, $path)) {
                        call_user_func($callback);
                        continue 2;
                    }
                }
                continue;
            }

            call_user_func($callback);
        }
    }

    // ---------------------
    // Register routes
    // ---------------------
    public static function get(string $path, callable|array|string $callback)
    {
        $fullPath = self::normalizePath(self::$prefix . '/' . $path);
        self::$routes['GET'][$fullPath] = [
            'callback' => $callback
        ];
    }

    public static function post(string $path, callable|array|string $callback)
    {
        $fullPath = self::normalizePath(self::$prefix . '/' . $path);
        self::$routes['POST'][$fullPath] = [
            'callback' => $callback
        ];
    }

    // ---------------------
    // Group routes
    // ---------------------
    public static function group(array $options, callable $callback)
    {
        $previousPrefix = self::$prefix;
        self::$prefix .= $options['prefix'] ?? '';
        $callback();
        self::$prefix = $previousPrefix;
    }

    // ---------------------
    // Dispatch
    // ---------------------
    public static function run()
    {
        $uri = self::normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $method = $_SERVER['REQUEST_METHOD'];

        // ✅ Run middlewares FIRST (before route check)
        self::runMiddlewares($uri);

        // Then check if the route exists
        if (!isset(self::$routes[$method][$uri])) {
            http_response_code(404);
            return self::handleNotFound();
        }

        $route = self::$routes[$method][$uri];
        $callback = $route['callback'] ?? null;

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
            return call_user_func($callback, $data);
        }

        // Handle [Class::class, 'method'] style
        if (is_array($callback) && count($callback) === 2) {
            [$class, $methodName] = $callback;
            if (class_exists($class) && method_exists($class, $methodName)) {
                $controller = new $class();
                return $controller->$methodName($data);
            }

            http_response_code(500);
            return self::handleMethodError();
        }

        // Handle string (view)
        if (is_string($callback)) {
            return \Xenos\Renderer::view($callback);
        }
    }

    // ---------------------
    // Custom error handling
    // ---------------------
    private static function handleNotFound()
    {
        if (!self::$notFoundHandler) {
            echo "<h1>404 - Page Not Found</h1>";
            return;
        }
        self::invokeHandler(self::$notFoundHandler);
    }

    private static function handleMethodError()
    {
        if (!self::$methodErrorHandler) {
            echo "<h1>500 - Controller or Method Not Found</h1>";
            return;
        }
        self::invokeHandler(self::$methodErrorHandler);
    }

    private static function invokeHandler($handler)
    {
        if (is_callable($handler)) {
            call_user_func($handler);
            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $methodName] = $handler;
            if (class_exists($class) && method_exists($class, $methodName)) {
                $controller = new $class();
                $controller->$methodName();
                return;
            }
        }

        if (is_string($handler)) {
            \Xenos\Renderer::view($handler);
            return;
        }
    }

    // ---------------------
    // Helper
    // ---------------------
    public static function isActive(string $path = '', bool $strict = false): bool
    {
        $uri = self::normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $path = self::normalizePath($path);

        return $strict
            ? $uri === $path
            : ($uri === $path || str_starts_with($uri, rtrim($path, '/')));
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
