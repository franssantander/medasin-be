<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerEntrypointTest extends TestCase
{
    #[DataProvider('startupModes')]
    public function test_startup_runs_only_enabled_database_commands_before_the_server(array $environment, array $databaseCalls): void
    {
        $result = $this->runEntrypoint($environment);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertSame([
            'composer <install> <--no-interaction>',
            ...$databaseCalls,
            'server <argument with spaces> <--port=9000>',
        ], $result['calls']);
    }

    /**
     * @return array<string, array{array<string, string|false>, list<string>}>
     */
    public static function startupModes(): array
    {
        $migrate = 'php <artisan> <migrate> <--force> <--no-interaction>';
        $seed = 'php <artisan> <db:seed> <--force> <--no-interaction>';

        return [
            'migrate and seed' => [['RUN_MIGRATIONS' => 'true', 'RUN_SEEDERS' => 'true'], [$migrate, $seed]],
            'migrate only' => [['RUN_MIGRATIONS' => 'true'], [$migrate]],
            'seed only' => [['RUN_SEEDERS' => 'true'], [$seed]],
            'disabled flags' => [['RUN_MIGRATIONS' => 'false', 'RUN_SEEDERS' => 'false'], []],
            'unset flags' => [[], []],
            'retired fresh flag cannot reset data' => [['RUN_MIGRATIONS' => 'true', 'RUN_SEEDERS' => 'true', 'AUTO_FRESH_SEED' => 'true'], [$migrate, $seed]],
        ];
    }

    #[DataProvider('startupFailures')]
    public function test_startup_stops_after_a_command_fails(string $exitVariable, int $exitCode, array $expectedCalls): void
    {
        $result = $this->runEntrypoint([
            'RUN_MIGRATIONS' => 'true',
            'RUN_SEEDERS' => 'true',
            $exitVariable => (string) $exitCode,
        ]);

        $this->assertSame($exitCode, $result['exit_code'], $result['output']);
        $this->assertSame($expectedCalls, $result['calls']);
    }

    /**
     * @return array<string, array{string, int, list<string>}>
     */
    public static function startupFailures(): array
    {
        $composer = 'composer <install> <--no-interaction>';
        $migrate = 'php <artisan> <migrate> <--force> <--no-interaction>';
        $seed = 'php <artisan> <db:seed> <--force> <--no-interaction>';

        return [
            'composer failure' => ['COMPOSER_EXIT_CODE', 17, [$composer]],
            'migration failure' => ['MIGRATION_EXIT_CODE', 18, [$composer, $migrate]],
            'seeder failure' => ['SEEDER_EXIT_CODE', 19, [$composer, $migrate, $seed]],
            'server exit forwarded' => ['SERVER_EXIT_CODE', 20, [$composer, $migrate, $seed, 'server <argument with spaces> <--port=9000>']],
        ];
    }

    /**
     * @param  array<string, string|false>  $environment
     * @return array{exit_code: ?int, calls: list<string>, output: string}
     */
    private function runEntrypoint(array $environment): array
    {
        $directory = sys_get_temp_dir().'/medasin-entrypoint-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $stub = <<<'SH'
#!/bin/sh
printf '%s' "${0##*/}" >> "$STARTUP_CALLS"
for argument do
    printf ' <%s>' "$argument" >> "$STARTUP_CALLS"
done
printf '\n' >> "$STARTUP_CALLS"
case "${0##*/}:${2:-}" in
    composer:*) exit "${COMPOSER_EXIT_CODE:-0}" ;;
    php:migrate) exit "${MIGRATION_EXIT_CODE:-0}" ;;
    php:db:seed) exit "${SEEDER_EXIT_CODE:-0}" ;;
    server:*) exit "${SERVER_EXIT_CODE:-0}" ;;
    *) exit 99 ;;
esac
SH;

        try {
            foreach (['composer', 'php', 'server'] as $command) {
                file_put_contents($directory.'/'.$command, $stub);
                chmod($directory.'/'.$command, 0700);
            }

            $process = new Process(
                ['/bin/sh', dirname(__DIR__, 2).'/.docker/entrypoint.sh', 'server', 'argument with spaces', '--port=9000'],
                $directory,
                [
                    'PATH' => $directory.':'.getenv('PATH'),
                    'STARTUP_CALLS' => $directory.'/calls',
                    'RUN_MIGRATIONS' => false,
                    'RUN_SEEDERS' => false,
                    'AUTO_FRESH_SEED' => false,
                    'COMPOSER_EXIT_CODE' => false,
                    'MIGRATION_EXIT_CODE' => false,
                    'SEEDER_EXIT_CODE' => false,
                    'SERVER_EXIT_CODE' => false,
                    ...$environment,
                ],
            );
            $process->run();

            return [
                'exit_code' => $process->getExitCode(),
                'calls' => file($directory.'/calls', FILE_IGNORE_NEW_LINES),
                'output' => $process->getOutput().$process->getErrorOutput(),
            ];
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
}
