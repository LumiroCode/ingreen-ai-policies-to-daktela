<?php

declare(strict_types=1);

final class FakeDaktela
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    /**
     * @param array<string, array{status:int,headers:array<string,string>,body:string}|callable(string,string,array<string,string>,?string): array{status:int,headers:array<string,string>,body:string}> $routes
     */
    public function __construct(private readonly array $routes)
    {
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $route = $this->routes[$path] ?? ['status' => 404, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];

        return is_callable($route) ? $route($method, $url, $headers, $body) : $route;
    }
}
