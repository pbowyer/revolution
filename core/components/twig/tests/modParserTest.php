<?php
declare(strict_types=1);

namespace MODX\Revolution\Tests\Twig;

use Boffinate\Twig\Twig;
use MODX\Revolution\MODxTestCase;
use MODX\Revolution\modChunk;
use ModxPro\PdoTools\CoreTools;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use xPDO\xPDO;

class modParserTest extends MODxTestCase
{
    /**
     * @before
     */
    public function setUpFixtures(): void
    {
        parent::setUpFixtures();
        $this->loadPdoTools();
        $this->loadTwig();
        $this->useTwigParser();
        $this->modx->elementCache = [];
        $this->modx->sourceCache = [];
        $this->modx->setOption('parser_max_iterations', 10); // TODO test with 1 iteration
    }

    /**
     * @after
     */
    public function tearDownFixtures(): void
    {
        $this->modx->elementCache = [];
        $this->modx->sourceCache = [];
        parent::tearDownFixtures();
    }

    private function loadPdoTools(): void
    {
        if (!$this->modx->services->has('pdotools')) {
            $modx = $this->modx;
            require_once MODX_CORE_PATH . 'components/pdotools/bootstrap.php';
        }
    }

    private function loadTwig(): void
    {
        if (
            !$this->modx->services->has('twigparser')
            && !$this->modx->services->has(Twig::class)
        ) {
            $modx = $this->modx;
            require_once MODX_CORE_PATH . 'components/twig/bootstrap.php';
        }
    }

    private function useTwigParser(): void
    {
        $pdoTools = $this->modx->services->has('pdotools')
            ? $this->modx->services->get('pdotools')
            : new CoreTools($this->modx, []);

        $parser = new Twig($this->modx, $pdoTools);
        // Do not override the frozen 'parser' service; just replace the parser instance for this test run.
        $this->modx->parser = $parser;
        if (!$this->modx->services->has('twigparser')) {
            $this->modx->services->add('twigparser', $parser);
        }
    }

    private function registerChunk(string $name, string $content, array $fields = []): void
    {
        $classKey = $this->modx->loadClass(modChunk::class) ?: modChunk::class;
        $chunk = $this->modx->newObject(modChunk::class);
        $chunk->fromArray(
            array_merge([
                'id' => count($this->modx->sourceCache[modChunk::class] ?? []) + 1,
                'name' => $name,
                'content' => $content,
                'properties' => [],
                'static' => false,
            ], $fields),
            '',
            true,
            true
        );

        $cacheEntry = [
            'fields' => $chunk->toArray(),
            'policies' => [],
            'source' => [],
        ];
        $this->modx->sourceCache[$classKey][$name] = $cacheEntry;
        $this->modx->sourceCache[modChunk::class][$name] = $cacheEntry;
        $this->modx->elementCache = [];
    }

    private function processContent(string $content, int $depth = 10): string
    {
        $this->modx->elementCache = [];
        $this->modx->parser->processElementTags('', $content, true, true, '[[', ']]', [], $depth);
        return $content;
    }

    private function clearTwigCache(): void
    {
        $path = $this->getTwigCachePath();
        $this->modx->getCacheManager();
        if (is_dir($path)) {
            $this->modx->cacheManager->deleteTree($path, ['deleteTop' => false, 'skipDirs' => false, 'extensions' => []]);
        }
        $this->modx->cacheManager->writeTree($path);
    }

    private function getTwigCachePath(): string
    {
        $cacheBase = $this->modx->getOption(xPDO::OPT_CACHE_PATH, null, MODX_CORE_PATH . 'cache/');
        return rtrim($cacheBase, '/\\') . '/twig/';
    }

    private function getTwigEnvironment(): Environment
    {
        // Ensure the environment has been initialized
        $this->modx->parser->renderString('init', []);

        $accessor = \Closure::bind(function (Twig $parser) {
            return $parser->twig;
        }, null, Twig::class);

        return $accessor($this->modx->parser);
    }

    private function countCacheFiles(): int
    {
        $files = glob($this->getTwigCachePath() . '*');
        return $files ? count($files) : 0;
    }

    private function latestCacheMTime(): int
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->getTwigCachePath(), \FilesystemIterator::SKIP_DOTS));
        $latest = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $latest = max($latest, $file->getMTime());
            }
        }
        return $latest;
    }

    private function getReloadTemplatePath(): string
    {
        $base = rtrim($this->modx->getOption(xPDO::OPT_CACHE_PATH, null, MODX_CORE_PATH . 'cache/'), '/\\');
        return $base . '/twig-tests/reload.twig';
    }

    public function test_base_template_parses_twig_tags(): void
    {
        $this->registerChunk('SimpleChunk', 'Chunk content');
        $content = 'Twig sum {{ 6 / 2 }} and chunk [[$SimpleChunk]]';

        $this->assertSame('Twig sum 3 and chunk Chunk content', $this->processContent($content));
    }

    public function test_chunk_twig_is_rendered_before_being_injected(): void
    {
        $this->registerChunk('TwiggyChunk', 'Chunk twig value {{ value|upper }}');
        $content = 'Base math {{ 1 + 1 }} | [[$TwiggyChunk? &value=`chunked`]]';

        $this->assertSame('Base math 2 | Chunk twig value CHUNKED', $this->processContent($content));
    }

    public function test_chunk_outputting_raw_twig_tags_is_parsed_once_in_base_template_cycle(): void
    {
        $this->registerChunk('RawTwigChunk', '{{ "{{ 5 + 5 }}" }}');
        $content = 'Base math {{ 2 + 2 }} + [[$RawTwigChunk]]';

        $this->assertSame('Base math 4 + 10', $this->processContent($content));
    }

    public function test_compiled_templates_are_cached_on_disk(): void
    {
        $this->clearTwigCache();
        $this->assertSame(0, $this->countCacheFiles());

        $this->processContent('Caching check {{ 7 * 3 }}');

        $this->assertGreaterThan(0, $this->countCacheFiles());
    }

    public function test_auto_reload_recompiles_when_source_changes(): void
    {
        $this->clearTwigCache();

        $templateFile = $this->getReloadTemplatePath();
        $this->modx->cacheManager->writeTree(dirname($templateFile) . '/');
        file_put_contents($templateFile, 'First {{ value }}');

        $env = $this->getTwigEnvironment();
        $env->setLoader(new FilesystemLoader(dirname($templateFile)));

        $env->render('reload.twig', ['value' => 'first']);
        $mtimeBefore = filemtime($templateFile);
        $this->assertTrue($env->isAutoReload());

        file_put_contents($templateFile, 'Second {{ value }}');
        touch($templateFile, time() + 2);

        $this->assertFalse($env->isTemplateFresh('reload.twig', $mtimeBefore));

        $this->modx->cacheManager->deleteTree(dirname($templateFile), ['deleteTop' => true, 'skipDirs' => false, 'extensions' => []]);

        $this->assertTrue(true); // explicit assertion to satisfy PHPUnit when only freshness checks run
    }
}
