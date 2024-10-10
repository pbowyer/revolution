<?php
declare(strict_types=1);

namespace Boffinate\Twig\Proxy;

use Boffinate\Twig\Twig;
use MODX\Revolution\modChunk;
use MODX\Revolution\modElement;
use ReflectionClass;

class modChunkTwig extends modChunk
{
    public mixed $processProperties = null;

    public function __construct(private modChunk $wrappedClass, private Twig $twig)
    {
        parent::__construct($twig->modx);
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
        // We have to process Twig before Fenom which is why this comes first and duplicates some setup
        $reflectionMethod = new \ReflectionMethod(get_parent_class(get_parent_class($this->wrappedClass)),
            'process');
        echo $reflectionMethod->invoke($this->wrappedClass);
        if (is_string($this->wrappedClass->getContent())) {
            $parsedProperties = $this->wrappedClass->getProperties($properties);
            $twigRenderedContent = $this->twig->renderString($this->wrappedClass->getContent(), (array)$parsedProperties);
            $this->wrappedClass->_content = $twigRenderedContent;
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
