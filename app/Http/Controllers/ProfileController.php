<?php

namespace App\Http\Controllers;

use App\Gameplay\GameException;
use App\Gameplay\HostLock;
use App\Gameplay\ShipCommandExecutor;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AutomationRun;
use App\Models\ShipAction;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            app(HostLock::class)->run('imports', function () use ($user) {
                $agents = $user->agents()->orderBy('id')->get();
                $delete = function () use ($user, $agents) {
                    $ids = $agents->modelKeys();
                    if (ShipAction::whereIn('agent_id', $ids)->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists() || AutomationRun::whereIn('agent_id', $ids)->whereNotNull('active_agent_id')->exists()) {
                        throw new GameException('Stop and reconcile gameplay before deleting this account.');
                    }
                    Auth::logout();
                    $user->delete();
                };
                foreach ($agents->reverse() as $agent) {
                    $next = $delete;
                    $delete = fn () => app(ShipCommandExecutor::class)->locked($agent, $next);
                }
                $delete();
            });
        } catch (GameException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
