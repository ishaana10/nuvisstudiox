<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuFnRef;

require_once __DIR__ . '/../../core/FnRef.php';

final class FnRefTest extends TestCase
{
    public function testGetReferenceStructure(): void
    {
        $ref = NuFnRef::getReference();
        $this->assertIsArray($ref);
        $this->assertArrayHasKey('php', $ref);
        $this->assertArrayHasKey('js', $ref);
        $this->assertArrayHasKey('sql', $ref);

        $phpCategory = $ref['php'];
        $this->assertNotEmpty($phpCategory['title']);
        $this->assertIsArray($phpCategory['items']);
        $this->assertNotEmpty($phpCategory['items']);

        $firstItem = $phpCategory['items'][0];
        $this->assertArrayHasKey('name', $firstItem);
        $this->assertArrayHasKey('category', $firstItem);
        $this->assertArrayHasKey('signature', $firstItem);
        $this->assertArrayHasKey('description', $firstItem);
        $this->assertArrayHasKey('example', $firstItem);
    }
}
