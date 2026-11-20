<?php

namespace App\Gameplay;

use App\Models\CampaignEpisode;
use App\Models\CampaignGrant;
use Symfony\Component\Yaml\Yaml;

/** Local, read-only explanation of C0. Keep runtime wording beside changes to the director. */
class CampaignStrategy
{
    public function read(): array
    {
        $grant = CampaignGrant::where('slot', 1)->first();
        $pending = CampaignGrant::where('slot', 2)->first();
        if ($grant) {
            $revision = $grant->revision();
            $body = $revision->body;
            $hash = $revision->content_hash;
            $states = [];
            if ($grant->paused_at) {
                $states[] = 'Paused';
            }
            if ($grant->expires_at?->isPast()) {
                $states[] = 'Expired';
            }
            if ($grant->revoked_at || $grant->superseded_at) {
                $states[] = 'Inactive';
            }
            $status = $states ? implode(' · ', $states).' C0 revision' : 'Approved C0 revision';
        } else {
            $yaml = file_get_contents(base_path('campaigns/weekly-default.yaml'));
            $body = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            app(CampaignPlaybook::class)->validate($body);
            $hash = hash('sha256', $yaml);
            $status = 'Bundled example · unapproved';
        }

        return [
            'source' => ['name' => $body['name'], 'hash' => substr($hash, 0, 12), 'status' => $status,
                'example' => ! $grant, 'pauseReason' => $grant?->pause_reason,
                'pending' => $pending ? ['name' => $pending->revision()->name,
                    'notice' => 'Activation depends on the director and a safe handoff from the current grant.'] : null],
            'graph' => $this->graph($body, $grant ? (CampaignEpisode::where('campaign_grant_id', $grant->id)->whereNull('closed_at')->latest('id')->value('phase') ?? $body['phases'][0]['id']) : null),
        ];
    }

