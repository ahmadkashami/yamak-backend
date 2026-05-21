<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedMongoDumpCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_mongo_dump_import_is_repeatable_for_related_collections(): void
    {
        $path = storage_path('framework/testing/mongo-dump');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $ids = [
            'country' => '64f000000000000000000001',
            'state' => '64f000000000000000000002',
            'city' => '64f000000000000000000003',
            'company' => '64f000000000000000000004',
            'service' => '64f000000000000000000005',
            'branch' => '64f000000000000000000006',
            'user' => '64f000000000000000000007',
            'driver' => '64f000000000000000000008',
            'website' => '64f000000000000000000009',
        ];

        $this->writeJsonLine($path, 'countries', [
            '_id' => ['$oid' => $ids['country']],
            'countryCode' => '965',
            'name' => ['en' => 'Kuwait', 'ar' => 'Kuwait'],
            'currency' => 'KWD',
        ]);
        $this->writeJsonLine($path, 'states', [
            '_id' => ['$oid' => $ids['state']],
            'countryId' => ['$oid' => $ids['country']],
            'name' => ['en' => 'Capital', 'ar' => 'Capital'],
        ]);
        $this->writeJsonLine($path, 'cities', [
            '_id' => ['$oid' => $ids['city']],
            'stateId' => ['$oid' => $ids['state']],
            'name' => ['en' => 'Kuwait City', 'ar' => 'Kuwait City'],
        ]);
        $this->writeJsonLine($path, 'companies', [
            '_id' => ['$oid' => $ids['company']],
            'email' => 'company@example.com',
            'name' => ['en' => 'Demo Company', 'ar' => 'Demo Company'],
        ]);
        $this->writeJsonLine($path, 'services', [
            '_id' => ['$oid' => $ids['service']],
            'appCode' => 'SVC-1',
            'name' => ['en' => 'Fuel', 'ar' => 'Fuel'],
        ]);
        $this->writeJsonLine($path, 'branches', [
            '_id' => ['$oid' => $ids['branch']],
            'appCode' => 'BR-1',
            'companyId' => ['$oid' => $ids['company']],
            'name' => 'Main Branch',
            'services' => [['$oid' => $ids['service']]],
        ]);
        $this->writeJsonLine($path, 'appusers', [
            '_id' => ['$oid' => $ids['user']],
            'email' => 'user@example.com',
            'name' => 'App User',
        ]);
        $this->writeJsonLine($path, 'drivers', [
            '_id' => ['$oid' => $ids['driver']],
            'email' => 'driver@example.com',
            'companyId' => ['$oid' => $ids['company']],
            'branchId' => ['$oid' => $ids['branch']],
            'name' => 'Driver One',
        ]);
        $this->writeJsonLine($path, 'vehicles', [
            '_id' => ['$oid' => '64f000000000000000000010'],
            'appCode' => 'VEH-1',
            'companyId' => ['$oid' => $ids['company']],
            'branchId' => ['$oid' => $ids['branch']],
            'currentDriver' => ['$oid' => $ids['driver']],
        ]);
        $this->writeJsonLine($path, 'websites', [
            '_id' => ['$oid' => $ids['website']],
            'domainUrl' => 'demo.local',
            'companyId' => ['$oid' => $ids['company']],
            'services' => [['$oid' => $ids['service']]],
        ]);
        $this->writeJsonLine($path, 'websitefeatures', [
            'websiteId' => ['$oid' => $ids['website']],
            'companyId' => ['$oid' => $ids['company']],
            'icon' => 'fuel',
            'title' => ['en' => 'Feature', 'ar' => 'Feature'],
        ]);
        $this->writeJsonLine($path, 'websitesliders', [
            'websiteId' => ['$oid' => $ids['website']],
            'companyId' => ['$oid' => $ids['company']],
            'imageUrl' => 'slider.jpg',
        ]);
        $this->writeJsonLine($path, 'websitecarousels', [
            'websiteId' => ['$oid' => $ids['website']],
            'companyId' => ['$oid' => $ids['company']],
            'imageUrl' => 'carousel.jpg',
        ]);
        $this->writeJsonLine($path, 'requests', [
            'platform' => 'mobile',
            'userId' => ['$oid' => $ids['user']],
            'driverId' => ['$oid' => $ids['driver']],
            'services' => [['$oid' => $ids['service']]],
        ]);
        $this->writeJsonLine($path, 'usernotifications', [
            'userId' => ['$oid' => $ids['user']],
            'title' => 'Ready',
            'type' => 'request',
            'body' => 'Done',
        ]);

        $this->artisan('yamak:seed-mongo-dump', ['path' => $path])->assertSuccessful();
        $this->artisan('yamak:seed-mongo-dump', ['path' => $path])->assertSuccessful();

        $this->assertSame(1, DB::table('website_features')->count());
        $this->assertSame(1, DB::table('website_sliders')->count());
        $this->assertSame(1, DB::table('website_carousels')->count());
        $this->assertSame(1, DB::table('service_requests')->count());
        $this->assertSame(1, DB::table('user_notifications')->count());
        $this->assertSame(1, DB::table('request_service')->count());
    }

    private function writeJsonLine(string $path, string $collection, array $document): void
    {
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.$collection.'.json',
            json_encode($document, JSON_THROW_ON_ERROR).PHP_EOL
        );
    }
}
