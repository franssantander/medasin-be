<?php

use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('seeds the personal access client after migrate:fresh', function (): void {
    $this->app['env'] = 'local';

    event(new DatabaseRefreshed);

    $this->app['env'] = 'testing';
    $this->assertDatabaseCount('oauth_clients', 1);
    expect(app(ClientRepository::class)->personalAccessClient('users'))
        ->provider->toBe('users')
        ->revoked->toBeFalse();
});

it('leaves test databases without a client after a refresh', function (): void {
    event(new DatabaseRefreshed);

    $this->assertDatabaseEmpty('oauth_clients');
});
