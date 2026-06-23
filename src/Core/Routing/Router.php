<?php

namespace App\Core\Routing;

/**
 * Router
 * Handles request routing
 */
class Router
{
    private $routes = [];
    private $middleware = [];

    /**
     * Add GET route
     */
    public function get(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Add POST route
     */
    public function post(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Add PUT route
     */
    public function put(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Add DELETE route
     */
    public function delete(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Add route for any method
     */
    public function any(string $path, $handler, array $middleware = []): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $path, $handler, $middleware);
    }

    /**
     * Add route
     */
    private function addRoute($methods, string $path, $handler, array $middleware = []): Route
    {
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        $route = new Route($methods, $path, $handler, $middleware);
        
        foreach ($methods as $method) {
            $this->routes[$method][] = $route;
        }

        return $route;
    }

    /**
     * Dispatch request
     */
    public function dispatch(string $method, string $uri)
    {
        // Remove query string
        $uri = parse_url($uri, PHP_URL_PATH);

        // Strip the base path so the router works whether the app is installed at
        // the domain root (/index.php → base = "") or in a subdirectory
        // (/www/projects/.../public/index.php → base = /www/projects/.../public).
        // SCRIPT_NAME always points to the entry-point file, so dirname() gives the base.
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($scriptDir && $scriptDir !== '/' && strpos($uri, $scriptDir) === 0) {
            $uri = substr($uri, strlen($scriptDir));
        } elseif (strpos($uri, '/public/') !== false) {
            // Fallback: strip up to and including /public
            $uri = substr($uri, strpos($uri, '/public/') + strlen('/public'));
        }

        // Normalize URI
        $uri = '/' . ltrim($uri ?: '/', '/');
        $uri = rtrim($uri, '/') ?: '/';

        // HEAD is identical to GET per HTTP spec — fall back to GET handlers,
        // but suppress the response body at the end.
        $isHead   = ($method === 'HEAD');
        $lookupMethod = $isHead ? 'GET' : $method;

        $routes = $this->routes[$lookupMethod] ?? [];

        foreach ($routes as $route) {
            if ($route->matches($uri)) {
                // Execute middleware
                foreach ($route->getMiddleware() as $middleware) {
                    if (is_callable($middleware)) {
                        $result = $middleware();
                        if ($result === false) {
                            return false; // Middleware blocked request
                        }
                    }
                }

                // Execute route handler; for HEAD discard the body (headers still sent)
                if ($isHead) {
                    ob_start();
                    $result = $route->execute($uri);
                    ob_end_clean();
                    return $result;
                }
                return $route->execute($uri);
            }
        }

        // 404 Not Found
        \App\Utils\LogHelper::warning('Route not found', [
            'method' => $method,
            'uri' => $uri,
        ]);
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Route not found', 'path' => $uri]);
        return false;
    }

    /**
     * Get all routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

