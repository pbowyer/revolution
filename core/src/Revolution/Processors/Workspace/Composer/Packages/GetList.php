<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Packages;

use Boffinate\ComposerOps\Core\ComposerOutputParser;
use Boffinate\ComposerOps\Exception\ComposerOpsExceptionInterface;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;
use RuntimeException;

/**
 * Lists the packages installed in the site's root composer project.
 *
 * @param string $query (optional) Case-insensitive substring filter on name and description.
 * @param bool $checkUpdates (optional) When true, ask composer for the latest available
 *        versions so latest/latestStatus are populated. Hits the network and can take
 *        several seconds. Defaults to false.
 * @param int $start (optional) The record to start at. Defaults to 0.
 * @param int $limit (optional) The number of records to limit to. Defaults to 20.
 * @param string $sort (optional) The column to sort by: name or version. Defaults to name.
 * @param string $dir (optional) The direction of the sort. Defaults to ASC.
 * @package MODX\Revolution\Processors\Workspace\Composer\Packages
 */
class GetList extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        try {
            $service = $this->getComposerService();
            // Even the local `composer show` is a multi-second subprocess; do
            // not hold the session lock (and thereby block the user's other
            // manager requests) while it runs.
            $this->releaseSession();
            if ($this->getBooleanProperty('checkUpdates')) {
                // `composer show --latest` returns every installed package
                // WITH latest/latest-status, so one subprocess serves both
                // halves (composer outdated is just show --latest --outdated).
                $result = $service->ops()->show($service->context(), null, true, false, false, 'json');
                if (!$result->isSuccessful()) {
                    return $this->failure($this->scrubUtf8(trim($result->stderr)) ?: 'composer show failed');
                }
                $installed = ComposerOutputParser::parseInstalledPackages($result->stdout);
            } else {
                $installed = $service->ops()->installedPackages($service->context());
            }
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        $rows = [];
        foreach ($installed as $package) {
            $rows[] = [
                'name' => $package->name,
                'version' => $package->version,
                'description' => $package->description,
                'latest' => $package->latest,
                'latestStatus' => $package->latestStatus,
                'abandoned' => $package->abandoned,
                'replacement' => $package->replacement,
            ];
        }

        $query = trim((string)$this->getProperty('query', ''));
        if ($query !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($query) {
                return stripos($row['name'], $query) !== false
                    || ($row['description'] !== null && stripos($row['description'], $query) !== false);
            }));
        }

        $sort = $this->getProperty('sort', 'name') === 'version' ? 'version' : 'name';
        $dir = strtoupper((string)$this->getProperty('dir', 'ASC')) === 'DESC' ? -1 : 1;
        usort($rows, function (array $a, array $b) use ($sort, $dir) {
            $result = strnatcasecmp((string)$a[$sort], (string)$b[$sort]);
            if ($result === 0) {
                $result = strnatcasecmp($a['name'], $b['name']);
            }

            return $result * $dir;
        });

        [$rows, $total] = $this->paginate($rows);

        return $this->outputArray($rows, $total);
    }
}
