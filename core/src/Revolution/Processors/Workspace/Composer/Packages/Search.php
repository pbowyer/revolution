<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Packages;

use Boffinate\ComposerOps\Exception\ComposerOpsExceptionInterface;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;
use RuntimeException;

/**
 * Searches Packagist (and any other configured repositories) for packages.
 *
 * @param string $query The search term; at least 2 characters.
 * @package MODX\Revolution\Processors\Workspace\Composer\Packages
 */
class Search extends ComposerProcessor
{
    /**
     * The maximum number of search results returned to the Manager.
     */
    private const MAX_RESULTS = 30;

    /**
     * @return array|string
     */
    public function process()
    {
        $query = trim((string)$this->getProperty('query', ''));
        // Byte length is fine for a 2-character minimum: multibyte characters
        // are at least 2 bytes each.
        if (strlen($query) < 2) {
            // The Manager's combo fires an empty query when it opens; that is
            // simply "no results yet", not an error.
            return $this->outputArray([], 0);
        }

        try {
            $service = $this->getComposerService();
            // The search hits the network; do not hold the session lock (and
            // thereby block the user's other manager requests) while it runs.
            $this->releaseSession();
            $results = $service->ops()->searchPackages($service->context(), $query);
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        $rows = [];
        foreach (array_slice($results, 0, self::MAX_RESULTS) as $result) {
            $rows[] = [
                'name' => $result['name'] ?? '',
                'description' => $result['description'] ?? null,
            ];
        }

        return $this->outputArray($rows, count($rows));
    }
}
