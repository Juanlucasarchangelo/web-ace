<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\PlanFeatures;
use PHPUnit\Framework\TestCase;

class PlanFeaturesTest extends TestCase
{
    public function test_plan_feature_matrix(): void
    {
        $free = new Tenant(['plan' => 'free']);
        $cloud = new Tenant(['plan' => 'cloud']);
        $pro = new Tenant(['plan' => 'pro']);
        $this->assertFalse(PlanFeatures::cloud($free));
        $this->assertFalse(PlanFeatures::ai($free));
        $this->assertTrue(PlanFeatures::cloud($cloud));
        $this->assertFalse(PlanFeatures::ai($cloud));
        $this->assertTrue(PlanFeatures::cloud($pro));
        $this->assertTrue(PlanFeatures::ai($pro));
    }
}
