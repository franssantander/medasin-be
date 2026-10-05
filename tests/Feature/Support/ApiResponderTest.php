<?php

use Illuminate\Support\Facades\Route;

pest()->group('pest-features');

it('returns JSON debug errors without stream arguments or credentials in the trace', function (): void {
    config(['app.debug' => true]);
    $previousSetting = ini_set('zend.exception_ignore_args', '0');
    $stream = fopen('php://memory', 'w+');
    $raise = static function (mixed $stream, string $credential): never {
        throw new RuntimeException('Simulated storage failure.');
    };

    try {
        $raise($stream, 'private-trace-credential');
    } catch (RuntimeException $exception) {
        Route::get('/api/v1/test-exception', fn (): never => throw $exception);

        $response = $this->getJson('/api/v1/test-exception');

        $response->assertServerError()
            ->assertJsonPath('data', null)
            ->assertJsonPath('status', 500)
            ->assertJsonPath('message', 'Simulated storage failure.')
            ->assertJsonPath('debug.exception', RuntimeException::class)
            ->assertJsonPath('debug.message', 'Simulated storage failure.')
            ->assertJsonPath('debug.trace.0.file', __FILE__)
            ->assertJsonCount(10, 'debug.trace')
            ->assertDontSee('private-trace-credential', false);
        foreach ($response->json('debug.trace') as $frame) {
            expect($frame)->not->toHaveKey('args');
        }
    } finally {
        fclose($stream);
        ini_set('zend.exception_ignore_args', $previousSetting);
    }
});

it('omits debug details when debugging is disabled or the environment is production', function (
    bool $debug,
    string $environment,
): void {
    config(['app.debug' => $debug]);
    $this->app->instance('env', $environment);
    Route::get('/api/v1/test-exception', function (): never {
        throw new RuntimeException('Simulated storage failure.');
    });

    $this->getJson('/api/v1/test-exception')->assertServerError()->assertExactJson([
        'data' => null,
        'status' => 500,
        'message' => 'Simulated storage failure.',
    ]);
})->with([
    'debug disabled' => [false, 'testing'],
    'production' => [true, 'production'],
]);
