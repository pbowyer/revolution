<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Packages;

use Boffinate\ComposerOps\Job\JobOperation;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;

/**
 * Submits an async `composer update` job.
 *
 * @param string $packages (optional) JSON-encoded array of package strings to limit
 *        the update to. Empty or absent updates everything.
 * @package MODX\Revolution\Processors\Workspace\Composer\Packages
 */
class Update extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        $packages = $this->getPackagesProperty();
        if ($packages === null) {
            return $this->failure($this->modx->lexicon('composer_err_packages_invalid'));
        }

        return $this->submitJobResponse(JobOperation::Update, $packages);
    }
}
