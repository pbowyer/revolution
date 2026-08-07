<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Jobs;

use Boffinate\ComposerOps\Exception\ComposerOpsExceptionInterface;
use Boffinate\ComposerOps\Exception\JobNotFoundException;
use Boffinate\ComposerOps\Job\JobRecord;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;
use RuntimeException;

/**
 * Cancels a composer job. Idempotent: cancelling an already-finished job
 * returns it unchanged.
 *
 * @param string $id The job id.
 * @package MODX\Revolution\Processors\Workspace\Composer\Jobs
 */
class Cancel extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        // loadJob() goes through JobManager::get(), whose stale-PID
        // reconciliation ensures a Running record whose worker already died
        // (and whose PID may have been recycled by an unrelated process) is
        // not SIGTERMed blindly.
        $record = $this->loadJob();
        if (!$record instanceof JobRecord) {
            return $record;
        }

        try {
            $record = $this->getComposerService()->jobManager()->cancel($record->id);
        } catch (JobNotFoundException $exception) {
            return $this->failure($this->modx->lexicon('composer_err_job_not_found'));
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        return $this->success('', $this->jobRow($record));
    }
}
