<?php
declare(strict_types=1);

namespace Boffinate\Twig;

use Boffinate\Twig\Proxy\modChunkTwig;
use MODX\Revolution\modChunk;
use MODX\Revolution\modX;
use ModxPro\PdoTools\CoreTools;
use ModxPro\PdoTools\Parsing\Parser;
use Twig\Environment;
use Twig\Extension\DebugExtension;

class Twig extends Parser
{
    /** @var modX $modx */
    public $modx;
    /** @var CoreTools $pdoTools */
    protected $pdoTools;

    private Environment $twig;

    public function __construct(modX $modx, CoreTools $pdoTools)
    {
        parent::__construct($modx, $pdoTools);

        $this->modx = $modx;
        $this->pdoTools = $pdoTools;
    }
    /**
     * Trying to process MODX pages with Fenom template engine
     *
     * @param string $parentTag
     * @param string $content
     * @param bool $processUncacheable
     * @param bool $removeUnprocessed
     * @param string $prefix
     * @param string $suffix
     * @param array $tokens
     * @param int $depth
     *
     * @return int
     */
    public function processElementTags(
        $parentTag,
        & $content,
        $processUncacheable = false,
        $removeUnprocessed = false,
        $prefix = "[[",
        $suffix = "]]",
        $tokens = array(),
        $depth = 0
    ) {
        //xdebug_break();
        if (is_string($content) && $processUncacheable
            && $this->modx->context->key !== 'mgr') {
            $this->init();
            $_processingUncacheable = $this->_processingUncacheable;
            $this->_processingUncacheable = true;
            $content = $this->renderString($content, []
//                array_merge(array_filter(
//                    $this->modx->placeholders,
//                    fn($v, $k) => !str_starts_with($k, '+'),
//                    ARRAY_FILTER_USE_BOTH
//                ), ['modx' => $this->modx])
            ); //
            $this->_processingUncacheable = $_processingUncacheable;
        }

        return parent::processElementTags($parentTag, $content, $processUncacheable, $removeUnprocessed, $prefix,
            $suffix, $tokens, $depth
        );
    }


    /**
     * Quickly processes a simple tag and returns the result.
     *
     * @param string $tag A full tag string parsed from content.
     * @param boolean $processUncacheable
     *
     * @return mixed The output of the processed element represented by the specified tag.
     */
    public function processTag($tag, $processUncacheable = true)
    {
        return parent::processTag($tag, $processUncacheable);
    }

    public function getElement($class, $name)
    {
        $obj = parent::getElement($class, $name);
        if ($obj instanceof modChunk) {
            return new modChunkTwig($obj, $this);
        }
        return $obj;
    }

    private function init()
    {
        if (isset($this->twig)) return;

        $loader = new \Twig\Loader\ArrayLoader([
        ]);
        $this->twig = new \Twig\Environment($loader, [
            'debug' => true,
        ]);
        $this->twig->addExtension(new DebugExtension());
        // TODO add event so ppl can register other extensions

        $this->twig->addGlobal('_modx', $this->modx);
    }

    public function renderString(string $content, array $placeholders)
    {
        $this->init();
        return $this->twig->render(
            $this->twig->createTemplate($content),
            $placeholders
        );
    }
}
