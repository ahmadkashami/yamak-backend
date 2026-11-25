<?php
require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/vendor/laravel/laravel/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Users: ";
print_r(App\Models\User::all()->pluck('id')->toArray());

echo "AppUsers: ";
print_r(App\Models\AppUser::all()->pluck('id')->toArray());

echo "Drivers: ";
print_r(App\Models\Driver::all()->pluck('id')->toArray());

echo "Vehicles: ";
print_r(App\Models\Vehicle::all()->pluck('id')->toArray());

echo "Services: ";
print_r(App\Models\Service::all()->pluck('id')->toArray());