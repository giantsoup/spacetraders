<?php

use App\Http\Controllers\AgentCommandController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDashboardController;
use App\Http\Controllers\AutomationRunController;
use App\Http\Controllers\ForwarderController;
use App\Http\Controllers\ProfileController;
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
        'canRegister' => Route::has('register'),
    ]);
});

Route::middleware('auth')->group(function () {
    // Only whitelisted public reads remain on the legacy forwarder.
    Route::match(['get', 'post'], '/forward', [ForwarderController::class, 'forward'])->name('forward');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/agents/storeExisting', [AgentController::class, 'storeExisting'])->name('agents.storeExisting');
    Route::resource('/agents', AgentController::class)->only(['index', 'create', 'store']);

    Route::get('/agents/{agent}', [AgentDashboardController::class, 'show'])->name('agents.show');
    Route::post('/agents/{agent}/sync', [AgentDashboardController::class, 'sync'])->name('game.sync');
    Route::post('/agents/{agent}/commands/preview', [AgentCommandController::class, 'preview'])->name('game.commands.preview');
    Route::post('/agents/{agent}/commands/{action}/approve', [AgentCommandController::class, 'approve'])->name('game.commands.approve');
    Route::post('/agents/{agent}/commands/{action}/reconcile', [AgentCommandController::class, 'reconcile'])->name('game.commands.reconcile');
    Route::post('/agents/{agent}/commands/{action}/acknowledge', [AgentCommandController::class, 'acknowledge'])->name('game.commands.acknowledge');
    Route::post('/agents/{agent}/runs/preview', [AutomationRunController::class, 'preview'])->name('game.runs.preview');
    Route::post('/agents/{agent}/runs/{run}/start', [AutomationRunController::class, 'start'])->name('game.runs.start');
    Route::post('/agents/{agent}/runs/{run}/stop', [AutomationRunController::class, 'stop'])->name('game.runs.stop');

});

require __DIR__.'/auth.php';
