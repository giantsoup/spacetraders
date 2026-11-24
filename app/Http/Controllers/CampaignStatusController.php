<?php

namespace App\Http\Controllers;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignStatus;
use App\Gameplay\CampaignStrategy;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Http\Controllers\Concerns\SelectsCampaignAgent;
use App\Models\CampaignGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignStatusController extends Controller
{
    use SelectsCampaignAgent;

    public function show(Request $request, CampaignStatus $status): Response
    {
        $this->authorizeOperator($request);

        $selection = $this->selection($request);

        return Inertia::render('Campaign/Status', $status->read($request->user()->id, $selection['selectedAgent']) + $selection + ['status' => $request->session()->get('status')]);
    }

    public function strategy(Request $request, CampaignStrategy $strategy): Response
    {
        $this->authorizeOperator($request);

        $selection = $this->selection($request);

        return Inertia::render('Campaign/Strategy', $strategy->read($request->user()->id, $selection['selectedAgent']) + $selection + [
            'fromBridge' => $request->query('from') === 'bridge',
        ]);
    }

    public function pause(Request $request, CampaignControl $control, HostLock $lock): RedirectResponse
    {
        $this->authorizeOperator($request);
        $agent = $request->validate(['agent' => 'nullable|string|max:32'])['agent'] ?? null;
        try {
            $lock->retry('campaign', fn () => $control->pause($control->resolvePrimary($request->user()->id, $agent), 'operator_pause'));
        } catch (GameException $exception) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()]);
        }

        return to_route('campaign.show', array_filter(['agent' => $agent]))->with('status', 'Campaign paused.');
    }

    public function resume(Request $request, CampaignControl $control): RedirectResponse
    {
        $this->authorizeOperator($request);
        $agent = $request->validate(['agent' => 'nullable|string|max:32'])['agent'] ?? null;
        try {
            $control->resume(userId: $request->user()->id, agentSymbol: $agent);
        } catch (GameException $exception) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()]);
        }

        return to_route('campaign.show', array_filter(['agent' => $agent]))->with('status', 'Campaign resumed; the next decision uses fresh state.');
    }

    private function selection(Request $request): array
    {
        $symbols = CampaignGrant::allPrimaries($request->user()->id)->orderBy('id')->pluck('agent_symbol')->all();
        $selected = $this->requestedCampaignSymbol($request);
        abort_if($selected && ! in_array($selected, $symbols, true), 404);

        return ['campaignAgents' => $symbols, 'selectedAgent' => $selected ?? (count($symbols) > 1 ? $symbols[0] : null)];
    }
}
