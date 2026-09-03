<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A company merge could not move the superseded company's rows to the
 * survivor because the survivor already holds a row the database considers
 * the same — a skill code, a scale version — and merging would have had to
 * pick one silently. The merge is rolled back whole; the rows stay where
 * they were and the collision is named so a person can resolve it first.
 */
final class WorkforceMergeConflictException extends \RuntimeException
{
    /** @param class-string $model */
    public static function for(string $model, int $supersededEntityId, int $survivorEntityId, \Throwable $previous): self
    {
        return new self(
            "Merging company entity {$supersededEntityId} into {$survivorEntityId} collides on {$model}: "
            .'the survivor already holds a row the superseded company also defines. Resolve the duplicate before merging.',
            0,
            $previous,
        );
    }
}
