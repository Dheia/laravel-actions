<?php

namespace Lorisleiva\Actions\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionParameter;

trait ResolvesMethodDependencies
{
    protected function resolveAndCall($instance, $method, $save = true)
    {
        $parameters = $this->resolveMethodDependencies($instance, $method, $save);

        return $instance->{$method}(...$parameters);
    }

    protected function resolveMethodDependencies($instance, $method, $save = true)
    {
        if (! method_exists($instance, $method)) {
            return [];
        }

        $reflector = new ReflectionMethod($instance, $method);

        $handler = function ($parameter) use ($save) {
            return $this->resolveDependency($parameter, $save);
        };

        return array_map($handler, $reflector->getParameters());
    }

    protected function resolveDependency(ReflectionParameter $parameter, $save = true)
    {   
        list($key, $value) = $this->findAttributeFromParameter($parameter->name);
        $class = $parameter->getClass();

        if ($key && (! $class || $value instanceof $class->name)) {
            return $value;
        }

        if ($class) {
            return $this->resolveContainerDependency($class->name, $key, $value, $save);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
    }

    protected function resolveContainerDependency($class, $key, $value, $save = true)
    {
        $instance = app($class);

        if ($key && method_exists($instance, 'resolveRouteBinding')) {
            $instance = $this->resolveRouteBinding($instance, $value);
        }

        if ($key && $save) {
            $this->updateAttributeWithResolvedInstance($key, $instance);
        }

        return $instance;
    }

    protected function resolveRouteBinding($instance, $value)
    {
        if (! $model = $instance->resolveRouteBinding($value)) {
            throw (new ModelNotFoundException)->setModel(get_class($instance));
        }

        return $model;
    }

    protected function findAttributeFromParameter($name)
    {
        $attributes = $this->getAttributesForResolvingMethodDependencies();

        if (array_key_exists($name, $attributes)) {
            return [$name, $attributes[$name]];
        }
        if (array_key_exists($snakedName = Str::snake($name), $attributes)) {
            return [$snakedName, $attributes[$snakedName]];
        }
    }

    public function getAttributesForResolvingMethodDependencies()
    {
        if (! $this->runningAs('controller')) {
            return $this->attributes;
        }

        return array_merge($this->attributes, $this->getAttributesFromRoute($this->request));
    }

    public function updateAttributeWithResolvedInstance($key, $instance)
    {
        if ($this->runningAs('controller') && $this->request->has($key)) {
            return;
        }

        return $this->attributes[$key] = $instance;
    }
}