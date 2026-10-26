<?php
declare(strict_types=1);

ob_start();

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e['message']]);
    }
    ob_end_flush();
});

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (empty($body)) $body = $_POST;
}

try {
    // Try to ensure schema columns for PDFs exist dynamically
    try {
        $db->query("SELECT report_pdf_template FROM nu_reports LIMIT 1");
    } catch (Throwable $t) {
        try {
            $db->query("ALTER TABLE nu_reports ADD COLUMN report_pdf_template LONGTEXT NULL, ADD COLUMN report_pdf_settings JSON NULL");
        } catch (Throwable $t2) {}
    }

    switch ($action) {

        // ── list all reports ────────────────────────────────────────────────────────────────
        case 'list':
            $pid = ProjectContext::getId();
            $rows = $db->fetchAll(
                "SELECT report_id, report_code, report_name, report_type,
                        report_view_mode, report_active,
                        COALESCE(report_updated_at, report_created_at) AS report_created_at
                 FROM nu_reports
                 WHERE project_id = ?
                 ORDER BY report_name",
                [$pid]
            );
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── get single report ──────────────────────────────────────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $pid = ProjectContext::getId();
            $row = $db->fetchOne(
                "SELECT * FROM nu_reports WHERE report_id = ? AND project_id = ?", [$id, $pid]
            );
            if (!$row) throw new Exception('Report not found or access denied');
            foreach (['report_columns','report_filters','report_settings','report_pdf_settings'] as $col) {
                if (isset($row[$col]) && is_string($row[$col])) {
                    $row[$col] = json_decode($row[$col], true) ?? [];
                }
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ── preview — run raw SQL without saving ───────────────────────────────────────
        case 'preview':
            $sql     = trim($body['report_sql'] ?? '');
            $columns = $body['report_columns'] ?? [];
            if (!$sql) throw new Exception('SQL is required');

            $firstWord = strtoupper(strtok(ltrim($sql), " \t\n\r"));
            if ($firstWord !== 'SELECT') throw new Exception('Only SELECT queries allowed');

            $limit      = max(1, min(1000, (int)($body['limit'] ?? 200)));
            $sql        = rtrim($sql, "; \t\n\r");
            $previewSql = "SELECT * FROM ({$sql}) AS _preview LIMIT {$limit}";
            $started    = microtime(true);
            $rows       = $db->fetchAll($previewSql, []);
            $elapsed    = (int)round((microtime(true) - $started) * 1000);

            if (empty($columns) && !empty($rows)) {
                foreach (array_keys($rows[0]) as $col) {
                    $columns[] = ['field' => $col, 'label' => ucwords(str_replace('_', ' ', $col))];
                }
            }
            echo json_encode(['success' => true, 'data' => $rows, 'columns' => $columns, 'total' => count($rows),
                              'limit' => $limit, 'elapsed_ms' => $elapsed]);
            break;

        // ── save ────────────────────────────────────────────────────────────────────
        case 'save':
            $id          = (int)($body['report_id'] ?? 0);
            $name        = trim($body['report_name'] ?? '');
            $code        = trim($body['report_code'] ?? '');
            $type        = $body['report_type']      ?? 'table';
            $viewMode    = $body['report_view_mode'] ?? 'table';
            $sql         = trim($body['report_sql']  ?? '');
            $columns     = $body['report_columns']   ?? [];
            $filters     = $body['report_filters']   ?? [];
            $settings    = $body['report_settings']  ?? [];
            $pdfTemplate = $body['report_pdf_template'] ?? '';
            $pdfSettings = $body['report_pdf_settings'] ?? [];

            if (!$name) throw new Exception('Report name is required');
            if (!$sql)  throw new Exception('SQL query is required');

            if (!$code) {
                $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
                $code = trim($code, '_');
            }

            $firstWord = strtoupper(strtok(ltrim($sql), " \t\n\r"));
            if ($firstWord !== 'SELECT') throw new Exception('Only SELECT queries are allowed');

            $colsJson     = json_encode(array_values($columns));
            $filtersJson  = json_encode(array_values($filters));
            $settingsJson = json_encode((object)$settings);
            $pdfSetJson   = json_encode((object)$pdfSettings);
            $userId       = $_SESSION['user_id'] ?? null;
            $pid          = ProjectContext::getId();

            if ($id) {
                $existing = $db->fetchOne("SELECT report_id FROM nu_reports WHERE report_id = ? AND project_id = ?", [$id, $pid]);
                if (!$existing) throw new Exception('Report not found or access denied in this project');

                // UPDATE — use $db->query() which exists on NuDatabase
                $db->query(
                    "UPDATE nu_reports SET
                        report_name=?, report_code=?, report_type=?, report_view_mode=?,
                        report_sql=?, report_columns=?, report_filters=?, report_settings=?,
                        report_pdf_template=?, report_pdf_settings=?,
                        report_updated_at=NOW()
                     WHERE report_id=? AND project_id=?",
                    [$name, $code, $type, $viewMode, $sql, $colsJson, $filtersJson, $settingsJson, $pdfTemplate, $pdfSetJson, $id, $pid]
                );
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Report updated']);
            } else {
                // INSERT — use $db->query() which exists on NuDatabase
                $db->query(
                    "INSERT INTO nu_reports
                        (project_id, report_name, report_code, report_type, report_view_mode,
                         report_sql, report_columns, report_filters, report_settings,
                         report_pdf_template, report_pdf_settings,
                         report_created_by, report_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)",
                    [$pid, $name, $code, $type, $viewMode, $sql, $colsJson, $filtersJson, $settingsJson, $pdfTemplate, $pdfSetJson, $userId]
                );
                $newId = $db->lastInsertId();
                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Report created']);
            }
            break;

        // ── delete ─────────────────────────────────────────────────────────────────
        // ── duplicate ───────────────────────────────────────────────────────────────────
        case 'duplicate':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $pid = ProjectContext::getId();
            $src = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id = ? AND project_id = ?", [$id, $pid]);
            if (!$src) throw new Exception('Report not found or access denied');

            $base = preg_replace('/_copy(_\d+)?$/', '', (string)$src['report_code']);
            $code = $base . '_copy';
            for ($n = 2; $db->fetchOne("SELECT report_id FROM nu_reports WHERE report_code = ? AND project_id = ?", [$code, $pid]); $n++) {
                $code = $base . '_copy_' . $n;
            }
            unset($src['report_id'], $src['report_created_at'], $src['report_updated_at']);
            $src['report_code'] = $code;
            $src['report_name'] = $src['report_name'] . ' (copy)';
            $newId = $db->insert('nu_reports', $src);
            echo json_encode(['success' => true, 'report_id' => $newId, 'report_code' => $code]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $pid = ProjectContext::getId();
            $db->query("DELETE FROM nu_reports WHERE report_id = ? AND project_id = ?", [$id, $pid]);
            echo json_encode(['success' => true, 'message' => 'Report deleted']);
            break;

        // ── run saved report ───────────────────────────────────────────────────────────
        case 'run':
            $id   = (int)($_GET['id'] ?? 0);
            $code = trim($_GET['code'] ?? '');

            $filterParams = [];
            foreach ($_GET as $k => $v) {
                if (!in_array($k, ['action','id','code'], true)) {
                    $filterParams[$k] = $v;
                }
            }

            $pid = ProjectContext::getId();
            if ($id) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id=? AND project_id=?", [$id, $pid]);
            } elseif ($code) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_code=? AND project_id=?", [$code, $pid]);
            } else {
                throw new Exception('id or code required');
            }
            if (!$report) throw new Exception('Report not found');

            $sql     = $report['report_sql'];
            $filters = json_decode($report['report_filters'] ?? '[]', true) ?: [];

            // Filter field names and operators come from the saved definition: whitelist both
            // before they are interpolated into SQL (values are always bound).
            $allowedOps = ['=', 'LIKE', '>=', '<=', '>', '<', '!='];
            $whereParts = [];
            $bindings   = [];
            foreach ($filters as $f) {
                $field = (string)($f['field'] ?? '');
                if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) continue;
                if (isset($filterParams[$field]) && $filterParams[$field] !== '') {
                    $op = in_array(($f['operator'] ?? '='), $allowedOps, true) ? $f['operator'] : '=';
                    if ($op === 'LIKE') {
                        $whereParts[] = "`{$field}` LIKE ?";
                        $bindings[]   = '%' . $filterParams[$field] . '%';
                    } else {
                        $whereParts[] = "`{$field}` {$op} ?";
                        $bindings[]   = $filterParams[$field];
                    }
                }
            }

            if ($whereParts) {
                $sql = "SELECT * FROM (" . rtrim((string)$sql, "; \t\n\r") . ") AS _rpt WHERE " . implode(' AND ', $whereParts);
            }

            $started = microtime(true);
            $rows    = $db->fetchAll($sql, $bindings);
            $elapsed = (int)round((microtime(true) - $started) * 1000);
            $columns = json_decode($report['report_columns'] ?? '[]', true) ?: [];

            if (empty($columns) && !empty($rows)) {
                foreach (array_keys($rows[0]) as $col) {
                    $columns[] = ['field' => $col, 'label' => ucwords(str_replace('_', ' ', $col))];
                }
            }

            echo json_encode([
                'success'     => true,
                'data'        => $rows,
                'columns'     => $columns,
                'total'       => count($rows),
                'elapsed_ms'  => $elapsed,
                'view_mode'   => $report['report_view_mode'] ?? 'table',
                'report_name' => $report['report_name'],
            ]);
            break;

        // ── list tables ─────────────────────────────────────────────────────────────────
        case 'tables':
            $tables = $db->fetchAll("SHOW TABLES");
            $flat   = array_map(fn($r) => array_values($r)[0], $tables);
            echo json_encode(['success' => true, 'data' => $flat]);
            break;

        // ── list columns of a table ────────────────────────────────────────────────────
        case 'columns':
            $table = preg_replace('/[^a-z0-9_]/i', '', $_GET['table'] ?? '');
            if (!$table) throw new Exception('table required');
            $cols = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
            echo json_encode(['success' => true, 'data' => $cols]);
            break;

        // ── export dynamic report as PDF ────────────────────────────────────────────────
        case 'export_pdf':
            require_once dirname(__DIR__) . '/core/PdfGenerator.php';
            $id   = (int)($_GET['id'] ?? 0);
            $code = trim($_GET['code'] ?? '');

            $filterParams = [];
            foreach ($_GET as $k => $v) {
                if (!in_array($k, ['action','id','code'], true)) {
                    $filterParams[$k] = $v;
                }
            }

            if ($id) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id=?", [$id]);
            } elseif ($code) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_code=?", [$code]);
            } else {
                throw new Exception('id or code required');
            }
            if (!$report) throw new Exception('Report not found');

            $sql     = $report['report_sql'];
            $filters = json_decode($report['report_filters'] ?? '[]', true) ?: [];

            $whereParts = [];
            $bindings   = [];
            foreach ($filters as $f) {
                $field = $f['field'] ?? '';
                if ($field && isset($filterParams[$field]) && $filterParams[$field] !== '') {
                    $op = $f['operator'] ?? '=';
                    if ($op === 'LIKE') {
                        $whereParts[] = "`{$field}` LIKE ?";
                        $bindings[]   = '%' . $filterParams[$field] . '%';
                    } else {
                        $whereParts[] = "`{$field}` {$op} ?";
                        $bindings[]   = $filterParams[$field];
                    }
                }
            }

            if ($whereParts) {
                $sql = "SELECT * FROM ({$sql}) AS _rpt WHERE " . implode(' AND ', $whereParts);
            }

            $rows = $db->fetchAll($sql, $bindings);

            // Prepare settings for generator
            $pdfSettings = json_decode($report['report_pdf_settings'] ?? '{}', true) ?: [];

            $pdfContent = NuPdfGenerator::generate($report, $rows, $pdfSettings);

            ob_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . ($report['report_code'] ?: 'report') . '.pdf"');
            echo $pdfContent;
            exit;

        // ── email report as PDF attachment ──────────────────────────────────────────────
        case 'email_pdf':
            require_once dirname(__DIR__) . '/core/PdfGenerator.php';
            require_once dirname(__DIR__) . '/core/EmailService.php';

            // Accept from POST body or query string
            $id       = (int)($body['id'] ?? $_GET['id'] ?? 0);
            $to       = trim((string)($body['to'] ?? $_GET['to'] ?? ''));
            $subject  = trim((string)($body['subject'] ?? $_GET['subject'] ?? ''));
            $emailMsg = trim((string)($body['message'] ?? $_GET['message'] ?? ''));

            if (!$id) throw new Exception('Report ID is required');
            if (!$to) throw new Exception('Recipient email (to) is required');

            $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id=?", [$id]);
            if (!$report) throw new Exception('Report not found');

            // Apply filters from either GET or POST body (filters)
            $filterParams = [];
            foreach ($_GET as $k => $v) {
                if (!in_array($k, ['action','id','code','to','subject','message'], true)) {
                    $filterParams[$k] = $v;
                }
            }
            if (isset($body['filters']) && is_array($body['filters'])) {
                $filterParams = array_merge($filterParams, $body['filters']);
            }

            $sql     = $report['report_sql'];
            $filters = json_decode($report['report_filters'] ?? '[]', true) ?: [];

            $whereParts = [];
            $bindings   = [];
            foreach ($filters as $f) {
                $field = $f['field'] ?? '';
                if ($field && isset($filterParams[$field]) && $filterParams[$field] !== '') {
                    $op = $f['operator'] ?? '=';
                    if ($op === 'LIKE') {
                        $whereParts[] = "`{$field}` LIKE ?";
                        $bindings[]   = '%' . $filterParams[$field] . '%';
                    } else {
                        $whereParts[] = "`{$field}` {$op} ?";
                        $bindings[]   = $filterParams[$field];
                    }
                }
            }

            if ($whereParts) {
                $sql = "SELECT * FROM ({$sql}) AS _rpt WHERE " . implode(' AND ', $whereParts);
            }

            $rows = $db->fetchAll($sql, $bindings);

            // Generate PDF
            $pdfSettings = json_decode($report['report_pdf_settings'] ?? '{}', true) ?: [];
            $pdfContent = NuPdfGenerator::generate($report, $rows, $pdfSettings);

            // Use EmailService to send
            if (empty($subject)) {
                $subject = "Report PDF: " . $report['report_name'];
            }
            if (empty($emailMsg)) {
                $emailMsg = "<p>Please find attached the PDF report for <b>" . htmlspecialchars($report['report_name']) . "</b>.</p>";
            } else {
                $emailMsg = "<p>" . nl2br(htmlspecialchars($emailMsg)) . "</p>";
            }

            $filename = ($report['report_code'] ?: 'report') . '.pdf';

            $emailService = new EmailService();
            $options = [
                'attachments' => [
                    [
                        'data' => $pdfContent,
                        'filename' => $filename,
                        'mimetype' => 'application/pdf'
                    ]
                ]
            ];

            $res = $emailService->send($to, $subject, $emailMsg, $options);
            echo json_encode($res);
            break;

        // ── fetch sample starter layouts ────────────────────────────────────────────────
        case 'starter_templates':
            require_once dirname(__DIR__) . '/core/PdfGenerator.php';
            echo json_encode([
                'success' => true,
                'data' => [
                    'invoice'     => NuPdfGenerator::getInvoiceTemplate(),
                    'receipt'     => NuPdfGenerator::getReceiptTemplate(),
                    'certificate' => NuPdfGenerator::getCertificateTemplate()
                ]
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
