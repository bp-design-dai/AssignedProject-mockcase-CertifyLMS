<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Stripe\StripeApiCheckoutGateway;
use App\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class StripeApiCheckoutGatewayTest extends TestCase
{
    private ClientInterface $http;

    private StripeApiCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fake_only', 'services.stripe.webhook_secret' => 'whsec_fake_only']);
        $this->http = new class implements ClientInterface
        {
            public array $calls = [];

            public array $body = ['id' => 'cs_test_example', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_example', 'livemode' => false];

            public int $status = 200;

            public bool $fail = false;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls[] = compact('method', 'absUrl', 'headers', 'params');
                if ($this->fail) {
                    throw new \RuntimeException('Provider detail with sk_test_fake_only and private-card-field');
                }

                return [json_encode($this->body), $this->status, []];
            }
        };
        ApiRequestor::setHttpClient($this->http);
        $this->gateway = new StripeApiCheckoutGateway;
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    public function test_sdk_creates_jpy_single_purchase_from_snapshot_with_idempotency_key(): void
    {
        $payment = new Payment(['pack_name' => '購入時の3回パック', 'amount' => 6000, 'quantity' => 3]);
        $payment->id = '01examplepayment';
        $result = $this->gateway->create($payment);
        $request = $this->http->calls[0];
        $params = $request['params'];
        $this->assertSame('post', $request['method']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $request['absUrl']);
        $this->assertSame('payment', $params['mode']);
        $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertSame(['enabled' => 'false'], $params['adaptive_pricing']);
        $this->assertSame(['enabled' => 'false'], $params['automatic_tax']);
        $this->assertSame('false', $params['allow_promotion_codes']);
        $this->assertSame($payment->id, $params['metadata']['payment_id']);
        $this->assertSame($payment->id, $params['client_reference_id']);
        $this->assertSame(['price_data' => ['currency' => 'jpy', 'unit_amount' => 6000, 'product_data' => ['name' => '購入時の3回パック']], 'quantity' => 1], $params['line_items'][0]);
        $this->assertStringEndsWith('/meeting-quota/success?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertStringStartsWith(config('app.url'), $params['success_url']);
        $this->assertStringContainsString('/meeting-quota/checkout?canceled=1', $params['cancel_url']);
        $this->assertContains('Idempotency-Key: meeting-pack:'.$payment->id, $request['headers']);
        $this->assertArrayNotHasKey('customer_email', $params);
        $this->assertArrayNotHasKey('setup_future_usage', $params);
        $this->assertSame(['id', 'url'], array_keys($result));
    }

    public function test_sdk_retrieves_only_safe_matching_fields_and_excludes_customer_details(): void
    {
        $this->http->body += ['mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'jpy', 'payment_intent' => 'pi_example', 'client_reference_id' => 'purchase', 'metadata' => ['payment_id' => 'purchase'], 'customer_details' => ['email' => 'private@example.test'], 'payment_method_details' => ['card' => ['last4' => '4242']]];
        $data = $this->gateway->retrieve('cs_test_example');
        $this->assertSame('get', $this->http->calls[0]['method']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_example', $this->http->calls[0]['absUrl']);
        $this->assertSame(6000, $data['amount_total']);
        $this->assertSame('purchase', $data['payment_id']);
        $this->assertSame('pi_example', $data['payment_intent']);
        $this->assertArrayNotHasKey('customer_details', $data);
        $this->assertArrayNotHasKey('payment_method_details', $data);
    }

    #[DataProvider('badConfiguration')]
    public function test_missing_or_live_keys_never_start_network_requests(?string $key, ?string $webhook): void
    {
        config(['services.stripe.secret' => $key, 'services.stripe.webhook_secret' => $webhook]);
        $this->assertFalse($this->gateway->isConfigured());
        try {
            $this->gateway->create(new Payment);
            $this->fail('Unconfigured or live Stripe must be rejected.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertSame([], $this->http->calls);
        }
    }

    public static function badConfiguration(): array
    {
        return [[null, null], ['', 'whsec_fake'], ['sk_live_fake_never_use', 'whsec_fake'], ['pk_test_wrong', 'whsec_fake'], ['sk_test_fake', null]];
    }

    #[DataProvider('unsafeResponse')]
    public function test_live_or_unsafe_checkout_responses_are_rejected(string $field, mixed $value): void
    {
        $this->http->body[$field] = $value;
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->gateway->create(new Payment(['amount' => 3000, 'pack_name' => '検証用']));
    }

    public static function unsafeResponse(): array
    {
        return [['livemode', true], ['id', 'cs_live_example'], ['url', 'https://attacker.example/path'], ['url', 'http://checkout.stripe.com/path']];
    }

    #[DataProvider('providerMethods')]
    public function test_transport_errors_never_retain_secret_or_provider_details(string $method): void
    {
        $this->http->fail = true;
        try {
            $method === 'create' ? $this->gateway->create(new Payment(['pack_name' => '検証用', 'amount' => 3000])) : $this->gateway->retrieve('cs_test_example');
            $this->fail('Transport error must fail safely.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('sk_test_fake_only', $e->getMessage());
            $this->assertStringNotContainsString('private-card-field', $e->getMessage());
        }
    }

    public static function providerMethods(): array
    {
        return [['create'], ['retrieve']];
    }
}
