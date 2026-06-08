<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the queue and failed-jobs infrastructure is correctly wired:
 *   - Default queue connection is sync in test/local mode (QUEUE_CONNECTION=sync).
 *   - failed_jobs table exists with uuid column (matches QUEUE_FAILED_DRIVER=database-uuids).
 */
class QueueConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_queue_connection_is_sync(): void
    {
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_failed_jobs_table_exists_with_uuid_column(): void
    {
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'failed_jobs table must exist');
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'uuid'), 'failed_jobs.uuid required for database-uuids driver');
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'connection'));
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'queue'));
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'payload'));
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'exception'));
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'failed_at'));
    }
}
