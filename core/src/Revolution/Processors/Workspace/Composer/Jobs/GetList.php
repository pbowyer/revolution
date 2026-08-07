<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Jobs;

use Boffinate\ComposerOps\Exception\ComposerOpsExceptionInterface;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;
use RuntimeException;

/**
 * Lists all composer jobs, newest first.
 *
 * @param int $start (optional) The record to start at. Defaults to 0.
 * @param int $limit (optional) The number of records to limit to. Defaults to 20.
 * @package MODX\Revolution\Processors\Workspace\Composer\Jobs
 */
class GetList extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        try {
            $jobs = $this->getComposerService()->jobManager()->list();
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        // The job manager lists by createdAt ascending; the Manager wants the
        // most recent jobs first.
        [$jobs, $total] = $this->paginate(array_reverse($jobs));

        $rows = [];
        foreach ($jobs as $record) {
            $rows[] = $this->jobRow($record);
        }

        return $this->outputArray($rows, $total);
    }
}
