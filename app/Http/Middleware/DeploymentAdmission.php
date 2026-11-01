<?php

namespace App\Http\Middleware;

use App\Gameplay\DeploymentGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeploymentAdmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $gate = app(DeploymentGate::class);
        // Reads can also archive agents and write sessions. Gate the entire dynamic request.
        $id = $gate->begin('web');
        if ($id === null) {
            return ($request->expectsJson()
                ? response()->json(['message' => 'Deployment in progress. Queued commands are saved. Retry shortly.'], 503)
                : response()->view('deployment', [], 503))->header('Retry-After', '5');
        }
        $request->attributes->set('_deployment_admission', $id);

        return $next($request);
    }
}
