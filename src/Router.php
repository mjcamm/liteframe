<?php

class Router
{
    private array $routes = [];
    private array $registeredPaths = [];

    public function addRoute(string $name, array $route): void
    {
        $key = $route['path'] . '|' . $route['method'];
        if (isset($this->registeredPaths[$key])) {
            return;
        }
        $this->registeredPaths[$key] = true;
        $this->routes[$name] = $route;
    }

    /**
     * Match a request URI and method against registered routes.
     * Returns the matched route array or null.
     */
    public function match(string $uri, string $method): ?array
    {
        foreach ($this->routes as $name => $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $uri);
            if ($params !== null) {
                return array_merge($route, [
                    'name' => $name,
                    'params' => $params,
                ]);
            }
        }

        return null;
    }

    /**
     * Match a route path pattern against a URI.
     * Supports :param placeholders.
     * Returns param array on match, null on no match.
     */
    private function matchPath(string $pattern, string $uri): ?array
    {
        // Replace :param placeholders with tokens, escape static parts, then restore
        $placeholders = [];
        $tokenized = preg_replace_callback('/:([a-zA-Z_]+)/', function ($m) use (&$placeholders) {
            $token = "__PARAM{$m[1]}__";
            $placeholders[$token] = '(?P<' . $m[1] . '>[^/]+)';
            return $token;
        }, $pattern);
        $escaped = preg_quote($tokenized, '#');
        $regex = str_replace(array_map(fn($t) => preg_quote($t, '#'), array_keys($placeholders)), array_values($placeholders), $escaped);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Extract only named params
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }
}