    public function graph(array $body, ?string $phase = null): array
    {
        $nodes = [];
        $chapters = [];
        $add = function (string $id, string $title, string $summary, array $branches, array $groups = []) use (&$nodes, &$chapters): void {
            $chapters[] = $id;
            $links = [];
            foreach ($branches as $branchId => [$condition, $label, $heading, $text]) {
                $target = $id.'.'.$branchId;
                $links[] = ['condition' => $condition, 'label' => $label, 'target' => $target,
                    'tone' => match ($label) {
                        'Wait', 'Recheck' => 'wait', 'Pause' => 'pause', default => 'continue'
                    }];
                $nodes[$target] = ['id' => $target, 'kind' => 'outcome', 'title' => $heading, 'summary' => $text, 'links' => []];
            }
            $nodes[$id] = ['id' => $id, 'kind' => 'chapter', 'title' => $title, 'summary' => $summary, 'links' => $links, 'groups' => $groups];
        };
        $number = fn ($value) => $this->number($value);
        $percent = fn ($value) => $number($value * 100).'%';
        $trade = $body['trade'];
        $local = ($phase ?? $body['phases'][0]['id']) === 'earn' ? ($trade['local_earn'] ?? null) : null;
        $hasTrade = collect($body['phases'])->contains(fn ($phase) => collect($phase['activities'])->contains(fn ($activity) => $activity['strategy'] === 'trade'));
        $hasMining = collect($body['phases'])->contains(fn ($phase) => collect($phase['activities'])->contains(fn ($activity) => $activity['strategy'] === 'mining'));
        $losses = $trade['losses']['pause_after_consecutive_losing_loads'];
        $add('authority', 'Authority', 'Can the campaign run? C0 needs approval, an available engine and automation enabled.', [
            'ready' => ['Authority and engine ready', 'Continue', 'Check the world', 'C0 confirms the reset schedule before deciding what to run. Eligible C0 and arm work rotate scheduler priority. Arm experiments and explicit gate-recon missions are outside this walkthrough.'],
            'engine' => ['Engine unavailable or automation disabled', 'Wait', 'Wait for the engine', 'This pass ends. C0 can try again when the engine has a session and automation is enabled. Each decision has a '.CampaignDirector::DEADLINE_SECONDS.' second request budget.'],
            'approval' => ['Approval absent, paused or expired', 'Pause', 'Approval is required', 'No new work starts. A paused grant needs operator review before it can resume. An expired grant needs new approval.'],
            'interval' => ['The previous pass just finished', 'Wait', 'Leave room between passes', 'C0 waits at least 30 seconds after a pass finishes before beginning another. This pass ends and the scheduler returns later.'],
        ]);
        $add('world', 'World and agent', 'Is the reset schedule confirmed? The approved agent is '.$body['agent']['symbol'].' in '.$body['agent']['faction'].'.', [
            'confirmed' => ['Reset schedule confirmed', 'Continue', 'Reuse or register the agent', 'Reuse the agent in this reset, or register the approved symbol for the confirmed world. Old worlds are archived and the episode follows this reset.'],
            'schedule' => ['Schedule missing, overdue or invalid', 'Wait', 'Wait for confirmation', 'This pass ends without new work. C0 needs a usable reset identifier and a future reset time.'],
            'retry' => ['Registration is retryable or awaiting confirmation', 'Wait', 'Wait before retrying', 'This pass ends. Registration records its intent and waits at least 60 seconds before the allowed retry of an ambiguous attempt. A definitely unsent request can be retried.'],
            'review' => ['Registration conflict, repeated ambiguity or missing account token', 'Pause', 'Review registration', 'This pass ends for operator review. Validation failures and repeated ambiguity require operator review.'],
        ]);
        $add('safety', 'Safety and current work', 'Revalidate a retained preview first. Otherwise sync local results, record finished loads, and check safety before planning new work.', [
            'preview' => ['A retained preview is available', 'Recheck', 'Recheck before starting', 'Revalidate authority, safety, plan limits and current state. A valid preview can start a run. A retryable error defers it and a rejected preview is discarded. Each outcome ends this pass.'],
            'uncertain' => ['Accounting or an action is uncertain', 'Pause', 'Review the balance', 'This pass ends before cargo recovery. Uncertain actions, review holds or unexplained credit changes need operator review.'],
            'active' => ['Another run is active or a role ship is busy', 'Wait', 'Let current work settle', 'A running operation or pending ship action can end this pass while the role ship is busy. A blocked, stale or stopping run can hand off once pending actions and transit settle. Wind-down also hands off work outside sell-only recovery.'],
            'losses' => ['Loss streak reaches '.$losses.' losing loads', 'Recover', 'Recover cargo before pausing', 'The pause is pending while sell-only recovery is needed. Once role-ship cargo is cleared, C0 pauses for review. Other safety holds can stop earlier.'],
            'clear' => ['No safety hold or current work', 'Continue', 'Check cargo first', 'New work is possible only after checking the existing cargo on the command ship and probe.'],
        ]);
        $attempts = max(1, (int) config('gameplay.campaign_recovery_attempts', 5));
        $add('cargo', 'Cargo first', 'Try to sell existing role-ship cargo before planning a new activity, including during wind-down or a pending loss pause.', [
            'aboard' => ['Cargo aboard with a usable buyer', 'Recover', 'Sell first', 'Use a bounded sell-only recovery run. Starting recovery ends this pass. C0 returns after the run settles.'],
            'waiting' => ['Ship in transit, or quotes or routes need verification', 'Wait', 'Wait for a safe recovery plan', 'This pass ends. Wait for arrival, a fresh buyer quote or a verified route before selling.'],
            'floor' => ['No buyer meets the cargo cost floor', 'Recover', 'Use the recovery fallback', 'Recovery first seeks a buyer at the recorded cargo cost floor. It can accept the best positive bid after its recovery deadline. The cost-floor wait lasts ten minutes from the start of recovery. Before accepting a lower bid, recovery checks for a better reachable buyer. Standalone cargo recovery does not establish trade proof.'],
            'buyer' => ['No observed buyer has a positive bid', 'Pause', 'No cargo buyer', 'This pass ends with a pause for operator review. The cargo stays aboard for review.'],
            'stalled' => [$attempts.' consecutive recovery attempts make no proceeds', 'Pause', 'Recovery stalled', 'This pass ends for review after '.$attempts.' stopped or blocked recovery attempts with no positive sale proceeds or credit delta. Operator resume clears the attempt window.'],
            'empty' => ['Role-ship holds are empty', 'Continue', 'Check the phase', 'C0 can evaluate phase exits and then the ordered activities, unless a pending safety pause now applies.'],
        ]);
        $groups = [];
        foreach ($body['phases'] as $index => $phase) {
            $last = $index === count($body['phases']) - 1;
            $gate = $phase['exit'] ?? null;
            $groups[] = [
                'id' => $phase['id'], 'title' => $this->label($phase['id']),
                'exitLabel' => match (true) {
                    ! $gate => 'No configured exit',
                    $last && isset($gate['any']) => 'Configured exit conditions · any one is sufficient',
                    $last => 'Configured exit condition',
                    isset($gate['any']) => 'Advance if any one is true',
                    default => 'Advance when',
                },
                'exits' => $gate ? $this->gate($gate) : [],
                'note' => match (true) {
                    $last => 'End of the phase sequence.',
                    $gate !== null => 'A passing exit advances to '.$this->label($body['phases'][$index + 1]['id']).'.',
                    default => null,
                },
                'activities' => array_map(fn ($activity) => [
                    'label' => $this->activity($activity),
                    'condition' => isset($activity['when']) ? (isset($activity['when']['any']) ? 'Eligible if any one is true: ' : 'Eligible when: ').implode('; ', $this->gate($activity['when'])) : null,
                ], $phase['activities']),
            ];
        }
        $windDown = in_array('wind_down', array_column($body['phases'], 'id'), true)
            ? 'In the final hour, C0 moves to the configured Wind-down phase.'
            : 'This revision has no literal wind_down phase. Its phase label can stay as configured in the final hour.';
        $add('phase', 'Phase and activity', 'Check phase exits before activities. Several exits can pass in one turn. Try eligible activities in order. The first activity that starts a run wins.', [
            'run' => ['An eligible activity starts a run', 'Run', 'The first successful activity wins', 'Starting a run ends this pass. Later activities wait for another pass. A missing role ship, transit or a failed activity condition skips that activity.'],
            'discovery' => ['Discovery is due outside Earn', 'Recheck', 'Discovery can take over this pass', 'Outside Earn and wind-down, due discovery advances before activities and ends the pass. Discover waits two minutes between sweeps. Other phases use fifteen minutes. In Earn, C0 refreshes parked markets and may advance discovery during an active run or after activities yield.'],
            'wind' => ['The final hour begins', 'Recover', 'Only sell aboard', $windDown.' The final-hour restriction still blocks new buys, mining and scouting. Selling happens in Cargo first. A configured sell_aboard activity starts no work on its own.'],
            'idle' => ['No eligible activity starts a run', 'Recheck', 'End this pass', 'C0 returns on a later pass. A trade that yields allows the next eligible activity to try.'.($hasMining ? ' If trade was already attempted, mining is deferred to the next pass and that turn skips trade.' : '')],
        ], $groups);
        if ($hasTrade) {
            $bid = $percent($trade['switch']['bid_below_first_seen']);
            $ratio = $number($trade['switch']['better_pair_ratio']);
            $quote = $trade['quote_max_age_seconds'];
            $summary = 'Rank feasible observed good/buyer pairs by conservative margin per planned minute. Fuel, available cash, cargo hold, route time and fleet claims bound the choice.';
            $branches = [
                'bid' => ['Current buyer bid falls to '.$bid.' or less of first seen', 'Drop', 'Rule out the current pair', 'Reject that current good/buyer pair at or below '.$bid.' of the first buyer bid C0 recorded. Other pairs can still qualify.'],
                'switch' => ['A challenger scores at least '.$ratio.'× the eligible current pair', 'Switch', 'Try the challenger', 'Keep a still-eligible current pair unless another scores at least '.$ratio.'× as well. Refresh quotes and rerank before loading. A changed preferred pair yields this activity.'],
                'position' => ['The command ship is away from the source', 'Run', 'Position, then observe', 'Position the command ship to the source. Then use an available probe to observe the buyer when needed. Positioning can start a run and end this pass.'],
                'stale' => ['Quotes lack enough freshness for loading', 'Wait', 'Refresh before buying', 'Quote age must fit '.$quote.' seconds and the remaining buy plan. Refresh source and buyer quotes, then rerank. A stale or changed pair can yield to the next eligible activity, or end the pass if none starts.'],
                'floor' => ['Earn score is below the value floor', 'Flag', 'Flag the low score', 'In Earn the value floor is a diagnostic flag. The absolute floor is '.number_format(CampaignTrade::FLOOR_CR_PER_MINUTE).' CR/min, also compared with '.($percent(CampaignTrade::FLOOR_MEDIAN_SHARE)).' of the trailing '.CampaignTrade::FLOOR_TRAILING_LOADS.' loads’ median realized score. A positive-spread phase exit counts only eligible trades at the absolute floor.'],
                'eligible' => ['The refreshed pair still qualifies', 'Continue', 'Size the load', 'Use the pair’s confirmed evidence to choose proof or scaled sizing.'],
            ];
            if ($local) {
                $summary = 'C0 Earn checks a bounded shortlist in the command ship’s system. It tries fresh buys at the current source first, then pairs it can refresh there. Estimates cover the projected pairs.';
                $branches['switch'][3] = 'Within comparable readiness, retain an eligible current pair unless a challenger scores at least '.$ratio.'× as well. Order the exact good, source and buyer identities once. Keep that order as each pair is refreshed and rechecked.';
                $branches['position'] = ['No safe buy starts at the current source', 'Run', 'Scout before spending fuel', 'Try one available fuel-free probe to check the buyer first. Recon keeps its probe while the loan is active. If no observer run starts, C0 can move the command ship on evidence no older than '.$local['position_evidence_max_age_seconds'].' seconds. A buyer observer must be available or the quote must survive travel and loading. The trade must cover conservative cycle and positioning fuel and protect recon return cash. At most '.$local['max_positionings_without_trade'].' paid moves can start before a trade. A trade or '.CampaignLocalEarn::POSITION_COOLDOWN_MINUTES.' minutes with work settled can reset the count. One run ends the pass.'];
                $branches['stale'] = ['A candidate fails the loading check', 'Next pair', 'Try another safe pair', 'Quotes must be fresh enough to finish loading within the '.$quote.'-second limit. Reuse recent reads from occupied markets and recheck the exact pair with its own proof, claims, fuel and cash limits. A known refusal before sending an action may advance to the next pair. API errors, uncertain actions, provider deferrals and safety or approval failures end the pass.'];
                $branches['eligible'][3] = 'Project at most '.$local['projection_limit'].' pairs and try up to '.$local['max_attempts'].' buys. Require a positive conservative contribution after cycle and positioning fuel. Rank by contribution per planned minute, including positioning time. A smaller safe profit can start after an earlier pair fails. Use confirmed receipts to measure the actual load result.';
                $branches['budget'] = ['Fewer than '.CampaignLocalEarn::RESERVE_SECONDS.' seconds remain', 'Wait', 'Defer the entire local pass', 'End the pass before another projection, preview, move or scout. Keep the provider deadline. A retryable preview can be retained and rechecked against approval, harvested losses and full safety on the next pass.'];
                $branches['exhausted'] = ['The bounded shortlist is exhausted', 'Continue', 'Allow the ordinary fallback', 'First-seen bids come from projected pairs. The fixed bound can miss a better pair. New quotes affect the next pass’s order. Exhausting the candidates allows ordinary scout and discovery work. A budget deferral ends the entire pass.'];
                $nodes['safety']['summary'] = 'Harvest finished local loads and recheck full safety before starting a retained preview. Otherwise sync, record results and check safety before planning.';
            }
            $add('trade', 'Trade selection', $summary, $branches);
            $add('proof', 'Proof or scale', 'C0 learns separately for each good and intended buyer. New or losing pairs need proof before scaling.', [
                'new' => ['No qualifying proof', 'Proof', 'Run a small proof', 'Cap the load at '.$trade['proof']['max_units_batches'].($trade['proof']['max_units_batches'] === 1 ? ' batch' : ' batches').' at the smaller of the two markets’ trade volumes and '.number_format($trade['proof']['max_credits']).' CR of inventory. Cash, fuel, hold and time can lower the load further.'],
                'qualified' => ['Proof meets '.$percent($trade['proof']['min_realized_ratio']).' of predicted margin', 'Scale', 'Scale the next load', 'Eligible delivery to the intended buyer at '.$percent($trade['proof']['min_realized_ratio']).' or more of predicted margin unlocks up to '.$trade['lot']['first_scaled_max_buyer_batches'].' buyer batches first, then '.$trade['lot']['max_buyer_batches'].' on later scaled loads. The default marginal batch decay is '.$percent($trade['lot']['default_batch_decay']).' until observations provide an estimate.'],
                'loss' => ['The pair loses money', 'Reprove', 'Reset the affected pair’s proof', 'A confirmed loss resets proof for this good and buyer. Other pairs retain their own evidence.'],
            ]);
        }
        $finalSummary = $hasTrade ? 'A bounded preview can start a run or defer to a later pass. Confirmed load results inform the next choice.'
            : 'A bounded preview can start a configured activity or defer to a later pass. Scout observations and confirmed work inform the next choice.';
        $finalBranches = [
            'start' => ['A bounded preview is ready', 'Run', 'Start and return later', 'Revalidate the preview before starting. Runs are bounded to '.$body['limits']['run_max_minutes'].' minutes, with '.number_format($body['limits']['reserve_credits']).' CR reserved and fuel for the next leg. Starting a run ends the pass.'],
            'defer' => ['Preview is deferred or no activity can run', 'Wait', 'Try on the next pass', 'This pass ends without new work. Later passes use fresh state. The final hour permits only selling aboard through cargo recovery.'],
        ];
        if ($hasTrade) {
            $finalBranches['results'] = ['A load has confirmed results', 'Learn', 'Record what happened', 'Use confirmed proceeds, purchase cost and fuel cost to assess the load. A losing load resets the affected pair’s proof. Predictions in the playbook are report targets.'];
            $finalBranches['pause'] = ['Loss streak reaches '.$losses.' losing loads', 'Pause', 'Recover cargo, then pause', 'New buys stop. Sell-only recovery continues while needed, then C0 pauses for operator review. This ends the pass.'];
        } else {
            $finalBranches['configured'] = ['Configured activities yield', 'Recheck', 'Follow the configured activity order', 'Scouting reads markets. Command scouting stays at its own coordinates.'.($hasMining ? ' Mining uses a bounded investigation and run when eligible.' : '').' Cargo first handles sell_aboard entries through recovery. If nothing starts, this pass ends and C0 returns later. Predictions are report targets.'];
        }
        if ($local) {
            $nodes['proof']['summary'] .= ' This-reset replacement starts fresh C0 proof and bids. H1 retains its own results; its C0 learning and receipt-derived decay reference follows the new episode. Replacement refuses any increase in H1 sizing mode.';
        }
        $add('learn', 'Start and learn', $finalSummary, $finalBranches);

        return ['root' => $chapters[0], 'chapters' => $chapters, 'nodes' => $nodes];
    }

