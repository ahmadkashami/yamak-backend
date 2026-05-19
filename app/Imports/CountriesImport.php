<?php
namespace App\Imports;

use App\Models\Country;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CountriesImport implements ToModel, WithHeadingRow, SkipsEmptyRows, WithBatchInserts, WithChunkReading
{
    public function model(array $row)
    {
        // Transform the name and description columns
        $name = [
            'ar' => $row['ar_name'] ?? '',
            'en' => $row['en_name'] ?? '',
        ];

        $description = [
            'ar' => $row['ar_description'] ?? '',
            'en' => $row['en_description'] ?? '',
        ];

        $whenToVisit = [
            'ar' => $row['ar_when_to_visit'] ?? '',
            'en' => $row['en_when_to_visit'] ?? '',
        ];

        return new Country([
           'name' => $name,
            'description' => $description,
            'when_to_visit' => $whenToVisit,
            'featured' => $row['featured'] ?? 0,
            'status' => $row['status'] ?? 0,
            'cover_image' => $row['cover_image'] ?? '',
            'flag_image' => $row['flag_image'] ?? null,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
            'deleted_at' => $row['deleted_at'] ?? null,
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
