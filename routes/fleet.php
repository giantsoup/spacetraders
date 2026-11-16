<?php

use App\Gameplay\FleetMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

Artisan::command('fleet:scorecard {--since=} {--until=} {--reset=} {--json}', function (FleetMetrics $metrics) {
    $card = $metrics->scorecard($this->option('since') ? CarbonImmutable::parse($this->option('since')) : null,
        $this->option('until') ? CarbonImmutable::parse($this->option('until')) : null, $this->option('reset') ?: null);
    if ($this->option('json')) {
        $this->line(json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return;
    }
    $n = fn ($value, int $digits = 0) => $value === null ? '-' : number_format($value, $digits);
    $this->line('Reset '.($card['reset'] ?? '-').'  T0 '.$card['since'].'  until '.$card['until']);
    $this->table(['Arm', 'Ship', 'Loads', 'Hours', 'Net/h', '80% CI', 'Net/load', 'Loads/h', 'Pair ratio', 'Payback h', '1st sale min', 'Req/ship-h', 'Holds', 'Stall min', 'Lock/Defer', 'Limits'],
        array_map(fn ($row) => [$row['label'], $row['ship'] ?? '-', $row['loads'].'/'.$row['loads_total'], $n($row['hours'], 2), $n($row['net_per_hour']),
            $row['net_per_hour_80'] ? $n($row['net_per_hour_80'][0]).'..'.$n($row['net_per_hour_80'][1]) : '-', $n($row['net_per_load']), $n($row['loads_per_hour'], 2),
            $n($row['pair_ratio'], 2), $n($row['payback_hours'], 1), $n($row['first_sale_minutes'], 1), $n($row['requests_per_ship_hour']), $row['holds'],
            $n($row['stall_minutes'], 1), $row['lock_collisions'].'/'.$row['deferrals'],
            implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($row['limiting_factors']), $row['limiting_factors']))], array_values($card['arms'])));
    $this->table(['Arm', 'Block 1 loads', 'Block 1 net/h', 'Block 1 pair', 'Block 2 loads', 'Block 2 net/h', 'Block 2 pair'],
        array_map(fn ($row) => [$row['label'], $row['blocks'][0]['loads'], $n($row['blocks'][0]['net_per_hour']), $n($row['blocks'][0]['pair_ratio'], 2),
            $row['blocks'][1]['loads'], $n($row['blocks'][1]['net_per_hour']), $n($row['blocks'][1]['pair_ratio'], 2)], array_values($card['arms'])));
    if ($unit = $card['shared_pair']) {
        $this->line('Shared-pair unit T1a+T3 '.$n($unit['unit_net_per_hour']).'/h vs T1b+T1c '.$n($unit['independent_net_per_hour']).'/h, ratio '.$n($unit['ratio'], 2)
            .' (blocks '.implode(', ', array_map(fn ($b) => $n($b['ratio'], 2), $unit['blocks'])).')');
    }
    foreach ($card['flags'] as $flag) {
        $this->line(sprintf('Flag %s %s: %sx vs T1 — >=1.5x %s, outside T1 range %s, both blocks %s, >=12 loads %s => %s', $flag['label'], $flag['metric'], $flag['ratio'],
            $flag['at_least_1_5x'] ? 'yes' : 'no', $flag['outside_t1_range'] ? 'yes' : 'no', $flag['same_direction_both_blocks'] ? 'yes' : 'no', $flag['enough_loads'] ? 'yes' : 'no',
            $flag['flagged'] ? 'FLAGGED' : 'not flagged'));
    }
    $s = $card['scheduler'];
    $this->line('Scheduler: '.$s['passes'].' passes, median dispatch lag '.$n($s['median_dispatch_lag'], 2).' s, median director '.$n($s['median_director'], 2)
        .' s, median arms '.$n($s['median_arms'], 2).' s, max queue depth '.($s['max_queue_depth'] ?? '-').', shared requests '.$s['shared_requests']);
})->purpose('Multi-ship round-one scorecard per arm (net/hour, intervals, flags)');
