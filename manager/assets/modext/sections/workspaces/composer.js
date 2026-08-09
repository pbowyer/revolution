MODx.page.WorkspacesComposer = function(config) {
    config = config || {};
    Ext.applyIf(config, {
        components: [{xtype: 'modx-panel-workspaces-composer'}]
    });
    MODx.page.WorkspacesComposer.superclass.constructor.call(this, config);
};
Ext.extend(MODx.page.WorkspacesComposer, MODx.Component);
Ext.reg('modx-page-workspaces-composer', MODx.page.WorkspacesComposer);

/**
 * The exitCode/failureReason/error summary shared by the jobs grid and the
 * job window; callers choose the separator.
 */
MODx.util.composerJobResult = function(job) {
    var parts = [];
    if (job.exitCode !== null && job.exitCode !== undefined && job.exitCode !== '') {
        parts.push(_('composer_job_exit_code') + ': ' + job.exitCode);
    }
    if (job.failureReason && job.failureReason !== 'none') { parts.push(job.failureReason); }
    if (job.error) { parts.push(job.error); }
    return parts;
};

MODx.panel.WorkspacesComposer = function(config) {
    config = config || {};
    Ext.applyIf(config, {id: 'modx-panel-workspaces-composer', layout: 'anchor', cls: 'container',
        items: [{html: _('composer_packages_intro'), xtype: 'modx-description'},
            {xtype: 'modx-grid-composer-packages', anchor: '100%'},
            {xtype: 'fieldset', title: _('composer_jobs'), collapsible: true, collapsed: false,
                anchor: '100%', layout: 'anchor',
                items: [{xtype: 'modx-grid-composer-jobs', anchor: '100%'}]}]});
    MODx.panel.WorkspacesComposer.superclass.constructor.call(this, config);
};
Ext.extend(MODx.panel.WorkspacesComposer, MODx.Panel, {
    showJobWindow: function(job) {
        var win = MODx.load({
            xtype: 'modx-window-composer-job',
            job: job || {},
            listeners: {complete: {fn: this.refreshGrids, scope: this}}
        });
        win.show();
        return win;
    },
    refreshGrids: function(job) {
        var jobs = Ext.getCmp('modx-grid-composer-jobs');
        if (jobs) { jobs.refresh(); }
        // The installed set only changes when a job actually succeeded;
        // refreshing the packages grid spawns a composer subprocess.
        if (job && job.status === 'succeeded') {
            var packages = Ext.getCmp('modx-grid-composer-packages');
            if (packages) { packages.refresh(); }
        }
    }
});
Ext.reg('modx-panel-workspaces-composer', MODx.panel.WorkspacesComposer);

