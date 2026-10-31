<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SpaceTradersClient
{
    public function request(string $method, string $path, array $data = [], ?string $token = null): Response
    {
        $request = Http::baseUrl('https://api.spacetraders.io/v2')
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->withoutRedirecting();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request->send(strtoupper($method), $path, $data === [] ? [] : [
            strtolower($method) === 'get' ? 'query' : 'json' => $data,
        ]);
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
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages([
                'api' => 'Could not connect to SpaceTraders. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            $message = $response->json('error.message');

            throw ValidationException::withMessages([
                'api' => is_string($message) && $message !== ''
                    ? $message
                    : 'SpaceTraders could not complete the request. Please try again later.',
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
