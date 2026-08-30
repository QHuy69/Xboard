<?php

namespace Tests\Unit\Models;

use App\Http\Requests\Admin\PlanSave;
use App\Models\Plan;
use App\Services\PlanService;
use PHPUnit\Framework\TestCase;

class PlanFreePriceTest extends TestCase
{
    public function test_admin_request_keeps_an_explicit_zero_price(): void
    {
        $request = new class extends PlanSave
        {
            public function cleanPrices(): void
            {
                $this->passedValidation();
            }
        };

        $request->replace([
            'prices' => [
                Plan::PERIOD_MONTHLY => 0,
                Plan::PERIOD_QUARTERLY => '',
            ],
        ]);
        $request->cleanPrices();

        $this->assertSame([
            Plan::PERIOD_MONTHLY => 0.0,
        ], $request->input('prices'));
    }

    public function test_free_period_is_available_and_listed(): void
    {
        $plan = new Plan();
        $plan->prices = [
            Plan::PERIOD_MONTHLY => 0,
            Plan::PERIOD_QUARTERLY => null,
        ];

        $this->assertArrayHasKey(Plan::PERIOD_MONTHLY, $plan->getActivePeriods());
        $this->assertArrayNotHasKey(Plan::PERIOD_QUARTERLY, $plan->getActivePeriods());
        $this->assertSame(0, $plan->getPriceList()[Plan::PERIOD_MONTHLY]['price']);

        $available = (new PlanService($plan))->getAvailablePeriods($plan);
        $this->assertSame([
            Plan::PERIOD_MONTHLY => Plan::getAvailablePeriods()[Plan::PERIOD_MONTHLY],
        ], $available);
    }
}
