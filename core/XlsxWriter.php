<?php
declare(strict_types=1);

/**
 * Native Excel export for Nuvis StudioX: a lightweight, zero-dependency OpenXML (.xlsx) writer using PHP's ZipArchive.
 */
final class NuXlsx
{
	public const MAX_ROWS = 1048576;
	public const MAX_COLS = 16384;
	public const MAX_TEXT = 32767;
	public const STYLE_KEYS = ['bold', 'italic', 'underline', 'strike', 'size', 'font', 'color', 'bg', 'format', 'align', 'valign', 'wrap', 'border'];
	private const BORDERS = ['thin', 'medium', 'thick', 'dashed', 'dotted', 'double', 'hair'];
	private const BUILTIN_FORMATS = ['0' => 1, '0.00' => 2, '#,##0' => 3, '#,##0.00' => 4, '0%' => 9, '0.00%' => 10, '0.00E+00' => 11, '@' => 49];
	private const FUTURE_FUNCTIONS = [
		'ACOT', 'ACOTH', 'AGGREGATE', 'ARABIC', 'BASE', 'BETA.DIST', 'BETA.INV', 'BINOM.DIST', 'BINOM.DIST.RANGE', 'BINOM.INV', 'BITAND', 'BITLSHIFT',
		'BITOR', 'BITRSHIFT', 'BITXOR', 'CEILING.MATH', 'CEILING.PRECISE', 'CHISQ.DIST', 'CHISQ.DIST.RT', 'CHISQ.INV', 'CHISQ.INV.RT', 'CHISQ.TEST',
		'COMBINA', 'CONCAT', 'CONFIDENCE.NORM', 'CONFIDENCE.T', 'COT', 'COTH', 'COVARIANCE.P', 'COVARIANCE.S', 'CSC', 'CSCH', 'DAYS', 'DECIMAL',
		'ENCODEURL', 'ERF.PRECISE', 'ERFC.PRECISE', 'EXPON.DIST', 'F.DIST', 'F.DIST.RT', 'F.INV', 'F.INV.RT', 'F.TEST', 'FILTERXML', 'FLOOR.MATH',
		'FLOOR.PRECISE', 'FORECAST.LINEAR', 'FORMULATEXT', 'GAMMA', 'GAMMA.DIST', 'GAMMA.INV', 'GAMMALN.PRECISE', 'GAUSS', 'HYPGEOM.DIST', 'IFNA',
		'IFS', 'IMCOSH', 'IMCOT', 'IMCSC', 'IMCSCH', 'IMSEC', 'IMSECH', 'IMSINH', 'IMTAN', 'ISFORMULA', 'ISOWEEKNUM', 'LOGNORM.DIST', 'LOGNORM.INV',
		'MAXIFS', 'MINIFS', 'MODE.MULT', 'MODE.SNGL', 'MUNIT', 'NEGBINOM.DIST', 'NORM.DIST', 'NORM.INV', 'NORM.S.DIST', 'NORM.S.INV', 'NUMBERVALUE',
		'PERMUTATIONA', 'PHI', 'POISSON.DIST', 'RANK.AVG', 'RANK.EQ', 'SEC', 'SECH', 'SKEW.P', 'STDEV.P', 'STDEV.S', 'T.DIST', 'T.DIST.2T',
		'T.DIST.RT', 'T.INV', 'T.INV.2T', 'T.TEST', 'TEXTJOIN', 'UNICHAR', 'UNICODE', 'VAR.P', 'VAR.S', 'WEIBULL.DIST', 'XLOOKUP', 'XMATCH', 'Z.TEST',
	];

	private static ?string $futureRegex = null;

	/** @var NuXlsxSheet[] */
	private array $sheets = [];
	private array $props = ['title' => '', 'creator' => 'Nuvis StudioX', 'company' => ''];
	private string $dateFormat = 'yyyy\-mm\-dd';
	private string $dateTimeFormat = 'yyyy\-mm\-dd\ hh:mm:ss';
	private array $xfIndex = [];

	public static function create(string $title = ''): self
	{
		$x = new self();
		if ($title !== '') $x->props['title'] = $title;
		return $x;
	}

	public function sheet(string $name = ''): NuXlsxSheet
	{
		$s = new NuXlsxSheet($this, $this->uniqueName($name !== '' ? $name : 'Sheet' . (count($this->sheets) + 1)));
		$this->sheets[] = $s;
		return $s;
	}

