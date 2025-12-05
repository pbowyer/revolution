<?php
declare(strict_types=1);

namespace MODX\Revolution\Tests\Twig;

use Boffinate\Twig\Twig;
use MODX\Revolution\MODxTestCase;
use MODX\Revolution\modChunk;
use MODX\Revolution\modSnippet;
use MODX\Revolution\Processors\Element\Snippet\Create;
use ModxPro\PdoTools\CoreTools;
use Twig\Error\SyntaxError;

class TwigParserTest extends MODxTestCase
{
    /**
     * @before
     */
    public function setUpFixtures(): void
    {
        $this->registeredSnippets = [];
        parent::setUpFixtures();
        $this->loadPdoTools();
        $this->loadTwig();
        $this->useTwigParser();
        $this->modx->elementCache = [];
        $this->modx->sourceCache = [];
        $this->modx->setOption('parser_max_iterations', 10);
    }

    /**
     * @after
     */
    public function tearDownFixtures(): void
    {
        $this->cleanupSnippets();
        $this->modx->elementCache = [];
        $this->modx->sourceCache = [];
        parent::tearDownFixtures();
    }

    /** @var string[] */
    private array $registeredSnippets = [];

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

    private function registerSnippet(string $name, string $code): void
    {
        $result = $this->modx->runProcessor(Create::class, [
            'name' => $name,
            'snippet' => $code,
            'content' => $code,
            'properties' => [],
            'static' => false,
        ]);
        $this->assertTrue($result && !$result->isError(),'Could not create Snippet: `'.$name.'`: '.implode("\n", $result->getAllErrors()));

        $this->registeredSnippets[] = $name;
    }

    private function processContent(string $content, int $depth = 10): string
    {
        // Reset caches per run to avoid cross-test contamination
        $this->modx->elementCache = [];
        $this->modx->parser->processElementTags('', $content, true, true, '[[', ']]', [], $depth);
        return $content;
    }

    public function test_modx_template_without_twig_syntax_still_processes_modx_tags(): void
    {
        $this->modx->setPlaceholder('name', 'MODX');
        $content = 'Hello [[+name]]!';

        $this->assertSame('Hello MODX!', $this->processContent($content));
    }

    public function test_modx_template_with_valid_twig_is_rendered(): void
    {
        $content = 'Sum: {{ 2 + 3 }}';

        $this->assertSame('Sum: 5', $this->processContent($content));
    }

    public function test_modx_template_with_invalid_twig_syntax_throws(): void
    {
        $this->expectException(SyntaxError::class);

        $content = 'Broken {{ name ';
        $this->processContent($content);
    }

    public function test_template_calls_chunk_with_twig_content(): void
    {
        $this->registerChunk('TwigChunk', 'Hello {{ name }}');
        $content = '[[$TwigChunk? &name=`World`]]';

        $this->assertSame('Hello World', $this->processContent($content));
    }

    public function test_modx_chunk_renders_standard_placeholders(): void
    {
        $this->registerChunk('PlainChunk', 'Hi [[+subject]]');

        $output = $this->modx->getChunk('PlainChunk', ['subject' => 'MODX']);
        $this->assertSame('Hi MODX', $output);
    }

    public function test_twig_can_call_modx_snippet(): void
    {
        $this->registerSnippet('TwiggedSnippet', 'return "Snippet output";');
        $content = 'Start {% if true %}[[TwiggedSnippet]]{% endif %} End';

        $this->assertSame('Start Snippet output End', $this->processContent($content));
    }

    public function test_snippet_output_twig_template_is_parsed(): void
    {
        $this->registerSnippet('TwigTemplateSnippet', 'return "Twig math {{ 3 * 3 }}";');

        $this->assertSame('Twig math 9', $this->processContent('[[TwigTemplateSnippet]]'));
    }

    public function test_snippet_output_with_twig_syntax_is_rendered(): void
    {
        $this->registerSnippet(
            'TwigFilterSnippet',
            'return "{% set word = \"twig\" %}Word {{ word|upper }}";'
        );

        $this->assertSame('Word TWIG', $this->processContent('[[TwigFilterSnippet]]'));
    }

    private function cleanupSnippets(): void
    {
        if (empty($this->registeredSnippets)) {
            return;
        }

        $snippets = $this->modx->getCollection(modSnippet::class, ['name:IN' => $this->registeredSnippets]);
        foreach ($snippets as $snippet) {
            $snippet->remove();
        }

        $this->registeredSnippets = [];
    }
}
