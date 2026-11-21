<?php

namespace Tests\Unit;

use App\Support\DatabaseSafety;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the fail-closed name checks. No application boot and
 * no database connection are required by these tests. NOT EXECUTED in B3.6-G1R.
 */
final class DatabaseSafetyGuardTest extends TestCase
{
    /**
     * @dataProvider forbiddenDatabaseProvider
     */
    public function test_forbidden_databases_are_refused(?string $name): void
    {
        $this->assertTrue(DatabaseSafety::isForbiddenDatabase($name));
    }

    public static function forbiddenDatabaseProvider(): array
    {
        return [
            'integration database' => ['yamak_integration_test'],
            'legacy production test' => ['yamakdb_prod_test'],
            'legacy database' => ['yamakdb'],
            'case insensitive' => ['YamakDB_Prod_Test'],
            'any production-like name' => ['customer_prod_backup'],
            'missing configuration' => [null],
            'empty configuration' => [''],
        ];
    }

    /**
     * @dataProvider disposableDatabaseProvider
     */
    public function test_approved_disposable_databases_are_not_forbidden(string $name): void
    {
        $this->assertFalse(DatabaseSafety::isForbiddenDatabase($name));
    }

    public static function disposableDatabaseProvider(): array
    {
        return [
            'phpunit disposable' => [DatabaseSafety::TEST_DISPOSABLE_DATABASE],
            'import disposable' => [DatabaseSafety::IMPORT_DISPOSABLE_DATABASE],
        ];
    }
}
