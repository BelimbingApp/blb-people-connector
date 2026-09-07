<?php

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Console\Commands\SyncWorkforceCommand;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use Illuminate\Support\Facades\Artisan;

/*
 * Every console command this domain ships runs inside one tenant (#249),
 * except the scheduler's cross-tenant sync, which says so below.
 *
 * `blb:domain-commands --audit` (belimbing#710) is the platform's check; this
 * is the domain's own, so a command added here without the base class fails
 * in this repository's suite before the composed audit sees it.
 * Self-contained: helpers are prefixed connectorTenantScoped and live here.
 */

/**
 * Commands that legitimately run with no tenant bound. Each one keeps a dated
 * allowlist entry in belimbing's domain_commands config with the reason here.
 *
 * - people-connector:sync walks every active connection in every tenant on
 *   the scheduler's behalf and binds the tenant per connection itself;
 *   `--tenant` there is a filter, not a requirement.
 */
const CONNECTOR_CROSS_TENANT_COMMANDS = [SyncWorkforceCommand::class];

/** @return list<string> every console command class under this domain */
function connectorTenantScopedClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../Console/Commands/*Command.php') as $file) {
        $classes[] = 'App\\Domains\\PeopleConnector\\Connector\\Console\\Commands\\'.basename($file, '.php');
    }

    sort($classes);

    return $classes;
}

/** @return array<string, string> command name => class, tenant-scoped ones only */
function connectorTenantScopedNames(): array
{
    $names = [];

    foreach (connectorTenantScopedClasses() as $class) {
        if (! in_array($class, CONNECTOR_CROSS_TENANT_COMMANDS, true)) {
            $names[app($class)->getName()] = $class;
        }
    }

    return $names;
}

/**
 * The arguments each command needs to get past its own input validation, so
 * a refusal is the tenant guard's and not a missing-argument error.
 *
 * @return array<string, mixed>
 */
function connectorTenantScopedArguments(string $name): array
{
    return match ($name) {
        'connector:capability:verify' => ['provider' => 'test.none', 'capability' => 'employee_directory'],
        'people-connector:cutover-rehearsal' => ['from' => 1, 'to' => 2],
        'connector:identity:audit-trail' => ['external-id' => 'EMP-1'],
        'connector:migrate:dry-run' => ['--to' => 2, '--as' => 1],
        'connector:webhook:replay' => ['delivery' => 1],
        'connector:webhook:secret:rotate' => ['connection' => 1],
        'people-connector:subject-export' => ['entity' => 1],
        'connector:identity-import' => ['package' => 'none'],
        default => [],
    };
}

test('every console command except the cross-tenant sync extends TenantScopedCommand', function (): void {
    $plain = array_filter(
        connectorTenantScopedClasses(),
        static fn (string $class): bool => ! is_subclass_of($class, TenantScopedCommand::class)
            && ! in_array($class, CONNECTOR_CROSS_TENANT_COMMANDS, true),
    );

    expect(array_values($plain))->toBe([]);
});

test('a command run without --tenant exits non-zero before its handler runs', function (string $name): void {
    $exit = Artisan::call($name, connectorTenantScopedArguments($name));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('--tenant')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
})->with(array_keys(connectorTenantScopedNames()));

test('a command run with an unknown tenant exits non-zero with the base message', function (string $name): void {
    $exit = Artisan::call($name, ['--tenant' => 999_999] + connectorTenantScopedArguments($name));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('999999')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
})->with(array_keys(connectorTenantScopedNames()));

test('a writing command refused for tenancy leaves no side effect', function (): void {
    $before = OperatorAudit::query()->count();

    // retention-purge audits every run it performs; refused, it performs none.
    expect(Artisan::call('people-connector:retention-purge', ['--as' => 1, '--yes' => true]))->not->toBe(0)
        ->and(OperatorAudit::query()->count())->toBe($before);
});
