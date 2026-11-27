<?php

namespace App\Services;

use App\Gameplay\CampaignRegistrationFailure;
use App\Gameplay\FleetMetrics;
use App\Gameplay\GameException;
use App\Gameplay\RequestBudget;
use App\Gameplay\RequestDeferred;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SpaceTradersClient
{
    public const REGISTRATION_UNKNOWN = 'Registration outcome is unknown. Check your official account dashboard and import the agent token if created. Do not repeat registration until its outcome is resolved.';

    public const BASE_URL = 'https://api.spacetraders.io/v2';

    public const TOKEN_INVALID = 'Token is invalid.';

    public const TOKEN_FORBIDDEN = 'Token does not have permission to complete this request.';

    private int $scopeDepth = 0;

    private string $priority = 'discovery';

    private array $reads = [];

    private ?float $scopeDeadline = null;

    private static int $writes = 0;

    private static int $cacheHits = 0;

    public static function writeCount(): int
    {
        return self::$writes;
    }

    public static function cacheHitCount(): int
    {
        return self::$cacheHits;
    }

    public static function resetCounters(): void
    {
        self::$writes = 0;
        self::$cacheHits = 0;
    }

    public function scope(callable $work, string $priority = 'critical', ?float $deadline = null): mixed
    {
        $previous = $this->priority;
        $previousDeadline = $this->scopeDeadline;
        $this->scopeDeadline = $deadline === null ? $previousDeadline : min($deadline, $previousDeadline ?? $deadline);
        $this->priority = $priority;
        $this->scopeDepth++;
        try {
            return $work();
        } finally {
            $this->priority = $previous;
            $this->scopeDeadline = $previousDeadline;
            if (--$this->scopeDepth === 0) {
                $this->reads = [];
            }
        }
    }

    public function request(string $method, string $path, array $data = [], ?string $token = null, ?float $deadline = null, ?string $account = null, ?callable $beforeTransport = null, ?string $agentSymbol = null, bool $fresh = false): Response
    {
        $deadline = $this->scopeDeadline === null ? $deadline : min($this->scopeDeadline, $deadline ?? $this->scopeDeadline);
        $read = strtolower($method) === 'get';
        $key = hash('sha256', json_encode([$path, $data, $token]));
        if ($read && ! $fresh && $this->scopeDepth > 0 && isset($this->reads[$key]) && microtime(true) - $this->reads[$key]['at'] < 2) {
            self::$cacheHits++;

            return $this->reads[$key]['response'];
        }
        if (! $read) {
            self::$writes++;
            // Even rejected or ambiguous writes invalidate every read in this preflight scope.
            $this->reads = [];
        }
        $remaining = ($deadline ?? INF) - microtime(true);
        $priority = $read ? $this->priority : ($this->priority === 'settlement' ? 'settlement' : 'write');
        $budget = app(RequestBudget::class);
        if ($remaining > 0) {
            // A successful preflight can exhaust a one-second window plus our safety margin.
            // Short waits apply to writes too; actual error backoffs still defer before transport.
            $baseMaxWait = 2.0;
            $maxWait = min($baseMaxWait, max(0, $remaining - 0.1));
            RequestBudget::forAgent($agentSymbol, fn () => $budget->reserve($token, $account, $priority, $maxWait, $baseMaxWait, $deadline));
            $remaining = ($deadline ?? INF) - microtime(true);
        }
        $timeout = min(30, $remaining);
        if ($timeout < 0.1) {
            throw (new RequestDeferred('Investigation elapsed-time limit reached before transport.', 5, delaySource: 'transport_deadline', deadlineCapped: true))->withDeadline($deadline);
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
        if ($beforeTransport !== null) {
            // For purchases, the final check sees stops committed before it acquires the database writer.
            // HTTP runs outside that transaction, so a later stop can still commit before send().
            $beforeTransport();
        }
        $category = FleetMetrics::category($method, $path);
        FleetMetrics::count($method, $path, $category, $agentSymbol,
            host: $token === null && $agentSymbol === null && FleetMetrics::currentAgent() === null, ordinary: $budget->usedOrdinaryCapacity($priority));
        $response = $request->send(strtoupper($method), $path, $options);
        $budget = app(RequestBudget::class);
        try {
            $budget->observe($response, $token, $account, $category);
        } catch (\Throwable) {
            $budget->recordingFailed($response);
            if ($response->status() === 429 || $response->serverError()) {
                throw new GameException('Could not save upstream budget. Reconcile before another write.', uncertain: ! $read, retryAfter: 10, httpStatus: $response->status());
            }
            // Budget recording cannot discard a successful write or a known upstream rejection.
        }

        if ($read && $this->scopeDepth > 0 && $response->successful()) {
            $this->reads[$key] = ['response' => $response, 'at' => microtime(true)];
        }

        return $response;
    }

    public function register(array $data, string $accountToken, bool $campaign = false): array
    {
        return $this->agentData('post', '/register', $data, $accountToken, [
            'token' => ['required', 'string'],
            'agent.accountId' => ['sometimes', 'string', 'max:255'],
            'agent.symbol' => ['required', 'string', 'min:3', 'max:14'],
            'agent.startingFaction' => ['required', 'string', 'max:255'],
        ], $campaign);
    }

    public function agent(string $token): array
    {
        return $this->agentData('get', '/my/agent', [], $token, [
            'accountId' => ['sometimes', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'min:3', 'max:14'],
            'startingFaction' => ['required', 'string', 'max:255'],
        ]);
    }

    private function agentData(string $method, string $path, array $data, string $token, array $rules, bool $campaign = false): array
    {
        try {
            $response = $this->request($method, $path, $data, $token);
        } catch (ConnectionException|GameException $exception) {
            if ($campaign) {
                throw new CampaignRegistrationFailure($exception instanceof RequestDeferred || ($exception instanceof GameException && $exception->apiCode === 429) ? 'not_sent' : 'ambiguous');
            }
            throw ValidationException::withMessages([
                'api' => $method === 'post' ? self::REGISTRATION_UNKNOWN : 'Could not connect to SpaceTraders. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            if ($campaign && in_array($response->status(), [408, 409, 429], true) || $campaign && $response->serverError()) {
                throw new CampaignRegistrationFailure(match ($response->status()) {
                    429 => 'not_sent', 409 => 'conflict', default => 'ambiguous',
                });
            }
            if ($method === 'post' && ($response->serverError() || $response->status() < 400 || in_array($response->status(), [408, 429], true))) {
                throw ValidationException::withMessages(['api' => self::REGISTRATION_UNKNOWN]);
            }
            throw ValidationException::withMessages([
                'api' => match ($response->status()) {
                    401 => self::TOKEN_INVALID,
                    403 => self::TOKEN_FORBIDDEN,
                    409 => 'That agent symbol is already registered. Choose another symbol, or Import the agent if it is yours.',
                    422 => $this->validationMessage($response, $token),
                    429 => 'Rate limit reached.',
                    default => 'SpaceTraders could not complete the request. Please try again later.',
                },
            ]);
        }

        $payload = $response->json('data');

        if (! is_array($payload) || Validator::make($payload, $rules)->fails()) {
            if ($campaign) {
                throw new CampaignRegistrationFailure('ambiguous');
            }
            throw ValidationException::withMessages([
                'api' => $method === 'post' ? self::REGISTRATION_UNKNOWN : 'SpaceTraders returned incomplete agent details. No agent was saved.',
            ]);
        }

        return $payload;
    }

    private function validationMessage(Response $response, string $token): string
    {
        $message = $response->json('error.message');
        if (! is_string($message)) {
            return 'SpaceTraders rejected the submitted details. Check the fields and try again.';
        }
        $message = strip_tags(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $message = str_replace($token, '[redacted]', $message);
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $message);
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '');

        return $message === '' ? 'SpaceTraders rejected the submitted details. Check the fields and try again.' : mb_substr($message, 0, 300);
    }
}
