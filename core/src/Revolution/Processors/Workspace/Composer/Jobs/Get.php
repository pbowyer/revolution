<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Jobs;

use Boffinate\ComposerOps\Job\JobRecord;
use Boffinate\ComposerOps\Job\JobStatus;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;

/**
 * Returns the state of a single composer job plus an incremental chunk of its
 * log, for polling from the Manager.
 *
 * @param string $id The job id.
 * @param int $logOffset (optional) Byte offset to read the log from. Defaults to 0.
 *        Pass the logSize from the previous response to receive only new output.
 * @package MODX\Revolution\Processors\Workspace\Composer\Jobs
 */
class Get extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        $record = $this->loadJob();
        if (!$record instanceof JobRecord) {
            return $record;
        }

        $data = $this->jobRow($record);

        $offset = max(0, (int)$this->getProperty('logOffset', 0));
        $logChunk = '';
        $logSize = $offset;
        $logPath = $this->getComposerService()->resolveLogPath($record->logPath);
        if ($logPath !== null) {
            $chunk = @file_get_contents($logPath, false, null, $offset);
            $chunk = $chunk === false ? '' : $chunk;
            // Hold back an incomplete trailing UTF-8 sequence and report a
            // correspondingly smaller logSize, so the client re-requests the
            // held-back bytes on the next poll once the rest have been
            // written.
            $chunk = $this->trimIncompleteUtf8Tail($chunk);
            $logSize = $offset + strlen($chunk);
            // Scrub any residual invalid bytes: modConnectorResponse encodes
            // with plain json_encode(), and an invalid payload would become
            // an empty response body that stalls the polling UI for good.
            $logChunk = $this->scrubUtf8($chunk);
        }
        $data['logChunk'] = $logChunk;
        $data['logSize'] = $logSize;
        $data['done'] = $record->isTerminal();
        $data['noChanges'] = $record->status === JobStatus::Succeeded
            && $logPath !== null
            && $this->logReportsNoChanges($logPath);

        return $this->success('', $data);
    }

    /**
     * Whether a succeeded mutation actually changed anything: composer prints
     * "Nothing to modify in lock file" when an update/require resolves to the
     * already-locked versions (e.g. a targeted update whose newer releases are
     * blocked by the project's constraints), and exits 0 anyway. Composer
     * output is not localized and jobs run with --no-ansi, so a plain string
     * match on the full log is stable. Only called on terminal polls, so the
     * extra full-file read happens once per job, not per poll.
     *
     * @param string $logPath
     * @return bool
     */
    private function logReportsNoChanges(string $logPath): bool
    {
        $log = @file_get_contents($logPath);

        return is_string($log) && strpos($log, 'Nothing to modify in lock file') !== false;
    }

    /**
     * If the chunk ends mid-UTF-8 sequence (the log writer got interrupted
     * between the bytes of a multi-byte character), drop the incomplete
     * trailing bytes; the caller re-serves them on the next poll.
     *
     * @param string $chunk
     * @return string
     */
    private function trimIncompleteUtf8Tail(string $chunk): string
    {
        $length = strlen($chunk);
        $inspect = min(3, $length);
        for ($i = 1; $i <= $inspect; $i++) {
            $byte = ord($chunk[$length - $i]);
            if (($byte & 0xC0) === 0x80) {
                // Continuation byte; keep looking back for the lead byte.
                continue;
            }
            if ($byte < 0x80) {
                // ASCII: the tail is complete.
                return $chunk;
            }
            if (($byte & 0xE0) === 0xC0) {
                $expected = 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $expected = 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                $expected = 4;
            } else {
                // Invalid lead byte; leave it for the scrubber.
                return $chunk;
            }

            return $expected > $i ? substr($chunk, 0, $length - $i) : $chunk;
        }

        return $chunk;
    }
}
