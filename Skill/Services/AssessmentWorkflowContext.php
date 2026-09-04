<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use Closure;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidAssessmentException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Scoped proof that an assessment mutation is executing through its workflow. */
final class AssessmentWorkflowContext
{
    private static int $depth = 0;

    /** @internal Persistence authority issued by AssessmentStore only. */
    public static function runStoreMutation(Closure $callback): mixed
    {
        self::assertStoreCaller();
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

    private static function assertStoreCaller(): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        $callerClass = $caller['class'] ?? null;
        $callerFile = str_replace('\\', '/', (string) ($caller['file'] ?? ''));

        // The production authority is private to AssessmentStore. Feature
        // fixtures retain access only so they can exercise database guards
        // with deliberately hostile writes; application code cannot activate
        // this context around arbitrary query-builder mutations.
        if ($callerClass === AssessmentStore::class
            || str_contains($callerFile, '/Skill/Tests/')) {
            return;
        }

        throw new InvalidAssessmentException(
            'Assessment workflow authority is private to AssessmentStore.',
        );
    }
}
