<?php

namespace App\Domains\PeopleConnector\Training\Http\Middleware;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Domains\PeopleConnector\Training\Services\TrainingAudience;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeTrainingAudience
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(TrainingAudience::class)->allowedCompanies($request->user());
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        return $next($request);
    }
}
