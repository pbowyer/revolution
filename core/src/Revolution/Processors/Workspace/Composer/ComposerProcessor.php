<?php

namespace MODX\Revolution\Processors\Workspace\Composer;

use Boffinate\ComposerOps\Exception\CommandFailedException;
use Boffinate\ComposerOps\Exception\ComposerOpsExceptionInterface;
use Boffinate\ComposerOps\Exception\JobNotFoundException;
use Boffinate\ComposerOps\Job\JobOperation;
use Boffinate\ComposerOps\Job\JobRecord;
use MODX\Revolution\Composer\ComposerBusyException;
use MODX\Revolution\Composer\ComposerService;
use MODX\Revolution\Processors\Processor;
use RuntimeException;

/**
 * Shared plumbing for the Manager-facing composer processors: permission and
 * lexicon wiring, lazy ComposerService access, property parsing, pagination,
 * job submission/loading, and consistent failure output for composer-ops
 * exceptions.
 *
 * @package MODX\Revolution\Processors\Workspace\Composer
 */
abstract class ComposerProcessor extends Processor
{
    public $permission = 'packages';

    /** @var ComposerService|null $composerService */
    protected $composerService = null;

    /**
     * @return bool
     */
    public function checkPermissions()
    {
        return $this->modx->hasPermission($this->permission);
    }

    /**
     * @return array
     */
    public function getLanguageTopics()
    {
        return ['composer'];
    }

    /**
     * @return ComposerService
     */
    protected function getComposerService(): ComposerService
    {
        if ($this->composerService === null) {
            $this->composerService = new ComposerService($this->modx);
        }

        return $this->composerService;
    }

    /**
     * Boolean cast for request properties: the Manager sends the strings
     * 'true'/'false' rather than real booleans.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    protected function getBooleanProperty($key, $default = false): bool
    {
        $value = $this->getProperty($key, $default);

        return is_string($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
            : (bool)$value;
    }

    /**
     * Decode the JSON-encoded `packages` property into a list of package
     * strings. An absent/empty property decodes to []; malformed JSON, a
     * non-list payload, or non-string entries return null so callers can
     * fail with a clear message. Package name/constraint grammar is NOT
     * validated here — the composer-ops library owns that.
     *
     * @return array|null
     */
    protected function getPackagesProperty()
    {
        $raw = $this->getProperty('packages', '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = $this->modx->fromJSON($raw);
        if (!is_array($decoded)) {
            return null;
        }

        $packages = [];
        foreach ($decoded as $package) {
            if (!is_string($package) || trim($package) === '') {
                return null;
            }
            $packages[] = trim($package);
        }

        return $packages;
    }

    /**
     * Slice an already-filtered/sorted row list according to the start/limit
     * request properties.
     *
     * @param array $rows
     * @return array [rows for this page, total row count]
     */
    protected function paginate(array $rows): array
    {
        $total = count($rows);
        $start = max(0, (int)$this->getProperty('start', 0));
        $limit = (int)$this->getProperty('limit', 0);
        if ($limit < 1) {
            $limit = (int)$this->modx->getOption('default_per_page', null, 20);
        }

        return [array_slice($rows, $start, $limit), $total];
    }

    /**
     * Submit a composer mutation job and answer with the curated job row, or
     * a failure when the project is busy or composer-ops rejects the input.
     *
     * @param JobOperation $operation
     * @param array $packages
     * @param bool $dev
     * @return array|string
     */
    protected function submitJobResponse(JobOperation $operation, array $packages, bool $dev = false)
    {
        try {
            $record = $this->getComposerService()->submitJob($operation, $packages, $dev);
        } catch (ComposerBusyException $exception) {
            return $this->failure($this->modx->lexicon('composer_err_busy'));
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        return $this->success('', $this->jobRow($record));
    }

    /**
     * Load the job named by the `id` request property. Returns the record, or
     * a ready-made failure response when the id is missing/unknown. Loading
     * goes through JobManager::get(), which also runs stale-PID
     * reconciliation on the record.
     *
     * @return JobRecord|array|string
     */
    protected function loadJob()
    {
        $id = trim((string)$this->getProperty('id', ''));
        if ($id === '') {
            return $this->failure($this->modx->lexicon('composer_err_job_id_required'));
        }

        try {
            return $this->getComposerService()->jobManager()->get($id);
        } catch (JobNotFoundException $exception) {
            return $this->failure($this->modx->lexicon('composer_err_job_not_found'));
        } catch (ComposerOpsExceptionInterface $exception) {
            return $this->failureFromException($exception);
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * Turn a composer-ops exception into a processor failure. For failed
     * commands the message already carries a stderr excerpt; append a stdout
     * excerpt as well, since composer writes much of its diagnostic output
     * there. Both parts are scrubbed to valid UTF-8 (which also drops a
     * truncation-split trailing character) so the failure message always
     * survives json_encode().
     *
     * @param ComposerOpsExceptionInterface $exception
     * @return array|string
     */
    protected function failureFromException(ComposerOpsExceptionInterface $exception)
    {
        $message = $this->scrubUtf8($exception->getMessage());
        if ($exception instanceof CommandFailedException) {
            $stdout = trim($exception->result->stdout);
            if (strlen($stdout) > 500) {
                $stdout = substr($stdout, 0, 500) . '…';
            }
            $stdout = $this->scrubUtf8($stdout);
            if ($stdout !== '') {
                $message .= "\n" . $stdout;
            }
        }

        return $this->failure($message);
    }

    /**
     * Remove bytes that do not form valid UTF-8 so a payload always survives
     * json_encode(): modConnectorResponse encodes with plain json_encode(),
     * and an invalid payload would become an empty response body.
     *
     * @param string $text
     * @return string
     */
    protected function scrubUtf8(string $text): string
    {
        if ($text === '' || preg_match('//u', $text) === 1) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($converted)) {
                return $converted;
            }
        }

        // Keep valid UTF-8 sequences, drop every other byte.
        $scrubbed = preg_replace(
            '/((?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3})+)|./s',
            '$1',
            $text
        );

        return is_string($scrubbed) ? $scrubbed : '';
    }

    /**
     * Release the manager session lock before running a slow composer
     * subprocess: PHP file sessions hold an exclusive lock per session, so a
     * multi-second composer call would otherwise freeze every other manager
     * request from the same user until it finishes.
     *
     * @return void
     */
    protected function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * The single job response shape shared by every job-returning processor
     * (Packages/Install, Packages/Update, Packages/Remove, Jobs/Get,
     * Jobs/GetList, Jobs/Cancel). Deliberately curated: never expose the raw
     * record/spec, which carries projectRoot, phpBinary, env, and logPath.
     *
     * @param JobRecord $record
     * @return array
     */
    protected function jobRow(JobRecord $record): array
    {
        return [
            'id' => $record->id,
            'operation' => $record->spec->operation->value,
            'packages' => implode(', ', $record->spec->packages),
            'status' => $record->status->value,
            'createdAt' => $record->createdAt->format(DATE_ATOM),
            'exitCode' => $record->exitCode,
            'failureReason' => $record->failureReason?->value,
            'durationMs' => $record->durationMs,
            'error' => $record->error === null ? null : $this->scrubUtf8($record->error),
        ];
    }
}
