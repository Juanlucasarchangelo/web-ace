<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\BillingController;
use App\Models\AceBillingOrder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AceBillingTrackingTest extends TestCase
{
    private function order(): array
    {
        $id = '27ca8a3e-471a-494d-95d7-04d9bb3cf4af';
        $token = str_repeat('A', 64);
        $order = new AceBillingOrder([
            'id' => $id,
            'tracking_token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'plan' => 'cloud',
        ]);
        return [$order, $token, 'ace_order_'.str_replace('-', '', $id)];
    }

    public function test_order_status_denies_uuid_only_access(): void
    {
        [$order] = $this->order();
        $this->expectException(NotFoundHttpException::class);
        (new BillingController())->orderStatus(Request::create('/api/v1/billing/orders/'.$order->id), $order);
    }

    public function test_order_status_denies_invalid_cookie(): void
    {
        [$order, , $name] = $this->order();
        $request = Request::create('/api/v1/billing/orders/'.$order->id);
        $request->cookies->set($name, str_repeat('B', 64));
        $this->expectException(NotFoundHttpException::class);
        (new BillingController())->orderStatus($request, $order);
    }

    public function test_order_status_accepts_matching_cookie(): void
    {
        [$order, $token, $name] = $this->order();
        $request = Request::create('/api/v1/billing/orders/'.$order->id);
        $request->cookies->set($name, $token);
        $response = (new BillingController())->orderStatus($request, $order);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pending', $response->getData(true)['data']['status']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
