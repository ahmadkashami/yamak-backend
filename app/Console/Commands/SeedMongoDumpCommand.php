<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Models\AppSetting;
use App\Models\AppUser;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Driver;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\State;
use App\Models\UserCar;
use App\Models\UserNotification;
use App\Models\UserOtp;
use App\Models\Vehicle;
use App\Models\Website;
use App\Models\WebsiteCarousel;
use App\Models\WebsiteFeature;
use App\Models\WebsiteSlider;
use App\Models\WebsiteTheme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SeedMongoDumpCommand extends Command
{
    protected $signature = 'yamak:seed-mongo-dump
        {path=../dump/yamak-db : Path to directory containing line-delimited .json dump files}
        {--convert : Convert BSON files to JSON using bsondump before importing}
        {--clean : Truncate all data tables before importing (keeps roles/permissions)}';


    protected $description = 'Import the legacy MongoDB dump into the normalized Laravel/MySQL schema.';

    private array $ids = [];

    private array $summary = [];

    public function handle(): int
    {
        $path = realpath(base_path($this->argument('path'))) ?: realpath($this->argument('path'));

        if (! $path || ! is_dir($path)) {
            $this->error('Dump path not found: '.$this->argument('path'));

            return self::FAILURE;
        }

        if ($this->option('convert') && ! $this->convertBsonFiles($path)) {
            return self::FAILURE;
        }

        if ($this->option('clean')) {
            $this->cleanDatabase();
        }

        DB::transaction(function () use ($path) {
            $this->importCountries($path);
            $this->importStates($path);
            $this->importCities($path);
            $this->importCompanies($path);
            $this->importSysUsers($path);
            $this->importServices($path);
            $this->importBranches($path);
            $this->importAppSettings($path);
            $this->importAds($path);
            $this->importAppUsers($path);
            $this->importDrivers($path);
            $this->importVehicles($path);
            $this->importUserCars($path);
            $this->importUserOtps($path);
            $this->importUserNotifications($path);
            $this->importRequests($path);
            $this->importWebsites($path);
            $this->importWebsiteContent($path);
        });

        $this->info('Legacy dump import completed.');
        $this->writeSummary();

        return self::SUCCESS;
    }

    private function cleanDatabase(): void
    {
        $this->warn('Cleaning all data tables (roles and permissions preserved)...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'personal_access_tokens',
            'model_has_roles',
            'model_has_permissions',
            'request_service',
            'driver_vehicle',
            'vehicle_service',
            'website_service',
            'branch_service',
            'user_notifications',
            'user_cars',
            'user_otps',
            'service_requests',
            'website_carousels',
            'website_sliders',
            'website_features',
            'website_themes',
            'websites',
            'app_users',
            'vehicles',
            'drivers',
            'branches',
            'services',
            'ads',
            'app_settings',
            'companies',
            'cities',
            'states',
            'countries',
            'users',
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info('Database cleaned.');
    }

    private function importSysUsers(string $path): void
    {
        $roleMap = Role::query()->pluck('id', 'name')->all();
        $now = now();

        foreach ($this->docs($path, 'sysusers') as $doc) {
            $email = $doc['email'] ?? null;

            if (! $email) {
                $this->skipped('sysusers');
                continue;
            }

            $fullName = $doc['fullName'] ?? 'User';

            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => json_encode(['en' => $fullName, 'ar' => $fullName]),
                    'password' => $doc['password'] ?? '',
                    'role_id' => $roleMap[$doc['role'] ?? ''] ?? null,
                    'type' => $doc['type'] ?? 'administration',
                    'lang' => 'en',
                    'email_verified_at' => $now,
                    'created_at' => $this->date($doc['createdAt'] ?? null) ?: $now,
                    'updated_at' => $this->date($doc['updatedAt'] ?? null) ?: $now,
                ]
            );
            $this->imported('sysusers');
        }
    }

    private function convertBsonFiles(string $path): bool
    {
        if (! $this->commandExists('bsondump')) {
            $this->error('bsondump is required for --convert. Install MongoDB Database Tools, then rerun this command.');

            return false;
        }

        foreach (glob($path.'/*.bson') ?: [] as $bsonFile) {
            $jsonFile = substr($bsonFile, 0, -5).'.json';
            $command = 'bsondump --outFile='.escapeshellarg($jsonFile).' '.escapeshellarg($bsonFile);
            exec($command, $output, $code);

            if ($code !== 0) {
                $this->error('Failed to convert '.$bsonFile);

                return false;
            }
        }

        return true;
    }

    private function commandExists(string $command): bool
    {
        $where = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        exec($where.' '.$command, $output, $code);

        return $code === 0;
    }

    private function docs(string $path, string $collection): array
    {
        $file = $path.DIRECTORY_SEPARATOR.$collection.'.json';

        if (! file_exists($file)) {
            $this->warn("Skipping {$collection}: {$collection}.json not found.");

            return [];
        }

        $docs = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $doc = json_decode($line, true);

            if (! is_array($doc)) {
                $this->warn("Skipping {$collection} line ".($lineNumber + 1).': invalid JSON.');
                continue;
            }

            $docs[] = $doc;
        }

        $this->summary[$collection]['read'] = count($docs);

        return $docs;
    }

    private function imported(string $collection): void
    {
        $this->summary[$collection]['imported'] = ($this->summary[$collection]['imported'] ?? 0) + 1;
    }

    private function skipped(string $collection): void
    {
        $this->summary[$collection]['skipped'] = ($this->summary[$collection]['skipped'] ?? 0) + 1;
    }

    private function writeSummary(): void
    {
        $required = ['countries', 'states', 'cities', 'sysusers', 'companies', 'services', 'branches', 'appusers', 'drivers', 'vehicles'];

        foreach ($required as $collection) {
            if (($this->summary[$collection]['imported'] ?? 0) === 0) {
                $this->warn("Validation warning: {$collection} imported 0 records.");
            }
        }

        $rows = collect($this->summary)
            ->sortKeys()
            ->map(fn (array $counts, string $collection) => [
                $collection,
                $counts['read'] ?? 0,
                $counts['imported'] ?? 0,
                $counts['skipped'] ?? 0,
            ])
            ->values()
            ->all();

        if ($rows) {
            $this->table(['Collection', 'Read', 'Imported', 'Skipped'], $rows);
        }
    }

    private function oid(mixed $value): ?string
    {
        if (is_array($value) && isset($value['$oid'])) {
            return $value['$oid'];
        }

        return is_string($value) ? $value : null;
    }

    private function date(mixed $value): mixed
    {
        if (is_array($value) && isset($value['$date'])) {
            if (is_array($value['$date']) && isset($value['$date']['$numberLong'])) {
                return Carbon::createFromTimestampMs((int) $value['$date']['$numberLong']);
            }

            return Carbon::parse($value['$date']);
        }

        return $value;
    }

    private function text(mixed $value, ?string $key = null): ?string
    {
        if (is_array($value) && $key) {
            return $value[$key] ?? null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function remember(string $collection, ?string $oldId, int $newId): void
    {
        if ($oldId) {
            $this->ids[$collection][$oldId] = $newId;
        }
    }

    private function id(string $collection, mixed $oldId): ?int
    {
        $oid = $this->oid($oldId);

        return $oid ? ($this->ids[$collection][$oid] ?? null) : null;
    }

    private function importCountries(string $path): void
    {
        foreach ($this->docs($path, 'countries') as $doc) {
            $country = Country::query()->updateOrCreate(
                ['country_code' => $doc['countryCode'] ?? $doc['country_code'] ?? '965'],
                [
                    'name_en' => $this->text($doc['name'] ?? null, 'en') ?? $doc['name_en'] ?? 'Kuwait',
                    'name_ar' => $this->text($doc['name'] ?? null, 'ar') ?? $doc['name_ar'] ?? 'Kuwait',
                    'currency' => $doc['currency'] ?? 'KWD',
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('countries', $this->oid($doc['_id'] ?? null), $country->id);
            $this->imported('countries');
        }
    }

    private function importStates(string $path): void
    {
        foreach ($this->docs($path, 'states') as $doc) {
            $state = State::query()->updateOrCreate(
                [
                    'country_id' => $this->id('countries', $doc['countryId'] ?? null) ?? Country::query()->value('id'),
                    'name_en' => $this->text($doc['name'] ?? null, 'en') ?? $doc['name_en'] ?? 'State',
                ],
                [
                    'name_ar' => $this->text($doc['name'] ?? null, 'ar') ?? $doc['name_ar'] ?? 'State',
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('states', $this->oid($doc['_id'] ?? null), $state->id);
            $this->imported('states');
        }
    }

    private function importCities(string $path): void
    {
        foreach ($this->docs($path, 'cities') as $doc) {
            $city = City::query()->updateOrCreate(
                [
                    'state_id' => $this->id('states', $doc['stateId'] ?? null) ?? State::query()->value('id'),
                    'name_en' => $this->text($doc['name'] ?? null, 'en') ?? $doc['name_en'] ?? 'City',
                ],
                [
                    'name_ar' => $this->text($doc['name'] ?? null, 'ar') ?? $doc['name_ar'] ?? 'City',
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('cities', $this->oid($doc['_id'] ?? null), $city->id);
            $this->imported('cities');
        }
    }

    private function importCompanies(string $path): void
    {
        foreach ($this->docs($path, 'companies') as $doc) {
            $company = Company::query()->updateOrCreate(
                ['email' => $doc['email'] ?? 'company-'.$this->oid($doc['_id'] ?? null).'@example.com'],
                [
                    'name_en' => $this->text($doc['name'] ?? null, 'en') ?? $doc['name_en'] ?? 'Company',
                    'name_ar' => $this->text($doc['name'] ?? null, 'ar') ?? $doc['name_ar'] ?? 'Company',
                    'phone' => $doc['phone'] ?? null,
                    'app_code' => $doc['appCode'] ?? null,
                    'logo' => $doc['logo'] ?? null,
                    'address' => $doc['address'] ?? null,
                    'description' => $doc['description'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('companies', $this->oid($doc['_id'] ?? null), $company->id);
            $this->imported('companies');
        }
    }

    private function importServices(string $path): void
    {
        foreach ($this->docs($path, 'services') as $doc) {
            $service = Service::query()->updateOrCreate(
                ['app_code' => $doc['appCode'] ?? 'SER-'.$this->oid($doc['_id'] ?? null)],
                [
                    'name_en' => $this->text($doc['name'] ?? null, 'en') ?? 'Service',
                    'name_ar' => $this->text($doc['name'] ?? null, 'ar') ?? 'Service',
                    'logo' => $doc['logo'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('services', $this->oid($doc['_id'] ?? null), $service->id);
            $this->imported('services');
        }
    }

    private function importBranches(string $path): void
    {
        foreach ($this->docs($path, 'branches') as $doc) {
            $branch = Branch::query()->updateOrCreate(
                ['app_code' => $doc['appCode'] ?? 'BR-'.$this->oid($doc['_id'] ?? null)],
                [
                    'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                    'name' => $doc['name'] ?? 'Branch',
                    'phone' => $doc['phone'] ?? null,
                    'logo' => $doc['logo'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                    'country_id' => $this->id('countries', $doc['address']['country'] ?? null),
                    'state_id' => $this->id('states', $doc['address']['state'] ?? null),
                    'city_id' => $this->id('cities', $doc['address']['city'] ?? null),
                    'block' => $doc['address']['block'] ?? null,
                    'street' => $doc['address']['street'] ?? null,
                    'zip_code' => $doc['address']['zipCode'] ?? null,
                    'latitude' => $doc['geoLocation']['latitude'] ?? null,
                    'longitude' => $doc['geoLocation']['longitude'] ?? null,
                ]
            );
            $branch->services()->sync(array_filter(array_map(fn ($id) => $this->id('services', $id), $doc['services'] ?? [])));
            $this->remember('branches', $this->oid($doc['_id'] ?? null), $branch->id);
            $this->imported('branches');
        }
    }

    private function importAppSettings(string $path): void
    {
        foreach ($this->docs($path, 'appsettings') as $doc) {
            AppSetting::query()->updateOrCreate(
                ['id' => 1],
                [
                    'android_version_code' => $doc['androidVersionCode'] ?? null,
                    'android_recommended_version' => $doc['androidRecommendedVersion'] ?? null,
                    'ios_version' => $doc['iosVersion'] ?? null,
                    'ios_recommended_version' => $doc['iosRecommendedVersion'] ?? null,
                    'test' => $doc['test'] ?? null,
                ]
            );
            $this->imported('appsettings');
        }
    }

    private function importAds(string $path): void
    {
        foreach ($this->docs($path, 'adds') as $doc) {
            Ad::query()->updateOrCreate(
                ['app_code' => $doc['appCode'] ?? 'ADD-'.$this->oid($doc['_id'] ?? null)],
                [
                    'title' => $doc['title'] ?? 'Ad',
                    'link' => $doc['link'] ?? null,
                    'photo' => $doc['photo'] ?? '',
                    'started_at' => $this->date($doc['startedAt'] ?? null) ?: now(),
                    'expired_at' => $this->date($doc['expiredAt'] ?? null) ?: now()->addMonth(),
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->imported('adds');
        }
    }

    private function importAppUsers(string $path): void
    {
        foreach ($this->docs($path, 'appusers') as $doc) {
            $user = AppUser::query()->updateOrCreate(
                ['email' => $doc['email'] ?? 'app-'.$this->oid($doc['_id'] ?? null).'@example.com'],
                [
                    'name' => $doc['name'] ?? null,
                    'phone' => $doc['phone'] ?? null,
                    'password' => $doc['password'] ?? null,
                    'photo' => $doc['photo'] ?? null,
                    'notifications_enabled' => (bool) ($doc['notificationsEnabled'] ?? false),
                    'notification_token' => $doc['notificationToken'] ?? null,
                    'gender' => $doc['gender'] ?? 'male',
                    'is_email_verified' => (bool) ($doc['isEmailVerified'] ?? false),
                    'reset_pass_code' => $doc['resetPassCode'] ?? null,
                    'fcm_token' => $doc['fcmToken'] ?? null,
                    'reset_pass_expire_date' => $this->date($doc['resetPassExpireDate'] ?? null),
                    'is_social_signup' => (bool) ($doc['isSocialSignUp'] ?? false),
                    'birth_day' => $this->date($doc['birthDay'] ?? null),
                    'last_login' => $this->date($doc['lastLogin'] ?? null),
                    'language' => $doc['language'] ?? 'en',
                    'latitude' => $doc['geoLocation']['latitude'] ?? null,
                    'longitude' => $doc['geoLocation']['longitude'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->remember('appusers', $this->oid($doc['_id'] ?? null), $user->id);
            $this->imported('appusers');
        }
    }

    private function importDrivers(string $path): void
    {
        foreach ($this->docs($path, 'drivers') as $doc) {
            $driver = Driver::query()->updateOrCreate(
                ['email' => $doc['email'] ?? 'driver-'.$this->oid($doc['_id'] ?? null).'@example.com'],
                [
                    'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                    'branch_id' => $this->id('branches', $doc['branchId'] ?? null) ?? Branch::query()->value('id'),
                    'name' => $doc['name'] ?? 'Driver',
                    'photo' => $doc['photo'] ?? null,
                    'phone_num' => $doc['phoneNum'] ?? null,
                    'password' => $doc['password'] ?? 'legacy-import-password',
                    'driver_license' => $doc['driverLicense'] ?? null,
                    'license_expired_at' => $this->date($doc['licenseExpiredAt'] ?? null),
                    'status' => $doc['status'] ?? 'active',
                    'last_login' => $this->date($doc['lastLogin'] ?? null),
                ]
            );
            $this->remember('drivers', $this->oid($doc['_id'] ?? null), $driver->id);
            $this->imported('drivers');
        }
    }

    private function importVehicles(string $path): void
    {
        foreach ($this->docs($path, 'vehicles') as $doc) {
            $vehicle = Vehicle::query()->updateOrCreate(
                ['app_code' => $doc['appCode'] ?? 'VEH-'.$this->oid($doc['_id'] ?? null)],
                [
                    'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                    'branch_id' => $this->id('branches', $doc['branchId'] ?? null) ?? Branch::query()->value('id'),
                    'current_driver_id' => $this->id('drivers', $doc['currentDriver'] ?? null),
                    'status' => $doc['status'] ?? 'active',
                    'work_status' => $doc['workStatus'] ?? 'offline',
                    'last_active_date' => $this->date($doc['lastActiveDate'] ?? null),
                    'latitude' => $doc['geoLocation']['latitude'] ?? ($doc['geoMetry']['coordinates'][1] ?? null),
                    'longitude' => $doc['geoLocation']['longitude'] ?? ($doc['geoMetry']['coordinates'][0] ?? null),
                    'logo' => $doc['logo'] ?? null,
                    'type' => $doc['type'] ?? null,
                    'icon_map' => $doc['iconMap'] ?? null,
                    'label_number' => $doc['labelNumber'] ?? null,
                    'model_name' => $doc['modelName'] ?? null,
                ]
            );
            $vehicle->drivers()->sync(array_filter(array_map(fn ($id) => $this->id('drivers', $id), $doc['drivers'] ?? [])));
            $vehicle->services()->sync(array_filter(array_map(fn ($id) => $this->id('services', $id), $doc['services'] ?? [])));
            $this->remember('vehicles', $this->oid($doc['_id'] ?? null), $vehicle->id);
            $this->imported('vehicles');
        }
    }

    private function importUserCars(string $path): void
    {
        foreach ($this->docs($path, 'usercars') as $doc) {
            $userId = $this->id('appusers', $doc['userId'] ?? null);

            if (! $userId) {
                $this->skipped('usercars');
                continue;
            }

            UserCar::query()->updateOrCreate(
                [
                    'app_user_id' => $userId,
                    'make' => $doc['make'] ?? null,
                    'model' => $doc['model'] ?? null,
                ],
                [
                    'year' => $doc['year'] ?? null,
                    'type' => $doc['type'] ?? 'Car',
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->imported('usercars');
        }
    }

    private function importUserOtps(string $path): void
    {
        foreach ($this->docs($path, 'userotps') as $doc) {
            UserOtp::query()->updateOrCreate(
                [
                    'phone' => $doc['phone'] ?? null,
                    'email' => $doc['email'] ?? null,
                ],
                [
                    'code' => $doc['code'] ?? '0000',
                    'code_expire_date' => $this->date($doc['codeExpireDate'] ?? null),
                ]
            );
            $this->imported('userotps');
        }
    }

    private function importUserNotifications(string $path): void
    {
        foreach ($this->docs($path, 'usernotifications') as $doc) {
            $appUserId = $this->id('appusers', $doc['userId'] ?? null);

            UserNotification::query()->updateOrCreate([
                'app_user_id' => $appUserId,
                'title' => $doc['title'] ?? null,
                'type' => $doc['type'] ?? null,
                'expired_at' => $this->date($doc['expiredAt'] ?? null),
            ], [
                'title' => $doc['title'] ?? null,
                'body' => $doc['body'] ?? null,
                'description' => $doc['description'] ?? null,
                'type' => $doc['type'] ?? null,
                'is_read' => (bool) ($doc['isRead'] ?? false),
                'expired_at' => $this->date($doc['expiredAt'] ?? null),
            ]);
            $this->imported('usernotifications');
        }
    }

    private function importRequests(string $path): void
    {
        foreach ($this->docs($path, 'requests') as $doc) {
            $appUserId = $this->id('appusers', $doc['userId'] ?? null);
            $driverId = $this->id('drivers', $doc['driverId'] ?? null);
            $driver = $driverId ? Driver::query()->find($driverId) : null;

            if (! $appUserId || ! $driverId) {
                $this->skipped('requests');
                continue;
            }

            $request = ServiceRequest::query()->updateOrCreate([
                'app_user_id' => $appUserId,
                'driver_id' => $driverId,
                'platform' => $doc['platform'] ?? 'mobile',
                'status' => $doc['status'] ?? 'active',
            ], [
                'platform' => $doc['platform'] ?? 'mobile',
                'app_user_id' => $appUserId,
                'driver_id' => $driverId,
                'company_id' => $driver?->company_id,
                'branch_id' => $driver?->branch_id,
                'status' => $doc['status'] ?? 'active',
            ]);
            $request->services()->sync(array_filter(array_map(fn ($id) => $this->id('services', $id), $doc['services'] ?? [])));
            $this->imported('requests');
        }
    }

    private function importWebsites(string $path): void
    {
        foreach ($this->docs($path, 'websites') as $doc) {
            $website = Website::query()->updateOrCreate(
                ['domain_url' => $doc['domainUrl'] ?? 'website-'.$this->oid($doc['_id'] ?? null).'.local'],
                [
                    'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                    'logo' => $doc['logo'] ?? null,
                    'about_us_en' => $this->text($doc['aboutUs'] ?? null, 'en'),
                    'about_us_ar' => $this->text($doc['aboutUs'] ?? null, 'ar'),
                    'contact_address_en' => $this->text($doc['contactUs']['address'] ?? null, 'en'),
                    'contact_address_ar' => $this->text($doc['contactUs']['address'] ?? null, 'ar'),
                    'contact_phone' => $doc['contactUs']['phone'] ?? null,
                    'contact_email' => $doc['contactUs']['email'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $website->services()->sync(array_filter(array_map(fn ($id) => $this->id('services', $id), $doc['services'] ?? [])));
            $this->remember('websites', $this->oid($doc['_id'] ?? null), $website->id);
            $this->imported('websites');
        }
    }

    private function importWebsiteContent(string $path): void
    {
        foreach ($this->docs($path, 'websitethemes') as $doc) {
            $websiteId = $this->id('websites', $doc['websiteId'] ?? null);

            if (! $websiteId) {
                continue;
            }

            WebsiteTheme::query()->updateOrCreate(
                ['website_id' => $websiteId],
                [
                    'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                    'primary_color' => $doc['primaryColor'] ?? '#002F6C',
                    'secondary_color' => $doc['secondaryColor'] ?? '#F7B123',
                    'secondary_background_color' => $doc['secondaryBackgroundColor'] ?? '#F7B123',
                    'banner_image' => $doc['bannerImage'] ?? null,
                    'status' => $doc['status'] ?? 'active',
                ]
            );
            $this->imported('websitethemes');
        }

        foreach ($this->docs($path, 'websitefeatures') as $doc) {
            $websiteId = $this->id('websites', $doc['websiteId'] ?? null);

            if (! $websiteId) {
                $this->skipped('websitefeatures');
                continue;
            }

            WebsiteFeature::query()->updateOrCreate([
                'website_id' => $websiteId,
                'icon' => $doc['icon'] ?? '',
                'title_en' => $this->text($doc['title'] ?? null, 'en'),
            ], [
                'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                'website_id' => $websiteId,
                'icon' => $doc['icon'] ?? '',
                'title_en' => $this->text($doc['title'] ?? null, 'en'),
                'title_ar' => $this->text($doc['title'] ?? null, 'ar'),
                'description_en' => $this->text($doc['description'] ?? null, 'en'),
                'description_ar' => $this->text($doc['description'] ?? null, 'ar'),
                'status' => $doc['status'] ?? 'active',
            ]);
            $this->imported('websitefeatures');
        }

        foreach ($this->docs($path, 'websitesliders') as $doc) {
            $websiteId = $this->id('websites', $doc['websiteId'] ?? null);

            if (! $websiteId) {
                $this->skipped('websitesliders');
                continue;
            }

            WebsiteSlider::query()->updateOrCreate([
                'website_id' => $websiteId,
                'image_url' => $doc['imageUrl'] ?? '',
            ], [
                'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                'website_id' => $websiteId,
                'image_url' => $doc['imageUrl'] ?? '',
                'title_en' => $this->text($doc['title'] ?? null, 'en'),
                'title_ar' => $this->text($doc['title'] ?? null, 'ar'),
                'sub_title_en' => $this->text($doc['subTitle'] ?? null, 'en'),
                'sub_title_ar' => $this->text($doc['subTitle'] ?? null, 'ar'),
                'status' => $doc['status'] ?? 'active',
            ]);
            $this->imported('websitesliders');
        }

        foreach ($this->docs($path, 'websitecarousels') as $doc) {
            $websiteId = $this->id('websites', $doc['websiteId'] ?? null);

            if (! $websiteId) {
                $this->skipped('websitecarousels');
                continue;
            }

            WebsiteCarousel::query()->updateOrCreate([
                'website_id' => $websiteId,
                'image_url' => $doc['imageUrl'] ?? '',
            ], [
                'company_id' => $this->id('companies', $doc['companyId'] ?? null) ?? Company::query()->value('id'),
                'website_id' => $websiteId,
                'image_url' => $doc['imageUrl'] ?? '',
            ]);
            $this->imported('websitecarousels');
        }
    }
}
