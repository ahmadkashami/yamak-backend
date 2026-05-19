<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoOrphanRecords();

        Schema::table('places', function (Blueprint $table) {
            $table->foreignId('city_id')->change();
            $table->foreignId('place_type_id')->change();

            $table->foreign('city_id', 'places_city_id_foreign')
                ->references('id')
                ->on('cities')
                ->cascadeOnDelete();

            $table->foreign('place_type_id', 'places_place_type_id_foreign')
                ->references('id')
                ->on('place_types')
                ->cascadeOnDelete();
        });

        Schema::table('contains', function (Blueprint $table) {
            $table->foreignId('feature_id')->change();
            $table->foreignId('tour_id')->change();

            $table->foreign('feature_id', 'contains_feature_id_foreign')
                ->references('id')
                ->on('features')
                ->cascadeOnDelete();

            $table->foreign('tour_id', 'contains_tour_id_foreign')
                ->references('id')
                ->on('tours')
                ->cascadeOnDelete();
        });

        Schema::table('excludes', function (Blueprint $table) {
            $table->foreignId('feature_id')->change();
            $table->foreignId('tour_id')->change();

            $table->foreign('feature_id', 'excludes_feature_id_foreign')
                ->references('id')
                ->on('features')
                ->cascadeOnDelete();

            $table->foreign('tour_id', 'excludes_tour_id_foreign')
                ->references('id')
                ->on('tours')
                ->cascadeOnDelete();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->foreignId('tour_id')->change();

            $table->foreign('tour_id', 'tracks_tour_id_foreign')
                ->references('id')
                ->on('tours')
                ->cascadeOnDelete();
        });

        Schema::table('category_place_place', function (Blueprint $table) {
            $table->foreign('place_id', 'category_place_place_place_id_foreign')
                ->references('id')
                ->on('places')
                ->cascadeOnDelete();

            $table->foreign('category_place_id', 'category_place_place_category_place_id_foreign')
                ->references('id')
                ->on('category_places')
                ->cascadeOnDelete();
        });

        Schema::table('feature_place_place', function (Blueprint $table) {
            $table->foreign('place_id', 'feature_place_place_place_id_foreign')
                ->references('id')
                ->on('places')
                ->cascadeOnDelete();

            $table->foreign('feature_place_id', 'feature_place_place_feature_place_id_foreign')
                ->references('id')
                ->on('feature_places')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feature_place_place', function (Blueprint $table) {
            $table->dropForeign('feature_place_place_feature_place_id_foreign');
            $table->dropForeign('feature_place_place_place_id_foreign');
        });

        Schema::table('category_place_place', function (Blueprint $table) {
            $table->dropForeign('category_place_place_category_place_id_foreign');
            $table->dropForeign('category_place_place_place_id_foreign');
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropForeign('tracks_tour_id_foreign');
        });

        Schema::table('excludes', function (Blueprint $table) {
            $table->dropForeign('excludes_tour_id_foreign');
            $table->dropForeign('excludes_feature_id_foreign');
        });

        Schema::table('contains', function (Blueprint $table) {
            $table->dropForeign('contains_tour_id_foreign');
            $table->dropForeign('contains_feature_id_foreign');
        });

        Schema::table('places', function (Blueprint $table) {
            $table->dropForeign('places_place_type_id_foreign');
            $table->dropForeign('places_city_id_foreign');
        });
    }

    /**
     * Stop before adding constraints if legacy data contains invalid references.
     *
     * The migration intentionally does not repair or delete records; existing
     * production data should be cleaned or re-linked by an explicit data task.
     */
    private function assertNoOrphanRecords(): void
    {
        $checks = [
            ['places', 'city_id', 'cities', 'id'],
            ['places', 'place_type_id', 'place_types', 'id'],
            ['contains', 'feature_id', 'features', 'id'],
            ['contains', 'tour_id', 'tours', 'id'],
            ['excludes', 'feature_id', 'features', 'id'],
            ['excludes', 'tour_id', 'tours', 'id'],
            ['tracks', 'tour_id', 'tours', 'id'],
            ['category_place_place', 'place_id', 'places', 'id'],
            ['category_place_place', 'category_place_id', 'category_places', 'id'],
            ['feature_place_place', 'place_id', 'places', 'id'],
            ['feature_place_place', 'feature_place_id', 'feature_places', 'id'],
        ];

        $orphans = [];

        foreach ($checks as [$childTable, $foreignColumn, $parentTable, $parentColumn]) {
            $count = DB::table($childTable.' as child')
                ->leftJoin($parentTable.' as parent', 'child.'.$foreignColumn, '=', 'parent.'.$parentColumn)
                ->whereNotNull('child.'.$foreignColumn)
                ->whereNull('parent.'.$parentColumn)
                ->count();

            if ($count > 0) {
                $orphans[] = sprintf(
                    '%s.%s -> %s.%s has %d orphan record(s)',
                    $childTable,
                    $foreignColumn,
                    $parentTable,
                    $parentColumn,
                    $count
                );
            }
        }

        if ($orphans !== []) {
            throw new RuntimeException(
                "Cannot add missing foreign key constraints while orphan records exist:\n".
                implode("\n", $orphans).
                "\n\nRun the orphan-check queries in database/database-fixes-summary.md, then clean or re-link the invalid records before running php artisan migrate again."
            );
        }
    }
};
