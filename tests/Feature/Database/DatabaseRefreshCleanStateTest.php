<?php

use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('seeds the clean state after migrate:fresh', function (): void {
    $this->app['env'] = 'local';

    event(new DatabaseRefreshed);

    $this->app['env'] = 'testing';
    $this->assertDatabaseEmpty('users');
    $this->assertDatabaseCount('oauth_clients', 1);
    expect(app(ClientRepository::class)->personalAccessClient('users'))
        ->provider->toBe('users')
        ->revoked->toBeFalse();
    $this->assertDatabaseCount('plans', count(config('plans.tiers')));
});

it('leaves test databases unseeded after a refresh', function (): void {
    event(new DatabaseRefreshed);

    $this->assertDatabaseEmpty('oauth_clients');
    $this->assertDatabaseEmpty('plans');
});
