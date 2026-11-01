<?php
declare(strict_types=1);

/**
 * CustomerRequests.php — reusable "Customer Request" engine for Nuvis StudioX.
 *
 * A Customer Request channel is a thin, reusable orchestration over primitives
 * that already exist in the platform:
 *
 *   • Forms          — the data model, layout, validation and builder.
 *   • Public sharing  — a tokenised public intake link (public_form.php) that
 *                       inserts a record, emails staff + customer, and auto-starts
 *                       the bound workflow for every submission.
 *   • Workflows       — status tracking: stages, transitions, SLA, history, and
 *                       hooks (send_email / update_record / create_record) used to
 *                       connect a request to other tables/modules.
 *   • Email templates — notifications.
 *
 * This class wires those together so a developer can stand up a complete,
 * customisable request system in ANY project with one call to provision(),
 * and the module/API read a single config row (nu_cr_configs) to know which
 * form + workflow + share make up "customer requests" in that project.
 *
 * Everything created by provision() remains fully editable afterwards in the
 * Forms, Workflow and Email Settings modules — nothing here is a black box.
 */
final class NuCustomerRequests
{
    private static bool $schemaChecked = false;

    /* ──────────────────────────────────────────────────────────────────────
       SCHEMA (idempotent — safe to run on every request and on old installs)
    ────────────────────────────────────────────────────────────────────── */
    public static function ensureSchema(NuDatabase $db): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;

        $sqlite = $db->getDriverName() === 'sqlite';

