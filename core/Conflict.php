<?php
declare(strict_types=1);

/**
 * Concurrent edits & optimistic collision detection for Nuvis StudioX.
 */
final class NuConflict
{
    /**
     * Compute a short hash signature for a column value.
     */
    public static function sig(mixed $v): string
    {
        return $v === null ? 'n' : substr(md5((string)$v), 0, 12);
    }

    /**
     * Compute field signatures for an entire database row.
     *
     * @param array $row
     * @return array<string, string>
     */
    public static function rowSigs(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[strtolower((string)$k)] = self::sig($v);
        }
        return $out;
    }

    /**
     * Compare client's record signatures recorded when form was loaded
     * against current record signatures in the database.
     *
     * @param array<string, string> $clientSigs Associative array of field => signature
     * @param array $currentDbRow Associative array of current DB row data
     * @param array $submittedData Array of fields being saved in this request
     * @return array{hasConflict: bool, conflicts: array<string, array{clientSig: string, dbSig: string, currentVal: mixed}>}
     */
    public static function check(array $clientSigs, array $currentDbRow, array $submittedData = []): array
    {
        $conflicts = [];
        $dbSigs = self::rowSigs($currentDbRow);

        $submittedLower = [];
        foreach ($submittedData as $k => $v) {
            $submittedLower[strtolower((string)$k)] = $v;
        }

        foreach ($clientSigs as $field => $origSig) {
            $fieldLower = strtolower((string)$field);
            // Skip fields not present in current DB record
            if (!array_key_exists($fieldLower, $dbSigs)) {
                continue;
            }

            // If submittedData is provided, only check fields that were actually submitted
            if (!empty($submittedData) && !array_key_exists($fieldLower, $submittedLower)) {
                continue;
            }

            $currentDbSig = $dbSigs[$fieldLower];
            // If the database value changed since the client loaded the form
            if ($origSig !== $currentDbSig) {
                $submittedVal = $submittedLower[$fieldLower] ?? null;
                $currentDbVal = $currentDbRow[$field] ?? $currentDbRow[$fieldLower] ?? null;

                // Check if submitted value differs from current DB value
                if (self::sig($submittedVal) !== $currentDbSig) {
                    $conflicts[$field] = [
                        'clientSig' => $origSig,
                        'dbSig' => $currentDbSig,
                        'currentVal' => $currentDbVal,
                    ];
                }
            }
        }

        return [
            'hasConflict' => !empty($conflicts),
            'conflicts' => $conflicts,
        ];
    }
}
