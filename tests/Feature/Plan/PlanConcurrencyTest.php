<?php

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('pest-features', 'pgsql-locking');

it('serializes two Core writes competing for the final available slot', function (string $feature): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Run the pgsql-locking group against a dedicated PostgreSQL test database.');
    }
    $connection = config('database.connections.'.DB::getDefaultConnection());
    if (! app()->environment('testing') || ! str_ends_with($connection['database'], '_test')) {
        throw new RuntimeException('Concurrency tests require a dedicated database ending in _test.');
    }
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'free')->sole()->updateOrFail(['limits' => ['projects' => 1, 'areas' => 1, 'resources' => 1]]);
    $user = User::factory()->create();
    $tag = 'quota-'.Str::random(12);
    $environment = [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.$tag.'-config.php',
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => '',
        'DB_HOST' => $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'CACHE_STORE' => 'array',
        'API_READ_CACHE_STORE' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'PLAN_ENFORCEMENT_ENABLED' => 'true',
    ];
    $worker = <<<'PHP'
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    \Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
    try {
        $user = \App\Models\User::findOrFail($argv[2]);
        $name = 'Concurrent '.$argv[4];
        match ($argv[3]) {
            'projects' => app(\App\Services\Project\ProjectService::class)->create(
                $user, \App\Data\Project\ProjectData::from(['name' => $name]),
            ),
            'areas' => app(\App\Services\Area\AreaService::class)->create(
                $user, \App\Data\Area\AreaData::from(['name' => $name]),
            ),
            'resources' => app(\App\Services\Resource\ResourceService::class)->create(
                $user, \App\Data\Resource\StoreResourceData::from(['title' => $name]),
            ),
        };
        echo json_encode(['status' => 201]).PHP_EOL;
    } catch (\App\Support\PlanLimitExceededException $exception) {
        echo json_encode(['status' => 403, 'feature' => $exception->feature->value]).PHP_EOL;
    }
    PHP;
    $processes = [];
    $labels = [$tag.'-1', $tag.'-2'];

    DB::beginTransaction();
    try {
        User::query()->lockForUpdate()->findOrFail($user->id);
        foreach ($labels as $label) {
            $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $user->id, $feature, $label], base_path(), $environment);
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne(
                "SELECT count(*) AS total FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = 'Lock'",
                $labels,
            );
            if ((int) $waiting->total === 2) {
                break;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Quota worker exited before obtaining its lock: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Both quota workers must wait for the account lock before it is released.');
            }
            usleep(10000);
        } while (true);

        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $statuses[] = $result['status'];
            if ($result['status'] === 403) {
                expect($result['feature'])->toBe($feature);
            }
        }
        sort($statuses);

        expect($statuses)->toBe([201, 403]);
        expect($user->{$feature}()->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
})->with(['projects', 'areas', 'resources']);
