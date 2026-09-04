<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Scoped proof that an assessment mutation is executing through its workflow. */
final class AssessmentWorkflowContext
{
    private static int $depth = 0;

    /** @internal Persistence authority issued by AssessmentStore only. */
    public static function runStoreMutation(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return DB::transaction(function () use ($callback): mixed {
                $connection = DB::connection();
                $previousAuthority = null;

                if ($connection->getDriverName() === 'pgsql') {
                    $previousAuthority = $connection->selectOne(
                        "select current_setting('blb.skill_assessment_workflow', true) as value",
                    )->value ?? '';
                    $connection->statement("select set_config('blb.skill_assessment_workflow', '1', true)");
                } elseif ($connection->getDriverName() === 'sqlite') {
                    $pdo = $connection->getPdo();
                    if (method_exists($pdo, 'sqliteCreateFunction')) {
                        $pdo->sqliteCreateFunction(
                            'pcs_assessment_workflow_authorized',
                            static fn (): int => self::active() ? 1 : 0,
                            0,
                        );
                    }
                }

                try {
                    return $callback();
                } finally {
                    if ($connection->getDriverName() === 'pgsql') {
                        try {
                            $connection->statement(
                                "select set_config('blb.skill_assessment_workflow', ?, true)",
                                [$previousAuthority ?? ''],
                            );
                        } catch (Throwable) {
                            // A failed statement aborts this savepoint; its rollback restores the prior setting.
                        }
                    }
                }
            });
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
