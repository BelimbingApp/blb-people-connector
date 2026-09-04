<?php

namespace App\Domains\PeopleConnector\Skill;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements;
use App\Domains\PeopleConnector\Skill\Listeners\SendRequirementProfileTransitionNotification;
use App\Domains\PeopleConnector\Skill\Services\RequirementResolver;
use App\Domains\PeopleConnector\Skill\Services\SkillAudience;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolvesSkillRequirements::class, RequirementResolver::class);
        $this->app->singleton(RequirementProfileTransitionAuthority::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-connector-skill');
        Event::listen(TransitionCompleted::class, SendRequirementProfileTransitionNotification::class);

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register(
                'people-connector.skill.catalog-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people-connector.skill.catalog.view'),
            );
            $registry->register(
                'people-connector.skill.assessment-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people-connector.skill.assessment.view'),
            );
        });
    }
}