    private function number(int|float $value): string
    {
        $text = (string) $value;
        if (stripos($text, 'e') !== false) {
            return $text;
        }
        $decimals = str_contains($text, '.') ? strlen(explode('.', $text)[1]) : 0;

        return number_format($value, $decimals, '.', ',');
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', '-', $value));
    }

    private function gate(array $gate): array
    {
        if (isset($gate['any'])) {
            return array_merge(...array_map(fn ($child) => $this->gate($child), $gate['any']));
        }
        $value = reset($gate);
        $number = $this->number($value);

        return [match (array_key_first($gate)) {
            'minutes_elapsed_gte' => 'At least '.$number.' minutes have elapsed',
            'hours_to_reset_lte' => 'At most '.$number.($value == 1 ? ' hour remains' : ' hours remain').' before reset',
            'credits_gte' => 'At least '.$number.' CR are available',
            'markets_observed_gte' => 'At least '.$number.' markets have detailed prices',
            'cycles_completed_gte' => 'At least '.$number.' cycles are completed',
            'minutes_without_trade_gte' => 'At least '.$number.' minutes have passed without trade',
            'positive_spread_gte' => 'At least '.$number.($value == 1 ? ' eligible trade scores ' : ' eligible trades score ').number_format(CampaignTrade::FLOOR_CR_PER_MINUTE).' CR/min or higher',
        }];
    }

    private function activity(array $activity): string
    {
        $role = $activity['role'] === 'command' ? 'Command ship' : 'Probe';

        return $role.' '.match ($activity['strategy']) {
            'scout' => $activity['role'] === 'command' ? 'scouts unpriced or unknown markets at its own coordinates' : 'scouts markets',
            'trade' => 'tries trade',
            'mining' => 'tries mining. If trade was already attempted, mining is deferred to the next turn',
            'sell_aboard' => 'sells aboard through the earlier Cargo first step',
        };
    }
}
