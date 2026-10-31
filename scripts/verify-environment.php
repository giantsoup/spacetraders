<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__);
if (($argv[1] ?? '') === '--probe') {
    $directory = $argv[2] ?? '';
    if (! is_file($directory.'/.owned-ignition-probe') || ! str_starts_with(realpath($directory) ?: '', realpath(sys_get_temp_dir()).'/st-env-')) {
        exit(2);
    }
    $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $directory;
    putenv('APP_BASE_PATH='.$directory);
    $app = require $root.'/bootstrap/app.php';
    if ($app->basePath() !== $directory) {
        exit(2);
    }
    try {
        $kernel = $app->make(Kernel::class);
        $before = hash_file('sha256', $directory.'/.env');
        $response = $kernel->handle(Request::create('/_ignition/execute-solution', 'POST', [
            'solution' => 'Spatie\\ErrorSolutions\\Solutions\\Laravel\\GenerateAppKeySolution', 'parameters' => [],
        ], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ORIGIN' => 'https://attacker.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']));
        echo json_encode(['status' => $response->getStatusCode(), 'changed' => $before !== hash_file('sha256', $directory.'/.env')]);
    } catch (Throwable $error) {
        echo json_encode(['error_class' => get_class($error)]);
        exit(1);
    }
    exit;
}

$defaults = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.example'));
if (($defaults['APP_DEBUG'] ?? null) !== 'false' || ($defaults['IGNITION_ENABLE_RUNNABLE_SOLUTIONS'] ?? null) !== 'false') {
    throw new RuntimeException('Environment template must disable debug and runnable solutions.');
}
$cases = [
    'template defaults' => ['local', 'false', 'false', 404, false],
    'local debug with solutions disabled' => ['local', 'true', 'false', 404, false],
    'production control' => ['production', 'true', 'false', 404, false],
    'isolated vulnerable positive control' => ['local', 'true', 'true', 204, true],
];
foreach ($cases as $name => [$environment, $debug, $runnable, $status, $changed]) {
    $directory = sys_get_temp_dir().'/st-env-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    foreach (['app', 'config', 'routes', 'resources', 'tests', 'vendor'] as $path) {
        symlink($root.'/'.$path, $directory.'/'.$path);
    }
    foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        mkdir($directory.'/'.$path, 0700, true);
    }
    copy($root.'/composer.json', $directory.'/composer.json');
    touch($directory.'/.owned-ignition-probe');
    $values = [
        'APP_ENV' => $environment, 'APP_DEBUG' => $debug, 'IGNITION_ENABLE_RUNNABLE_SOLUTIONS' => $runnable,
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $directory.'/no-config.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DATABASE_URL' => '',
        'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        'SPACETRADERS_FIXTURE_PATH' => '', 'SPACETRADERS_AUTOMATION_ENABLED' => 'false', 'APP_BASE_PATH' => $directory,
    ];
    $contents = '';
    foreach ($values as $key => $value) {
        $contents .= $key.'='.$value."\n";
    }
    file_put_contents($directory.'/.env', $contents);
    chmod($directory.'/.env', 0600);
    $process = proc_open([PHP_BINARY, __FILE__, '--probe', $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/stderr.txt', 'w']], $pipes, $root, array_merge(getenv(), $values));
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $result = json_decode($output, true);
    if ($exit !== 0 || ($result['status'] ?? null) !== $status || ($result['changed'] ?? null) !== $changed) {
        throw new RuntimeException('Isolated environment regression failed: '.$name.'. Inspect its private disposable fixture.');
    }
    echo 'PASS '.$name.': HTTP '.$status.', key changed '.($changed ? 'only in disposable positive control' : 'no')."\n";
}
