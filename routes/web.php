<?php

use App\Http\Controllers\AdviserController;
use App\Http\Controllers\AgentCommandController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDashboardController;
use App\Http\Controllers\AgentReadController;
use App\Http\Controllers\AutomationRunController;
use App\Http\Controllers\ForwarderController;
use App\Http\Controllers\LocalAutomationController;
use App\Http\Controllers\PlayerGuideController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RateLimitController;
use App\Http\Controllers\SystemMapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('agents.index');
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
});

Route::middleware('auth')->group(function () {
    Route::get('/rate-limit', RateLimitController::class)->name('game.rate-limit');
    Route::get('/guide', [PlayerGuideController::class, 'show'])->name('guide');
    Route::get('/agents/{agent}/guide', [PlayerGuideController::class, 'show'])->name('agents.guide');

    // Only whitelisted public reads remain on the legacy forwarder.
    Route::match(['get', 'post'], '/forward', [ForwarderController::class, 'forward'])->name('forward');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/agents/storeExisting', [AgentController::class, 'storeExisting'])->name('agents.storeExisting');
    Route::resource('/agents', AgentController::class)->only(['index', 'create', 'store']);

    Route::get('/agents/{agent}/adviser/history', [AdviserController::class, 'history'])->name('game.adviser.history');
    Route::get('/agents/{agent}/history', [AgentDashboardController::class, 'history'])->name('game.history');
    Route::get('/agents/{agent}/map', SystemMapController::class)->name('agents.map');
    Route::get('/agents/{agent}', [AgentDashboardController::class, 'show'])->name('agents.show');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/agents/{agent}/adviser/policies', [AdviserController::class, 'policies'])->name('game.adviser.policies');
        Route::post('/agents/{agent}/adviser/policies/{policy}/pause', [AdviserController::class, 'pause'])->name('game.adviser.pause');
        Route::post('/agents/{agent}/adviser/assess', [AdviserController::class, 'assess'])->name('game.adviser.assess');
        Route::post('/agents/{agent}/adviser/collect', [AdviserController::class, 'collect'])->name('game.adviser.collect');
        Route::post('/agents/{agent}/adviser/investigations/{investigation}/advance', [AdviserController::class, 'advance'])->name('game.adviser.advance');
        Route::post('/agents/{agent}/adviser/investigations/{investigation}/cancel', [AdviserController::class, 'cancel'])->name('game.adviser.cancel');
        Route::post('/agents/{agent}/economics/investment', [AgentDashboardController::class, 'investment'])->name('game.economics.investment');
        Route::post('/agents/{agent}/accounting/review', [LocalAutomationController::class, 'reviewBalance'])->name('game.accounting.review');
        Route::post('/agents/{agent}/investigations', [LocalAutomationController::class, 'investigate'])->name('game.investigations.create');
        Route::post('/agents/{agent}/investigations/{investigation}/advance', [LocalAutomationController::class, 'advance'])->name('game.investigations.advance');
        Route::post('/agents/{agent}/investigations/{investigation}/cancel', [LocalAutomationController::class, 'cancel'])->name('game.investigations.cancel');
        Route::post('/agents/{agent}/drafts', [LocalAutomationController::class, 'stage'])->name('game.drafts.create');
        Route::post('/agents/{agent}/drafts/{draft}/update', [LocalAutomationController::class, 'stage'])->name('game.drafts.update');
        Route::post('/agents/{agent}/drafts/{draft}/preview', [LocalAutomationController::class, 'preview'])->name('game.drafts.preview');
        Route::post('/agents/{agent}/engine/start', [LocalAutomationController::class, 'engineStart'])->name('game.engine.start');
        Route::post('/agents/{agent}/engine/end', [LocalAutomationController::class, 'engineEnd'])->name('game.engine.end');
        Route::post('/agents/{agent}/engine/force-stop', [LocalAutomationController::class, 'engineForceStop'])->name('game.engine.force-stop');
        Route::post('/agents/{agent}/reads', [AgentReadController::class, 'read'])->name('game.reads');
        Route::post('/agents/{agent}/sync', [AgentDashboardController::class, 'sync'])->name('game.sync');
        Route::post('/agents/{agent}/commands/preview', [AgentCommandController::class, 'preview'])->name('game.commands.preview');
        Route::post('/agents/{agent}/commands/{action}/approve', [AgentCommandController::class, 'approve'])->name('game.commands.approve');
        Route::post('/agents/{agent}/commands/{action}/stop', [AgentCommandController::class, 'stop'])->name('game.commands.stop');
        Route::post('/agents/{agent}/commands/{action}/reconcile', [AgentCommandController::class, 'reconcile'])->name('game.commands.reconcile');
        Route::post('/agents/{agent}/commands/{action}/acknowledge', [AgentCommandController::class, 'acknowledge'])->name('game.commands.acknowledge');
        Route::post('/agents/{agent}/runs/preview', [AutomationRunController::class, 'preview'])->name('game.runs.preview');
        Route::post('/agents/{agent}/runs/{run}/start', [AutomationRunController::class, 'start'])->name('game.runs.start');
        Route::post('/agents/{agent}/runs/{run}/stop', [AutomationRunController::class, 'stop'])->name('game.runs.stop');
    });

});

require __DIR__.'/auth.php';
