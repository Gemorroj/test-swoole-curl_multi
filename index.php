<?php

declare(strict_types=1);

use Swoole\Coroutine\WaitGroup;
use Swoole\Coroutine as Co;
use function Swoole\Coroutine\batch;

$http = new Swoole\Http\Server('0.0.0.0', 8000);
$http->set(['hook_flags' => SWOOLE_HOOK_ALL]);

function curlRequest(int $delay): ?array
{
    // create both cURL resources
    $ch1 = curl_init();

// set URL and other appropriate options
    curl_setopt($ch1, CURLOPT_URL, 'https://httpbin.org/delay/'.$delay);
    curl_setopt($ch1, CURLOPT_HEADER, false);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Accept: application/json']);

//create the multiple cURL handle
    $mh = curl_multi_init();

//add the two handles
    curl_multi_add_handle($mh, $ch1);

//execute the multi handle
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    $data = curl_multi_getcontent($ch1);

//close all the handles
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_close($mh);

    return json_decode($data, true);
}


$http->on('request', static function ($request, $response) {

    \Swoole\Runtime::enableCoroutine(true, \SWOOLE_HOOK_ALL);
    $wg = new WaitGroup();

    $countRequests = 10;
    $delay = 1;
    $startTime = microtime(true);
    $results = [];
    for ($i = 0; $i < 10; ++$i) {
        go(function () use ($wg, $delay, &$results) {
            $wg->add();
            $results[] = curlRequest($delay);
            $wg->done();
        });
    }
    $wg->wait(60);
    $time = microtime(true) - $startTime;

    $response->header('Content-Type', 'application/json');
    $response->end(\json_encode([
        'expected_time' => 'must be less than '.($countRequests * $delay).'sec.',
        'actual_time' => \round($time, 2),
        'results' => $results,
    ]));
});

$http->start();
