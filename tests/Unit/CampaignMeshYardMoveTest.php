<?php

namespace Tests\Unit;

use App\Gameplay\CampaignMesh;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CampaignMeshYardMoveTest extends TestCase
{
    public static function movementPlans(): iterable
    {
        $cases = [
            'yard' => true, 'purchase' => true, 'hauler_purchase' => true, 'buy_fleet' => true,
            'market' => false, 'scout' => false, 'coverage' => false, 'proof_scout' => false,
            'proof' => false, 'trade' => false, 'mining' => false, 'siphon' => false,
            'survey' => false, 'position' => false, 'tender' => false, '' => false,
        ];
        foreach ($cases as $work => $yard) {
            foreach ([null, 'market', 'shipyard'] as $observe) {
                $plan = $work === '' ? [] : ['mesh_work' => $work];
                if ($observe !== null) {
                    $plan['observe_kind'] = $observe;
                }
                yield ($work ?: 'no work').'/'.($observe ?? 'no observation') => [$plan, $yard || $observe === 'shipyard'];
            }
        }
    }

    #[DataProvider('movementPlans')]
    public function test_classifies_yard_work(array $plan, bool $yard): void
    {
        $this->assertSame($yard, CampaignMesh::isYardMove($plan));
    }
}
