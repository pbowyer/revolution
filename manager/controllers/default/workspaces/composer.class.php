<?php

use MODX\Revolution\modManagerController;

/**
 * Manage the site's root composer.json packages.
 */
class WorkspacesComposerManagerController extends modManagerController
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('packages');
    }

    public function process(array $scriptProperties = [])
    {
    }

    public function loadCustomCssJs()
    {
        $this->addJavascript($this->modx->getOption('manager_url', null, MODX_MANAGER_URL)
            . 'assets/modext/sections/workspaces/composer.js');
        $this->addHtml('<script>Ext.onReady(function(){'
            . 'MODx.load({xtype:"modx-page-workspaces-composer"});});</script>');
    }

    public function getPageTitle()
    {
        return $this->modx->lexicon('composer_packages');
    }

    public function getTemplateFile()
    {
        return '';
    }

    public function getLanguageTopics()
    {
        return ['composer'];
    }
}
