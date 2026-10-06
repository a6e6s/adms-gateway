<?php

namespace App\Http\Controllers\BioTime;

use App\Http\Controllers\Controller;
use App\Models\BioTimeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $client = BioTimeClient::query()
            ->where('username', $credentials['username'])
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();

        if ($client === null || ! Hash::check($credentials['password'], $client->password)) {
            return response()->json(['non_field_errors' => ['Unable to log in with provided credentials.']], 400);
        }

        return response()->json(['token' => $client->token])->header('Cache-Control', 'no-store');
    }
}
