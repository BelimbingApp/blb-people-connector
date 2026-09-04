<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use Closure;

/** Scoped proof that an assessment mutation is executing through its workflow. */
final class AssessmentWorkflowContext
{
    private static int $depth = 0;

    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
