<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_tables_do_not_use_generic_document_columns(): void
    {
        $forbiddenColumns = ['data', 'payload', 'meta', 'document', 'json_data'];
        $frameworkTables = [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'personal_access_tokens',
            'sessions',
        ];

        $violations = [];
        $tables = $this->businessTableNames();

        foreach ($tables as $tableName) {
            if (in_array($tableName, $frameworkTables, true)) {
                continue;
            }

            foreach (Schema::getColumnListing($tableName) as $column) {
                if (in_array(strtolower($column), $forbiddenColumns, true)) {
                    $violations[] = "{$tableName}.{$column}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    private function businessTableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
                ->pluck('name')
                ->all();
        }

        if ($driver === 'mysql') {
            return collect(DB::select("show full tables where Table_type = 'BASE TABLE'"))
                ->map(fn (object $row) => array_values((array) $row)[0])
                ->all();
        }

        $this->fail("Unsupported schema governance test connection: {$driver}");
    }
}
