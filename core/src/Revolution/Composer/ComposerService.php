<?php

namespace MODX\Revolution\Composer;

use Boffinate\ComposerOps\ComposerOps;
use Boffinate\ComposerOps\Core\ComposerBinary;
use Boffinate\ComposerOps\Core\ComposerHome;
use Boffinate\ComposerOps\Core\ComposerOptions;
use Boffinate\ComposerOps\Core\ProjectContext;
use Boffinate\ComposerOps\Job\DetachedSpawner;
use Boffinate\ComposerOps\Job\FileJobStore;
use Boffinate\ComposerOps\Job\JobManager;
use Boffinate\ComposerOps\Job\JobOperation;
use Boffinate\ComposerOps\Job\JobRecord;
use Boffinate\ComposerOps\Job\JobSpec;
use Boffinate\ComposerOps\Lock\SymfonyLockManager;
use Boffinate\ComposerOps\Runner\LocalProcessRunner;
use MODX\Revolution\modX;
use RuntimeException;
use Throwable;

/**
 * Wires boffinate/composer-ops to this MODX installation so processors and
 * the CLI worker share one configuration: the site's own root composer.json
 * as the project, work directories under core/cache/composer-ops/, and the
 * composer/php binaries resolved from system settings.
 *
 * Mutations (require/update/remove) always go through the async job layer via
 * submitJob(); they are never run synchronously inside a web request.
 *
 * The work directories live under core/cache/, which core/.htaccess already
 * denies on Apache; nginx installs must deny /core in the vhost as usual.
 */
class ComposerService
{
    /**
     * How long a worker's mutation waits for the per-project lock before
     * failing, in seconds. This is the TOCTOU backstop behind the submit-time
     * busy guard; read operations never take the lock, so it does not apply
     * to them.
     */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Overall timeout for a synchronous read command run inside a web
     * request, in seconds.
     */
    private const READ_TIMEOUT_SECONDS = 60;

    /**
     * Overall timeout for a composer mutation executed by the detached job
     * worker, in seconds.
     */
    private const JOB_TIMEOUT_SECONDS = 1800;

    /**
     * A Pending job older than this, in seconds, is presumed to be a dead
     * spawn (the worker never launched). JobManager reconciles such records
     * to Failed on every get()/list(), so they cannot trip the busy guard
     * forever.
     */
    private const STALE_PENDING_SECONDS = 900;

    /**
     * Terminal job records older than this, in seconds, are pruned (record
     * and log file). v1 runs the sweep on submit only, so history on a site
     * that stops submitting is kept until the next submission.
     */
    private const HISTORY_RETENTION_SECONDS = 604800;

    /** @var modX $modx */
    private $modx;

    /** @var string $projectRoot Absolute path (no trailing slash) holding composer.json */
    private $projectRoot;

    /** @var string $workPath Base path (no trailing slash) for jobs/logs/locks/home dirs */
    private $workPath;

    /** @var ComposerOps|null $ops */
    private $ops = null;

    /** @var JobManager|null $jobManager */
    private $jobManager = null;

    /** @var FileJobStore|null $jobStore */
    private $jobStore = null;

    /** @var ProjectContext|null $context */
    private $context = null;

    /**
     * @param modX $modx
     * @throws RuntimeException When no composer.json exists at the project root.
     */
    public function __construct(modX $modx)
    {
        $this->modx = $modx;

        $basePath = $modx->getOption('base_path', null, defined('MODX_BASE_PATH') ? MODX_BASE_PATH : '');
        $this->projectRoot = rtrim((string)$basePath, '/');
        if ($this->projectRoot === '' || !is_file($this->projectRoot . '/composer.json')) {
            throw new RuntimeException(
                'No composer.json found at "' . $this->projectRoot . '/composer.json"; '
                . 'the composer manager requires the MODX base path to be a composer project root.'
            );
        }

        $corePath = $modx->getOption('core_path', null, defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '');
        $this->workPath = rtrim((string)$corePath, '/') . '/cache/composer-ops';
    }

