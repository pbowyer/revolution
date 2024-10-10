<?php
declare(strict_types=1);

namespace Boffinate\Twig\Proxy;

use Boffinate\Twig\Twig;
use MODX\Revolution\modChunk;
use ReflectionClass;

/**
 * A pain we have to extend the whole of modChunk, but MODX has some `instanceof` checks we need to pass.
 * If only there was an interface to implement :)
 */
class modChunkTwig extends modChunk
{
    public function __construct(private modChunk $wrappedClass, private Twig $twig)
    {
        parent::__construct($twig->modx);
    }

    public function process($properties = null, $content = null)
    {
        $response = $this->wrappedClass->process($properties, $content);
        $response = $this->twig->renderString($response, (array)$this->wrappedClass->_properties);
        return $response;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->wrappedClass->$name(...$arguments);
    }

    public function __get($name)
    {
        if ($this->isConstant($name)) {
            return constant(get_class($this->wrappedClass) . '::' . $name);
        }
        return $this->wrappedClass->$name;
    }

    public function __set($name, mixed $value): void
    {
        $this->wrappedClass->$name = $value;
    }

    public function __isset($name): bool
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
