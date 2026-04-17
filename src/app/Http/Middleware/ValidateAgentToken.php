<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.agent.token');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'AGENT_API_TOKEN no configurat al servidor',
            ], 500);
        }

        $bearerToken = $request->bearerToken();

        if (!$bearerToken || !hash_equals($token, $bearerToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Token invàlid o absent',
            ], 401);
        }

        return $next($request);
    }
}
