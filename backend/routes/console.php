<?php

use App\Support\Ab150StressDataset;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ab150:verify-database', function () {
    Ab150StressDataset::assertIsolatedMySql();

    $this->info('Verified active MySQL database: '.Ab150StressDataset::DATABASE);
    if (DB::getSchemaBuilder()->hasTable('users')) {
        $this->table(['users', 'movies', 'reviews', 'visible reviews', 'statuses', 'watched', 'watchlist'], [Ab150StressDataset::counts()]);
    } else {
        $this->warn('The database has no application tables yet. It is safe to run ab150:prepare.');
    }
})->purpose('Verify the isolated AB#150 MySQL database without writing to it');

Artisan::command('ab150:prepare', function () {
    Ab150StressDataset::assertIsolatedMySql();
    Ab150StressDataset::assertNoApplicationData();
    $this->info('Isolation verified. Running standard migrations only on '.Ab150StressDataset::DATABASE.'.');
    Artisan::call('migrate', ['--force' => true]);
    $this->output->write(Artisan::output());

    Ab150StressDataset::assertIsolatedMySql();
    $counts = Ab150StressDataset::seed();
    $this->info('Seeded deterministic AB#150 stress fixtures.');
    $this->table(array_keys($counts), [$counts]);
})->purpose('Migrate and seed the empty, isolated AB#150 MySQL database');
