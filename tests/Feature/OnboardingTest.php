<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const BASE = '/api/dashboard/v1/onboardings';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAsDashboardAdmin();
    }

    private function actingAsDashboardAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        return $admin;
    }

    /**
     * A. The images table is created by the migration set, and the
     *    UNIQUE(onboarding_id, sort_order) constraint stays in place.
     */
    public function test_fresh_test_database_has_images_table_and_keeps_slide_order_unique(): void
    {
        $this->assertTrue(Schema::hasTable('images'), 'images table is missing from the migrated test schema');

        $columns = Schema::getColumnListing('images');
        foreach (['id', 'imageable_type', 'imageable_id', 'path', 'description', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columns, "images.{$column} is missing");
        }

        $indexNames = array_map(
            fn (object $index): string => $index->name,
            DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'onboarding_slides'")
        );

        $this->assertContains(
            'onboarding_slides_onboarding_id_sort_order_unique',
            $indexNames,
            'UNIQUE(onboarding_id, sort_order) must not be removed'
        );
    }

    /**
     * B. Creating a slide with an image no longer fails with "no such table: images".
     */
    public function test_create_onboarding_with_image_succeeds(): void
    {
        $imageUrl = 'https://res.cloudinary.com/demo/image/upload/v1712345678/onboarding-1.png';

        $created = $this->createDraft([
            $this->slidePayload(1, ['image' => $imageUrl]),
        ]);

        $this->assertSame('draft', $created['status']);
        $this->assertCount(1, $created['slides']);
        $this->assertSame($imageUrl, $created['slides'][0]['imageUrl']);
        $this->assertSame(1, Image::query()->count());
        $this->assertSame(
            'App\Models\OnboardingSlide',
            Image::query()->firstOrFail()->imageable_type
        );
    }

    /**
     * C. show() eager-loads slides.image, which is the path that previously
     *    crashed for onboarding created without any image at all.
     */
    public function test_show_eager_loads_slide_images(): void
    {
        $first = 'https://res.cloudinary.com/demo/image/upload/v1712345678/first.png';
        $second = 'https://res.cloudinary.com/demo/image/upload/v1712345678/second.png';

        $created = $this->createDraft([
            $this->slidePayload(1, ['image' => $first]),
            $this->slidePayload(2, ['image' => $second]),
        ]);

        $shown = $this->getJson(self::BASE . '/' . $created['id'])
            ->assertOk()
            ->json('data');

        $byId = $this->slidesById($shown);
        $this->assertSame($first, $byId[$created['slides'][0]['id']]['imageUrl']);
        $this->assertSame($second, $byId[$created['slides'][1]['id']]['imageUrl']);
    }

    /**
     * C2. An onboarding with no images at all still resolves slides.image.
     */
    public function test_create_and_show_onboarding_without_image_succeeds(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
        ]);

        $shown = $this->getJson(self::BASE . '/' . $created['id'])
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $shown['slides']);
        $this->assertNull($shown['slides'][0]['imageUrl']);
        $this->assertSame(0, Image::query()->count());
    }

    /**
     * D. Swap: 1,2 -> 2,1 through the real update endpoint.
     */
    public function test_swapping_two_existing_slide_orders_succeeds(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
        ]);

        $firstId = $created['slides'][0]['id'];
        $secondId = $created['slides'][1]['id'];

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 2),
            $this->existingSlide($secondId, 1),
        ]);

        $this->assertSame([$secondId, $firstId], array_column($updated['slides'], 'id'));

        $orders = $this->orderMap($updated);
        $this->assertSame(2, $orders[$firstId]);
        $this->assertSame(1, $orders[$secondId]);
    }

    /**
     * E. Reorder: 1,2,3 -> 3,1,2.
     */
    public function test_reordering_three_slides_succeeds(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
            $this->slidePayload(3),
        ]);

        [$firstId, $secondId, $thirdId] = array_column($created['slides'], 'id');

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 3),
            $this->existingSlide($secondId, 1),
            $this->existingSlide($thirdId, 2),
        ]);

        $this->assertSame([$secondId, $thirdId, $firstId], array_column($updated['slides'], 'id'));

        $orders = $this->orderMap($updated);
        $this->assertSame(3, $orders[$firstId]);
        $this->assertSame(1, $orders[$secondId]);
        $this->assertSame(2, $orders[$thirdId]);
    }

    /**
     * F. Reorder and add a new slide in the same update.
     */
    public function test_reorder_and_add_slide_in_same_update(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
        ]);

        [$firstId, $secondId] = array_column($created['slides'], 'id');

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 2),
            $this->existingSlide($secondId, 1),
            $this->slidePayload(3, ['title_en' => 'Slide 3']),
        ]);

        $displayIds = array_column($updated['slides'], 'id');
        $this->assertCount(3, $displayIds);
        $this->assertSame([$secondId, $firstId, $displayIds[2]], $displayIds);
        $this->assertNotContains($displayIds[2], [$firstId, $secondId]);

        $orders = $this->orderMap($updated);
        $this->assertSame(2, $orders[$firstId]);
        $this->assertSame(1, $orders[$secondId]);
        $this->assertSame(3, $orders[$displayIds[2]]);
    }

    /**
     * G. Reorder and remove a slide in the same update.
     */
    public function test_reorder_and_remove_slide_in_same_update(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
            $this->slidePayload(3),
        ]);

        [$firstId, $secondId, $thirdId] = array_column($created['slides'], 'id');

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 3),
            $this->existingSlide($thirdId, 1),
        ]);

        $this->assertSame([$thirdId, $firstId], array_column($updated['slides'], 'id'));

        $orders = $this->orderMap($updated);
        $this->assertSame(3, $orders[$firstId]);
        $this->assertSame(1, $orders[$thirdId]);

        $this->assertDatabaseMissing('onboarding_slides', ['id' => $secondId]);
    }

    /**
     * H. Regression: an omitted `image` key must not touch the stored image,
     *    including while the slide is being reordered.
     */
    public function test_images_survive_reorder_when_image_key_is_omitted(): void
    {
        $first = 'https://res.cloudinary.com/demo/image/upload/v1712345678/keep-first.png';
        $second = 'https://res.cloudinary.com/demo/image/upload/v1712345678/keep-second.png';

        $created = $this->createDraft([
            $this->slidePayload(1, ['image' => $first]),
            $this->slidePayload(2, ['image' => $second]),
        ]);

        [$firstId, $secondId] = array_column($created['slides'], 'id');

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 2),
            $this->existingSlide($secondId, 1),
        ]);

        $byId = $this->slidesById($updated);
        $this->assertSame($first, $byId[$firstId]['imageUrl']);
        $this->assertSame($second, $byId[$secondId]['imageUrl']);
        $this->assertSame(2, Image::query()->count());

        $orders = $this->orderMap($updated);
        $this->assertSame(2, $orders[$firstId]);
        $this->assertSame(1, $orders[$secondId]);
    }

    /**
     * I. Sending a new image URL replaces only that slide's image.
     */
    public function test_replacing_one_image_still_works(): void
    {
        $first = 'https://res.cloudinary.com/demo/image/upload/v1712345678/original-first.png';
        $second = 'https://res.cloudinary.com/demo/image/upload/v1712345678/original-second.png';
        $replacement = 'https://res.cloudinary.com/demo/image/upload/v1712345678/replacement.png';

        $created = $this->createDraft([
            $this->slidePayload(1, ['image' => $first]),
            $this->slidePayload(2, ['image' => $second]),
        ]);

        [$firstId, $secondId] = array_column($created['slides'], 'id');

        $updated = $this->updateSlides($created['id'], [
            $this->existingSlide($firstId, 1, ['image' => $replacement]),
            $this->existingSlide($secondId, 2),
        ]);

        $byId = $this->slidesById($updated);
        $this->assertSame($replacement, $byId[$firstId]['imageUrl']);
        $this->assertSame($second, $byId[$secondId]['imageUrl']);
        $this->assertSame(2, Image::query()->count());
    }

    /**
     * J. Duplicate final sort_order is still rejected before anything is written.
     */
    public function test_duplicate_final_sort_order_is_rejected(): void
    {
        $created = $this->createDraft([
            $this->slidePayload(1),
            $this->slidePayload(2),
        ]);

        [$firstId, $secondId] = array_column($created['slides'], 'id');

        $this->putJson(self::BASE . '/' . $created['id'], [
            'slides' => [
                $this->existingSlide($firstId, 1),
                $this->existingSlide($secondId, 1),
            ],
        ])->assertStatus(422);

        $orders = $this->orderMap(
            $this->getJson(self::BASE . '/' . $created['id'])->assertOk()->json('data')
        );
        $this->assertSame(1, $orders[$firstId]);
        $this->assertSame(2, $orders[$secondId]);
    }

    private function createDraft(array $slides): array
    {
        return $this->postJson(self::BASE, [
            'is_skippable' => true,
            'slides' => $slides,
        ])
            ->assertOk()
            ->json('data');
    }

    private function updateSlides(int $onboardingId, array $slides): array
    {
        return $this->putJson(self::BASE . '/' . $onboardingId, ['slides' => $slides])
            ->assertOk()
            ->json('data');
    }

    private function slidePayload(int $order, array $overrides = []): array
    {
        return array_merge([
            'sort_order' => $order,
            'title_en' => "Slide {$order}",
            'title_ar' => "Slide AR {$order}",
            'description_en' => "Description {$order}",
        ], $overrides);
    }

    private function existingSlide(int $id, int $order, array $overrides = []): array
    {
        return array_merge(['id' => $id], $this->slidePayload($order), $overrides);
    }

    private function orderMap(array $data): array
    {
        $map = [];
        foreach ($data['slides'] as $slide) {
            $map[$slide['id']] = $slide['order'];
        }

        return $map;
    }

    private function slidesById(array $data): array
    {
        $map = [];
        foreach ($data['slides'] as $slide) {
            $map[$slide['id']] = $slide;
        }

        return $map;
    }
}
