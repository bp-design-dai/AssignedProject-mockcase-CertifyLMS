<?php

declare(strict_types=1);

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$request = Request::create('/enrollments/'.$argv[2].'/meetings', 'POST', [
    'scheduled_at' => $argv[3],
    'topic' => 'B-A-01 concurrent reservation',
], server: ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}

Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
Event::fake([MeetingReserved::class, MeetingCanceled::class]);
Auth::guard('web')->setUser(User::findOrFail($argv[1]));
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id, DATABASE() AS name, @@transaction_isolation AS isolation_level');
echo json_encode(['ready' => true, 'connection' => $connection, 'student_id' => $argv[1]]), PHP_EOL;
flush();

$response = $kernel->handle($request);
echo json_encode([
    'status' => $response->getStatusCode(),
    'student_id' => $argv[1],
    'connection_id' => $connection->id,
    'location' => $response->headers->get('Location'),
    'body' => $response->getStatusCode() >= 400 ? $response->getContent() : null,
]), PHP_EOL;
$kernel->terminate($request, $response);
