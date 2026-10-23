<?php
declare(strict_types=1);

/**
 * Developer Function Reference catalog for Nuvis StudioX.
 */
final class NuFnRef
{
    /**
     * Get reference categories and documented helper functions/APIs.
     *
     * @return array<string, array{title: string, description: string, items: array<int, array{name: string, category: string, signature: string, description: string, example: string}>}>
     */
    public static function getReference(): array
    {
        return [
            'php' => [
                'title' => 'Backend PHP Reference',
                'description' => 'Core helper methods, database access, audit, security, and export functions available in custom PHP scripts and backend handlers.',
                'items' => [
                    [
                        'name' => 'NuDatabase::getInstance()',
                        'category' => 'Database',
                        'signature' => 'NuDatabase::getInstance(): NuDatabase',
                        'description' => 'Retrieves the global singleton instance for executing database queries.',
                        'example' => '$db = NuDatabase::getInstance(); $rows = $db->fetchAll("SELECT * FROM customers WHERE status = ?", ["active"]);',
                    ],
                    [
                        'name' => 'NuXlsx::create()',
                        'category' => 'Export',
                        'signature' => 'NuXlsx::create(string $title = ""): NuXlsx',
                        'description' => 'Creates a new zero-dependency Excel workbook.',
                        'example' => '$xlsx = NuXlsx::create("Sales Report"); $sheet = $xlsx->sheet("Q1"); $sheet->header(["Item", "Price"]); $sheet->row(["Widget", 99.99]); $xlsx->download("sales.xlsx");',
                    ],
                    [
                        'name' => 'NuAudit::logChange()',
                        'category' => 'Audit & Security',
                        'signature' => 'NuAudit::logChange(string $table, string $recordId, string $action, array $oldData = [], array $newData = []): void',
                        'description' => 'Logs record level field diffs and audit trail entry.',
                        'example' => 'NuAudit::logChange("orders", "101", "UPDATE", $oldOrder, $newOrder);',
                    ],
                    [
                        'name' => 'NuAuth::getInstance()',
                        'category' => 'Auth & ACL',
                        'signature' => 'NuAuth::getInstance(): NuAuth',
                        'description' => 'Access current user context, session, permissions, and roles.',
                        'example' => '$auth = NuAuth::getInstance(); $user = $auth->getCurrentUser();',
                    ],
                    [
                        'name' => 'NuConflict::check()',
                        'category' => 'Concurrency',
                        'signature' => 'NuConflict::check(array $clientSigs, array $currentDbRow, array $submittedData = []): array',
                        'description' => 'Checks if record fields were changed concurrently by another user.',
                        'example' => '$res = NuConflict::check($_POST["_record_sigs"], $currentDbRow, $_POST);',
                    ],
                    [
                        'name' => 'WorkflowEngine::trigger()',
                        'category' => 'Workflow',
                        'signature' => 'WorkflowEngine::trigger(string $workflowCode, string $recordId, string $event = "transition"): bool',
                        'description' => 'Triggers workflow transitions and associated transition hooks.',
                        'example' => 'WorkflowEngine::trigger("customer_request_wf", "123", "APPROVED");',
                    ],
                ]
            ],
            'js' => [
                'title' => 'Client JavaScript Reference',
                'description' => 'Frontend helper functions, calculation engine, global placeholders, and UI modals.',
                'items' => [
                    [
                        'name' => 'nuCalculateFields()',
                        'category' => 'Calculations',
                        'signature' => 'nuCalculateFields(containerElement)',
                        'description' => 'Evaluates field formulas, subform aggregates (SUM, AVG), and updates DOM inputs dynamically.',
                        'example' => 'nuCalculateFields(document.getElementById("nuFormWrap"));',
                    ],
                    [
                        'name' => 'window.nuGetGlobalHashes()',
                        'category' => 'Session & Meta',
                        'signature' => 'window.nuGetGlobalHashes(): Object',
                        'description' => 'Returns current user session metadata and global developer settings as an object.',
                        'example' => 'const meta = window.nuGetGlobalHashes(); console.log(meta.tax_rate, meta.usr_id);',
                    ],
                    [
                        'name' => 'openLookupModal()',
                        'category' => 'Form Builder UI',
                        'signature' => 'openLookupModal(formCode, targetFieldId, displayFieldId, additionalFields)',
                        'description' => 'Launches the lookup search modal for selecting records.',
                        'example' => 'openLookupModal("customers_lk", "cust_id", "cust_name", "phone,email");',
                    ],
                    [
                        'name' => 'window.nuApplyExtraMappings()',
                        'category' => 'Lookups',
                        'signature' => 'window.nuApplyExtraMappings(extraMappingConfig, selectedRow, container)',
                        'description' => 'Applies lookup extra column mapping definitions onto target form fields.',
                        'example' => 'window.nuApplyExtraMappings("price:unit_price\\nqty:1", row, container);',
                    ],
                ]
            ],
            'sql' => [
                'title' => 'SQL & Formula Placeholders',
                'description' => 'Dynamic double-hash and curly-braced placeholders supported in SQL queries, workflows, and form calculations.',
                'items' => [
                    [
                        'name' => '##user_id## / {{user_id}}',
                        'category' => 'User Meta',
                        'signature' => '##user_id##',
                        'description' => 'Substitutes the active authenticated user ID.',
                        'example' => 'SELECT * FROM tasks WHERE assigned_user_id = "##user_id##"',
                    ],
                    [
                        'name' => '##role## / {{role}}',
                        'category' => 'User Meta',
                        'signature' => '##role##',
                        'description' => 'Substitutes the active user role code.',
                        'example' => 'SELECT * FROM approvals WHERE required_role = "##role##"',
                    ],
                    [
                        'name' => '{YEAR} / {MONTH} / {DAY}',
                        'category' => 'Autonumber',
                        'signature' => '{YEAR}',
                        'description' => 'Dynamic date component placeholders for autonumber sequence patterns.',
                        'example' => 'INV-{YEAR}-{MONTH}-{autonumber}',
                    ],
                    [
                        'name' => 'SUM(subform_code.field_name)',
                        'category' => 'Formulas',
                        'signature' => 'SUM(items.line_total)',
                        'description' => 'Aggregates subform row values in parent calculated fields.',
                        'example' => 'SUM(order_items.total_price)',
                    ],
                ]
            ]
        ];
    }
}
