<?php

namespace App\Http\Controllers;

use App\Gameplay\CampaignControl;
use App\Gameplay\CampaignStatus;
use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Models\CampaignGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignStatusController extends Controller
{
    public function show(Request $request, CampaignStatus $status): Response
    {
        $this->authorizeOperator($request);

        return Inertia::render('Campaign/Status', $status->read() + ['status' => $request->session()->get('status')]);
    }

    public function pause(Request $request, CampaignControl $control, HostLock $lock): RedirectResponse
    {
        $this->authorizeOperator($request);
        try {
            $lock->retry('campaign', fn () => $control->pause(CampaignGrant::where('slot', 1)->firstOrFail(), 'operator_pause'));
        } catch (GameException $exception) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()]);
        }

        return to_route('campaign.show')->with('status', 'Campaign paused.');
    }

    public function resume(Request $request, CampaignControl $control): RedirectResponse
    {
        $this->authorizeOperator($request);
        try {
            $control->resume();
        } catch (GameException $exception) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()]);
        }

        return to_route('campaign.show')->with('status', 'Campaign resumed; the next decision uses fresh state.');
    }

    private function authorizeOperator(Request $request): void
    {
        abort_unless((string) $request->user()->id === (string) config('gameplay.operator_id'), 404);
    }
}
