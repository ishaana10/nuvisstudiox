<?php
declare(strict_types=1);
/**
 * Workflow rules: conditions, templates and a small *safe* expression evaluator.
 *
 * Nothing here calls eval(). Conditions come in two shapes and both are accepted anywhere a
 * condition is stored (nu_workflow_transitions.wft_condition, action "when", scenarios):
 *
 *   1. Structured (what the visual builder writes):
 *        {"match":"all","rules":[{"field":"amount","op":">","value":"1000"}, ...]}
 *      Rules may be nested through {"match":"any","rules":[...]} entries.
 *   2. Legacy expression strings, e.g.  amount > 1000 && dept == 'engineering'
 *
 * Context passed to evaluate()/render():
 *   record   fields of the linked record      meta     the instance's JSON meta
 *   actor    {id,name,role}                   instance wfi_* columns + wf_name/wf_code
 *   comment, from_stage, to_stage, now, today
 * A bare field name looks in record first, then meta.
 */
final class NuWfRules
{
    /** Operators the structured builder offers: key => label. */
    public const OPERATORS = [
        '='            => 'equals',
        '!='           => 'does not equal',
        '>'            => 'is greater than',
        '>='           => 'is at least',
        '<'            => 'is less than',
        '<='           => 'is at most',
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'starts_with'  => 'starts with',
        'ends_with'    => 'ends with',
        'in'           => 'is one of (comma list)',
        'not_in'       => 'is not one of',
        'between'      => 'is between (a, b)',
        'empty'        => 'is empty',
        'not_empty'    => 'is not empty',
    ];

    /** Parse a stored condition into ['type'=>'none'|'rules'|'expr', ...]. */
    public static function parse($raw): array
    {
        if (is_array($raw)) {
            return isset($raw['rules']) ? ['type' => 'rules', 'def' => $raw] : ['type' => 'none'];
        }
        $s = trim((string)$raw);
        if ($s === '') return ['type' => 'none'];
        if ($s[0] === '{') {
            $d = json_decode($s, true);
            if (is_array($d) && isset($d['rules'])) {
                return empty($d['rules']) ? ['type' => 'none'] : ['type' => 'rules', 'def' => $d];
            }
        }
        return ['type' => 'expr', 'expr' => $s];
    }

    /** True when the condition passes (empty condition always passes). */
    public static function passes($raw, array $ctx): bool
    {
        return self::explain($raw, $ctx)['pass'];
    }

    /** @return array{pass:bool,reason:string} reason is human readable when the condition fails. */
    public static function explain($raw, array $ctx): array
    {
        $p = self::parse($raw);
        if ($p['type'] === 'none') return ['pass' => true, 'reason' => ''];
        if ($p['type'] === 'expr') {
            try {
                $ok = self::truthy(NuWfExpr::evaluate($p['expr'], $ctx));
            } catch (Throwable $e) {
                return ['pass' => false, 'reason' => 'Condition error: ' . $e->getMessage()];
            }
            return ['pass' => $ok, 'reason' => $ok ? '' : 'Condition not met: ' . $p['expr']];
        }
        $failed = [];
        $ok = self::evalGroup($p['def'], $ctx, $failed);
        return ['pass' => $ok, 'reason' => $ok ? '' : 'Condition not met: ' . implode('; ', array_slice($failed, 0, 3))];
    }

