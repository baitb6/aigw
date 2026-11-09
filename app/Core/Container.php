<?php
/**
 * 极简依赖注入容器：单例注册 + 反射自动装配 + 方法参数注入
 */

declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, callable> */
    private array $bindings = [];

    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
    }

    public function make(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $instance = ($this->bindings[$abstract])($this);
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        if (!class_exists($abstract)) {
            throw new RuntimeException("无法解析：{$abstract}");
        }

        $reflection = new ReflectionClass($abstract);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("类不可实例化：{$abstract}");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $instance = new $abstract();
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->make($type->getName());
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
                $args[] = [];
                continue;
            }
            throw new RuntimeException("无法注入参数 \${$param->getName()} @ {$abstract}");
        }

        $instance = $reflection->newInstanceArgs($args);
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * 调用 callable，自动注入类型化参数，其余位置按 $extras 顺序填充
     */
    public function call($callable, array $extras = [])
    {
        $reflection = is_array($callable)
            ? new ReflectionMethod($callable[0], $callable[1])
            : new ReflectionMethod($callable, '__invoke');

        if (is_string($callable) && str_contains($callable, '::')) {
            $reflection = new ReflectionMethod($callable);
        }

        $args = [];
        $extras = array_values($extras);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                // 优先复用调用方传入的同类实例（如带路由参数的 Request），
                // 避免容器重新 new 出一个空对象导致 routeParam 丢失。
                foreach ($extras as $i => $extra) {
                    if ($extra instanceof $class) {
                        $args[] = $extra;
                        unset($extras[$i]);
                        $extras = array_values($extras);
                        continue 2;
                    }
                }

                $args[] = $this->make($class);
                continue;
            }

            if (!empty($extras)) {
                $candidate = array_shift($extras);
                if ($candidate !== null) {
                    $args[] = $candidate;
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            $args[] = null;
        }

        return $callable(...$args);
    }
}