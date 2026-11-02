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
    public const REGISTRATION_UNKNOWN = 'Registration outcome is unknown. Check your official account dashboard and import the agent token if created. Do not repeat registration until its outcome is resolved.';

    public const BASE_URL = 'https://api.spacetraders.io/v2';

    private int $scopeDepth = 0;

    private string $priority = 'discovery';

    private array $reads = [];

    public function scope(callable $work, string $priority = 'critical'): mixed
    {
        $previous = $this->priority;
        $this->priority = $priority;
        $this->scopeDepth++;
        try {
            return $work();
        } finally {
            $this->priority = $previous;
            if (--$this->scopeDepth === 0) {
                $this->reads = [];
            }
        }
    }

    public function request(string $method, string $path, array $data = [], ?string $token = null, ?float $deadline = null, ?string $account = null): Response
    {
        $read = strtolower($method) === 'get';
        $key = hash('sha256', json_encode([$path, $data, $token]));
        if ($read && $this->scopeDepth > 0 && isset($this->reads[$key]) && microtime(true) - $this->reads[$key]['at'] < 2) {
            return $this->reads[$key]['response'];
        }
        if (! $read) {
            // Even rejected or ambiguous writes invalidate every read in this preflight scope.
            $this->reads = [];
        }
        // A successful preflight can exhaust a one-second window plus our safety margin.
        // Short waits apply to writes too; actual error backoffs still defer before transport.
        $maxWait = 2.0;
        if ($deadline !== null) {
            $maxWait = min($maxWait, max(0, $deadline - microtime(true) - 0.1));
        }
        app(RequestBudget::class)->reserve($token, $account, $read ? $this->priority : 'write', $maxWait);
        $timeout = $deadline === null ? 30 : min(30, $deadline - microtime(true));
        if ($timeout < 0.1) {
            throw new GameException('Investigation elapsed-time limit reached before transport.');
        }
        $request = Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->connectTimeout(min(10, $timeout))
            ->timeout($timeout)
            ->withoutRedirecting();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $options = $data === [] ? [] : [$read ? 'query' : 'json' => $data];
        if ($read && isset($data['traits']) && is_array($data['traits'])) {
            // OpenAPI form/explode query arrays repeat the name; PHP bracket encoding is a different contract.
            $pairs = [];
            foreach ($data as $name => $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $pairs[] = rawurlencode($name).'='.rawurlencode((string) $item);
                }
            }
            $options['query'] = implode('&', $pairs);
        }
        $response = $request->send(strtoupper($method), $path, $options);
        $budget = app(RequestBudget::class);
        try {
            $budget->observe($response, $token, $account);
        } catch (\Throwable) {
            $budget->recordingFailed($response);
            if ($response->status() === 429 || $response->serverError()) {
                throw new GameException('Could not save upstream budget. Reconcile before another write.', uncertain: ! $read, retryAfter: 10);
            }
            // Budget recording cannot discard a successful write or a known upstream rejection.
        }

        if ($read && $this->scopeDepth > 0 && $response->successful()) {
            $this->reads[$key] = ['response' => $response, 'at' => microtime(true)];
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
                'api' => $method === 'post' ? self::REGISTRATION_UNKNOWN : 'Could not connect to SpaceTraders. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            if ($method === 'post' && ($response->serverError() || $response->status() === 429)) {
                throw ValidationException::withMessages(['api' => self::REGISTRATION_UNKNOWN]);
            }
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
                'api' => $method === 'post' ? self::REGISTRATION_UNKNOWN : 'SpaceTraders returned incomplete agent details. No agent was saved.',
            ]);
        }

        return $payload;
    }
}
