<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuXlsx;
use NuXlsxCell;

require_once __DIR__ . '/../../core/XlsxWriter.php';

final class XlsxWriterTest extends TestCase
{
    public function testColNameConversion(): void
    {
        $this->assertEquals('A', NuXlsx::colName(1));
        $this->assertEquals('Z', NuXlsx::colName(26));
        $this->assertEquals('AA', NuXlsx::colName(27));
        $this->assertEquals('AZ', NuXlsx::colName(52));
    }

    public function testColIndexConversion(): void
    {
        $this->assertEquals(1, NuXlsx::colIndex('A'));
        $this->assertEquals(26, NuXlsx::colIndex('Z'));
        $this->assertEquals(27, NuXlsx::colIndex('AA'));
    }

    public function testRangeParsing(): void
    {
        $range = NuXlsx::range('A1:C5');
        $this->assertEquals([1, 1, 5, 3], $range);
    }

    public function testWorkbookGenerationToString(): void
    {
        $xlsx = NuXlsx::create('Test Workbook');
        $sheet = $xlsx->sheet('Products');
        $sheet->header(['ID', 'Name', 'Price']);
        $sheet->row([1, 'Widget', 19.99]);
        $sheet->row([2, 'Gadget', 49.95]);

        $binaryData = $xlsx->toString();
        $this->assertNotEmpty($binaryData);
        // OpenXML files start with PK zip signature
        $this->assertStringStartsWith("PK\x03\x04", $binaryData);
    }

    public function testFormulaCellCreation(): void
    {
        $formulaCell = NuXlsx::formula('SUM(C2:C3)', 69.94);
        $this->assertTrue($formulaCell->formula);
        $this->assertEquals('SUM(C2:C3)', $formulaCell->value);
        $this->assertEquals(69.94, $formulaCell->cached);
    }
}