    private static function evalGroup(array $group, array $ctx, array &$failed): bool
    {
        $any   = (($group['match'] ?? 'all') === 'any');
        $rules = $group['rules'] ?? [];
        if (!$rules) return true;
        $results = [];
        foreach ($rules as $r) {
            if (isset($r['rules'])) {
                $results[] = self::evalGroup($r, $ctx, $failed);
                continue;
            }
            $field = (string)($r['field'] ?? '');
            if ($field === '') continue;
            $actual   = self::lookup($field, $ctx);
            $expected = $r['value'] ?? '';
            if (is_string($expected)) $expected = self::render($expected, $ctx);
            $expected2 = $r['value2'] ?? null;
            if (is_string($expected2)) $expected2 = self::render($expected2, $ctx);
            $ok = self::compare($actual, (string)($r['op'] ?? '='), $expected, $expected2);
            if (!$ok) {
                $label = self::OPERATORS[$r['op'] ?? '='] ?? ($r['op'] ?? '=');
                $failed[] = $field . ' ' . $label . (in_array($r['op'] ?? '', ['empty', 'not_empty'], true) ? '' : ' "' . $expected . '"')
                          . ' (is "' . (is_scalar($actual) || $actual === null ? (string)$actual : json_encode($actual)) . '")';
            }
            $results[] = $ok;
        }
        if (!$results) return true;
        return $any ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    public static function compare($actual, string $op, $expected, $expected2 = null): bool
    {
        $a = is_scalar($actual) || $actual === null ? (string)$actual : json_encode($actual);
        $e = is_scalar($expected) || $expected === null ? (string)$expected : json_encode($expected);
        $num = is_numeric($a) && is_numeric($e);
        switch ($op) {
            case '=':  case '==': return $num ? (float)$a == (float)$e : strcasecmp($a, $e) === 0;
            case '!=': case '<>': return $num ? (float)$a != (float)$e : strcasecmp($a, $e) !== 0;
            case '>':  return $num ? (float)$a >  (float)$e : strcmp($a, $e) >  0;
            case '>=': return $num ? (float)$a >= (float)$e : strcmp($a, $e) >= 0;
            case '<':  return $num ? (float)$a <  (float)$e : strcmp($a, $e) <  0;
            case '<=': return $num ? (float)$a <= (float)$e : strcmp($a, $e) <= 0;
            case 'contains':     return $e === '' || stripos($a, $e) !== false;
            case 'not_contains': return $e !== '' && stripos($a, $e) === false;
            case 'starts_with':  return $e === '' || stripos($a, $e) === 0;
            case 'ends_with':    return $e === '' || strcasecmp(substr($a, -strlen($e)), $e) === 0;
            case 'in': case 'not_in':
                $list = array_map('trim', explode(',', $e));
                $hit = false;
                foreach ($list as $item) {
                    if (is_numeric($a) && is_numeric($item) ? (float)$a == (float)$item : strcasecmp($a, $item) === 0) { $hit = true; break; }
                }
                return $op === 'in' ? $hit : !$hit;
            case 'between':
                $lo = $e; $hi = (string)($expected2 ?? '');
                if ($hi === '' && strpos($lo, ',') !== false) { [$lo, $hi] = array_map('trim', explode(',', $lo, 2)); }
                if (is_numeric($a) && is_numeric($lo) && is_numeric($hi)) return (float)$a >= (float)$lo && (float)$a <= (float)$hi;
                return strcmp($a, $lo) >= 0 && strcmp($a, $hi) <= 0;
            case 'empty':     return trim($a) === '';
            case 'not_empty': return trim($a) !== '';
        }
        return false;
    }

    /** Resolve "record.x", "meta.x", "actor.name", "instance.wfi_id" or a bare field. */
    public static function lookup(string $path, array $ctx)
    {
        $path = trim($path);
        if (strpos($path, '.') !== false) {
            [$root, $rest] = explode('.', $path, 2);
            if (in_array($root, ['record', 'meta', 'actor', 'instance', 'target'], true)) {
                return $ctx[$root][$rest] ?? null;
            }
        }
        switch ($path) {
            case 'comment':    return $ctx['comment']    ?? '';
            case 'from_stage': return $ctx['from_stage'] ?? '';
            case 'to_stage':   return $ctx['to_stage']   ?? '';
            case 'now':        return $ctx['now']        ?? date('Y-m-d H:i:s');
            case 'today':      return $ctx['today']      ?? date('Y-m-d');
            case 'actor_name': return $ctx['actor']['name'] ?? '';
            case 'actor_id':   return $ctx['actor']['id']   ?? '';
            case 'actor_role': return $ctx['actor']['role'] ?? '';
        }
        return $ctx['record'][$path] ?? ($ctx['meta'][$path] ?? null);
    }

    /** Replace {{...}} placeholders. Unknown placeholders become an empty string. */
    public static function render(string $tpl, array $ctx): string
    {
        if (strpos($tpl, '{{') === false) return $tpl;
        return (string)preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', static function ($m) use ($ctx) {
            $path = $m[1];
            $aliases = ['wf_name' => 'instance.wf_name', 'wf_code' => 'instance.wf_code', 'wfi_id' => 'instance.wfi_id',
                        'stage.name' => 'to_stage', 'stage.from' => 'from_stage'];
            if (isset($aliases[$path])) $path = $aliases[$path];
            $v = self::lookup($path, $ctx);
            if ($v === null) return '';
            return is_scalar($v) ? (string)$v : (string)json_encode($v);
        }, $tpl);
    }

    /** Render placeholders in every string of a nested value. */
    public static function renderDeep($v, array $ctx)
    {
        if (is_string($v)) return self::render($v, $ctx);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::renderDeep($x, $ctx); }
        return $v;
    }

    public static function truthy($v): bool
    {
        if (is_string($v)) { $t = strtolower(trim($v)); return !in_array($t, ['', '0', 'false', 'no', 'null'], true); }
        return (bool)$v;
    }
}

/**
 * Tiny recursive-descent expression evaluator (no eval). Supports numbers, 'strings', true/false/null,
 * identifiers (record fields, dotted paths), + - * / %, comparisons, && || ! and/or/not, parentheses.
 */
final class NuWfExpr
{
    private array $t = [];
    private int $i = 0;
    private array $ctx;

    public static function evaluate(string $expr, array $ctx)
    {
        $p = new self();
        $p->ctx = $ctx;
        $p->t = $p->tokenize($expr);
        if (!$p->t) throw new InvalidArgumentException('Empty expression');
        $v = $p->parseOr();
        if ($p->i < count($p->t)) throw new InvalidArgumentException("Unexpected '" . $p->t[$p->i][1] . "'");
        return $v;
    }