MODx.grid.ComposerPackages = function(config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'modx-grid-composer-packages',
        cls: 'main-wrapper',
        border: false,
        autoHeight: true,
        loadMask: true,
        url: MODx.config.connector_url,
        baseParams: {action: 'Workspace/Composer/Packages/GetList', checkUpdates: false},
        fields: ['name', 'version', 'description', 'latest', 'latestStatus', 'abandoned',
            'replacement'],
        paging: true,
        remoteSort: true,
        primaryKey: 'name',
        autoExpandColumn: 'composer-package-description',
        columns: [{header: _('composer_package'), dataIndex: 'name', width: 240, sortable: true,
                renderer: {fn: this.renderName, scope: this}},
            {header: _('composer_installed'), dataIndex: 'version', width: 110, sortable: true},
            {header: _('composer_latest'), dataIndex: 'latest', width: 110,
                renderer: {fn: this.renderLatest, scope: this}},
            {header: _('composer_description'), dataIndex: 'description',
                id: 'composer-package-description'}],
        viewConfig: {forceFit: false, emptyText: _('composer_packages_empty')},
        tbar: [{text: _('composer_install_package'), cls: 'primary-button',
            handler: this.installPackage, scope: this},
            {text: _('composer_update_all'), handler: this.updateAll, scope: this},
            {text: _('composer_check_updates'), enableToggle: true, pressed: false,
                toggleHandler: this.toggleCheckUpdates, scope: this},
            '->',
            this.getQueryFilterField(),
            {text: _('ext_refresh'), handler: function() { this.refresh(); }, scope: this}]
    });
    MODx.grid.ComposerPackages.superclass.constructor.call(this, config);
};
Ext.extend(MODx.grid.ComposerPackages, MODx.grid.Grid, {
    latestStatusClasses: {
        'up-to-date': 'green',
        'semver-safe-update': 'orange',
        'update-possible': 'red'
    },

    getMenu: function() {
        return [{
            text: _('composer_update_package'),
            handler: this.updatePackage
        }, '-', {
            text: _('composer_remove_package'),
            handler: this.removePackage
        }];
    },

    renderName: function(value, metaData, record) {
        var name = Ext.util.Format.htmlEncode(value || '');
        if (record.data.abandoned) {
            var tip = _('composer_abandoned');
            if (record.data.replacement) {
                tip += ' ' + _('composer_abandoned_replacement', {replacement: record.data.replacement});
            }
            metaData.attr = 'ext:qtip="' + Ext.util.Format.htmlEncode(tip) + '"';
            name += ' <span class="orange">&#9888;</span>';
        }
        return name;
    },

    renderLatest: function(value, metaData, record) {
        if (!this.getStore().baseParams.checkUpdates || value === null
            || value === undefined || value === '') {
            return '';
        }
        var encoded = Ext.util.Format.htmlEncode(String(value));
        var cls = this.latestStatusClasses[record.data.latestStatus];
        return cls ? '<span class="' + cls + '">' + encoded + '</span>' : encoded;
    },

    toggleCheckUpdates: function(btn, pressed) {
        this.getStore().baseParams.checkUpdates = pressed;
        this.getBottomToolbar().changePage(1);
    },

    installPackage: function(btn, e) {
        this.loadWindow(btn, e, {
            xtype: 'modx-window-composer-install',
            blankValues: true,
            listeners: {
                success: {fn: function(o) { this.showJob(o.a.result.object); }, scope: this}
            }
        });
    },

    updateAll: function(btn, e) {
        MODx.msg.confirm({
            title: _('composer_update_all'),
            text: _('composer_update_all_confirm'),
            url: MODx.config.connector_url,
            params: {action: 'Workspace/Composer/Packages/Update', packages: '[]'},
            listeners: {
                success: {fn: function(r) { this.showJob(r.object); }, scope: this}
            }
        });
    },

    updatePackage: function() {
        MODx.Ajax.request({
            url: this.config.url,
            params: {
                action: 'Workspace/Composer/Packages/Update',
                packages: Ext.encode([this.menu.record.name])
            },
            listeners: {
                success: {fn: function(r) { this.showJob(r.object); }, scope: this}
            }
        });
    },

    removePackage: function() {
        MODx.msg.confirm({
            title: _('composer_remove_package'),
            text: _('composer_remove_package_confirm',
                {name: Ext.util.Format.htmlEncode(this.menu.record.name)}),
            url: this.config.url,
            params: {
                action: 'Workspace/Composer/Packages/Remove',
                packages: Ext.encode([this.menu.record.name])
            },
            listeners: {
                success: {fn: function(r) { this.showJob(r.object); }, scope: this}
            }
        });
    },

    showJob: function(job) {
        var panel = Ext.getCmp('modx-panel-workspaces-composer');
        if (panel) { panel.showJobWindow(job); }
    }
});
Ext.reg('modx-grid-composer-packages', MODx.grid.ComposerPackages);

