<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies the localization strategy:
 *   - Schema stores each locale as an explicit `_en` / `_ar` column.
 *   - Composite indexes make localized columns searchable.
 *   - Every API resource returns a { "en": "...", "ar": "..." } object for localized keys.
 *   - Arabic-text search (?query=) returns the correct rows.
 */
class LocalizedFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ─── 1. Schema column presence ───────────────────────────────────────────

    public function test_all_localized_column_pairs_exist_in_schema(): void
    {
        $pairs = [
            'companies'        => ['name_en', 'name_ar'],
            'services'         => ['name_en', 'name_ar'],
            'countries'        => ['name_en', 'name_ar'],
            'states'           => ['name_en', 'name_ar'],
            'cities'           => ['name_en', 'name_ar'],
            'websites'         => ['about_us_en', 'about_us_ar', 'contact_address_en', 'contact_address_ar'],
            'website_features' => ['title_en', 'title_ar', 'description_en', 'description_ar'],
            'website_sliders'  => ['title_en', 'title_ar', 'sub_title_en', 'sub_title_ar'],
        ];

        foreach ($pairs as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Expected localized column [{$table}.{$column}] is missing."
                );
            }
        }
    }

    // ─── 2. Company — {en,ar} round-trip + Arabic search ────────────────────

    public function test_company_stores_and_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Gulf Transport', 'ar' => 'نقل الخليج'],
            'email' => 'gulf@example.com',
            'phone' => '96550535103',
        ])
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Gulf Transport')
            ->assertJsonPath('data.name.ar', 'نقل الخليج')
            ->json('data.id');

        // GET returns the same shape
        $this->getJson("/api/dashboard/v1/companies/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Gulf Transport')
            ->assertJsonPath('data.name.ar', 'نقل الخليج');
    }

    public function test_company_arabic_search_returns_matching_row(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Desert Logistics', 'ar' => 'لوجستيات الصحراء'],
            'email' => 'desert@example.com',
        ])->assertOk();

        $this->getJson('/api/dashboard/v1/companies?query=الصحراء')
            ->assertOk()
            ->assertJsonPath('data.list.0.name.ar', 'لوجستيات الصحراء');
    }

    // ─── 3. Service — {en,ar} round-trip + Arabic search ────────────────────

    public function test_service_stores_and_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'Towing', 'ar' => 'سحب السيارات'],
            'logo' => 'https://example.com/tow.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Towing')
            ->assertJsonPath('data.name.ar', 'سحب السيارات')
            ->json('data.id');

        $this->getJson("/api/dashboard/v1/services/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Towing')
            ->assertJsonPath('data.name.ar', 'سحب السيارات');
    }

    public function test_service_arabic_search_returns_matching_row(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'Fuel Delivery', 'ar' => 'توصيل الوقود'],
        ])->assertOk();

        $this->getJson('/api/dashboard/v1/services?query=الوقود')
            ->assertOk()
            ->assertJsonPath('data.list.0.name.ar', 'توصيل الوقود');
    }

    // ─── 4. Country — {en,ar} round-trip + Arabic search ────────────────────

    public function test_country_stores_and_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'Kuwait', 'ar' => 'الكويت'],
            'countryCode' => 'KW',
            'currency'    => 'KWD',
        ])
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Kuwait')
            ->assertJsonPath('data.name.ar', 'الكويت')
            ->json('data.id');

        $this->getJson("/api/dashboard/v1/countries/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name.en', 'Kuwait')
            ->assertJsonPath('data.name.ar', 'الكويت');
    }

    public function test_country_arabic_search_returns_matching_row(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية'],
            'countryCode' => 'SA',
            'currency'    => 'SAR',
        ])->assertOk();

        $this->getJson('/api/dashboard/v1/countries?query=المملكة')
            ->assertOk()
            ->assertJsonPath('data.list.0.name.ar', 'المملكة العربية السعودية');
    }

    // ─── 5. State — {en,ar} round-trip + Arabic search ──────────────────────

    public function test_state_stores_and_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'State Country', 'ar' => 'دولة للاختبار'],
            'countryCode' => 'SC',
            'currency'    => 'USD',
        ])->json('data.id');

        $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'Capital', 'stateAR' => 'العاصمة', 'countryID' => ['oid' => $countryId]],
        ])
            ->assertOk()
            ->assertJsonPath('data.list.0.name.en', 'Capital')
            ->assertJsonPath('data.list.0.name.ar', 'العاصمة');
    }

    public function test_state_arabic_search_returns_matching_row(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'Search Country', 'ar' => 'بلد البحث'],
            'countryCode' => 'SRC',
            'currency'    => 'USD',
        ])->json('data.id');

        $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'Northern Region', 'stateAR' => 'المنطقة الشمالية', 'countryID' => ['oid' => $countryId]],
        ])->assertOk();

        $this->getJson('/api/dashboard/v1/states?query=الشمالية')
            ->assertOk()
            ->assertJsonPath('data.list.0.name.ar', 'المنطقة الشمالية');
    }

    // ─── 6. City — {en,ar} round-trip + Arabic search ───────────────────────

    public function test_city_stores_and_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'City Country', 'ar' => 'دولة المدينة'],
            'countryCode' => 'CC',
            'currency'    => 'USD',
        ])->json('data.id');

        $stateId = $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'City State', 'stateAR' => 'ولاية المدينة', 'countryID' => ['oid' => $countryId]],
        ])->json('data.list.0.id');

        $this->postJson('/api/dashboard/v1/cities/bulk', [
            ['cityEN' => 'Kuwait City', 'cityAR' => 'مدينة الكويت', 'stateID' => ['oid' => $stateId]],
        ])
            ->assertOk()
            ->assertJsonPath('data.list.0.name.en', 'Kuwait City')
            ->assertJsonPath('data.list.0.name.ar', 'مدينة الكويت');
    }

    public function test_city_arabic_search_returns_matching_row(): void
    {
        $this->actingAsAdmin();

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name'        => ['en' => 'City Search Country', 'ar' => 'دولة بحث المدن'],
            'countryCode' => 'CSC',
            'currency'    => 'USD',
        ])->json('data.id');

        $stateId = $this->postJson('/api/dashboard/v1/states/bulk', [
            ['stateEN' => 'City Search State', 'stateAR' => 'ولاية بحث المدن', 'countryID' => ['oid' => $countryId]],
        ])->json('data.list.0.id');

        $this->postJson('/api/dashboard/v1/cities/bulk', [
            ['cityEN' => 'Salmiya', 'cityAR' => 'السالمية', 'stateID' => ['oid' => $stateId]],
        ])->assertOk();

        $this->getJson('/api/dashboard/v1/cities?query=السالمية')
            ->assertOk()
            ->assertJsonPath('data.list.0.name.ar', 'السالمية');
    }

    // ─── 7. Website — localized about/contact fields ─────────────────────────

    public function test_website_stores_and_returns_localized_content_fields(): void
    {
        $this->actingAsAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => 'Web Co', 'ar' => 'شركة ويب'],
            'email' => 'web-co@example.com',
        ])->json('data.id');

        $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'https://web-co.example.com',
            'aboutUs'   => ['en' => 'About us English', 'ar' => 'من نحن بالعربية'],
            'contactUs' => [
                'address' => ['en' => 'Main Street', 'ar' => 'الشارع الرئيسي'],
                'phone'   => '+96500000002',
                'email'   => 'contact@web-co.example.com',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.aboutUs.en', 'About us English')
            ->assertJsonPath('data.aboutUs.ar', 'من نحن بالعربية')
            ->assertJsonPath('data.contactUs.address.en', 'Main Street')
            ->assertJsonPath('data.contactUs.address.ar', 'الشارع الرئيسي')
            ->json('data.id');

        // Verify stored values are retrievable via the byCompany endpoint
        $this->getJson("/api/dashboard/v1/websites/companies/{$companyId}")
            ->assertOk()
            ->assertJsonPath('data.aboutUs.en', 'About us English')
            ->assertJsonPath('data.aboutUs.ar', 'من نحن بالعربية');
    }

    // ─── 8. WebsiteFeature — localized title/description ────────────────────

    public function test_website_feature_stores_and_returns_localized_title_and_description(): void
    {
        $this->actingAsAdmin();

        [$companyId, $websiteId] = $this->createWebsiteFixture();

        $this->postJson('/api/dashboard/v1/website-features', [
            'companyId'   => $companyId,
            'websiteId'   => $websiteId,
            'icon'        => 'star',
            'title'       => ['en' => 'Fast Service', 'ar' => 'خدمة سريعة'],
            'description' => ['en' => 'We are fast', 'ar' => 'نحن سريعون'],
        ])
            ->assertOk()
            ->assertJsonPath('data.title.en', 'Fast Service')
            ->assertJsonPath('data.title.ar', 'خدمة سريعة')
            ->assertJsonPath('data.description.en', 'We are fast')
            ->assertJsonPath('data.description.ar', 'نحن سريعون');
    }

    // ─── 9. WebsiteSlider — localized title/subTitle ─────────────────────────

    public function test_website_slider_stores_and_returns_localized_title_and_sub_title(): void
    {
        $this->actingAsAdmin();

        [$companyId, $websiteId] = $this->createWebsiteFixture('slider');

        $this->postJson('/api/dashboard/v1/website-sliders', [
            'companyId' => $companyId,
            'websiteId' => $websiteId,
            'imageUrl'  => 'https://example.com/slide.jpg',
            'title'     => ['en' => 'Welcome', 'ar' => 'أهلاً وسهلاً'],
            'subTitle'  => ['en' => 'Learn more', 'ar' => 'تعرف على المزيد'],
        ])
            ->assertOk()
            ->assertJsonPath('data.title.en', 'Welcome')
            ->assertJsonPath('data.title.ar', 'أهلاً وسهلاً')
            ->assertJsonPath('data.subTitle.en', 'Learn more')
            ->assertJsonPath('data.subTitle.ar', 'تعرف على المزيد');
    }

    // ─── 10. Mobile service list returns {en,ar} shape ───────────────────────

    public function test_mobile_service_list_returns_localized_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'Mobile Tow', 'ar' => 'سحب موبايل'],
        ])->assertOk();

        // Mobile endpoint reuses the same serviceResource — must return { en, ar }
        $this->getJson('/api/mobile/v1/services')
            ->assertOk()
            ->assertJsonStructure(['data' => ['list' => [['name' => ['en', 'ar']]]]]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        return $admin;
    }

    private function createWebsiteFixture(string $suffix = 'feat'): array
    {
        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name'  => ['en' => "Loc Co {$suffix}", 'ar' => "شركة {$suffix}"],
            'email' => "loc-co-{$suffix}@example.com",
        ])->json('data.id');

        $websiteId = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => "https://loc-co-{$suffix}.example.com",
        ])->json('data.id');

        return [$companyId, $websiteId];
    }
}