    /**
     * The composer-ops facade, configured for synchronous (read) operations.
     *
     * @return ComposerOps
     */
    public function ops(): ComposerOps
    {
        return $this->ops ??= new ComposerOps(
            new LocalProcessRunner(),
            new SymfonyLockManager($this->workDir('locks')),
            $this->options(self::READ_TIMEOUT_SECONDS),
            self::LOCK_WAIT_SECONDS
        );
    }

    /**
     * The validated project context for the site's root composer.json.
     *
     * @return ProjectContext
     */
    public function context(): ProjectContext
    {
        return $this->context ??= new ProjectContext(
            $this->projectRoot,
            composerHome: ComposerHome::provided($this->workDir('home')),
            phpBinary: $this->getPhpBinary(),
            composerBinary: $this->getComposerBinary()
        );
    }

    /**
     * The async job manager used for all composer mutations.
     *
     * @return JobManager
     */
    public function jobManager(): JobManager
    {
        return $this->jobManager ??= new JobManager(
            $this->jobStore(),
            $this->ops(),
            $this->workDir('logs'),
            stalePendingSeconds: self::STALE_PENDING_SECONDS
        );
    }

    /**
     * Resolve a job record's log path to a real file inside the logs work
     * directory, or null when it is missing or points elsewhere. The single
     * containment rule for both reading and deleting job logs: a log path is
     * only trustworthy if it really lives in this service's logs directory.
     *
     * @param string $logPath
     * @return string|null
     */
    public function resolveLogPath(string $logPath): ?string
    {
        $logsDir = realpath($this->workDir('logs'));
        $resolved = realpath($logPath);
        if (
            $logsDir === false || $resolved === false
            || strpos($resolved, $logsDir . DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * Submit a composer mutation as an async job and spawn the detached CLI
     * worker that executes it. Only one job may be in flight per project, so
     * submission fails fast while any earlier job is still pending or running.
     *
     * @param JobOperation $operation
     * @param array $packages
     * @param bool $dev
     * @return JobRecord The pending record; poll the job manager for progress.
     * @throws ComposerBusyException When another job is still pending or running.
     */
    public function submitJob(JobOperation $operation, array $packages = [], bool $dev = false): JobRecord
    {
        $manager = $this->jobManager();
        $records = $manager->list();
        $this->pruneHistory($records);
        $this->assertNotBusy($records);

        // A targeted `composer update pkg` pins every other package to its
        // locked version, so a new release that raises a floor on one of its
        // own dependencies "succeeds" as a silent no-op (exit 0, "Nothing to
        // modify in lock file"). --with-all-dependencies lets composer move
        // whatever else must move, which is what "Update Package" means to a
        // Manager user. Deliberately not a try-conservative-then-prompt flow:
        // the conservative run does not fail, so there is nothing to prompt
        // on (see composer-ops planning/decisions.md, 2026-08-07).
        $withAllDependencies = $operation === JobOperation::Update && $packages !== [];

        $phpBinary = $this->getPhpBinary();
        $spec = new JobSpec(
            operation: $operation,
            projectRoot: $this->projectRoot,
            composerHome: ComposerHome::provided($this->workDir('home')),
            packages: $packages,
            dev: $dev,
            options: $this->options(self::JOB_TIMEOUT_SECONDS, $withAllDependencies),
            phpBinary: $phpBinary,
            composerPharPath: $this->getComposerPharPath()
        );

        $record = $manager->submit($spec);

        try {
            (new DetachedSpawner())->spawn(
                [$phpBinary, $this->projectRoot . '/bin/modx-composer', 'job-run', $record->id]
            );
        } catch (Throwable $exception) {
            // Never leave an unrunnable job pending: it would trip the busy
            // guard for every later submission.
            $manager->cancel($record->id);
            throw $exception;
        }

        return $record;
    }

    /**
     * Path to the PHP CLI binary used both to spawn the worker and to run a
     * composer phar. Never defaults to PHP_BINARY: under php-fpm that is the
     * FPM binary, which cannot run CLI scripts.
     *
     * @return string
     */
    public function getPhpBinary(): string
    {
        $default = is_file(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        $binary = trim((string)$this->modx->getOption('composer.php_binary', null, $default));

        return $binary !== '' ? $binary : $default;
    }

    /**
     * @return ComposerBinary
     */
    public function getComposerBinary(): ComposerBinary
    {
        $pharPath = $this->getComposerPharPath();

        return $pharPath !== null
            ? ComposerBinary::fromPharPath($pharPath)
            : ComposerBinary::fromGlobalCommand('composer');
    }

    /**
     * Path to a composer.phar from the `composer.binary` system setting, or
     * null to use the global `composer` command.
     *
     * @return string|null
     */
    public function getComposerPharPath()
    {
        $setting = trim((string)$this->modx->getOption('composer.binary', null, ''));

        return $setting !== '' ? $setting : null;
    }

    /**
     * @param int $timeoutSeconds
     * @param bool $withAllDependencies
     * @return ComposerOptions
     */
    private function options(int $timeoutSeconds, bool $withAllDependencies = false): ComposerOptions
    {
        return ComposerOptions::fromArray([
            'noProgress' => true,
            'timeoutSeconds' => $timeoutSeconds,
            'withAllDependencies' => $withAllDependencies,
        ]);
    }

    /**
     * The job store shared by the job manager and history pruning.
     *
     * @return FileJobStore
     */
    private function jobStore(): FileJobStore
    {
        return $this->jobStore ??= new FileJobStore($this->workDir('jobs'));
    }

    /**
     * Delete terminal job records (and their logs) past the retention window.
     *
     * @param JobRecord[] $records
     * @return void
     */
    private function pruneHistory(array $records): void
    {
        $now = time();
        foreach ($records as $record) {
            if (!$record->isTerminal()) {
                continue;
            }
            $finishedAt = $record->finishedAt ?? $record->createdAt;
            if (($now - $finishedAt->getTimestamp()) > self::HISTORY_RETENTION_SECONDS) {
                $this->pruneJob($record);
            }
        }
    }

    /**
     * Fail fast while any earlier job is still in flight. Stale records never
     * wedge this guard: JobManager::list() has already reconciled dead
     * workers and aged-out Pending records to Failed (terminal) before they
     * reach here.
     *
     * @param JobRecord[] $records
     * @return void
     * @throws ComposerBusyException
     */
    private function assertNotBusy(array $records): void
    {
        foreach ($records as $existing) {
            if ($existing->isTerminal()) {
                continue;
            }
            throw new ComposerBusyException(sprintf(
                'Composer job %s (%s) is still %s.',
                $existing->id,
                $existing->spec->operation->value,
                $existing->status->value
            ));
        }
    }

    /**
     * Delete an old terminal job record and its log file. Best effort: a
     * record that cannot be deleted is simply left for the next prune.
     *
     * @param JobRecord $record
     * @return void
     */
    private function pruneJob(JobRecord $record): void
    {
        try {
            $this->jobStore()->delete($record->id);
        } catch (Throwable $exception) {
            return;
        }

        $logPath = $this->resolveLogPath($record->logPath);
        if ($logPath !== null) {
            @unlink($logPath);
        }
    }

    /**
     * Resolve (and lazily create) one of the work directories under
     * core/cache/composer-ops/.
     *
     * @param string $name
     * @return string
     * @throws RuntimeException When the directory cannot be created.
     */
    private function workDir(string $name): string
    {
        $dir = $this->workPath . '/' . $name;
        if (!is_dir($dir) && !$this->modx->getCacheManager()->writeTree($dir) && !is_dir($dir)) {
            throw new RuntimeException('Could not create composer work directory "' . $dir . '".');
        }

        return $dir;
    }
}
