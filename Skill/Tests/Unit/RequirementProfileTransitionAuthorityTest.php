<?php

use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Exceptions\PublishedRequirementImmutableException;
use App\Domains\PeopleConnector\Skill\Models\RequirementProfile;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;
use Tests\TestCase;

uses(TestCase::class);

test('database lifecycle authority fails closed outside a transaction', function (): void {
    $profile = new RequirementProfile;
    $profile->setRawAttributes([
        'id' => 42,
        'tenant_id' => 7,
        'status' => RequirementProfileStatus::Draft->value,
    ], true);

    expect(fn () => app(RequirementProfileTransitionAuthority::class)->authorizeDatabaseWrite(
        $profile,
        RequirementProfileStatus::Draft,
        RequirementProfileStatus::PendingHodReview,
    ))->toThrow(
        PublishedRequirementImmutableException::class,
        'requires an active database transaction',
    );
});
