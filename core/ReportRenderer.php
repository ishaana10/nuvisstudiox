<?php
// Nuvis StudioX - Runtime Report Renderer
// Executes saved report SQL safely and renders output

class NuReportRenderer {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = NuDatabase::getInstance();
        $this->auth = new NuAuth();
    }

    public function render($reportCode, $parameters = []) {
        $report = $this->db->fetchOne(
            "SELECT * FROM nu_reports WHERE report_code = :code AND report_active = 1",
            [':code' => $reportCode]
        );
        if (!$report) return ['error' => 'Report not found'];

        $sql = $report['report_sql'] ?? '';
        $columns = json_decode($report['report_columns'] ?? '[]', true) ?? [];
        $filters = json_decode($report['report_filters'] ?? '[]', true) ?? [];

        // Validate SQL is read-only (SELECT only)
        $cleanSql = preg_replace('/\s+/', ' ', strtolower(trim($sql)));
        if (!preg_match('/^select\s/', $cleanSql)) {
            return ['error' => 'Only SELECT queries are allowed in reports'];
        }

        // Apply parameters
        $params = [];
        foreach ($filters as $filter) {
            $key = $filter['name'] ?? '';
            if (isset($parameters[$key]) && $parameters[$key] !== '') {
                $sql = str_replace(':' . $key, ':' . $key, $sql);
                $params[':' . $key] = $parameters[$key];
            }
        }

        try {
            $records = $this->db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return ['error' => 'Query failed: ' . $e->getMessage()];
        }

        // Auto-detect columns if not defined
        if (empty($columns) && !empty($records)) {
            foreach (array_keys($records[0]) as $col) {
                $columns[] = ['field' => $col, 'label' => ucwords(str_replace('_', ' ', $col))];
            }
        }

        return [
            'report' => $report,
            'columns' => $columns,
            'records' => $records,
            'total' => count($records),
            'type' => $report['report_type']
        ];
    }

    public function renderTable($reportCode, $parameters = []) {
        $result = $this->render($reportCode, $parameters);
        if (isset($result['error'])) return '<div class="nu-toast error">' . $result['error'] . '</div>';

        $columns = $result['columns'];
        $records = $result['records'];

        $html = '<div class="nu-report-output">';
        $html .= '<div class="nu-card">';
        $html .= '<div class="nu-card-header">';
        $html .= '<h3 class="nu-card-title">' . htmlspecialchars($result['report']['report_name']) . '</h3>';
        $html .= '<span style="color:var(--text-tertiary);font-size:13px;">' . count($records) . ' records</span>';
        $html .= '</div>';
        $html .= '<div class="nu-table-wrap">';
        $html .= '<table class="nu-table">';
        $html .= '<thead><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col['label'] ?? $col['field']) . '</th>';
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        foreach ($records as $row) {
            $html .= '<tr>';
            foreach ($columns as $col) {
                $val = $row[$col['field']] ?? '';
                $html .= '<td>' . htmlspecialchars($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderChartData($reportCode, $parameters = []) {
        $result = $this->render($reportCode, $parameters);
        if (isset($result['error'])) return $result;

        // Simple chart data extraction - assumes first column = label, second = value
        $labels = [];
        $values = [];
        $columns = $result['columns'];
        $records = $result['records'];

        if (count($columns) >= 2) {
            $labelCol = $columns[0]['field'];
            $valueCol = $columns[1]['field'];
            foreach ($records as $row) {
                $labels[] = $row[$labelCol];
                $values[] = (float)($row[$valueCol] ?? 0);
            }
        }

        return [
            'type' => 'chart',
            'chart_type' => 'bar',
            'labels' => $labels,
            'datasets' => [['label' => $result['report']['report_name'], 'data' => $values]]
        ];
    }
}
?>
