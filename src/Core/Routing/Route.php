<?php

namespace App\Core\Routing;

use App\Utils\LogHelper;

/**
 * Route
 * Represents a single route
 */
class Route
{
    private $methods;
    private $path;
    private $handler;
    private $middleware;
    private $params = [];

    public function __construct(array $methods, string $path, $handler, array $middleware = [])
    {
        $this->methods = $methods;
        $this->path = $path;
        $this->handler = $handler;
        $this->middleware = $middleware;
    }

    /**
     * Check if route matches URI
     */
    public function matches(string $uri): bool
    {
        // Convert route path to regex
        $pattern = $this->path;
        
        // Replace {param} with regex
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $pattern);
        
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $pattern);
        
        // Add start and end anchors
        $pattern = '/^' . $pattern . '$/';

        if (preg_match($pattern, $uri, $matches)) {
            // Extract parameter names
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $this->path, $paramNames);
            
            // Map parameter values
            $this->params = [];
            if (!empty($paramNames[1])) {
                foreach ($paramNames[1] as $index => $name) {
                    $this->params[$name] = $matches[$index + 1] ?? null;
                }
            }
            
            return true;
        }

        return false;
    }

    /**
     * Execute route handler
     */
    public function execute(string $uri)
    {
        if (is_callable($this->handler)) {
            return call_user_func($this->handler, $this->params);
        } elseif (is_string($this->handler) && file_exists($this->handler)) {
            // Include file and pass params
            extract($this->params);
            return require $this->handler;
        } elseif (is_array($this->handler) && count($this->handler) === 2) {
            // [Class, method] format
            [$class, $method] = $this->handler;
            $instance = new $class();
            return $instance->$method(...array_values($this->params));
        }

        LogHelper::error('Invalid route handler', [
            'handler_type' => gettype($this->handler),
        ]);
        throw new \Exception("Invalid route handler");
    }

    /**
     * Get middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get parameters
     */
    public function getParams(): array
    {
        return $this->params;
    }
}

