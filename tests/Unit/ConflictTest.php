<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuConflict;

require_once __DIR__ . '/../../core/Conflict.php';

final class ConflictTest extends TestCase
{
    public function testSignatureHashing(): void
    {
        $sigNull = NuConflict::sig(null);
        $this->assertEquals('n', $sigNull);

        $sig1 = NuConflict::sig('active');
        $this->assertEquals(12, strlen($sig1));

        $sig2 = NuConflict::sig('active');
        $this->assertEquals($sig1, $sig2);

        $sig3 = NuConflict::sig('inactive');
        $this->assertNotEquals($sig1, $sig3);
    }

    public function testRowSignatures(): void
    {
        $row = [
            'id' => '101',
            'status' => 'Pending',
            'amount' => 150.00,
        ];

        $sigs = NuConflict::rowSigs($row);
        $this->assertArrayHasKey('id', $sigs);
        $this->assertArrayHasKey('status', $sigs);
        $this->assertArrayHasKey('amount', $sigs);
        $this->assertEquals(NuConflict::sig('101'), $sigs['id']);
    }

    public function testConflictDetectionWhenRowUnchanged(): void
    {
        $origRow = [
            'id' => '101',
            'status' => 'Pending',
            'notes' => 'Initial note',
        ];

        $clientSigs = NuConflict::rowSigs($origRow);
        $dbRowNow = $origRow; // DB has not changed

        $submittedData = [
            'status' => 'Approved',
            'notes' => 'Updated note',
        ];

        $check = NuConflict::check($clientSigs, $dbRowNow, $submittedData);
        $this->assertFalse($check['hasConflict']);
        $this->assertEmpty($check['conflicts']);
    }

    public function testConflictDetectionWhenRowModifiedConcurrently(): void
    {
        $origRow = [
            'id' => '101',
            'status' => 'Pending',
            'notes' => 'Initial note',
        ];

        $clientSigs = NuConflict::rowSigs($origRow);

        // Database was updated by another user
        $dbRowNow = [
            'id' => '101',
            'status' => 'Rejected', // Changed by User B!
            'notes' => 'Initial note',
        ];

        // User A submits an update
        $submittedData = [
            'status' => 'Approved',
            'notes' => 'Initial note',
        ];

        $check = NuConflict::check($clientSigs, $dbRowNow, $submittedData);
        $this->assertTrue($check['hasConflict']);
        $this->assertArrayHasKey('status', $check['conflicts']);
        $this->assertEquals($clientSigs['status'], $check['conflicts']['status']['clientSig']);
    }
}
