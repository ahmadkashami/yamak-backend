<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Website;
use App\Models\WebsiteFeature;
use App\Models\WebsiteSlider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies the naming and legacy-ID strategy documented in
 * docs/migration/naming-and-id-strategy.md:
 *
 *   - Every resource response includes integer `id` AND string `_id`.
 *   - FK fields in responses use camelCase (companyId, branchId, …).
 *   - DB columns remain snake_case (enforced by model $fillable audit).
 */
class NamingConventionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        return $admin;
    }

    private function assertIdFields(array $data, string $context = ''): void
    {
        $this->assertArrayHasKey('id', $data, "Missing integer `id` in {$context}");
        $this->assertArrayHasKey('_id', $data, "Missing string `_id` in {$context}");
        $this->assertIsInt($data['id'], "`id` must be integer in {$context}");
        $this->assertIsString($data['_id'], "`_id` must be string in {$context}");
        $this->assertSame((string) $data['id'], $data['_id'], "`_id` must equal string cast of `id` in {$context}");
    }

    // ─── Company ─────────────────────────────────────────────────────────────

    public function test_company_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $data = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'ID Test Co', 'ar' => 'شركة اختبار'],
            'email' => 'id-test@example.com',
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'company');
    }

    // ─── Service ─────────────────────────────────────────────────────────────

    public function test_service_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $data = $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'ID Test Service', 'ar' => 'خدمة اختبار'],
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'service');
    }

    // ─── Country ─────────────────────────────────────────────────────────────

    public function test_country_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $data = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'ID Country', 'ar' => 'بلد الاختبار'],
            'countryCode' => 'IDC',
            'currency'    => 'USD',
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'country');
    }

    // ─── State ───────────────────────────────────────────────────────────────

    public function test_state_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'State Parent', 'ar' => 'دولة الاختبار'],
            'countryCode' => 'STP',
            'currency'    => 'USD',
        ])->json('data.id');

        $list = $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'ID State', 'stateAR' => 'ولاية اختبار', 'countryID' => ['oid' => $countryId]],
        ])->assertOk()->json('data.list');

        $this->assertIdFields($list[0], 'state');
    }

    // ─── City ────────────────────────────────────────────────────────────────

    public function test_city_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name' => ['en' => 'City Parent Country', 'ar' => 'دولة المدينة'], 'countryCode' => 'CPC', 'currency' => 'USD',
        ])->json('data.id');

        $stateId = $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'City Parent State', 'stateAR' => 'ولاية المدينة', 'countryID' => ['oid' => $countryId]],
        ])->json('data.list.0.id');

        $list = $this->postJson('/api/dashboard/v1/cities/bulk', [
            ['cityEN' => 'ID City', 'cityAR' => 'مدينة اختبار', 'stateID' => ['oid' => $stateId]],
        ])->assertOk()->json('data.list');

        $this->assertIdFields($list[0], 'city');
    }

    // ─── Branch ──────────────────────────────────────────────────────────────

    public function test_branch_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Branch Parent', 'ar' => 'شركة الفرع'],
            'email' => 'branch-parent@example.com',
        ])->json('data.id');

        $data = $this->postJson('/api/dashboard/v1/branches', [
            'name'      => 'ID Branch',
            'companyId' => $companyId,
            'phone'     => '96500000099',
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'branch');
        $this->assertSame((string) $companyId, $data['companyId'], 'branch.companyId must be string cast of parent company id');
    }

    // ─── Vehicle ─────────────────────────────────────────────────────────────

    public function test_vehicle_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Vehicle Co', 'ar' => 'شركة المركبة'],
            'email' => 'vehicle-co@example.com',
        ])->json('data.id');

        $branchId = $this->postJson('/api/dashboard/v1/branches', [
            'name'      => 'Vehicle Branch',
            'companyId' => $companyId,
            'phone'     => '96500000098',
        ])->json('data.id');

        $data = $this->postJson('/api/dashboard/v1/vehicles', [
            'companyId'    => $companyId,
            'branchId'     => $branchId,
            'vehicleType'  => 'sedan',
            'vehicleModel' => 'Camry',
            'plateNumber'  => 'TST-001',
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'vehicle');
        $this->assertSame((string) $companyId, $data['companyId'], 'vehicle.companyId must be string cast of company id');
        $this->assertSame((string) $branchId, $data['branchId'], 'vehicle.branchId must be string cast of branch id');
    }

    // ─── Website ─────────────────────────────────────────────────────────────

    public function test_website_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Web Parent', 'ar' => 'شركة الموقع'],
            'email' => 'web-parent@example.com',
        ])->json('data.id');

        $data = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'https://web-parent.example.com',
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'website');
        $this->assertSame((string) $companyId, $data['companyId'], 'website.companyId must be string cast of company id');
    }

    // ─── WebsiteFeature ──────────────────────────────────────────────────────

    public function test_website_feature_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Feature Co', 'ar' => 'شركة الميزة'],
            'email' => 'feature-co@example.com',
        ])->json('data.id');

        $websiteId = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'https://feature-co.example.com',
        ])->json('data.id');

        $data = $this->postJson('/api/dashboard/v1/website-features', [
            'companyId'   => $companyId,
            'websiteId'   => $websiteId,
            'icon'        => 'check',
            'title'       => ['en' => 'Feature Title', 'ar' => 'عنوان الميزة'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'website-feature');
        $this->assertSame((string) $companyId, $data['companyId'], 'feature.companyId must be string cast of company id');
        $this->assertSame((string) $websiteId, $data['websiteId'], 'feature.websiteId must be string cast of website id');
    }

    // ─── WebsiteSlider ───────────────────────────────────────────────────────

    public function test_website_slider_resource_has_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Slider Co', 'ar' => 'شركة الشريحة'],
            'email' => 'slider-co@example.com',
        ])->json('data.id');

        $websiteId = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'https://slider-co.example.com',
        ])->json('data.id');

        $data = $this->postJson('/api/dashboard/v1/website-sliders', [
            'companyId' => $companyId,
            'websiteId' => $websiteId,
            'imageUrl'  => 'https://example.com/slide.jpg',
            'title'     => ['en' => 'Slide', 'ar' => 'شريحة'],
            'subTitle'  => ['en' => 'Sub', 'ar' => 'ثانوي'],
        ])->assertOk()->json('data');

        $this->assertIdFields($data, 'website-slider');
    }

    // ─── List endpoints include _id on each item ──────────────────────────────

    public function test_company_list_items_all_have_integer_id_and_string_underscore_id(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/dashboard/v1/companies', [
            'name' => ['en' => 'List Test Co A', 'ar' => 'شركة أ'], 'email' => 'list-a@example.com',
        ])->assertOk();
        $this->postJson('/api/dashboard/v1/companies', [
            'name' => ['en' => 'List Test Co B', 'ar' => 'شركة ب'], 'email' => 'list-b@example.com',
        ])->assertOk();

        $list = $this->getJson('/api/dashboard/v1/companies')
            ->assertOk()
            ->json('data.list');

        $this->assertNotEmpty($list);

        foreach ($list as $index => $item) {
            $this->assertIdFields($item, "company list item [{$index}]");
        }
    }

    // ─── FK fields in DB are snake_case (model $fillable audit) ──────────────

    public function test_model_fillable_uses_snake_case_fk_column_names(): void
    {
        $snakePatterns = [
            Company::class   => ['name_en', 'name_ar'],
            Branch::class    => ['company_id'],
            Vehicle::class   => ['company_id', 'branch_id'],
            Driver::class    => ['company_id', 'branch_id'],
            Website::class   => ['company_id'],
            WebsiteFeature::class => ['company_id', 'website_id'],
            WebsiteSlider::class  => ['company_id', 'website_id'],
        ];

        foreach ($snakePatterns as $modelClass => $expectedColumns) {
            $model    = new $modelClass();
            $fillable = $model->getFillable();
            $name     = class_basename($modelClass);

            foreach ($expectedColumns as $column) {
                $this->assertContains(
                    $column,
                    $fillable,
                    "{$name}::\$fillable must include snake_case column `{$column}`"
                );
            }
        }
    }
}