MODx.grid.ComposerJobs = function(config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'modx-grid-composer-jobs',
        cls: 'main-wrapper',
        border: false,
        autoHeight: true,
        loadMask: true,
        url: MODx.config.connector_url,
        baseParams: {action: 'Workspace/Composer/Jobs/GetList'},
        fields: ['id', 'operation', 'packages', 'status', 'createdAt', 'exitCode',
            'failureReason', 'durationMs', 'pgid', 'error'],
        paging: true,
        remoteSort: false,
        primaryKey: 'id',
        autoExpandColumn: 'composer-job-result',
        columns: [{header: _('composer_job_operation'), dataIndex: 'operation', width: 100},
            {header: _('composer_job_packages'), dataIndex: 'packages', width: 220},
            {header: _('composer_job_status'), dataIndex: 'status', width: 100,
                renderer: {fn: this.renderStatus, scope: this}},
            {header: _('composer_job_created'), dataIndex: 'createdAt', width: 140,
                renderer: this.renderCreated},
            {header: _('composer_job_duration'), dataIndex: 'durationMs', width: 90,
                renderer: this.renderDuration},
            {header: _('composer_job_result'), dataIndex: 'exitCode', id: 'composer-job-result',
                renderer: this.renderResult}],
        viewConfig: {forceFit: false, emptyText: _('composer_jobs_empty')},
        tbar: ['->', {text: _('ext_refresh'), handler: function() { this.refresh(); }, scope: this}]
    });
    MODx.grid.ComposerJobs.superclass.constructor.call(this, config);
    this.on('rowdblclick', function(grid, rowIndex) {
        this.viewLog(this.getStore().getAt(rowIndex).data);
    }, this);
};
Ext.extend(MODx.grid.ComposerJobs, MODx.grid.Grid, {
    statusLabelKeys: {
        pending: 'composer_status_pending',
        running: 'composer_status_running',
        succeeded: 'composer_status_success',
        failed: 'composer_status_failed',
        cancelled: 'composer_status_cancelled'
    },

    statusClasses: {
        pending: 'orange',
        running: 'orange',
        succeeded: 'green',
        failed: 'red'
    },

    getMenu: function() {
        var menu = [{
            text: _('composer_view_log'),
            handler: function() { this.viewLog(this.menu.record); }
        }];
        var status = this.menu.record ? this.menu.record.status : '';
        if (status === 'pending' || status === 'running') {
            menu.push('-', {
                text: _('composer_job_cancel'),
                handler: this.cancelJob
            });
        }
        return menu;
    },

    viewLog: function(job) {
        var panel = Ext.getCmp('modx-panel-workspaces-composer');
        if (panel) { panel.showJobWindow(job); }
    },

    cancelJob: function() {
        MODx.Ajax.request({
            url: this.config.url,
            params: {action: 'Workspace/Composer/Jobs/Cancel', id: this.menu.record.id},
            listeners: {
                success: {fn: function() { this.refresh(); }, scope: this}
            }
        });
    },

    renderCreated: function(value) {
        if (value === null || value === undefined || value === '') { return ''; }
        var parsed = new Date(value);
        if (isNaN(parsed.getTime())) {
            return Ext.util.Format.htmlEncode(String(value));
        }
        var format = (MODx.config.manager_date_format || 'Y-m-d') + ' '
            + (MODx.config.manager_time_format || 'H:i');
        return Ext.util.Format.htmlEncode(Ext.util.Format.date(parsed, format));
    },

    renderStatus: function(value) {
        var key = this.statusLabelKeys[value];
        var encoded = Ext.util.Format.htmlEncode(key ? _(key) : (value || ''));
        var cls = this.statusClasses[value];
        return cls ? '<span class="' + cls + '">' + encoded + '</span>' : encoded;
    },

    renderDuration: function(value) {
        if (value === null || value === undefined || value === '') { return ''; }
        return Ext.util.Format.htmlEncode((parseInt(value, 10) / 1000).toFixed(1) + 's');
    },

    renderResult: function(value, metaData, record) {
        return Ext.util.Format.htmlEncode(MODx.util.composerJobResult(record.data).join(' — '));
    }
});
Ext.reg('modx-grid-composer-jobs', MODx.grid.ComposerJobs);

