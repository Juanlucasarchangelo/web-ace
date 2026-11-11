<?php
/* Executable, no-Laravel smoke tests of the actual gateway and signature classes. */
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $queries, private array $headers) {}
        public function query(?string $key = null, $default = null) {
            return $key === null ? $this->queries : ($this->queries[$key] ?? $default);
        }
        public function header(string $name, $default = null) {return $this->headers[strtolower($name)] ?? $default;}
    }
}
namespace Illuminate\Support\Facades {
    class FakeResponse {
        public function __construct(private array $payload, private int $status=200) {}
        public function json($key = null, $default=null) {return $key === null ? $this->payload : ($this->payload[$key] ?? $default);}
        public function successful() {return $this->status >= 200 && $this->status < 300;}
    }
    class FakeClient {
        public function acceptJson() {return $this;}
        public function connectTimeout($s) {return $this;}
        public function timeout($s) {return $this;}
        public function get($url) {return new FakeResponse(['id' => substr($url, strrpos($url,'/')+1), 'status' => 'created']);}
    }
    class Http {public static function withToken($token) {return new FakeClient;}}
}
namespace App\Models {class AceBillingOrder {}}
namespace {
    $settings = [
        'ace_billing.webhook_secret' => 'only-a-test-secret',
        'ace_billing.access_token' => 'TEST-not-live',
        'ace_billing.seller_id' => '12345',
        'ace_billing.environment' => 'sandbox',
        'ace_billing.enabled' => false,
        'ace_billing.public_url' => '',
        'mail.default' => 'log',
    ];
    function config($key) {global $settings;return $settings[$key] ?? null;}
    require __DIR__.'/../app/Services/Billing/MercadoPagoGateway.php';
    require __DIR__.'/../app/Services/Billing/WebhookSignature.php';
    $n=0;
    function verify($condition, string $message) {global $n; $n++; if (!$condition) throw new \RuntimeException('FAIL: '.$message);}
    $validator = new \App\Services\Billing\WebhookSignature();
    $gateway = new \App\Services\Billing\MercadoPagoGateway();
    $id = 'ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';
    $requestId = 'req-1';
    $make = function($id, $ts, $rid='req-1', $signedId=null) use ($validator) {
        $manifest='id:'.strtolower($signedId??$id).';request-id:'.$rid.';ts:'.$ts.';';
        $hash=hash_hmac('sha256', $manifest, 'only-a-test-secret');
        return new \Illuminate\Http\Request(['data_id'=>$id,'type'=>'order'], [
            'x-request-id'=>$rid, 'x-signature'=>'ts='.$ts.',v1='.$hash,
        ]);
    };
    $now=(string)time();
    $good=$make($id,$now);
    verify(\App\Services\Billing\WebhookSignature::orderId($good)===$id,'query data_id recognized');
    verify($validator->valid($good,$id),'signed notification accepted');
    verify(!$validator->valid($good,'ORDTEST0000000000000000001'),'replayed signature for different order rejected');
    verify(!$validator->valid(new \Illuminate\Http\Request(['data_id'=>$id],[]),$id),'unsigned rejected');
    verify(!$validator->valid($make($id,(string)(time()-901)),$id),'stale timestamp rejected');
    verify(!$validator->valid($make($id,(string)(time()+901)),$id),'future timestamp rejected');
    verify($validator->valid($make($id,(string)(time()*1000)),$id),'millisecond timestamp accepted');
    verify(\App\Services\Billing\WebhookSignature::orderId(new \Illuminate\Http\Request(['data.id'=>$id], []))===$id,'raw literal data.id recognized');
    verify(\App\Services\Billing\WebhookSignature::orderId(new \Illuminate\Http\Request(['data_id'=>'123456789'], []))===null,'seller/numeric ID rejected');
    verify(!$validator->valid(new \Illuminate\Http\Request(['data_id'=>$id], ['x-request-id'=>'req-1','x-signature'=>'ts='.$now.',v1='.str_repeat('0',64)]),$id),'bad hmac rejected');
    verify(!$gateway->configured(),'checkout remains closed with log mail and payments disabled');
    verify($gateway->canFetchOrders(),'reconciliation authorization is independent of checkout readiness');
    verify($gateway->fetchOrder($id)['id']===$id,'reconciliation fetch works independently of checkout readiness');
    verify(\App\Services\Billing\MercadoPagoGateway::validOrderId($id),'Orders API ID accepted');
    verify(!\App\Services\Billing\MercadoPagoGateway::validOrderId('3823648893'),'merchant ID not Order ID');
    echo "PASS $n standalone webhook/gateway tests; no network requests or database modifications.\n";
}
