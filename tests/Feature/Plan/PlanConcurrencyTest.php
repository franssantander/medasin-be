<?php

use App\Models\Plan;
use App\Models\User;
use App\Services\Trash\TrashService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('pest-features', 'pgsql-locking');

it('serializes two Core writes competing for the final available slot', function (string $feature, array $operations): void {
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
    $labels = [$tag.'-1', $tag.'-2'];
    $field = $feature === 'resources' ? 'title' : 'name';
    $restoredSubjects = [];
    $restoreEntries = [];
    foreach ($operations as $index => $operation) {
        if ($operation === 'restore') {
            $subject = $user->{$feature}()->create([$field => 'Trashed '.$labels[$index]]);
            $restoredSubjects[$index] = $subject;
            $restoreEntries[$index] = app(TrashService::class)->delete(
                $user, $subject, Str::singular($feature), $subject->{$field},
            );
        }
    }
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
        if ($argv[5] === 'restore') {
            $entry = \App\Models\TrashEntry::findOrFail($argv[6]);
            app(\App\Services\Trash\TrashService::class)->restore($entry);
            echo json_encode(['status' => 200]).PHP_EOL;
        } else {
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
        }
    } catch (\App\Support\PlanLimitExceededException $exception) {
        echo json_encode([
            'status' => 403, 'feature' => $exception->feature->value,
            'usage' => $exception->usage, 'limit' => $exception->limit,
        ]).PHP_EOL;
    }
    PHP;
    $processes = [];

    DB::beginTransaction();
    try {
        User::query()->lockForUpdate()->findOrFail($user->id);
        foreach ($labels as $index => $label) {
            $process = new Process([
                PHP_BINARY, '-r', $worker, base_path(), (string) $user->id, $feature, $label,
                $operations[$index], (string) ($restoreEntries[$index]?->id ?? 0),
            ], base_path(), $environment);
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
        $successfulCreates = 0;
        $successfulRestores = 0;
        foreach ($processes as $index => $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $statuses[] = $result['status'];
            if ($result['status'] === 403) {
                expect($result)->toBe(['status' => 403, 'feature' => $feature, 'usage' => 1, 'limit' => 1]);
                if ($operations[$index] === 'restore') {
                    $this->assertSoftDeleted($restoredSubjects[$index]);
                    $this->assertModelExists($restoreEntries[$index]);
                } else {
                    $this->assertDatabaseMissing($feature, ['user_id' => $user->id, $field => 'Concurrent '.$labels[$index]]);
                }
            } elseif ($operations[$index] === 'restore') {
                expect($result['status'])->toBe(200);
                $successfulRestores++;
                $this->assertNotSoftDeleted($restoredSubjects[$index]);
                $this->assertModelMissing($restoreEntries[$index]);
            } else {
                expect($result['status'])->toBe(201);
                $successfulCreates++;
                $this->assertDatabaseHas($feature, ['user_id' => $user->id, $field => 'Concurrent '.$labels[$index], 'deleted_at' => null]);
            }
        }

        expect($successfulCreates + $successfulRestores)->toBe(1);
        expect(array_count_values($statuses)[403] ?? 0)->toBe(1);
        expect($user->{$feature}()->count())->toBe(1);
        expect($user->{$feature}()->withTrashed()->count())->toBe(count($restoreEntries) + $successfulCreates);
        expect($user->trashEntries()->count())->toBe(count($restoreEntries) - $successfulRestores);
        if ($feature === 'projects') {
            $this->assertDatabaseCount('boards', $successfulCreates);
        }
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
})->with([
    'projects create/create' => ['projects', ['create', 'create']],
    'areas create/create' => ['areas', ['create', 'create']],
    'resources create/create' => ['resources', ['create', 'create']],
    'projects create/restore' => ['projects', ['create', 'restore']],
    'areas create/restore' => ['areas', ['create', 'restore']],
    'resources create/restore' => ['resources', ['create', 'restore']],
    'projects restore/restore' => ['projects', ['restore', 'restore']],
    'areas restore/restore' => ['areas', ['restore', 'restore']],
    'resources restore/restore' => ['resources', ['restore', 'restore']],
]);
