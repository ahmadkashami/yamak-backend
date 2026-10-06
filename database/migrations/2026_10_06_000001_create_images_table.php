<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `images` is shared polymorphic image storage for App\Models\Image.
     *
     * App\Models\Image and App\Models\Imageable have existed since the initial
     * commit (17944d7) but no migration ever created their table, and no code
     * touched the model until App\Models\OnboardingSlide::image() was added.
     * Without this table every onboarding request that resolves a slide image
     * fails with "no such table: images".
     */
    public function up(): void
    {
        // Guarded because the documented deployment path is `php artisan migrate --force`
        // (docs/migration/production-deployment-runbook.md) and a hand-maintained
        // database may already contain the table. Creating it unconditionally would
        // abort that run; skipping it keeps fresh databases working and existing
        // databases untouched.
        if (Schema::hasTable('images')) {
            return;
        }

        Schema::create('images', function (Blueprint $table) {
            $table->id();
            // Matches OnboardingSlide's `id()` primary key and the morphs() convention
            // already used by create_personal_access_tokens_table.
            $table->morphs('imageable');
            // Sized to the controller contract: slides.*.image is validated max:2048,
            // and the dashboard submits the full Cloudinary secure_url verbatim.
            $table->string('path', 2048);
            // App\Models\Image casts `description` to array; nullable text mirrors the
            // `abilities` column on personal_access_tokens.
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Intentionally a no-op.
     *
     * Because up() skips creation when the table already exists, this migration
     * cannot know whether it owns `images`. Dropping a shared table it may not have
     * created would destroy data on rollback. Full teardown is handled by
     * `migrate:fresh` / `db:wipe`.
     */
    public function down(): void
    {
        //
    }
};