    private function tokenize(string $s): array
    {
        $out = []; $n = strlen($s); $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) { $i++; continue; }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                $j = $i; while ($j < $n && (ctype_digit($s[$j]) || $s[$j] === '.')) $j++;
                $out[] = ['num', (float)substr($s, $i, $j - $i)]; $i = $j; continue;
            }
            if ($c === "'" || $c === '"') {
                $j = $i + 1; $buf = '';
                while ($j < $n && $s[$j] !== $c) { if ($s[$j] === '\\' && $j + 1 < $n) $j++; $buf .= $s[$j]; $j++; }
                if ($j >= $n) throw new InvalidArgumentException('Unterminated string');
                $out[] = ['str', $buf]; $i = $j + 1; continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i; while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_' || $s[$j] === '.')) $j++;
                $word = substr($s, $i, $j - $i); $lw = strtolower($word);
                if (in_array($lw, ['and', 'or', 'not'], true)) $out[] = ['op', ['and' => '&&', 'or' => '||', 'not' => '!'][$lw]];
                elseif ($lw === 'true' || $lw === 'false') $out[] = ['lit', $lw === 'true'];
                elseif ($lw === 'null') $out[] = ['lit', null];
                else $out[] = ['id', $word];
                $i = $j; continue;
            }
            $three = substr($s, $i, 3); $two = substr($s, $i, 2);
            if ($three === '===' || $three === '!==') { $out[] = ['op', $three === '===' ? '==' : '!=']; $i += 3; continue; }
            if (in_array($two, ['==', '!=', '>=', '<=', '&&', '||', '<>'], true)) { $out[] = ['op', $two === '<>' ? '!=' : $two]; $i += 2; continue; }
            if (strpos('+-*/%<>()!=', $c) !== false) { $out[] = ['op', $c === '=' ? '==' : $c]; $i++; continue; }
            throw new InvalidArgumentException("Unexpected character '$c'");
        }
        return $out;
    }

    private function peekOp(array $ops): ?string
    {
        $t = $this->t[$this->i] ?? null;
        return ($t && $t[0] === 'op' && in_array($t[1], $ops, true)) ? $t[1] : null;
    }

    private function parseOr()
    {
        $v = $this->parseAnd();
        while ($this->peekOp(['||'])) { $this->i++; $r = $this->parseAnd(); $v = NuWfRules::truthy($v) || NuWfRules::truthy($r); }
        return $v;
    }
    private function parseAnd()
    {
        $v = $this->parseCmp();
        while ($this->peekOp(['&&'])) { $this->i++; $r = $this->parseCmp(); $v = NuWfRules::truthy($v) && NuWfRules::truthy($r); }
        return $v;
    }
    private function parseCmp()
    {
        $v = $this->parseAdd();
        while (($op = $this->peekOp(['==', '!=', '>', '>=', '<', '<='])) !== null) {
            $this->i++; $r = $this->parseAdd();
            $v = NuWfRules::compare($v, $op, $r);
        }
        return $v;
    }
    private function parseAdd()
    {
        $v = $this->parseMul();
        while (($op = $this->peekOp(['+', '-'])) !== null) {
            $this->i++; $r = $this->parseMul();
            if ($op === '+' && !(is_numeric($v) && is_numeric($r))) $v = (string)$v . (string)$r;
            else $v = $op === '+' ? (float)$v + (float)$r : (float)$v - (float)$r;
        }
        return $v;
    }
    private function parseMul()
    {
        $v = $this->parseUnary();
        while (($op = $this->peekOp(['*', '/', '%'])) !== null) {
            $this->i++; $r = $this->parseUnary();
            if ($op === '*') $v = (float)$v * (float)$r;
            elseif ((float)$r == 0.0) throw new InvalidArgumentException('Division by zero');
            elseif ($op === '/') $v = (float)$v / (float)$r;
            else $v = fmod((float)$v, (float)$r);
        }
        return $v;
    }
    private function parseUnary()
    {
        if ($this->peekOp(['!'])) { $this->i++; return !NuWfRules::truthy($this->parseUnary()); }
        if ($this->peekOp(['-'])) { $this->i++; return -(float)$this->parseUnary(); }
        return $this->parsePrimary();
    }
    private function parsePrimary()
    {
        $t = $this->t[$this->i] ?? null;
        if (!$t) throw new InvalidArgumentException('Unexpected end of expression');
        $this->i++;
        switch ($t[0]) {
            case 'num': case 'str': case 'lit': return $t[1];
            case 'id':  return NuWfRules::lookup($t[1], $this->ctx);
            case 'op':
                if ($t[1] === '(') {
                    $v = $this->parseOr();
                    if (!$this->peekOp([')'])) throw new InvalidArgumentException("Missing ')'");
                    $this->i++;
                    return $v;
                }
        }
        throw new InvalidArgumentException("Unexpected '" . $t[1] . "'");
    }
}
