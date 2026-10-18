<?php

declare(strict_types=1);

namespace NnSpotty\Http;

final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->map('POST', $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        if ($method === 'HEAD' && isset($this->routes['GET'][$path])) {
            $response = ($this->routes['GET'][$path])($request);

            return $response->withoutBody();
        }

        if (isset($this->routes[$method][$path])) {
            return ($this->routes[$method][$path])($request);
        }

        foreach ($this->routes as $allowedMethod => $methodRoutes) {
            if ($allowedMethod !== $method && isset($methodRoutes[$path])) {
                return Response::json(['error' => 'Method Not Allowed'], 405);
            }
        }

        return Response::notFound();
    }

    private function map(string $method, string $path, callable $handler): void
    {
        $upperMethod = strtoupper($method);
        $this->routes[$upperMethod][$path] = $handler;
    }
}
