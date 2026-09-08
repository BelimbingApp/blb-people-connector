<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only: proves a trigger function survives an incubating table rebuild.');
    }

    $schema = 'zz_privileged_support_migration_'.bin2hex(random_bytes(6));
    $this->privilegedSupportMigrationSchema = $schema;

    DB::statement('CREATE SCHEMA "'.$schema.'"');
    DB::statement('SET LOCAL search_path TO "'.$schema.'", public');
    DB::unprepared(<<<SQL
        CREATE TABLE "{$schema}".base_database_tables (
            id bigserial PRIMARY KEY,
            table_name varchar(255) NOT NULL,
            module_name varchar(255),
            module_path varchar(255),
            migration_file varchar(255),
            created_at timestamp,
            updated_at timestamp
        )
        SQL);
});

afterEach(function (): void {
    if (! isset($this->privilegedSupportMigrationSchema)) {
        return;
    }

    DB::statement('SET LOCAL search_path TO public');
    DB::statement('DROP SCHEMA "'.$this->privilegedSupportMigrationSchema.'" CASCADE');
});

function privilegedSupportMigrationFunctionExists(string $schema, string $function): bool
{
    return DB::scalar(
        'SELECT to_regprocedure(?) IS NOT NULL',
        [$schema.'.'.$function],
    );
}

test('rebuild can recreate support tables while the PostgreSQL guard function remains', function (): void {
    $migration = require app_path('Domains/PeopleConnector/Connector/Database/Migrations/0330_01_01_000002_create_people_connector_privileged_support_tables.php');
    $schema = $this->privilegedSupportMigrationSchema;

    $migration->up();

    expect(Schema::hasTable('people_connector_connector_privileged_support_grants'))
        ->toBeTrue()
        ->and(Schema::hasTable('people_connector_connector_privileged_support_actions'))
        ->toBeTrue()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_action_immutable()'))
        ->toBeTrue()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_grant_immutable()'))
        ->toBeTrue();

    Schema::drop('people_connector_connector_privileged_support_actions');
    Schema::drop('people_connector_connector_privileged_support_grants');

    expect(Schema::hasTable('people_connector_connector_privileged_support_grants'))
        ->toBeFalse()
        ->and(Schema::hasTable('people_connector_connector_privileged_support_actions'))
        ->toBeFalse()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_action_immutable()'))
        ->toBeTrue()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_grant_immutable()'))
        ->toBeTrue();

    DB::transaction(fn () => $migration->up());

    expect(Schema::hasTable('people_connector_connector_privileged_support_grants'))
        ->toBeTrue()
        ->and(Schema::hasTable('people_connector_connector_privileged_support_actions'))
        ->toBeTrue()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_action_immutable()'))
        ->toBeTrue()
        ->and(privilegedSupportMigrationFunctionExists($schema, 'people_connector_support_grant_immutable()'))
        ->toBeTrue();
});
