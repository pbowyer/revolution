<?php

namespace MODX\Revolution\Processors\Workspace\Composer\Packages;

use Boffinate\ComposerOps\Job\JobOperation;
use MODX\Revolution\Processors\Workspace\Composer\ComposerProcessor;

/**
 * Submits an async `composer require` job for one or more packages.
 *
 * The action is named Install rather than Require because `Require` is a PHP
 * reserved word and cannot be declared as a class name.
 *
 * @param string $packages JSON-encoded array of package strings, each `vendor/name`
 *        or `vendor/name:constraint`. At least one is required.
 * @param bool $dev (optional) Require as a dev dependency. Defaults to false.
 * @package MODX\Revolution\Processors\Workspace\Composer\Packages
 */
class Install extends ComposerProcessor
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

        return $this->submitJobResponse(JobOperation::Require, $packages, $this->getBooleanProperty('dev'));
    }
}
