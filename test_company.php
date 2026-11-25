<?php
require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/vendor/laravel/laravel/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Creating Company...\n";
$company = App\Models\Company::create([
    'name_en' => 'Test',
    'status' => 'active',
]);
echo "Company ID: " . $company->id . "\n";

echo "Creating Branch...\n";
$branch = App\Models\Branch::create([
    'company_id' => $company->id,
    'name' => 'Test Branch',
]);
echo "Branch created: " . $branch->id . "\n";

echo "Done!\n";