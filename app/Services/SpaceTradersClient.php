<?php

namespace App\Services;

use App\Gameplay\GameException;
use App\Gameplay\RequestBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SpaceTradersClient
{
    public const BASE_URL = 'https://api.spacetraders.io/v2';

    public function request(string $method, string $path, array $data = [], ?string $token = null): Response
    {
        app(RequestBudget::class)->reserve();
        $request = Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->withoutRedirecting();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $response = $request->send(strtoupper($method), $path, $data === [] ? [] : [
            strtolower($method) === 'get' ? 'query' : 'json' => $data,
        ]);
        if ($response->status() === 429 || $response->serverError()) {
            $header = $response->header('Retry-After');
            $delay = is_numeric($header) ? (int) $header : (strtotime($header ?: '') ?: time()) - time();
            try {
                app(RequestBudget::class)->defer(max($response->status() === 502 ? 180 : 5, min(3600, $delay)) + random_int(0, 2));
            } catch (GameException) {
                throw new GameException('Could not save upstream backoff. Reconcile before another write.', uncertain: strtolower($method) !== 'get', retryAfter: 10);
            }
        }

        return $response;
    }

    public function register(array $data, string $accountToken): array
    {
        return $this->agentData('post', '/register', $data, $accountToken, [
            'token' => ['required', 'string'],
            'agent.accountId' => ['required', 'string', 'max:255'],
            'agent.symbol' => ['required', 'string', 'min:3', 'max:14'],
            'agent.startingFaction' => ['required', 'string', 'max:255'],
        ]);
    }

    public function agent(string $token): array
    {
        return $this->agentData('get', '/my/agent', [], $token, [
            'accountId' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'min:3', 'max:14'],
            'startingFaction' => ['required', 'string', 'max:255'],
        ]);
    }

    private function agentData(string $method, string $path, array $data, string $token, array $rules): array
    {
        try {
            $response = $this->request($method, $path, $data, $token);
        } catch (ConnectionException|GameException $exception) {
            throw ValidationException::withMessages([
                'api' => 'Could not connect to SpaceTraders. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'api' => match ($response->status()) {
                    401 => 'Token is invalid.',
                    422 => 'Invalid symbol.',
                    429 => 'Rate limit reached.',
                    default => 'SpaceTraders could not complete the request. Please try again later.',
                },
            ]);
        }

        $payload = $response->json('data');

        if (! is_array($payload) || Validator::make($payload, $rules)->fails()) {
            throw ValidationException::withMessages([
                'api' => 'SpaceTraders returned incomplete agent details. No agent was saved.',
            ]);
        }

        return $payload;
    }
}
