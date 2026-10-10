<?php

namespace App\Http\Middleware;

use App\Models\BioTimeClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBioTimeClient
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if ($authorization === null || $authorization === '') {
            return response()->json(['detail' => 'Authentication credentials were not provided.'], 401)
                ->header('WWW-Authenticate', 'Token');
        }

        if (! preg_match('/^(?:Token|jwt) ([a-f0-9]{40})$/iD', $authorization, $matches)) {
            return response()->json(['detail' => 'Invalid token.'], 401)->header('WWW-Authenticate', 'Token');
        }

        $client = BioTimeClient::query()
            ->where('token_hash', hash('sha256', $matches[1]))
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();

        if ($client === null) {
            return response()->json(['detail' => 'Invalid token.'], 401)->header('WWW-Authenticate', 'Token');
        }

        $request->attributes->set('biotime_client', $client);

        return $next($request);
    }
}