	/** @return NuXlsxSheet[] */
	public function sheets(): array { return $this->sheets; }

	public static function cell(mixed $value, array $style = []): NuXlsxCell
	{
		return new NuXlsxCell($value, self::cleanStyle($style));
	}

	public static function formula(string $f, mixed $value = null): NuXlsxCell
	{
		$f = trim($f);
		if ($f === '' || $f[0] !== '=') $f = '=' . $f;
		$f = substr($f, 1);
		if ($f === '') throw new InvalidArgumentException('NuXlsx: empty formula');
		return new NuXlsxCell(self::prefixFutureFunctions($f), [], true, $value);
	}

	public function save(string $filename): self
	{
		$tmp = self::tempFile();
		$this->writeZip($tmp);
		if (!@rename($tmp, $filename)) {
			copy($tmp, $filename);
			@unlink($tmp);
		}
		return $this;
	}

	public function toString(): string
	{
		$tmp = self::tempFile();
		$this->writeZip($tmp);
		$s = (string)file_get_contents($tmp);
		@unlink($tmp);
		return $s;
	}

	public function download(string $filename = 'export.xlsx'): void
	{
		[$ascii, $utf8] = self::downloadNames($filename);
		$data = $this->toString();
		if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
		@ini_set('zlib.output_compression', '0');
		if (ob_get_level()) @ob_end_clean();

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($utf8));
		header('Content-Length: ' . strlen($data));
		header('Cache-Control: max-age=0, must-revalidate, private');
		header('X-Content-Type-Options: nosniff');
		echo $data;
		exit;
	}

	public static function esc(string $s): string
	{
		return htmlspecialchars((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	public static function colName(int $col): string
	{
		if ($col < 1 || $col > self::MAX_COLS) throw new InvalidArgumentException('NuXlsx: column ' . $col . ' out of bounds');
		$s = '';
		while ($col > 0) {
			$m = ($col - 1) % 26;
			$s = chr(65 + $m) . $s;
			$col = intdiv($col - $m, 26);
		}
		return $s;
	}

	public static function colIndex(string $col): int
	{
		$col = strtoupper(trim($col));
		if (!preg_match('/^[A-Z]{1,3}$/', $col)) throw new InvalidArgumentException('NuXlsx: invalid column ' . $col);
		$n = 0;
		for ($i = 0; $i < strlen($col); $i++) $n = $n * 26 + (ord($col[$i]) - 64);
		if ($n < 1 || $n > self::MAX_COLS) throw new InvalidArgumentException('NuXlsx: invalid column ' . $col);
		return $n;
	}

	public static function range(string $range): array
	{
		$range = strtoupper(trim($range));
		if (preg_match('/^([A-Z]{1,3})([1-9]\d{0,6})(?::([A-Z]{1,3})([1-9]\d{0,6}))?$/', $range, $m)) {
			$c1 = self::colIndex($m[1]); $r1 = (int)$m[2];
			$c2 = isset($m[3]) && $m[3] !== '' ? self::colIndex($m[3]) : $c1;
			$r2 = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : $r1;
			if ($r1 > self::MAX_ROWS || $r2 > self::MAX_ROWS) throw new InvalidArgumentException('NuXlsx: row out of bounds in ' . $range);
			return [min($r1, $r2), min($c1, $c2), max($r1, $r2), max($c1, $c2)];
		}
		throw new InvalidArgumentException('NuXlsx: invalid range ' . $range);
	}

	public static function rangeRef(int $r1, int $c1, int $r2, int $c2): string
	{
		$p1 = self::colName($c1) . $r1;
		$p2 = self::colName($c2) . $r2;
		return $p1 === $p2 ? $p1 : $p1 . ':' . $p2;
	}

	public static function serial(DateTimeInterface $dt): float
	{
		$ts = $dt->getTimestamp();
		return $ts / 86400 + 25569;
	}

	public static function cleanStyle(array $st): array
	{
		$out = [];
		foreach ($st as $k => $v) {
			if (!in_array($k, self::STYLE_KEYS, true) || $v === null || $v === '') continue;
			if ($v === false) { $out[$k] = false; continue; }
			switch ($k) {
				case 'bold': case 'italic': case 'underline': case 'strike': case 'wrap': $out[$k] = true; break;
				case 'size': $n = (float)$v; if ($n >= 1 && $n <= 409) $out[$k] = $n; break;
				case 'font': if (preg_match('/^[\w .\-]{1,31}$/u', (string)$v)) $out[$k] = (string)$v; break;
				case 'color': case 'bg': if (preg_match('/^#?([0-9a-f]{6})$/i', (string)$v, $m)) $out[$k] = strtoupper($m[1]); break;
				case 'format': if (mb_strlen((string)$v) <= 200) $out[$k] = (string)$v; break;
				case 'align': if (in_array($v, ['left', 'center', 'right'], true)) $out[$k] = $v; break;
				case 'valign': if (in_array($v, ['top', 'middle', 'bottom'], true)) $out[$k] = $v; break;
				case 'border': if (in_array($v, self::BORDERS, true) || $v === true) $out[$k] = $v === true ? 'thin' : $v; break;
			}
		}
		ksort($out);
		return $out;
	}

	private static function tempFile(): string
	{
		if (!class_exists('ZipArchive')) throw new RuntimeException('NuXlsx: the PHP extension zip is missing');
		$tmp = tempnam(sys_get_temp_dir(), 'nuxlsx');
		if ($tmp === false) throw new RuntimeException('NuXlsx: no temporary file');
		return $tmp;
	}

	private static function downloadNames(string $filename): array
	{
		$utf8 = trim((string)preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', mb_scrub($filename, 'UTF-8')), ' ._');
		$utf8 = mb_substr($utf8 !== '' ? $utf8 : 'export', 0, 200);
		if (!preg_match('/\.xlsx$/i', $utf8)) $utf8 .= '.xlsx';
		$ascii = strtr($utf8, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
		$ascii = trim((string)preg_replace('/[^A-Za-z0-9_. -]+/', '_', $ascii), ' ._');
		if ($ascii === '' || strcasecmp($ascii, 'xlsx') === 0) $ascii = 'export';
		if (!preg_match('/\.xlsx$/i', $ascii)) $ascii .= '.xlsx';
		return [$ascii, $utf8];
	}

	private static function prefixFutureFunctions(string $f): string
	{
		if (self::$futureRegex === null) {
			$names = self::FUTURE_FUNCTIONS;
			usort($names, static fn($a, $b) => strlen($b) <=> strlen($a));
			self::$futureRegex = '/(?<![\w.])(' . implode('|', array_map(static fn($n) => preg_quote($n, '/'), $names)) . ')(?=\s*\()/i';
		}
		$parts = preg_split('/("(?:[^"]|"")*")/', $f, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) return $f;
		foreach ($parts as $i => $p) {
			if ($i % 2 === 0 && $p !== '') $parts[$i] = (string)preg_replace_callback(self::$futureRegex, static fn($m) => '_xlfn.' . strtoupper($m[1]), $p);
		}
		return implode('', $parts);
	}

	private function uniqueName(string $name): string
	{
		$n = trim((string)preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name), " '");
		$n = mb_substr($n !== '' ? $n : 'Sheet', 0, 31);
		$taken = array_map(static fn(NuXlsxSheet $s) => mb_strtolower($s->name()), $this->sheets);
		$taken[] = 'history';
		$base = $n; $i = 2;
		while (in_array(mb_strtolower($n), $taken, true)) $n = mb_substr($base, 0, 31 - strlen((string)$i) - 1) . ' ' . $i++;
		return $n;
	}

	private function writeZip(string $path): void
	{
		if (!class_exists('ZipArchive')) throw new RuntimeException('NuXlsx: the PHP extension zip is missing');
		if (!$this->sheets) $this->sheet();
		$strings = new NuXlsxStrings();
		$styles = $this->collectStyles();
		$parts = [];
		foreach ($this->sheets as $i => $s) $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $s->xml($strings, fn(array $st, string $kind) => $this->xf($st, $kind));
		$parts['xl/sharedStrings.xml'] = $strings->xml();
		$parts['xl/styles.xml'] = $styles;
		$parts['xl/workbook.xml'] = $this->workbookXml();
		$parts['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
		$parts['[Content_Types].xml'] = $this->contentTypes();
		$parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>';
		$parts['docProps/core.xml'] = $this->coreXml();
		$parts['docProps/app.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Nuvis StudioX</Application>'
			. ($this->props['company'] !== '' ? '<Company>' . self::esc($this->props['company']) . '</Company>' : '') . '</Properties>';

		$z = new ZipArchive();
		if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('NuXlsx: cannot create the zip file');
		foreach (['[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/sharedStrings.xml'] as $n) {
			$z->addFromString($n, $parts[$n]);
			unset($parts[$n]);
		}
		foreach ($parts as $n => $xml) $z->addFromString($n, $xml);
		if (!$z->close()) throw new RuntimeException('NuXlsx: cannot write the zip file');
	}

	private function collectStyles(): string
	{
		$used = [];
		foreach ($this->sheets as $s) foreach ($s->usedStyles() as $k => [$st, $kind]) $used[$k] = [$st, $kind];
		$fonts = ['<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'];
		$fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
		$borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
		$numFmts = [];
		$xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
		$this->xfIndex = [];
		foreach ($used as $key => [$st, $kind]) {
			$font = '<font>' . (!empty($st['bold']) ? '<b/>' : '') . (!empty($st['italic']) ? '<i/>' : '') . (!empty($st['strike']) ? '<strike/>' : '')
				. (!empty($st['underline']) ? '<u/>' : '') . '<sz val="' . ($st['size'] ?? 11) . '"/>' . (isset($st['color']) ? '<color rgb="FF' . $st['color'] . '"/>' : '')
				. '<name val="' . self::esc($st['font'] ?? 'Calibri') . '"/><family val="2"/></font>';
			$fontId = array_search($font, $fonts, true);
			if ($fontId === false) { $fonts[] = $font; $fontId = count($fonts) - 1; }
			$fillId = 0;
			if (isset($st['bg'])) {
				$fill = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $st['bg'] . '"/><bgColor indexed="64"/></patternFill></fill>';
				$fillId = array_search($fill, $fills, true);
				if ($fillId === false) { $fills[] = $fill; $fillId = count($fills) - 1; }
			}
			$borderId = 0;
			if (isset($st['border'])) {
				$b = '<border>' . implode('', array_map(static fn($side) => '<' . $side . ' style="' . $st['border'] . '"><color auto="1"/></' . $side . '>', ['left', 'right', 'top', 'bottom'])) . '<diagonal/></border>';
				$borderId = array_search($b, $borders, true);
				if ($borderId === false) { $borders[] = $b; $borderId = count($borders) - 1; }
			}
			$fmt = $st['format'] ?? ($kind === 'date' ? $this->dateFormat : ($kind === 'datetime' ? $this->dateTimeFormat : ''));
			$numFmtId = 0;
			if ($fmt !== '') {
				if (isset(self::BUILTIN_FORMATS[$fmt])) $numFmtId = self::BUILTIN_FORMATS[$fmt];
				else {
					$numFmtId = array_search($fmt, $numFmts, true);
					if ($numFmtId === false) { $numFmtId = 164 + count($numFmts); $numFmts[$numFmtId] = $fmt; }
				}
			} elseif ($kind === 'date') $numFmtId = 14;
			elseif ($kind === 'datetime') $numFmtId = 22;
			$align = '';
			if (isset($st['align']) || isset($st['valign']) || !empty($st['wrap'])) {
				$align = '<alignment' . (isset($st['align']) ? ' horizontal="' . $st['align'] . '"' : '')
					. (isset($st['valign']) ? ' vertical="' . ['top' => 'top', 'middle' => 'center', 'bottom' => 'bottom'][$st['valign']] . '"' : '')
					. (!empty($st['wrap']) ? ' wrapText="1"' : '') . '/>';
			}
			$xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
				. ($numFmtId ? ' applyNumberFormat="1"' : '') . ($fontId ? ' applyFont="1"' : '') . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
				. ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
			$at = array_search($xf, $xfs, true);
			if ($at === false) { $xfs[] = $xf; $at = count($xfs) - 1; }
			$this->xfIndex[$key] = (int)$at;
		}
		$nf = '';
		foreach ($numFmts as $id => $code) $nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. ($nf !== '' ? '<numFmts count="' . count($numFmts) . '">' . $nf . '</numFmts>' : '')
			. '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
			. '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
			. '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
			. '</styleSheet>';
	}

	private function xf(array $st, string $kind): int
	{
		return $this->xfIndex[self::sheetStyleKey($st) . '|' . $kind] ?? 0;
	}

	private static function sheetStyleKey(array $st): string
	{
		return $st ? (json_encode($st) ?: '') : '';
	}

	private function workbookXml(): string
	{
		$sheets = '';
		foreach ($this->sheets as $i => $s) {
			$sheets .= '<sheet name="' . self::esc($s->name()) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 3) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<calcPr calcId="124511" fullCalcOnLoad="1"/><sheets>' . $sheets . '</sheets></workbook>';
	}

	private function workbookRels(): string
	{
		$rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
		foreach ($this->sheets as $i => $s) {
			$rels .= '<Relationship Id="rId' . ($i + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
	}

	private function contentTypes(): string
	{
		$sheets = '';
		foreach ($this->sheets as $i => $s) {
			$sheets .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/xmlns/types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			. $sheets . '</Types>';
	}

	private function coreXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. ($this->props['title'] !== '' ? '<dc:title>' . self::esc($this->props['title']) . '</dc:title>' : '')
			. '<dc:creator>' . self::esc($this->props['creator']) . '</dc:creator>'
			. '<cp:lastModifiedBy>' . self::esc($this->props['creator']) . '</cp:lastModifiedBy>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
			. '</cp:coreProperties>';
	}
}

final class NuXlsxSheet
{
	private string $name;
	private array $cells = [];
	private array $heights = [];
	private array $widths = [];
	private array $rangeStyles = [];
	private array $merges = [];
	private int $row = 0;
	private bool $auto = true;
	private string $freeze = 'A1';
	private string $filter = '';
	private bool $rtl = false;
	private bool $numbers = false;

	public function __construct(private readonly NuXlsx $wb, string $name)
	{
		$this->name = $name;
	}

	public function name(): string { return $this->name; }

	public function header(array $row, array $style = ['bold' => true, 'bg' => 'F3F4F6']): self
	{
		$this->row(array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row), $style);
		$this->freeze('A2');
		$this->filter('A1:' . NuXlsx::colName(max(1, count($row))) . '1');
		return $this;
	}

	public function row(array $row, array $style = []): self
	{
		$this->row++;
		$rStyle = NuXlsx::cleanStyle($style);
		$col = 0;
		foreach ($row as $k => $item) {
			$col = is_int($k) ? $k + 1 : NuXlsx::colIndex($k);
			$cStyle = []; $isFormula = false; $cached = null;
			if ($item instanceof NuXlsxCell) {
				$cStyle = $item->style;
				$isFormula = $item->formula;
				$cached = $item->cached;
				$v = $item->value;
			} else {
				$v = $item;
			}
			$st = $rStyle ? ($cStyle ? $cStyle + $rStyle : $rStyle) : $cStyle;
			$norm = $isFormula ? (string)$v : self::normalize($v);
			$this->cells[$this->row][$col] = [$norm, $st, $isFormula, $cached];
		}
		return $this;
	}

	public function height(int $row, float $pt): self
	{
		$this->heights[$row] = $pt;
		return $this;
	}

	public function width(string|int $col, float $width): self
	{
		$this->widths[is_int($col) ? $col : NuXlsx::colIndex($col)] = max(0.0, $width);
		return $this;
	}

	public function autoWidth(bool $on = true): self { $this->auto = $on; return $this; }

	public function style(string $range, array $style): self
	{
		$st = NuXlsx::cleanStyle($style);
		if ($st) $this->rangeStyles[] = [NuXlsx::range($range), $st];
		return $this;
	}

	public function freeze(string $cell): self
	{
		$cell = strtoupper(trim($cell));
		NuXlsx::range($cell);
		$this->freeze = $cell;
		return $this;
	}

	public function filter(string $range): self
	{
		if ($range !== '') NuXlsx::range($range);
		$this->filter = strtoupper($range);
		return $this;
	}

	public function filterRange(): string
	{
		if ($this->filter === '') return '';
		[$r1, $c1, $r2, $c2] = NuXlsx::range($this->filter);
		if ($r1 === $r2) while ($r2 < NuXlsx::MAX_ROWS && isset($this->cells[$r2 + 1])) $r2++;
		return NuXlsx::rangeRef($r1, $c1, $r2, $c2);
	}

	public function usedStyles(): array
	{
		$out = [];
		foreach ($this->cells as $r => $cols) foreach ($cols as $c => $cell) {
			$v = $cell[2] ? null : $this->value($cell[0]);
			$st = $this->styleAt($r, $c, $cell[1], $v);
			$kind = $cell[2] ? 'formula' : self::kind($v);
			$out[self::sheetStyleKey($st) . '|' . $kind] = [$st, $kind];
		}
		return $out;
	}

	private static function sheetStyleKey(array $st): string
	{
		return $st ? (json_encode($st) ?: '') : '';
	}

	public function xml(NuXlsxStrings $strings, callable $xf): string
	{
		ksort($this->cells);
		$filter = $this->filter !== '' ? NuXlsx::range($this->filter) : null;
		$rowNums = array_keys($this->cells + array_fill_keys(array_keys($this->heights), []));
		sort($rowNums);
		$maxCol = 0;
		$len = [];
		$data = '';
		foreach ($rowNums as $r) {
			$cols = $this->cells[$r] ?? [];
			ksort($cols);
			$data .= '<row r="' . $r . '"' . (isset($this->heights[$r]) ? ' ht="' . $this->heights[$r] . '" customHeight="1"' : '') . ($cols ? '>' : '/>');
			if (!$cols) continue;
			foreach ($cols as $c => [$raw, $own, $isFormula, $cached]) {
				$maxCol = max($maxCol, $c);
				$ref = NuXlsx::colName($c) . $r;
				if ($isFormula) {
					$st = $this->styleAt($r, $c, $own, null);
					$s = $xf($st, 'formula');
					$sa = $s ? ' s="' . $s . '"' : '';
					$f = '<f>' . NuXlsx::esc((string)$raw) . '</f>';
					if (is_bool($cached)) $data .= '<c r="' . $ref . '"' . $sa . ' t="b">' . $f . '<v>' . ($cached ? 1 : 0) . '</v></c>';
					elseif (is_int($cached) || is_float($cached)) $data .= '<c r="' . $ref . '"' . $sa . '>' . $f . '<v>' . self::num($cached) . '</v></c>';
					elseif (is_string($cached)) $data .= '<c r="' . $ref . '"' . $sa . ' t="str">' . $f . '<v>' . NuXlsx::esc($cached) . '</v></c>';
					else $data .= '<c r="' . $ref . '"' . $sa . '>' . $f . '</c>';
					continue;
				}
				$v = $this->value($raw);
				$kind = self::kind($v);
				$st = $this->styleAt($r, $c, $own, $v);
				$s = $xf($st, $kind);
				$sa = $s ? ' s="' . $s . '"' : '';
				$w = 0;
				switch ($kind) {
					case 'empty': $data .= '<c r="' . $ref . '"' . $sa . '/>'; break;
					case 'bool': $data .= '<c r="' . $ref . '"' . $sa . ' t="b"><v>' . ($v ? 1 : 0) . '</v></c>'; $w = 7; break;
					case 'number':
						$n = self::num($v);
						$data .= '<c r="' . $ref . '"' . $sa . '><v>' . $n . '</v></c>';
						$w = strlen($n) + 2;
						if (isset($st['format']) && str_contains($st['format'], ',')) $w += intdiv(strlen(ltrim(explode('.', $n)[0], '-')) - 1, 3);
						break;
					case 'date': case 'datetime':
						$data .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num(NuXlsx::serial($v)) . '</v></c>';
						$w = $kind === 'date' ? 12 : 18;
						break;
					default:
						$t = mb_substr((string)$v, 0, NuXlsx::MAX_TEXT);
						$data .= '<c r="' . $ref . '"' . $sa . ' t="s"><v>' . $strings->id($t) . '</v></c>';
						$longest = max(array_map(static fn($line) => mb_strwidth($line, 'UTF-8'), explode("\n", $t)));
						$w = (int)ceil($longest * (!empty($st['bold']) ? 1.1 : 1.0)) + 2;
				}
				if ($filter && $r === $filter[0] && $c >= $filter[1] && $c <= $filter[3]) $w += 2;
				if ($w) $len[$c] = max($len[$c] ?? 0, $w);
			}
			$data .= '</row>';
		}
		$cols = '';
		$widths = $this->widths;
		if ($this->auto) foreach ($len as $c => $l) if (!isset($widths[$c])) $widths[$c] = (float)max(6, min(60, $l));
		ksort($widths);
		foreach ($widths as $c => $w) $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . round($w, 2) . '" customWidth="1"/>';
		$view = '<sheetView workbookViewId="0"' . ($this->rtl ? ' rightToLeft="1"' : '') . '>';
		if ($this->freeze !== '' && $this->freeze !== 'A1') {
			[$fr, $fc] = NuXlsx::range($this->freeze);
			$x = $fc - 1; $y = $fr - 1;
			$pane = $x && $y ? 'bottomRight' : ($y ? 'bottomLeft' : 'topRight');
			$view .= '<pane' . ($x ? ' xSplit="' . $x . '"' : '') . ($y ? ' ySplit="' . $y . '"' : '') . ' topLeftCell="' . $this->freeze . '" activePane="' . $pane . '" state="frozen"/>'
				. '<selection pane="' . $pane . '" activeCell="' . $this->freeze . '" sqref="' . $this->freeze . '"/>';
		}
		$view .= '</sheetView>';
		$dim = $this->cells ? 'A1:' . NuXlsx::colName(max(1, $maxCol)) . max(1, (int)max(array_keys($this->cells))) : 'A1';
		$merge = '';
		foreach ($this->merges as [$r1, $c1, $r2, $c2]) $merge .= '<mergeCell ref="' . NuXlsx::rangeRef($r1, $c1, $r2, $c2) . '"/>';
		$filterRef = $this->filterRange();
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<dimension ref="' . $dim . '"/><sheetViews>' . $view . '</sheetViews><sheetFormatPr defaultRowHeight="15"/>'
			. ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
			. '<sheetData>' . $data . '</sheetData>'
			. ($filterRef !== '' ? '<autoFilter ref="' . $filterRef . '"/>' : '')
			. ($merge !== '' ? '<mergeCells count="' . count($this->merges) . '">' . $merge . '</mergeCells>' : '')
			. '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
			. '</worksheet>';
	}

	private function styleAt(int $r, int $c, array $own, mixed $v = null): array
	{
		$st = $own;
		foreach ($this->rangeStyles as [[$r1, $c1, $r2, $c2], $rs]) if ($r >= $r1 && $r <= $r2 && $c >= $c1 && $c <= $c2) $st = $rs + $st;
		if (is_string($v) && str_contains($v, "\n") && !isset($st['wrap'])) $st['wrap'] = true;
		$st = array_filter($st, static fn($x) => $x !== false);
		ksort($st);
		return $st;
	}

	private static function normalize(mixed $v): mixed
	{
		if ($v instanceof BackedEnum) $v = $v->value;
		elseif ($v instanceof UnitEnum) $v = $v->name;
		if ($v === null || is_bool($v) || is_int($v)) return $v;
		if (is_float($v)) return is_finite($v) ? $v : null;
		if ($v instanceof DateTimeInterface) {
			$day = $v->format('Y-m-d');
			if ($day >= '1900-03-01' && strlen($day) === 10 && $day <= '9999-12-31') return $v;
			return $v->format($v->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
		}
		if ($v instanceof Stringable) $v = (string)$v;
		if (is_string($v)) return str_contains($v, "\r") ? str_replace(["\r\n", "\r"], "\n", $v) : $v;
		throw new InvalidArgumentException('NuXlsx: unsupported cell value of type ' . get_debug_type($v));
	}

	private function value(mixed $v): mixed
	{
		if ($this->numbers && is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$/', $v)) return str_contains($v, ".") ? (float)$v : (int)$v;
		return $v;
	}

	private static function kind(mixed $v): string
	{
		if ($v === null || $v === '') return 'empty';
		if (is_bool($v)) return 'bool';
		if (is_int($v) || is_float($v)) return 'number';
		if ($v instanceof DateTimeInterface) return $v->format('H:i:s') === '00:00:00' ? 'date' : 'datetime';
		return 'text';
	}

	private static function num(int|float $v): string
	{
		if (is_int($v)) return (string)$v;
		if ($v == 0.0) return '0';
		return sprintf('%.15G', $v);
	}
}

final class NuXlsxCell
{
	public function __construct(
		public readonly mixed $value,
		public readonly array $style = [],
		public readonly bool $formula = false,
		public readonly mixed $cached = null,
	) {}
}

final class NuXlsxStrings
{
	private array $ids = [];
	private int $count = 0;

	public function id(string $s): int
	{
		$this->count++;
		return $this->ids[$s] ??= count($this->ids);
	}

	public function xml(): string
	{
		$out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->count . '" uniqueCount="' . count($this->ids) . '">';
		foreach (array_keys($this->ids) as $s) $out .= '<si><t xml:space="preserve">' . self::text((string)$s) . '</t></si>';
		return $out . '</sst>';
	}

	private static function text(string $s): string
	{
		return NuXlsx::esc(str_contains($s, '_x') ? (string)preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $s) : $s);
	}
}
