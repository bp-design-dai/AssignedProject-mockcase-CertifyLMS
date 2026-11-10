<?php

declare(strict_types=1);

use App\Contracts\StripeCheckoutGateway;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalApiGuard;
use Tests\Support\FakeStripeGateway;
use Tests\Support\StripeWebhookSignature;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ExternalApiGuard::install($app);

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
    fwrite(STDERR, 'Only an isolated MySQL testing database is allowed.');
    exit(1);
}
Http::preventStrayRequests();
config(['services.stripe.secret' => 'sk_test_local_fake', 'services.stripe.webhook_secret' => 'whsec_sa03_fake']);
$gateway = new FakeStripeGateway;
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$gateway->onRetrieve = function () use ($connectionId) {
    echo json_encode(['ready' => true, 'connection_id' => $connectionId]).PHP_EOL;
    flush();
};
$app->instance(StripeCheckoutGateway::class, $gateway);
$payment = Payment::findOrFail($argv[1]);
$body = json_encode(['id' => $argv[2], 'object' => 'event', 'livemode' => false, 'type' => $argv[3],
    'data' => ['object' => ['id' => $payment->stripe_session_id, 'object' => 'checkout.session', 'metadata' => ['payment_id' => $payment->id]]]], JSON_THROW_ON_ERROR);
$signature = StripeWebhookSignature::sign($body, 'whsec_sa03_fake');
$request = Request::create('/webhooks/stripe', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'connection_id' => $connectionId]).PHP_EOL;
$kernel->terminate($request, $response);
