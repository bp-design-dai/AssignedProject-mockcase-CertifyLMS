<?php

declare(strict_types=1);

use App\Contracts\GeminiGateway;
use App\Data\GeminiResponse;
use App\Exceptions\AiChatException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$create = ($argv[3] ?? 'send') === 'create';
$request = Request::create($create ? '/ai-chat/conversations' : '/ai-chat/conversations/'.$argv[2].'/messages', 'POST',
    $create ? ['section_id' => $argv[2]] : ['content' => '並行送信テスト'], server: ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-only', 'ai-chat.daily_limit' => (int) ($argv[4] ?? 30)]);
Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
Http::preventStrayRequests();
$app->instance(GeminiGateway::class, new class implements GeminiGateway
{
    public function generate(array $contents, string $systemInstruction): GeminiResponse
    {
        if (getenv('SA02_FAIL_PROVIDER')) {
            throw new AiChatException('transport_error');
        }

        return new GeminiResponse('並行テストの回答', 'mock-only');
    }
});
Auth::guard('web')->setUser(User::findOrFail($argv[1]));
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id, DATABASE() AS name, @@transaction_isolation AS isolation_level');
echo json_encode(['ready' => true, 'connection' => $connection]), PHP_EOL;
flush();
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'conversation_id' => $body['conversation']['id'] ?? null, 'connection_id' => $connection->id]), PHP_EOL;
$kernel->terminate($request, $response);