MODx.combo.ComposerPackage = function(config) {
    config = config || {};
    Ext.applyIf(config, {
        valueField: 'name',
        fields: ['name', 'description'],
        editable: true,
        forceSelection: false,
        minChars: 2,
        queryParam: 'query',
        queryDelay: 500,
        mode: 'remote',
        pageSize: 0,
        listWidth: 440,
        url: MODx.config.connector_url,
        baseParams: {action: 'Workspace/Composer/Packages/Search'},
        tpl: new Ext.XTemplate('<tpl for="."><div class="x-combo-list-item">'
            + '<strong>{name:htmlEncode}</strong>'
            + '<div style="font-size:11px;color:#888;">{description:htmlEncode}</div>'
            + '</div></tpl>'),
        itemSelector: 'div.x-combo-list-item'
    });
    MODx.combo.ComposerPackage.superclass.constructor.call(this, config);
};
Ext.extend(MODx.combo.ComposerPackage, MODx.combo.ComboBox);
Ext.reg('modx-combo-composer-package', MODx.combo.ComposerPackage);

MODx.window.ComposerInstall = function(config) {
    config = config || {};
    Ext.applyIf(config, {
        title: _('composer_install_package'),
        width: 480,
        autoHeight: true,
        url: MODx.config.connector_url,
        action: 'Workspace/Composer/Packages/Install',
        saveBtnText: _('composer_install'),
        fields: [{
            xtype: 'modx-combo-composer-package',
            fieldLabel: _('composer_package'),
            name: 'package',
            anchor: '100%',
            allowBlank: false
        }, {
            xtype: 'textfield',
            fieldLabel: _('composer_version_constraint'),
            name: 'constraint',
            anchor: '100%',
            emptyText: _('composer_version_constraint_example')
        }, {
            xtype: 'checkbox',
            boxLabel: _('composer_require_dev'),
            hideLabel: true,
            name: 'dev'
        }, {
            xtype: 'hidden',
            name: 'packages'
        }]
    });
    MODx.window.ComposerInstall.superclass.constructor.call(this, config);
    this.on('beforeSubmit', this.prepareSubmit, this);
};
Ext.extend(MODx.window.ComposerInstall, MODx.Window, {
    prepareSubmit: function() {
        var form = this.fp.getForm();
        var name = String(form.findField('package').getValue() || '').trim();
        var constraintField = form.findField('constraint');
        // composer-ops' package grammar accepts real Composer constraint
        // syntax: space-separated tokens of printable ASCII ("^1.0 || ^2.0",
        // ">=1.0 <2.0", "1.0 - 2.0"), but no leading "-" (flag-safety) and
        // no control characters. Fold whitespace runs (tabs, newlines) into
        // single spaces, then pre-check what the grammar would reject.
        var constraint = String(constraintField.getValue() || '').trim()
            .replace(/\s+/g, ' ');
        constraintField.setValue(constraint);
        if (constraint.charAt(0) === '-' || /[^\x20-\x7E]/.test(constraint)) {
            constraintField.markInvalid(_('composer_constraint_invalid'));
            return false;
        }
        form.findField('packages').setValue(
            Ext.encode([constraint === '' ? name : name + ':' + constraint])
        );
        return true;
    }
});
Ext.reg('modx-window-composer-install', MODx.window.ComposerInstall);

