<?php

namespace App\Models;

use App\Enums\ActionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReconMission extends Model
{
    protected $guarded = [];

    protected $casts = ['terms' => 'array', 'hold' => 'array', 'graph' => 'array', 'visits' => 'array', 'capability' => 'array',
        'return_reservation' => 'array', 'homeward_authorizations' => 'array', 'approved_at' => 'immutable_datetime',
        'start_window_until' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $mission) {
            if ($mission->isDirty(['terms', 'terms_hash', 'agent_id', 'reset_id', 'ship_symbol', 'origin_system', 'origin_gate', 'approved_by', 'approved_at'])) {
                throw new LogicException('Recon terms are immutable. Cancel and approve a new mission.');
            }
            $old = $mission->getOriginal('homeward_authorizations') ?? [];
            if (array_slice($mission->homeward_authorizations ?? [], 0, count($old)) !== $old) {
                throw new LogicException('Homeward authorizations are append-only.');
            }
        });
        static::deleting(fn () => throw new LogicException('Recon missions are retained for audit.'));
    }

    public static function hash(array $data): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => is_array($item) ? $canonical($item) : $item, $value);
        };

        return hash('sha256', json_encode($canonical($data), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function actions(): Builder
    {
        return ShipAction::where('agent_id', $this->agent_id)->where('reset_id', $this->reset_id)
            ->whereIn('automation_run_id', $this->runs()->select('id'));
    }

    public function runs(): Builder
    {
        return AutomationRun::where('agent_id', $this->agent_id)->where('reset_id', $this->reset_id)->where('strategy', 'gate_recon')->where('plan->mission_id', $this->id);
    }

    public function path(string $from, string $to): ?array
    {
        $queue = [[$from]];
        $seen = [$from => true];
        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            $path = $queue[$cursor];
            $last = end($path);
            if ($last === $to) {
                return $path;
            }
            foreach ($this->graph[$last]['connections'] ?? [] as $gate) {
                $system = implode('-', array_slice(explode('-', $gate), 0, 2));
                if (! isset($seen[$system]) && $this->edge($last, $system)) {
                    $seen[$system] = true;
                    $queue[] = [...$path, $system];
                }
            }
        }

        return null;
    }

    public function edge(string $from, string $to): bool
    {
        $a = $this->graph[$from] ?? [];
        $b = $this->graph[$to] ?? [];

        return ($a['complete'] ?? false) && ($b['complete'] ?? false)
            && in_array($b['gate'], $a['connections'] ?? [], true) && in_array($a['gate'], $b['connections'] ?? [], true);
    }

    public function ledger(?int $exceptIntent = null): array
    {
        $spent = $unknown = 0;
        foreach ($this->actions()->where('command', 'jump')->get() as $action) {
            if ($action->state === ActionState::Confirmed && isset($action->outcome['transaction_total'])) {
                $spent += max(0, (int) $action->outcome['transaction_total']);
            } elseif ($action->id !== $exceptIntent && (in_array($action->state, [ActionState::Pending, ActionState::Sending, ActionState::Uncertain], true)
                || ($action->state === ActionState::Acknowledged && ! isset($action->outcome['transaction_total'])))) {
                $unknown += (int) ($action->arguments['maxTotal'] ?? $this->terms['jump_exposure']);
            } elseif ($action->state === ActionState::Acknowledged && isset($action->outcome['transaction_total'])) {
                $spent += max(0, (int) $action->outcome['transaction_total']);
            }
        }

        return ['spent' => $spent, 'unknown' => $unknown, 'remaining' => max(0, $this->terms['envelope'] - $spent - $unknown)];
    }

    public function authorization(): array
    {
        $records = $this->homeward_authorizations ?? [];
        $record = $records ? end($records) : ($this->return_reservation ?? []);
        if (! $records) {
            $confirmed = $this->actions()->where('command', 'jump')->where('state', 'confirmed')->whereNotNull('outcome->recon_return_reservation')->latest('id')->first();
            if ($confirmed && $confirmed->id > ($record['cursor'] ?? 0)) {
                $record = $confirmed->outcome['recon_return_reservation'];
            }
        }
        $consumed = 0;
        $debits = 0;
        foreach ($this->actions()->where('command', 'jump')->where('id', '>', $record['cursor'] ?? PHP_INT_MAX)->orderBy('id')->get() as $action) {
            if (! empty($action->arguments['recon_homeward']) && in_array($action->state, [ActionState::Confirmed, ActionState::Acknowledged], true) && isset($action->outcome['transaction_total'])) {
                $consumed++;
                $debits += (int) $action->outcome['transaction_total'];
            }
        }
        $caps = array_slice($record['caps'] ?? [], $consumed);

        return $record + ['remaining_caps' => $caps, 'remaining_path' => array_slice($record['path'] ?? [], $consumed),
            'exposure' => array_sum($caps), 'remaining_total' => max(0, ($record['total'] ?? 0) - $debits)];
    }

    public function exposure(): int
    {
        if ($this->closed_at) {
            return 0;
        }
        $unsettled = $this->actions()->where('command', 'jump')->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere(fn ($q) => $q->where('state', 'acknowledged')->whereNull('outcome->transaction_total')))->get();
        $ship = Ship::where('agent_id', $this->agent_id)->where('symbol', $this->ship_symbol)->first();
        if ($ship && $ship->snapshot['nav']['systemSymbol'] === $this->origin_system && $unsettled->isEmpty()) {
            return 0;
        }
        $exposure = $this->authorization()['exposure'];
        foreach ($unsettled as $action) {
            if (empty($action->arguments['recon_homeward'])) {
                $exposure = max($exposure, (int) ($action->arguments['recon_inflight_exposure'] ?? 0));
            }
        }

        return $exposure;
    }

    public static function returnExposure(Agent $agent): int
    {
        return (int) static::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->whereNull('closed_at')->get()->sum(fn ($mission) => $mission->exposure());
    }

    public static function holds(Agent $agent, string $symbol): bool
    {
        $mission = static::where('agent_id', $agent->id)->where('reset_id', $agent->reset_id)->where('ship_symbol', $symbol)->whereNull('closed_at')->first();
        if (! $mission) {
            return false;
        }
        $ship = Ship::where('agent_id', $agent->id)->where('symbol', $symbol)->first();

        return ! $ship || $ship->snapshot['nav']['systemSymbol'] !== $mission->origin_system || $mission->state === 'review'
            || $mission->start_window_until->isFuture() || $mission->exposure() > 0
            || $mission->runs()->whereNotNull('active_agent_id')->exists()
            || $mission->actions()->where(fn ($q) => $q->whereIn('state', ['pending', 'sending', 'uncertain'])->orWhere('review_required', true))->exists();
    }
}
