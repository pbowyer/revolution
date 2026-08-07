<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Packages;

use Boffinate\ComposerOps\Job\JobOperation;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;

/**
 * Submits an async `composer remove` job for one or more packages.
 *
 * @param string $packages JSON-encoded array of package name strings. At least one
 *        is required.
 * @param bool $dev (optional) Remove from the dev dependencies. Defaults to false.
 * @package MODX\Revolution\Processors\Workspace\Composer\Packages
 */
class Remove extends ComposerProcessor
{
    /**
     * @return array|string
     */
    public function process()
    {
        $packages = $this->getPackagesProperty();
        if ($packages === null || $packages === []) {
            return $this->failure($this->modx->lexicon('composer_err_no_packages'));
        }

        return $this->submitJobResponse(JobOperation::Remove, $packages, $this->getBooleanProperty('dev'));
    }
}
