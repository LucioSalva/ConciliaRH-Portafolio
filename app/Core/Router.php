<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use InvalidArgumentException;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,params:list<string>,handler:array{0:class-string,1:string},middleware:list<string>,name:?string}> */
    private array $routes = [];

    /** @var list<string> */
    private array $groupMiddleware = [];

    /** @var array<string,string> */
    private array $named = [];

    /** @param array{0:class-string,1:string} $handler @param list<string> $middleware */
    public function get(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param array{0:class-string,1:string} $handler @param list<string> $middleware */
    public function post(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware @param callable(self):void $routes */
    public function group(array $middleware, callable $routes): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = [...$previous, ...$middleware];
        $routes($this);
        $this->groupMiddleware = $previous;
    }

    /** @param array{0:class-string,1:string} $handler @param list<string> $middleware */
    public function add(string $method, string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([1-9][0-9]{0,18})';
        }, $path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $path,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
            'name' => $name,
        ];
        if ($name !== null) {
            $this->named[$name] = $path;
        }
    }

    /** @return array{route:array{method:string,pattern:string,regex:string,params:list<string>,handler:array{0:class-string,1:string},middleware:list<string>,name:?string},params:array<string,int>} */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = (int) $m[$i + 1];
            }
            return ['route' => $route, 'params' => $params];
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'METODO_NO_PERMITIDO', 'Método no permitido.');
        }
        throw HttpException::notFound();
    }

    /** @param array<string,int|string> $params */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException('Ruta no definida: ' . $name);
        }
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m) => (string) ($params[$m[1]] ?? ''), $this->named[$name]);
    }
}
