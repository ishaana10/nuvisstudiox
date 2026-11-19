<?php
declare(strict_types=1);
/**
 * NuWidgetSql — the one place widget SQL is prepared and run.
 * Only a single SELECT statement is ever executed.
 */
class NuWidgetSql {
    public static function substitute(string $sql, int $userId, string $role): string {
        return strtr($sql, [
            '{{user_id}}'    => (string)$userId,
            '{{role}}'       => $role,
            '{{today}}'      => date('Y-m-d'),
            '{{this_month}}' => date('Y-m-01'),
            '{{this_year}}'  => date('Y-01-01'),
        ]);
    }

    /**
     * @return array<int,array> rows; empty when the SQL is not a single SELECT
     * @throws RuntimeException with the database message when the query fails
     */
    public static function run(NuDatabase $db, string $sql, int $userId, string $role): array {
        $sql = rtrim(trim(self::substitute($sql, $userId, $role)), "; \t\r\n");
        if (!preg_match('/^SELECT\b/i', $sql) || self::hasExtraStatement($sql)) {
            return [];
        }
        try {
            return $db->fetchAll($sql) ?: [];
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** True when a ';' appears outside single/double-quoted string literals. */
    private static function hasExtraStatement(string $sql): bool {
        $quote = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== '') {
                if ($c === '\\') { $i++; continue; }
                if ($c === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) { $i++; continue; }
                    $quote = '';
                }
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === ';') {
                return true;
            }
        }
        return false;
    }
}