        try {
            if ($sqlite) {
                $db->exec('CREATE TABLE IF NOT EXISTS `nu_cr_configs` (
                    `cr_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `project_id` INTEGER NOT NULL DEFAULT 1,
                    `cr_code` TEXT NOT NULL,
                    `cr_name` TEXT NOT NULL,
                    `cr_description` TEXT,
                    `cr_icon` TEXT,
                    `cr_form_code` TEXT NOT NULL,
                    `cr_table` TEXT NOT NULL,
                    `cr_workflow_code` TEXT,
                    `cr_reference_prefix` TEXT,
                    `cr_customer_name_field` TEXT,
                    `cr_customer_email_field` TEXT,
                    `cr_subject_field` TEXT,
                    `cr_public_enabled` INTEGER NOT NULL DEFAULT 1,
                    `cr_notify_email` TEXT,
                    `cr_options` TEXT,
                    `cr_active` INTEGER NOT NULL DEFAULT 1,
                    `cr_created_by` INTEGER,
                    `cr_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `cr_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
                $db->exec('CREATE TABLE IF NOT EXISTS `nu_cr_notes` (
                    `crn_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `project_id` INTEGER NOT NULL DEFAULT 1,
                    `crn_cr_id` INTEGER NOT NULL,
                    `crn_record_id` TEXT NOT NULL,
                    `crn_note` TEXT NOT NULL,
                    `crn_author_id` INTEGER,
                    `crn_author_name` TEXT,
                    `crn_pinned` INTEGER NOT NULL DEFAULT 0,
                    `crn_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
            } else {
                $db->exec('CREATE TABLE IF NOT EXISTS `nu_cr_configs` (
                    `cr_id` INT NOT NULL AUTO_INCREMENT,
                    `project_id` INT UNSIGNED NOT NULL DEFAULT 1,
                    `cr_code` VARCHAR(64) NOT NULL,
                    `cr_name` VARCHAR(150) NOT NULL,
                    `cr_description` TEXT,
                    `cr_icon` VARCHAR(16) DEFAULT NULL,
                    `cr_form_code` VARCHAR(64) NOT NULL,
                    `cr_table` VARCHAR(64) NOT NULL,
                    `cr_workflow_code` VARCHAR(64) DEFAULT NULL,
                    `cr_reference_prefix` VARCHAR(16) DEFAULT NULL,
                    `cr_customer_name_field` VARCHAR(64) DEFAULT NULL,
                    `cr_customer_email_field` VARCHAR(64) DEFAULT NULL,
                    `cr_subject_field` VARCHAR(64) DEFAULT NULL,
                    `cr_public_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `cr_notify_email` VARCHAR(500) DEFAULT NULL,
                    `cr_options` TEXT DEFAULT NULL COMMENT "JSON: SLA, field map, module links, extras",
                    `cr_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `cr_created_by` INT DEFAULT NULL,
                    `cr_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `cr_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`cr_id`),
                    UNIQUE KEY `uq_cr_code_project` (`project_id`, `cr_code`),
                    KEY `idx_cr_active` (`project_id`, `cr_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
                $db->exec('CREATE TABLE IF NOT EXISTS `nu_cr_notes` (
                    `crn_id` INT NOT NULL AUTO_INCREMENT,
                    `project_id` INT UNSIGNED NOT NULL DEFAULT 1,
                    `crn_cr_id` INT NOT NULL,
                    `crn_record_id` VARCHAR(64) NOT NULL,
                    `crn_note` TEXT NOT NULL,
                    `crn_author_id` INT DEFAULT NULL,
                    `crn_author_name` VARCHAR(150) DEFAULT NULL,
                    `crn_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                    `crn_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`crn_id`),
                    KEY `idx_crn_record` (`crn_cr_id`, `crn_record_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
        } catch (Throwable $e) {
            error_log('[customer_requests schema] ' . $e->getMessage());
        }

        self::seedEmailTemplates($db);
    }

    /** Seed the two notification templates this module uses (idempotent). */
    public static function seedEmailTemplates(NuDatabase $db): void
    {
        try {
            if (!$db->fetchOne('SELECT id FROM nu_email_templates LIMIT 1') && false) return; // table missing guard below
        } catch (Throwable $e) {
            return; // email templates table not present yet
        }
        $tpls = [
            [
                'name'        => 'Customer Request — Received',
                'slug'        => 'cr_request_received',
                'subject'     => 'We received your request ({{reference}})',
                'description' => 'Sent to the customer when they submit a request. Variables: {{customer_name}}, {{reference}}, {{request_name}}, {{subject}}, {{status}}, {{app_name}}',
                'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#1e293b">'
                    . '<h2 style="color:#4f6bed;margin:0 0 12px">Thanks, we\'ve got your request</h2>'
                    . '<p>Hi {{customer_name}},</p>'
                    . '<p>We\'ve received your {{request_name}} and our team will be in touch. Your reference is <strong>{{reference}}</strong> — please quote it in any follow-up.</p>'
                    . '<table style="width:100%;border-collapse:collapse;margin:16px 0">'
                    . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Reference</strong></td><td style="padding:8px;border:1px solid #e2e8f0">{{reference}}</td></tr>'
                    . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Subject</strong></td><td style="padding:8px;border:1px solid #e2e8f0">{{subject}}</td></tr>'
                    . '<tr><td style="padding:8px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Status</strong></td><td style="padding:8px;border:1px solid #e2e8f0">{{status}}</td></tr>'
                    . '</table>'
                    . '<p style="font-size:12px;color:#94a3b8">This is an automated message from {{app_name}}.</p>'
                    . '</body></html>',
            ],
            [
                'name'        => 'Customer Request — Status Updated',
                'slug'        => 'cr_status_changed',
                'subject'     => 'Update on your request {{reference}}: {{status}}',
                'description' => 'Sent to the customer when a request changes status. Variables: {{customer_name}}, {{reference}}, {{status}}, {{comment}}, {{app_name}}',
                'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#1e293b">'
                    . '<h2 style="color:#4f6bed;margin:0 0 12px">Your request has an update</h2>'
                    . '<p>Hi {{customer_name}},</p>'
                    . '<p>Request <strong>{{reference}}</strong> is now: <strong>{{status}}</strong>.</p>'
                    . '<p style="background:#f8fafc;border-left:3px solid #4f6bed;padding:10px 14px;margin:14px 0">{{comment}}</p>'
                    . '<p style="font-size:12px;color:#94a3b8">This is an automated message from {{app_name}}.</p>'
                    . '</body></html>',
            ],
        ];
        foreach ($tpls as $t) {
            try {
                $exists = $db->fetchOne('SELECT id FROM nu_email_templates WHERE slug = ?', [$t['slug']]);
                if ($exists) continue;
                $db->insert('nu_email_templates', [
                    'name' => $t['name'], 'slug' => $t['slug'], 'subject' => $t['subject'],
                    'body' => $t['body'], 'description' => $t['description'], 'is_active' => 1,
                ]);
            } catch (Throwable $e) {
                error_log('[customer_requests email seed] ' . $e->getMessage());
            }
        }
    }

    /* ──────────────────────────────────────────────────────────────────────
       BUILT-IN SETUP TEMPLATES
       Each describes the fields (→ form + table) and the status pipeline
       (→ workflow stages + transitions). All are editable after setup.
    ────────────────────────────────────────────────────────────────────── */
    public static function templates(): array
    {
        return [
            'support' => [
                'label'       => 'Support Tickets',
                'icon'        => '🎫',
                'description' => 'Customers raise support issues; your team triages, works and resolves them.',
                'reference_prefix' => 'TKT-',
                'fields' => [
                    ['name' => 'customer_name',  'type' => 'text',     'label' => 'Your name',    'required' => true],
                    ['name' => 'customer_email', 'type' => 'email',    'label' => 'Email',        'required' => true],
                    ['name' => 'subject',        'type' => 'text',     'label' => 'Subject',      'required' => true],
                    ['name' => 'category',        'type' => 'select',   'label' => 'Category',     'options' => ['Technical', 'Billing', 'Account', 'General']],
                    ['name' => 'priority',        'type' => 'select',   'label' => 'Priority',     'options' => ['Low', 'Normal', 'High', 'Urgent']],
                    ['name' => 'description',     'type' => 'textarea', 'label' => 'How can we help?', 'required' => true, 'col' => 12],
                    ['name' => 'attachment',      'type' => 'file',     'label' => 'Attachment (optional)', 'col' => 12],
                ],
                'map' => ['name' => 'customer_name', 'email' => 'customer_email', 'subject' => 'subject'],
                'stages' => [
                    ['code' => 'new',         'name' => 'New',         'color' => '#6366f1', 'start' => true,  'sla' => 24],
                    ['code' => 'open',        'name' => 'Open',        'color' => '#0ea5e9', 'sla' => 48],
                    ['code' => 'in_progress', 'name' => 'In Progress', 'color' => '#f59e0b', 'sla' => 72],
                    ['code' => 'resolved',    'name' => 'Resolved',    'color' => '#10b981'],
                    ['code' => 'closed',      'name' => 'Closed',      'color' => '#64748b', 'end' => true],
                    ['code' => 'rejected',    'name' => 'Rejected',    'color' => '#ef4444', 'end' => true],
                ],
            ],
            'service' => [
                'label'       => 'Service Requests',
                'icon'        => '🛠️',
                'description' => 'Bookable service/job requests: schedule, carry out, and complete.',
                'reference_prefix' => 'SRV-',
                'fields' => [
                    ['name' => 'customer_name',  'type' => 'text',     'label' => 'Your name',  'required' => true],
                    ['name' => 'customer_email', 'type' => 'email',    'label' => 'Email',      'required' => true],
                    ['name' => 'phone',           'type' => 'tel',      'label' => 'Phone'],
                    ['name' => 'service_type',    'type' => 'select',   'label' => 'Service',    'options' => ['Installation', 'Repair', 'Maintenance', 'Inspection', 'Other']],
                    ['name' => 'preferred_date',  'type' => 'date',     'label' => 'Preferred date'],
                    ['name' => 'address',         'type' => 'textarea', 'label' => 'Address',    'col' => 12],
                    ['name' => 'details',         'type' => 'textarea', 'label' => 'Details',    'required' => true, 'col' => 12],
                ],
                'map' => ['name' => 'customer_name', 'email' => 'customer_email', 'subject' => 'service_type'],
                'stages' => [
                    ['code' => 'new',        'name' => 'New',         'color' => '#6366f1', 'start' => true, 'sla' => 24],
                    ['code' => 'scheduled',  'name' => 'Scheduled',   'color' => '#0ea5e9'],
                    ['code' => 'in_progress','name' => 'In Progress', 'color' => '#f59e0b', 'sla' => 48],
                    ['code' => 'completed',  'name' => 'Completed',   'color' => '#10b981'],
                    ['code' => 'closed',     'name' => 'Closed',      'color' => '#64748b', 'end' => true],
                    ['code' => 'cancelled',  'name' => 'Cancelled',   'color' => '#ef4444', 'end' => true],
                ],
            ],
            'contact' => [
                'label'       => 'Contact Enquiries',
                'icon'        => '✉️',
                'description' => 'A simple "contact us" inbox you can track and respond to.',
                'reference_prefix' => 'MSG-',
                'fields' => [
                    ['name' => 'name',    'type' => 'text',     'label' => 'Name',    'required' => true],
                    ['name' => 'email',   'type' => 'email',    'label' => 'Email',   'required' => true],
                    ['name' => 'phone',   'type' => 'tel',      'label' => 'Phone'],
                    ['name' => 'subject', 'type' => 'text',     'label' => 'Subject', 'required' => true],
                    ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'col' => 12],
                ],
                'map' => ['name' => 'name', 'email' => 'email', 'subject' => 'subject'],
                'stages' => [
                    ['code' => 'new',         'name' => 'New',         'color' => '#6366f1', 'start' => true, 'sla' => 24],
                    ['code' => 'in_progress', 'name' => 'In Progress', 'color' => '#f59e0b'],
                    ['code' => 'responded',   'name' => 'Responded',   'color' => '#10b981'],
                    ['code' => 'closed',      'name' => 'Closed',      'color' => '#64748b', 'end' => true],
                ],
            ],
            'bug' => [
                'label'       => 'Bug Reports',
                'icon'        => '🐞',
                'description' => 'Collect, triage and track defects through to a verified fix.',
                'reference_prefix' => 'BUG-',
                'fields' => [
                    ['name' => 'reporter_name',  'type' => 'text',     'label' => 'Your name',  'required' => true],
                    ['name' => 'reporter_email', 'type' => 'email',    'label' => 'Email',      'required' => true],
                    ['name' => 'title',           'type' => 'text',     'label' => 'Title',      'required' => true],
                    ['name' => 'severity',        'type' => 'select',   'label' => 'Severity',   'options' => ['Low', 'Medium', 'High', 'Critical']],
                    ['name' => 'steps',           'type' => 'textarea', 'label' => 'Steps to reproduce', 'col' => 12],
                    ['name' => 'expected',        'type' => 'textarea', 'label' => 'Expected result', 'col' => 12],
                    ['name' => 'actual',          'type' => 'textarea', 'label' => 'Actual result',   'required' => true, 'col' => 12],
                ],
                'map' => ['name' => 'reporter_name', 'email' => 'reporter_email', 'subject' => 'title'],
                'stages' => [
                    ['code' => 'new',         'name' => 'New',         'color' => '#6366f1', 'start' => true],
                    ['code' => 'triaged',     'name' => 'Triaged',     'color' => '#8b5cf6'],
                    ['code' => 'in_progress', 'name' => 'In Progress', 'color' => '#f59e0b', 'sla' => 120],
                    ['code' => 'fixed',       'name' => 'Fixed',       'color' => '#0ea5e9'],
                    ['code' => 'verified',    'name' => 'Verified',    'color' => '#10b981'],
                    ['code' => 'closed',      'name' => 'Closed',      'color' => '#64748b', 'end' => true],
                    ['code' => 'wontfix',     'name' => "Won't Fix",   'color' => '#ef4444', 'end' => true],
                ],
            ],
        ];
    }

    /** Lightweight template list for the UI (no heavy field/stage detail). */
    public static function templateSummaries(): array
    {
        $out = [];
        foreach (self::templates() as $key => $t) {
            $out[] = [
                'key' => $key, 'label' => $t['label'], 'icon' => $t['icon'],
                'description' => $t['description'],
                'field_count' => count($t['fields']), 'stage_count' => count($t['stages']),
                'stages' => array_map(static fn($s) => ['name' => $s['name'], 'color' => $s['color']], $t['stages']),
            ];
        }
        return $out;
    }

    /* ──────────────────────────────────────────────────────────────────────
       PROVISIONING (easy setup)
       $input: name, template (key) | form_code (existing),
               reference_prefix?, notify_email?, public_enabled?, icon?,
               description?, customer_email_field?, customer_name_field?, subject_field?
       Returns the created config row (array).
    ────────────────────────────────────────────────────────────────────── */
    public static function provision(NuDatabase $db, int $projectId, int $userId, array $input): array
    {
        self::ensureSchema($db);

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('A name is required.');

        $code = self::uniqueCode($db, $projectId, self::slugify($name));
        $templateKey = (string)($input['template'] ?? '');
        $templates = self::templates();

        $usingExisting = ($templateKey === 'existing');
        $tpl = (!$usingExisting && isset($templates[$templateKey])) ? $templates[$templateKey] : null;
        if (!$usingExisting && !$tpl) throw new InvalidArgumentException('Choose a template to start from.');

        $icon   = (string)($input['icon'] ?? ($tpl['icon'] ?? '🎫'));
        $prefix = trim((string)($input['reference_prefix'] ?? ($tpl['reference_prefix'] ?? 'REQ-')));
        $notify = trim((string)($input['notify_email'] ?? ''));
        $publicEnabled = isset($input['public_enabled']) ? (int)(bool)$input['public_enabled'] : 1;

        // ── Resolve the backing form + table ────────────────────────────────
        if ($usingExisting) {
            $formCode = trim((string)($input['form_code'] ?? ''));
            if ($formCode === '') throw new InvalidArgumentException('Pick an existing form to attach.');
            $form = $db->fetchOne('SELECT form_code, form_table FROM nu_forms WHERE form_code = ? AND project_id = ?', [$formCode, $projectId]);
            if (!$form) throw new InvalidArgumentException('That form was not found in this project.');
            $table = (string)$form['form_table'];
            if ($table === '') throw new InvalidArgumentException('That form has no backing table.');
            $nameField  = trim((string)($input['customer_name_field'] ?? ''));
            $emailField = trim((string)($input['customer_email_field'] ?? ''));
            $subjField  = trim((string)($input['subject_field'] ?? ''));
            $workflowCode = null; // optional for existing forms; can be created in the Workflow module
        } else {
            $formCode = self::uniqueFormCode($db, $projectId, $code);
            $table    = self::uniqueTable($db, 'cr_' . self::slugify($name, '_'));
            $layout   = self::buildFormLayout($tpl['fields']);
            self::createForm($db, $projectId, $userId, $formCode, $name . ' Request', $table, $layout, $tpl);
            $map        = $tpl['map'] ?? [];
            $nameField  = (string)($map['name'] ?? '');
            $emailField = (string)($map['email'] ?? '');
            $subjField  = (string)($map['subject'] ?? '');
            $workflowCode = self::createWorkflow($db, $projectId, $userId, $code, $name, $formCode, $tpl['stages']);
        }

        // ── Public intake link (reuses the existing form-share system) ──────
        if ($publicEnabled) {
            try { self::ensureShare($db, $projectId, $userId, $formCode, $name, $notify, $emailField); }
            catch (Throwable $e) { error_log('[customer_requests share] ' . $e->getMessage()); }
        }

        // ── Config row ──────────────────────────────────────────────────────
        $options = [
            'created_from' => $usingExisting ? 'existing_form' : $templateKey,
            'module_links' => [],  // developers can add update_record/create_record hooks in the Workflow module
        ];
        $db->insert('nu_cr_configs', [
            'project_id'              => $projectId,
            'cr_code'                 => $code,
            'cr_name'                 => $name,
            'cr_description'          => (string)($input['description'] ?? ($tpl['description'] ?? '')),
            'cr_icon'                 => $icon,
            'cr_form_code'            => $formCode,
            'cr_table'                => $table,
            'cr_workflow_code'        => $workflowCode,
            'cr_reference_prefix'     => $prefix,
            'cr_customer_name_field'  => $nameField ?: null,
            'cr_customer_email_field' => $emailField ?: null,
            'cr_subject_field'        => $subjField ?: null,
            'cr_public_enabled'       => $publicEnabled,
            'cr_notify_email'         => $notify ?: null,
            'cr_options'              => json_encode($options),
            'cr_active'               => 1,
            'cr_created_by'           => $userId > 0 ? $userId : null,
        ]);
        $id = (int)$db->lastInsertId();
        return self::getConfig($db, $projectId, $id);
    }

    /* ── Form creation ──────────────────────────────────────────────────── */
    private static function createForm(NuDatabase $db, int $pid, int $userId, string $formCode, string $formName, string $table, array $layout, array $tpl): void
    {
        $db->insert('nu_forms', [
            'project_id'       => $pid,
            'form_code'        => $formCode,
            'form_type'        => 'main',
            'form_name'        => $formName,
            'form_table'       => $table,
            'form_description' => 'Customer request intake form (created by the Customer Requests module).',
            'form_layout'      => json_encode($layout),
            'form_active'      => 1,
            'form_pk_type'     => 'uuid',
            'form_table_mode'  => 'new',
            'form_created_by'  => $userId > 0 ? $userId : null,
            'form_created_at'  => date('Y-m-d H:i:s'),
            'form_updated_at'  => date('Y-m-d H:i:s'),
        ]);
        self::syncTable($db, $table, $layout);
        try { ProjectContext::registerProjectTable($pid, $table, $formCode); } catch (Throwable $e) {}
    }

    /**
     * Create the backing table from a layout. Prefers the canonical helper in
     * api/forms.php (nu_sync_table_from_layout); falls back to a minimal builder
     * so the engine stays usable even if that file is unavailable.
     */
    private static function syncTable(NuDatabase $db, string $table, array $layout): void
    {
        $helper = dirname(__DIR__) . '/api/forms.php';
        if (function_exists('nu_sync_table_from_layout')) {
            nu_sync_table_from_layout($db, $table, json_encode($layout), 'uuid');
            return;
        }
        // Minimal fallback builder.
        $sqlite = $db->getDriverName() === 'sqlite';
        $pk = $sqlite ? '`id` TEXT NOT NULL PRIMARY KEY' : '`id` VARCHAR(36) NOT NULL PRIMARY KEY';
        $cols = [$pk];
        foreach ($layout as $f) {
            $n = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($f['name'] ?? ''));
            if ($n === '' || in_array(($f['type'] ?? ''), ['divider', 'html', 'group', 'tab', 'button', '_form_style'], true)) continue;
            $cols[] = "`$n` " . (($f['type'] ?? '') === 'textarea' ? 'TEXT NULL' : 'VARCHAR(255) NULL');
        }
        $cols[] = '`user_id` VARCHAR(36) NULL';
        $cols[] = '`location` VARCHAR(255) NULL';
        $cols[] = '`created_at` DATETIME NULL';
        $cols[] = '`updated_at` DATETIME NULL';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $db->exec("CREATE TABLE IF NOT EXISTS `$table` (" . implode(', ', $cols) . ")$engine");
    }

    /** Turn a template field list into a form-builder layout array. */
    public static function buildFormLayout(array $fields): array
    {
        $layout = [];
        $rowIndex = 0;
        foreach ($fields as $f) {
            $node = [
                'type'      => (string)($f['type'] ?? 'text'),
                'name'      => (string)($f['name'] ?? ''),
                'label'     => (string)($f['label'] ?? ucfirst((string)($f['name'] ?? ''))),
                'required'  => !empty($f['required']),
                'col'       => (int)($f['col'] ?? 6),
                'row_index' => $rowIndex++,
            ];
            if (!empty($f['options']) && is_array($f['options'])) {
                $node['options'] = array_map(static fn($o) => ['value' => (string)$o, 'label' => (string)$o], $f['options']);
            }
            $layout[] = $node;
        }
        return $layout;
    }

    /* ── Workflow creation ──────────────────────────────────────────────── */
    private static function createWorkflow(NuDatabase $db, int $pid, int $userId, string $code, string $name, string $formCode, array $stages): string
    {
        $wfCode = self::uniqueWorkflowCode($db, $pid, 'cr_' . $code);
        $db->insert('nu_workflows', [
            'project_id'    => $pid,
            'wf_code'       => $wfCode,
            'wf_name'       => $name . ' Pipeline',
            'wf_description'=> 'Status pipeline for the "' . $name . '" customer request channel.',
            'wf_form_code'  => $formCode,        // binds auto-start on every submission
            'wf_active'     => 1,
            'wf_created_by' => $userId > 0 ? $userId : null,
        ]);
        $wfId = (int)$db->lastInsertId();

        $stageIds = [];
        $order = 0;
        foreach ($stages as $s) {
            $db->insert('nu_workflow_stages', [
                'project_id'  => $pid,
                'wfs_wf_id'   => $wfId,
                'wfs_code'    => (string)$s['code'],
                'wfs_name'    => (string)$s['name'],
                'wfs_color'   => (string)($s['color'] ?? '#6366f1'),
                'wfs_is_start'=> !empty($s['start']) ? 1 : 0,
                'wfs_is_end'  => !empty($s['end']) ? 1 : 0,
                'wfs_order'   => $order++,
                'wfs_sla_hours' => isset($s['sla']) ? (int)$s['sla'] : null,
            ]);
            $stageIds[(string)$s['code']] = (int)$db->lastInsertId();
        }

        // Transitions: advance down the "happy path" (non-end stages in order),
        // plus a one-click jump to the first terminal/negative stage (reject/cancel/wontfix).
        $mainline = array_values(array_filter($stages, static fn($s) => empty($s['end'])));
        for ($i = 0; $i < count($mainline) - 1; $i++) {
            $from = $stageIds[$mainline[$i]['code']] ?? 0;
            $to   = $stageIds[$mainline[$i + 1]['code']] ?? 0;
            if ($from && $to) {
                self::addTransition($db, $pid, $wfId, $from, $to, 'advance', 'Move to ' . $mainline[$i + 1]['name']);
            }
        }
        // Close the last mainline stage into the first positive end stage, if any.
        $endStages = array_values(array_filter($stages, static fn($s) => !empty($s['end'])));
        if ($mainline && $endStages) {
            $lastMain = $stageIds[end($mainline)['code']] ?? 0;
            $firstEnd = $stageIds[$endStages[0]['code']] ?? 0;
            if ($lastMain && $firstEnd) self::addTransition($db, $pid, $wfId, $lastMain, $firstEnd, 'advance', 'Move to ' . $endStages[0]['name']);
            // Negative branch (e.g. Rejected/Cancelled) reachable from the start stage.
            $negative = $endStages[count($endStages) - 1] ?? null;
            if ($negative && count($endStages) > 1) {
                $startId = $stageIds[$mainline[0]['code']] ?? 0;
                $negId   = $stageIds[$negative['code']] ?? 0;
                if ($startId && $negId) self::addTransition($db, $pid, $wfId, $startId, $negId, 'reject', $negative['name'], true);
            }
        }
        return $wfCode;
    }

    private static function addTransition(NuDatabase $db, int $pid, int $wfId, int $from, int $to, string $action, string $label, bool $requireComment = false): void
    {
        $db->insert('nu_workflow_transitions', [
            'project_id' => $pid,
            'wft_wf_id'  => $wfId,
            'wft_from_id'=> $from,
            'wft_to_id'  => $to,
            'wft_action' => $action,
            'wft_label'  => $label,
            'wft_options'=> $requireComment ? json_encode(['require_comment' => true]) : null,
        ]);
    }

    /* ── Public share ───────────────────────────────────────────────────── */
    private static function ensureShare(NuDatabase $db, int $pid, int $userId, string $formCode, string $title, string $notify, string $emailField): void
    {
        $existing = $db->fetchOne('SELECT fs_id FROM nu_form_shares WHERE project_id = ? AND fs_form_code = ?', [$pid, $formCode]);
        if ($existing) {
            $db->update('nu_form_shares', ['fs_enabled' => 1], 'fs_id = ?', [$existing['fs_id']]);
            return;
        }
        require_once dirname(__DIR__) . '/core/PublicForm.php';
        $token  = method_exists('NuPublicForm', 'newToken') ? NuPublicForm::newToken() : bin2hex(random_bytes(16));
        $db->insert('nu_form_shares', [
            'project_id'      => $pid,
            'fs_form_code'    => $formCode,
            'fs_token'        => $token,
            'fs_secret'       => bin2hex(random_bytes(24)),
            'fs_enabled'      => 1,
            'fs_title'        => $title,
            'fs_submit_label' => 'Submit request',
            'fs_success_message' => 'Thank you! Your request has been submitted and our team will be in touch.',
            'fs_notify_email' => $notify ?: null,
            'fs_confirm_email'=> ($emailField !== '' ? 1 : 0),
            'fs_email_field'  => $emailField ?: null,
            'fs_confirm_message' => 'We will contact you shortly.',
            'fs_created_by'   => $userId > 0 ? (string)$userId : null,
        ]);
    }

    public static function publicUrl(NuDatabase $db, int $pid, string $formCode): ?array
    {
        $s = $db->fetchOne('SELECT fs_token, fs_enabled FROM nu_form_shares WHERE project_id = ? AND fs_form_code = ?', [$pid, $formCode]);
        if (!$s) return null;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        return [
            'enabled' => (int)$s['fs_enabled'] === 1,
            'url'     => $scheme . '://' . $host . $dir . '/public_form.php?t=' . $s['fs_token'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────────
       READ HELPERS
    ────────────────────────────────────────────────────────────────────── */
    public static function listConfigs(NuDatabase $db, int $pid): array
    {
        self::ensureSchema($db);
        $rows = $db->fetchAll('SELECT * FROM nu_cr_configs WHERE project_id = ? ORDER BY cr_active DESC, cr_name ASC', [$pid]);
        return array_map(static fn($r) => self::decorate($db, $pid, $r), $rows);
    }

    public static function getConfig(NuDatabase $db, int $pid, int $id): array
    {
        self::ensureSchema($db);
        $r = $db->fetchOne('SELECT * FROM nu_cr_configs WHERE cr_id = ? AND project_id = ?', [$id, $pid]);
        if (!$r) throw new RuntimeException('Request channel not found.');
        return self::decorate($db, $pid, $r);
    }

    private static function decorate(NuDatabase $db, int $pid, array $r): array
    {
        $r['cr_options'] = json_decode((string)($r['cr_options'] ?? ''), true) ?: [];
        $r['public'] = self::publicUrl($db, $pid, (string)$r['cr_form_code']);
        $r['stages'] = [];
        if (!empty($r['cr_workflow_code'])) {
            $wf = $db->fetchOne('SELECT wf_id FROM nu_workflows WHERE wf_code = ? AND project_id = ?', [$r['cr_workflow_code'], $pid]);
            if ($wf) {
                $r['wf_id'] = (int)$wf['wf_id'];
                $r['stages'] = $db->fetchAll('SELECT wfs_id, wfs_code, wfs_name, wfs_color, wfs_is_start, wfs_is_end, wfs_order, wfs_sla_hours FROM nu_workflow_stages WHERE wfs_wf_id = ? ORDER BY wfs_order ASC, wfs_id ASC', [$wf['wf_id']]);
            }
        }
        return $r;
    }

    /* ──────────────────────────────────────────────────────────────────────
       SMALL UTILITIES
    ────────────────────────────────────────────────────────────────────── */
    public static function slugify(string $s, string $sep = '-'): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', $sep, $s) ?? '';
        $s = trim($s, $sep);
        return $s !== '' ? substr($s, 0, 48) : 'requests';
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function uniqueCode(NuDatabase $db, int $pid, string $base): string
    {
        $code = $base; $i = 1;
        while ($db->fetchOne('SELECT cr_id FROM nu_cr_configs WHERE cr_code = ? AND project_id = ?', [$code, $pid])) {
            $code = $base . '_' . (++$i);
        }
        return $code;
    }
    private static function uniqueFormCode(NuDatabase $db, int $pid, string $base): string
    {
        $code = $base; $i = 1;
        while ($db->fetchOne('SELECT form_id FROM nu_forms WHERE form_code = ? AND project_id = ?', [$code, $pid])) {
            $code = $base . '_' . (++$i);
        }
        return $code;
    }
    private static function uniqueWorkflowCode(NuDatabase $db, int $pid, string $base): string
    {
        $code = $base; $i = 1;
        while ($db->fetchOne('SELECT wf_id FROM nu_workflows WHERE wf_code = ? AND project_id = ?', [$code, $pid])) {
            $code = $base . '_' . (++$i);
        }
        return $code;
    }
    private static function uniqueTable(NuDatabase $db, string $base): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base)) ?: 'cr_requests';
        $sqlite = $db->getDriverName() === 'sqlite';
        $exists = static function (string $t) use ($db, $sqlite): bool {
            try {
                return $sqlite
                    ? (bool)$db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$t])
                    : (bool)$db->fetchOne('SHOW TABLES LIKE ?', [$t]);
            } catch (Throwable $e) { return false; }
        };
        $t = $base; $i = 1;
        while ($exists($t)) { $t = $base . '_' . (++$i); }
        return $t;
    }
}
