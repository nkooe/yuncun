<?php
/**
 * 极简路由：静态路径 + {name} 占位参数，支持中间件数组。
 */
declare(strict_types=1);

class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:mixed, middlewares:array}> */
    private array $routes = [];
    private array $fallbacks = ['404' => null, 'error' => null];

    public function get(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function any(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function fallback(string $kind, mixed $handler): void
    {
        if (array_key_exists($kind, $this->fallbacks)) {
            $this->fallbacks[$kind] = $handler;
        }
    }

    private function add(string $method, string $path, mixed $handler, array $middlewares): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        $names = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '([^/]+)';
            },
            $path
        );
        $this->routes[] = [
            'method'      => $method,
            'pattern'     => $path,
            'regex'       => '#^' . $regex . '$#u',
            'names'       => $names,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        // 剥离部署子目录前缀
        $bp = base_path();
        if ($bp !== '' && str_starts_with($path, $bp)) {
            $path = substr($path, strlen($bp));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            // HEAD 复用 GET
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $methodMatched = true;
                continue;
            }
            array_shift($m);
            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = $m[$i] ?? '';
            }
            $this->runHandler($route['handler'], $route['middlewares'], $params);
            return;
        }

        if ($methodMatched) {
            $this->invoke($this->fallbacks['error'] ?? null, 405, ['path' => $path]);
            return;
        }
        $this->invoke($this->fallbacks['404'] ?? null, 404, ['path' => $path]);
    }

    private function runHandler(mixed $handler, array $middlewares, array $params): void
    {
        foreach ($middlewares as $mw) {
            if (is_callable($mw)) {
                $mw();
            } elseif (is_string($mw) && method_exists(Auth::class, $mw)) {
                Auth::$mw();
            }
        }
        $this->execute($handler, $params);
    }

    private function execute(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler(...array_values($params));
            return;
        }
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $action] = explode('@', $handler, 2);
            if (!class_exists($class)) {
                throw new RuntimeException("Controller not found: {$class}");
            }
            $obj = new $class();
            if (!method_exists($obj, $action)) {
                throw new RuntimeException("Action not found: {$class}@{$action}");
            }
            $obj->{$action}(...array_values($params));
            return;
        }
        throw new RuntimeException('Invalid route handler');
    }

    private function invoke(mixed $handler, int $code, array $ctx): void
    {
        http_response_code($code);
        if (is_callable($handler)) {
            $handler($ctx);
            return;
        }
        if ($code === 404) {
            view('errors/404', [], 'front');
            return;
        }
        echo 'Error ' . $code;
    }
}
