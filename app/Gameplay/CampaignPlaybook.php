<?php

namespace App\Gameplay;

use App\Models\CampaignRevision;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CampaignPlaybook
{
    public function import(string $source): CampaignRevision
    {
        if (strlen($source) > 65536) {
            throw new GameException('Playbook exceeds 64 KiB.');
        }
        try {
            $body = Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new GameException('Invalid campaign YAML: '.$exception->getMessage());
        }
        if (! is_array($body)) {
            throw new GameException('Campaign YAML must be a mapping.');
        }
        $this->validate($body);

        return CampaignRevision::firstOrCreate(['content_hash' => hash('sha256', $source)], ['name' => $body['name'], 'source' => $source, 'body' => $body]);
    }

    public function validate(array $body): void
    {
        $this->keys($body, ['name', 'version', 'agent', 'limits', 'trade', 'probes', 'phases', 'predictions']);
        $this->keys($body['agent'] ?? null, ['symbol', 'faction']);
        $this->keys($body['limits'] ?? null, ['reserve_credits', 'fuel_reserve', 'run_max_minutes']);
        $this->keys($body['trade'] ?? null, ['proof', 'lot', 'losses', 'switch', 'quote_max_age_seconds', 'local_earn']);
        $this->keys($body['trade']['proof'] ?? null, ['max_units_batches', 'max_credits', 'min_realized_ratio']);
        $this->keys($body['trade']['lot'] ?? null, ['max_buyer_batches', 'first_scaled_max_buyer_batches', 'default_batch_decay']);
        $this->keys($body['trade']['losses'] ?? null, ['losing_load', 'pause_after_consecutive_losing_loads']);
        $this->keys($body['trade']['switch'] ?? null, ['bid_below_first_seen', 'better_pair_ratio']);
        if (array_key_exists('probes', $body)) {
            $probes = $body['probes'];
            $this->keys($probes, ['target', 'max_price', 'min_working_capital', 'pool']);
            foreach (['target' => [1, 10], 'max_price' => [1, 1000000], 'min_working_capital' => [0, 10000000]] as $key => [$minimum, $maximum]) {
                if (! is_int($probes[$key] ?? null) || $probes[$key] < $minimum || $probes[$key] > $maximum) {
                    throw new GameException('Invalid probes policy: '.$key);
                }
            }
            if (array_key_exists('pool', $probes) && ! is_bool($probes['pool'])) {
                throw new GameException('Invalid probes policy: pool');
            }
        }
        if (array_key_exists('local_earn', $body['trade'])) {
            $policy = $body['trade']['local_earn'];
            $this->keys($policy, ['projection_limit', 'max_attempts', 'position_evidence_max_age_seconds', 'max_positionings_without_trade']);
            foreach (['projection_limit' => [1, 24], 'max_attempts' => [1, 5],
                'position_evidence_max_age_seconds' => [$body['trade']['quote_max_age_seconds'] ?? 180, 900],
                'max_positionings_without_trade' => [0, 3]] as $key => [$minimum, $maximum]) {
                if (! is_int($policy[$key] ?? null) || $policy[$key] < $minimum || $policy[$key] > $maximum) {
                    throw new GameException('Invalid local Earn policy: '.$key);
                }
            }
            if ($policy['max_attempts'] > $policy['projection_limit']) {
                throw new GameException('Local Earn attempts cannot exceed the projection limit.');
            }
        }
        $validator = Validator::make($body, [
            'name' => ['required', 'string', 'regex:/\A[a-z][a-z0-9-]{0,79}\z/'],
            'agent.symbol' => ['required', 'string', 'regex:/\A[A-Z0-9_]{3,14}\z/'],
            'agent.faction' => ['required', 'string', Rule::in(config('foundation.enums.FactionSymbol'))],
            'phases' => ['required', 'array', 'min:1', 'max:20'],
            'phases.*.id' => ['required', 'string', 'distinct', 'regex:/\A[a-z][a-z0-9_]{0,49}\z/'],
            'phases.*.activities' => ['required', 'array', 'min:1', 'max:10'],
            'phases.*.activities.*.role' => ['required', 'in:command,probe'],
            'phases.*.activities.*.strategy' => ['required', 'in:scout,trade,mining,sell_aboard'],
            'trade.proof.max_units_batches' => ['required', 'integer', 'min:1', 'max:100'],
            'trade.proof.max_credits' => ['required', 'integer', 'min:1'],
            'trade.proof.min_realized_ratio' => ['required', 'numeric', 'gt:0', 'max:1'],
            'trade.lot.max_buyer_batches' => ['required', 'integer', 'min:1', 'max:100'],
            'trade.lot.first_scaled_max_buyer_batches' => ['required', 'integer', 'min:1', 'lte:trade.lot.max_buyer_batches'],
            'trade.losses.losing_load' => ['required', 'in:reprove'],
            'trade.losses.pause_after_consecutive_losing_loads' => ['required', 'integer', 'min:1', 'max:100'],
            'limits.fuel_reserve' => ['required', 'in:next_leg'],
            'trade.lot.default_batch_decay' => ['required', 'numeric', 'min:0', 'lt:1'],
            'trade.switch.bid_below_first_seen' => ['required', 'numeric', 'gt:0', 'max:1'],
            'trade.switch.better_pair_ratio' => ['required', 'numeric', 'gt:1'],
            'trade.quote_max_age_seconds' => ['required', 'integer', 'min:1', 'max:180'],
            'predictions' => ['present', 'array', 'max:50'],
            'predictions.*.metric' => ['required', 'in:credits,proof_realized_ratio,minutes_to_first_profit,freshness_holds'],
            'predictions.*.gte' => ['sometimes', 'numeric', 'min:0'],
            'predictions.*.lte' => ['sometimes', 'numeric', 'min:0'],
            'predictions.*.at_hours' => ['sometimes', 'numeric', 'min:0', 'max:168'],
        ]);
        if ($validator->fails() || ($body['version'] ?? null) !== 1 || ! array_is_list($body['phases'] ?? []) || ! array_is_list($body['predictions'] ?? [])) {
            throw new GameException('Invalid v1 playbook: '.$validator->errors()->toJson());
        }
        foreach (['reserve_credits' => [0, 0], 'run_max_minutes' => [1, 1440]] as $key => [$minimum, $maximum]) {
            $value = $body['limits'][$key] ?? null;
            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                throw new GameException('Invalid campaign limit: '.$key);
            }
        }
        foreach ($body['phases'] as $phase) {
            $this->keys($phase, ['id', 'exit', 'activities']);
            if (! array_is_list($phase['activities'])) {
                throw new GameException('Phase activities must be a list.');
            }
            if (isset($phase['exit'])) {
                $this->validateGate($phase['exit']);
            }
            foreach ($phase['activities'] as $activity) {
                $this->keys($activity, ['role', 'strategy', 'when']);
                if (isset($activity['when'])) {
                    $this->validateGate($activity['when']);
                }
            }
        }
        foreach ($body['predictions'] as $prediction) {
            $this->keys($prediction, ['metric', 'at_hours', 'gte', 'lte']);
            if (isset($prediction['gte']) === isset($prediction['lte']) || ($prediction['metric'] === 'credits' && ! isset($prediction['at_hours']))) {
                throw new GameException('Predictions require exactly one threshold and timed credit predictions require at_hours.');
            }
        }
    }

    private function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys)) {
            throw new GameException('Unknown keys or invalid mapping. Allowed: '.implode(', ', $keys));
        }
    }

    /** @return list<string> The metric keys a gate reads, including those inside any. */
    public static function gateMetrics(array $gate): array
    {
        return isset($gate['any']) ? array_merge(...array_map(fn ($child) => self::gateMetrics($child), $gate['any'])) : array_keys($gate);
    }

    private function validateGate(mixed $gate, bool $allowAny = true): void
    {
        $metrics = ['minutes_elapsed_gte', 'hours_to_reset_lte', 'credits_gte', 'markets_observed_gte', 'cycles_completed_gte', 'minutes_without_trade_gte', 'positive_spread_gte'];
        $this->keys($gate, $allowAny ? [...$metrics, 'any'] : $metrics);
        if (count($gate) !== 1) {
            throw new GameException('Each phase gate must contain exactly one metric or any.');
        }
        if (isset($gate['any'])) {
            if (! is_array($gate['any']) || ! array_is_list($gate['any']) || ! $gate['any'] || count($gate['any']) > 10) {
                throw new GameException('Gate any requires a nonempty list of metric gates.');
            }
            foreach ($gate['any'] as $child) {
                $this->validateGate($child, false);
            }
        } elseif ((! is_int(reset($gate)) && ! is_float(reset($gate))) || ! is_finite((float) reset($gate)) || reset($gate) < 0) {
            throw new GameException('Gate thresholds must be nonnegative numbers.');
        }
    }

    public function passes(array $gate, array $metrics): bool
    {
        if (isset($gate['any'])) {
            return collect($gate['any'])->contains(fn ($child) => $this->passes($child, $metrics));
        }
        $key = array_key_first($gate);
        $value = $metrics[$key] ?? null;

        return $value !== null && ($key === 'hours_to_reset_lte' ? $value <= $gate[$key] : $value >= $gate[$key]);
    }
}