MODx.window.ComposerJob = function(config) {
    config = config || {};
    this.job = config.job || {};
    this.logOffset = 0;
    this.pollTask = null;
    this.pollInFlight = false;
    this.wasActive = this.job.status === 'pending' || this.job.status === 'running';
    this.cancelBtn = new Ext.Button({
        text: _('composer_job_cancel'),
        disabled: !this.wasActive,
        scope: this,
        handler: this.cancelJob
    });
    this.closeBtn = new Ext.Button({
        text: _('close'),
        scope: this,
        handler: function() { this.close(); }
    });
    Ext.applyIf(config, {
        title: Ext.util.Format.htmlEncode(_('composer_job') + ': ' + (this.job.operation || '')
            + (this.job.packages ? ' (' + this.job.packages + ')' : '')),
        width: 640,
        height: 420,
        layout: 'fit',
        modal: true,
        closeAction: 'close',
        cls: 'modx-window',
        items: [{
            xtype: 'panel',
            border: false,
            autoScroll: true,
            bodyStyle: 'background:#282828;color:#e8e8e8;font-family:monospace;font-size:12px;'
                + 'padding:10px;white-space:pre-wrap;word-break:break-word;'
        }],
        buttons: [this.cancelBtn, this.closeBtn]
    });
    MODx.window.ComposerJob.superclass.constructor.call(this, config);
    this.addEvents({complete: true});
    this.logPanel = this.get(0);
    this.on('show', this.startPolling, this);
    // closeAction 'close' destroys the window, so beforedestroy covers both
    // the Close button and a programmatic destroy.
    this.on('beforedestroy', this.stopPolling, this);
};
Ext.extend(MODx.window.ComposerJob, Ext.Window, {
    startPolling: function() {
        if (this.pollTask) { return; }
        this.logOffset = 0;
        this.pollTask = {run: this.poll, scope: this, interval: 1500};
        Ext.TaskMgr.start(this.pollTask);
    },

    stopPolling: function() {
        if (this.pollTask) {
            Ext.TaskMgr.stop(this.pollTask);
            this.pollTask = null;
        }
    },

    poll: function() {
        if (this.pollInFlight || document.hidden) { return; }
        this.pollInFlight = true;
        MODx.Ajax.request({
            url: MODx.config.connector_url,
            params: {
                action: 'Workspace/Composer/Jobs/Get',
                id: this.job.id,
                logOffset: this.logOffset
            },
            listeners: {
                success: {fn: function(r) {
                    this.pollInFlight = false;
                    if (this.isDestroyed) { return; }
                    var job = r.object || {};
                    if (job.logChunk) { this.appendLog(job.logChunk); }
                    if (job.logSize !== null && job.logSize !== undefined) {
                        this.logOffset = job.logSize;
                    }
                    if (job.done) {
                        this.stopPolling();
                        this.appendFinalStatus(job);
                        this.cancelBtn.disable();
                        if (this.wasActive) { this.fireEvent('complete', job); }
                    }
                }, scope: this},
                failure: {fn: function(r) {
                    this.pollInFlight = false;
                    this.stopPolling();
                    if (!this.isDestroyed) {
                        this.appendLog('\n' + (r && r.message ? r.message : _('composer_err')) + '\n');
                        this.cancelBtn.disable();
                    }
                }, scope: this}
            }
        });
    },

    appendLog: function(text) {
        if (!this.logPanel || !this.logPanel.body) { return; }
        var dom = this.logPanel.body.dom;
        dom.appendChild(document.createTextNode(text));
        dom.scrollTop = dom.scrollHeight;
    },

    appendFinalStatus: function(job) {
        var parts = [_('composer_job_status') + ': ' + (job.status || '')]
            .concat(MODx.util.composerJobResult(job));
        // Older records persisted before the pgid field existed have null.
        if (job.pgid !== null && job.pgid !== undefined && job.pgid !== '') {
            parts.push(_('composer_job_pgid') + ': ' + job.pgid);
        }
        var text = parts.join(' | ');
        // A succeeded job can still be a no-op (constraints already satisfied
        // or blocking); say so instead of leaving a bare "succeeded".
        if (job.noChanges) {
            text += '\n' + _('composer_job_no_changes');
        }
        this.appendLog('\n\n' + text + '\n');
    },

    cancelJob: function() {
        MODx.Ajax.request({
            url: MODx.config.connector_url,
            params: {action: 'Workspace/Composer/Jobs/Cancel', id: this.job.id},
            listeners: {
                success: {fn: function() {
                    this.appendLog('\n' + _('composer_job_cancel_requested') + '\n');
                }, scope: this}
            }
        });
    }
});
Ext.reg('modx-window-composer-job', MODx.window.ComposerJob);
