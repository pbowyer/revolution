<?php
declare(strict_types=1);

namespace Boffinate\Twig\Proxy;

use Boffinate\Twig\Twig;
use MODX\Revolution\modChunk;
use ReflectionClass;

class modChunkTwig // Oh fuck there's instanceof checks so this needs to have modChunk as its parent
{
    public mixed $processProperties = null;

    public function __construct(private modChunk $wrappedClass, private Twig $twig)
    {
    }

    /**
     * This method is the reason the class exists and I'm jumping through all these steps.
     * I can't change the class the CMS creates (modChunk).
     * I can get it and wrap it in order to add this method to support Twig
     * I can pass modChunkTwig around in place of the intercepted modChunk
     * BUT... I can't pass the `instanceof modChunk` checks like this
     */
    public function process($properties = null, $content = null)
    {
        if (is_string($content)) {
            $content = $this->twig->renderString($content, (array)$properties);
        }
        //$this->processProperties = $properties;
        $response = $this->wrappedClass->process($properties, $content);
        //$this->processProperties = null;
        return $response;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->wrappedClass->$name(...$arguments);
    }

    public function __get(string $name)
    {
        if ($this->isConstant($name)) {
            return constant(get_class($this->wrappedClass) . '::' . $name);
        }
        return $this->wrappedClass->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->wrappedClass->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->wrappedClass->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->wrappedClass->$name);
    }

    private function isConstant(string $name): bool
    {
        $reflectionClass = new ReflectionClass($this->wrappedClass);
        return $reflectionClass->hasConstant($name);
    }
}
