<?php

namespace App\Http\Controllers;

use App\Gameplay\Bridge\BridgeActions;
use App\Gameplay\Bridge\BridgeAttention;
use App\Gameplay\Bridge\BridgeCredits;
use App\Gameplay\Bridge\BridgeFleet;
use App\Gameplay\Bridge\BridgeGuards;
use App\Gameplay\Bridge\BridgeLog;
use App\Gameplay\Bridge\BridgePlan;
use App\Gameplay\Bridge\BridgeRoutes;
use App\Gameplay\Bridge\BridgeScope;
use App\Gameplay\GameException;
use App\Http\Controllers\Concerns\SelectsCampaignAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CampaignBridgeController extends Controller
{
    use SelectsCampaignAgent;

    public function brief(Request $request): Response
    {
        return $this->render($request, 'Brief', 'brief', fn (BridgeScope $scope) => [
            'routes' => fn () => BridgeRoutes::read($scope),
            'fleet' => fn () => BridgeFleet::read($scope),
            'log' => fn () => BridgeLog::read($scope, limit: 3),
            'chart' => fn () => BridgeCredits::chart($scope),
        ]);
    }

    public function routes(Request $request, ?string $good = null, ?string $source = null, ?string $buyer = null): Response
    {
        return $this->render($request, 'Routes', 'routes', function (BridgeScope $scope) use ($good, $source, $buyer) {
            $selected = $good !== null && $scope->agent !== null ? BridgeRoutes::detail($scope, $good, $source, $buyer) : null;
            abort_if($good !== null && $scope->agent !== null && $selected === null, 404);

            return ['routes' => fn () => BridgeRoutes::read($scope), 'selectedRoute' => $selected];
        });
    }

    public function ended(Request $request): Response
    {
        return $this->render($request, 'EndedRoutes', 'routes', fn (BridgeScope $scope) => [
            'endedRoutes' => fn () => BridgeRoutes::ended($scope),
        ]);
    }

    public function fleet(Request $request, ?string $ship = null): Response
    {
        return $this->render($request, 'Fleet', 'fleet', function (BridgeScope $scope) use ($ship) {
            $selected = $ship !== null && $scope->agent !== null ? BridgeFleet::detail($scope, $ship) : null;
            abort_if($ship !== null && $scope->agent !== null && $selected === null, 404);

            return [
                'fleet' => fn () => BridgeFleet::read($scope),
                'selectedShip' => $selected,
            ];
        });
    }

    public function probes(Request $request): Response
    {
        return $this->render($request, 'Probes', 'fleet', fn (BridgeScope $scope) => [
            'probes' => fn () => BridgeFleet::probes($scope),
        ]);
    }

    public function log(Request $request): Response
    {
        return $this->render($request, 'Log', 'log', function (BridgeScope $scope) use ($request) {
            $params = $request->validate([
                'filter' => ['nullable', Rule::in(['all', 'problems', 'routes', 'plan'])],
                'before' => ['nullable', 'integer', 'min:1'],
                'route' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Z0-9_]+\|[A-Z0-9-]+\|[A-Z0-9-]+\z/'],
            ]);

            return ['log' => fn () => BridgeLog::read(
                $scope,
                isset($params['route']) ? 'routes' : ($params['filter'] ?? 'all'),
                isset($params['before']) ? (int) $params['before'] : null,
                $params['route'] ?? null,
            )];
        });
    }

    public function resumeArm(Request $request, int $grant): RedirectResponse
    {
        return $this->act($request, 'arm_resume', $grant);
    }

    public function retireArm(Request $request, int $grant): RedirectResponse
    {
        return $this->act($request, 'arm_retire', $grant);
    }

    public function pauseFleet(Request $request): RedirectResponse
    {
        return $this->act($request, 'fleet_pause');
    }

    public function resumeFleet(Request $request): RedirectResponse
    {
        return $this->act($request, 'fleet_resume');
    }

    private function render(Request $request, string $component, string $tab, callable $page): Response
    {
        $this->authorizeOperator($request);
        $symbol = $this->requestedCampaignSymbol($request);
        if ($symbol !== null && $request->header('X-Inertia') && $request->header('X-Inertia-Partial-Data') === 'reviews'
            && $request->header('X-Inertia-Partial-Component') === 'Campaign/Bridge/'.$component && $request->has('review')) {
            return Inertia::render('Campaign/Bridge/'.$component, [
                'reviews' => fn () => $this->reviews($request, $symbol),
            ]);
        }

        $scope = BridgeScope::resolve($request->user()->id, $symbol);
        $credits = BridgeCredits::read($scope);
        $plan = BridgePlan::read($scope);
        $attention = BridgeAttention::read($scope, $plan);

        if ($scope->symbol === null) {
            $component = 'Brief';
            $tab = 'brief';
            $props = [
                'routes' => ['running' => [], 'stopped' => [], 'settling' => [], 'mining' => [], 'ended' => ['count' => 0, 'net' => 0], 'rate' => null],
                'fleet' => ['count' => 0, 'groups' => [], 'probes' => ['count' => 0, 'groups' => []]],
                'log' => ['rows' => [], 'next_before' => null, 'filter' => 'all', 'route' => null],
                'chart' => [],
                'reviews' => ['fleet_pause' => null, 'fleet_resume' => null, 'primary_resume' => null, 'arms' => []],
            ];
        } else {
            $props = $page($scope);
        }

        $episode = $scope->openEpisodes->sortBy('next_reset_at')->first();
        $bridge = [
            'agent' => $scope->symbol === null ? null : [
                'symbol' => $scope->symbol,
                'reset_id' => $scope->resetId,
                'connection_state' => $scope->agent?->connection_state,
            ],
            'campaignAgents' => $attention['agents'],
            'creditsStrip' => $credits,
            'needsYouCount' => count($attention['cards']),
            'engine' => $attention['engine'],
            'updatedAt' => now()->toIso8601String(),
            'dataAsOf' => $credits['data_as_of'],
            'latestSampleId' => $credits['latest_sample_id'],
            'status' => $request->session()->get('status'),
            'tab' => $tab,
            'nextResetAt' => $episode?->next_reset_at?->toIso8601String(),
            'resetUnconfirmed' => $scope->agent !== null && ! BridgeGuards::resetConfirmed($scope->agent, $episode),
            'flightDeckHref' => $scope->agent === null ? null : route('agents.show', $scope->agent),
            'ship_count' => $scope->ships->count(),
            'phase' => $plan['phase'],
        ];
        $props['attention'] = ['cards' => $attention['cards'], 'watching' => $attention['watching']];
        if ($component === 'Brief') {
            $props['plan'] = $plan;
        }

        return Inertia::render('Campaign/Bridge/'.$component, ['bridge' => $bridge] + $props);
    }

    private function reviews(Request $request, string $symbol): array
    {
        $armReview = in_array($request->query('review'), ['arm_resume', 'arm_retire'], true);
        $params = $request->validate([
            'review' => ['required', Rule::in(['fleet_pause', 'fleet_resume', 'primary_resume', 'arm_resume', 'arm_retire'])],
            'review_grant' => $armReview ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ]);

        return app(BridgeActions::class)->reviews(
            $request->user()->id, $symbol, $params['review'], isset($params['review_grant']) ? (int) $params['review_grant'] : null,
        );
    }

    private function act(Request $request, string $action, ?int $grant = null): RedirectResponse
    {
        $this->authorizeOperator($request);
        $validator = Validator::make($request->all(), [
            'agent' => ['required', 'string', 'max:32'],
            'reset_id' => ['present', 'nullable', 'string', 'max:64'],
            'expected' => ['present', 'array'],
            'budget_hold_id' => ['nullable', 'string', 'max:64'],
            'expected.*' => ['array'],
            'intent' => $action === 'fleet_resume'
                ? ['required', Rule::in(['primary', 'fleet'])]
                : ['nullable', Rule::in([$grant === null ? 'fleet' : 'arm'])],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(['bridge' => $validator->errors()->first()])
                ->redirectTo($this->returnUrl($request));
        }

        $params = $validator->validated();
        $symbol = strtoupper($params['agent']);
        $return = $this->returnUrl($request, $symbol);

        try {
            $message = app(BridgeActions::class)->execute(
                $request->user()->id, $symbol, $action, $grant, $params['reset_id'], $params['expected'], $params['intent'] ?? null, $params['budget_hold_id'] ?? null,
            );
        } catch (GameException $exception) {
            throw ValidationException::withMessages(['bridge' => $exception->getMessage()])->redirectTo($return);
        }

        return redirect()->to($return)->with('status', $message);
    }

    private function returnUrl(Request $request, ?string $symbol = null): string
    {
        $referer = $request->headers->get('referer', '');
        $origin = $request->getSchemeAndHttpHost();
        $fallback = route('campaign.bridge.brief', ['agent' => $symbol]);

        if (! str_starts_with($referer, $origin.'/campaign/bridge/')) {
            return $fallback;
        }

        try {
            $previous = Request::create($referer);
            $route = Route::getRoutes()->match($previous);
        } catch (HttpExceptionInterface) {
            return $fallback;
        }

        if (! in_array($route->getName(), [
            'campaign.bridge.routes', 'campaign.bridge.routes.ended', 'campaign.bridge.route',
            'campaign.bridge.fleet', 'campaign.bridge.probes', 'campaign.bridge.ship', 'campaign.bridge.log',
        ], true)) {
            return $fallback;
        }

        return route($route->getName(), $route->parameters() + ['agent' => $symbol] + $previous->only('filter', 'route', 'before', 'from'));
    }
}
