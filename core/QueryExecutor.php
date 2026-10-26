<?php
// Nuvis StudioX - Safe Query Executor v2

class NuQueryExecutor {
    private $db;
    private $auth;
    private $allowedPrefixes    = ['SELECT'];
    private $forbiddenKeywords  = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE',
        'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'UNION', 'INTO OUTFILE',
        'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'DELAYED', 'LOW_PRIORITY',
        'HIGH_PRIORITY', 'IGNORE', 'FORCE', 'STRAIGHT_JOIN'
    ];

    public function __construct() {
        $this->db   = NuDatabase::getInstance();
        $this->auth = new NuAuth();
    }

    public function execute($queryCode, $parameters = []) {
        $query = $this->db->fetchOne(
            "SELECT * FROM nu_queries WHERE query_code = :code AND query_active = 1",
            [':code' => $queryCode]
        );
        if (!$query) return ['error' => 'Query not found'];

        $defs   = json_decode($query['query_parameters'] ?? '[]', true) ?? [];
        $result = $this->runSql(trim($query['query_sql']), $parameters, $defs);
        if (isset($result['error'])) return $result;
        return ['query' => $query] + $result;
    }

    /**
     * Validate and run an ad-hoc SELECT. Used by execute() for saved queries and by the
     * builder's "Run" for unsaved SQL.
     *
     *  $values   runtime parameter values, keyed by name (without the colon)
     *  $defs     parameter definitions ({label,type,default}) keyed by name
     *  $maxRows  0 = no cap; otherwise rows beyond the cap are dropped and `truncated` is set
     *
     * Only placeholders that actually appear in the SQL are bound (emulated prepares reject
     * extras). A defined placeholder with no value and no default is bound to NULL, so optional
     * filters can be written as `(:status IS NULL OR status = :status)`.
     */
    public function runSql($sql, array $values = [], array $defs = [], $maxRows = 0) {
        $validation = $this->validateSql($sql);
        if (!$validation['valid']) {
            return ['error' => $validation['message']];
        }

        $used  = $this->placeholders($sql);
        $bound = [];
        foreach ($used as $name) {
            $config = is_array($defs[$name] ?? null) ? $defs[$name] : [];
            if (isset($values[$name]) && $values[$name] !== '') {
                $bound[':' . $name] = $this->sanitizeParam($values[$name], $config['type'] ?? 'text');
            } elseif (isset($config['default']) && $config['default'] !== '') {
                $bound[':' . $name] = $this->sanitizeParam($config['default'], $config['type'] ?? 'text');
            } elseif (array_key_exists($name, $defs)) {
                $bound[':' . $name] = null;
            }
        }

        $started = microtime(true);
        try {
            $records = $this->db->fetchAll($sql, $bound);
        } catch (Exception $e) {
            return ['error' => 'Query execution failed: ' . $e->getMessage()];
        }
        $elapsed = (int)round((microtime(true) - $started) * 1000);

        $total     = count($records);
        $truncated = false;
        if ($maxRows > 0 && $total > $maxRows) {
            $records   = array_slice($records, 0, $maxRows);
            $truncated = true;
        }

        return [
            'records'         => $records,
            'columns'         => !empty($records) ? array_keys($records[0]) : [],
            'total'           => count($records),
            'total_available' => $total,
            'truncated'       => $truncated,
            'elapsed_ms'      => $elapsed,
            'parameters_used' => $bound,
            'placeholders'    => $used,
        ];
    }

    /** Names of :placeholders in the SQL, ignoring quoted strings, backticked names and comments. */
    public function placeholders($sql) {
        $clean = preg_replace('/\/\*.*?\*\//s', ' ', (string)$sql);
        $clean = preg_replace('/(--|#)[^\n]*/', ' ', $clean);
        $clean = preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`[^`]*`/s', ' ', $clean);
        preg_match_all('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', $clean, $m);
        return array_values(array_unique($m[1]));
    }

    public function validateSql($sql) {
        // Remove comments
        $cleanSql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $cleanSql = preg_replace('/--.*?\n/', "\n", $cleanSql);
        $cleanSql = preg_replace('/#.*?\n/', "\n", $cleanSql);

        $upperSql = strtoupper(trim($cleanSql));

        if (!preg_match('/^SELECT\s/', $upperSql)) {
            return ['valid' => false, 'message' => 'Query must start with SELECT'];
        }

        foreach ($this->forbiddenKeywords as $keyword) {
            // Use preg_quote so keywords with spaces (e.g. "INTO OUTFILE") are matched literally
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $upperSql)) {
                return ['valid' => false, 'message' => 'Forbidden keyword detected: ' . $keyword];
            }
        }

        if (substr_count($cleanSql, ';') > 1) {
            return ['valid' => false, 'message' => 'Multiple statements not allowed'];
        }

        if (preg_match('/\bINTO\s+/', $upperSql)) {
            return ['valid' => false, 'message' => 'INTO clause not allowed'];
        }

        return ['valid' => true, 'message' => 'Valid'];
    }

    private function sanitizeParam($value, $type) {
        switch ($type) {
            case 'number':
                return is_numeric($value) ? (float)$value : 0;
            case 'date':
                $t = strtotime((string)$value);
                return $t ? date('Y-m-d', $t) : date('Y-m-d');
            case 'select':
                return preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $value);
            default:
                // Bound via PDO, so no SQL escaping is needed — and HTML-escaping here would corrupt
                // values like "O'Brien" or "A&B". Output is escaped where it is rendered.
                return trim((string)$value);
        }
    }

    public function renderParameterForm($queryCode) {
        $query = $this->db->fetchOne(
            "SELECT * FROM nu_queries WHERE query_code = :code",
            [':code' => $queryCode]
        );
        if (!$query) return '';

        $params = json_decode($query['query_parameters'] ?? '[]', true) ?? [];
        if (empty($params)) return '';

        $html  = '<form class="nu-query-params" onsubmit="runQuery(event, \'' . htmlspecialchars($queryCode, ENT_QUOTES) . '\')">';
        $html .= '<div class="nu-form-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">';

        foreach ($params as $key => $config) {
            $type  = $config['type']  ?? 'text';
            $label = $config['label'] ?? ucwords(str_replace('_', ' ', $key));
            $html .= '<div class="nu-field">';
            $html .= '<label>' . htmlspecialchars($label) . '</label>';

            if ($type === 'select' && !empty($config['options'])) {
                $html .= '<select name="' . htmlspecialchars($key, ENT_QUOTES) . '" class="nu-input">';
                $html .= '<option value="">-- All --</option>';
                foreach ($config['options'] as $opt) {
                    $html .= '<option value="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'date') {
                $html .= '<input type="date" name="' . htmlspecialchars($key, ENT_QUOTES) . '" class="nu-input">';
            } elseif ($type === 'number') {
                $html .= '<input type="number" name="' . htmlspecialchars($key, ENT_QUOTES) . '" class="nu-input">';
            } else {
                $html .= '<input type="text" name="' . htmlspecialchars($key, ENT_QUOTES) . '" class="nu-input" placeholder="' . htmlspecialchars($label) . '">';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div style="margin-top:12px;">';
        $html .= '<button type="submit" class="nu-btn nu-btn-primary">Run Query</button>';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="exportQuery(\'' . htmlspecialchars($queryCode, ENT_QUOTES) . '\')">Export CSV</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    public function exportCsv($queryCode, $parameters = []) {
        $result = $this->execute($queryCode, $parameters);
        if (isset($result['error'])) return $result;

        $records = $result['records'];
        $columns = $result['columns'];

        $output = fopen('php://temp', 'w');
        fputcsv($output, $columns);
        foreach ($records as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return ['csv' => $csv, 'filename' => $queryCode . '_' . date('Y-m-d') . '.csv'];
    }
}
