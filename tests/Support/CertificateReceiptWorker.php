<?php

declare(strict_types=1);

use App\Models\Enrollment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExternalApiGuard;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ExternalApiGuard::install($app);

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
    fwrite(STDERR, 'Only an isolated MySQL testing database is allowed.');
    exit(1);
}

$enrollment = Enrollment::findOrFail($argv[1]);
config(['filesystems.disks.private.root' => storage_path('app/private/sa04-race-'.$enrollment->id)]);
Auth::guard('web')->setUser($enrollment->user);
$id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
echo json_encode(['connection_id' => $id]).PHP_EOL;
flush();

$request = Request::create(route('enrollments.receiveCertificate', $enrollment), 'POST');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'redirect' => $response->headers->get('Location')]).PHP_EOL;
$kernel->terminate($request, $response);
