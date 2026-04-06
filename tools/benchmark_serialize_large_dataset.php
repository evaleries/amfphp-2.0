<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../Amfphp/ClassLoader.php';
require_once __DIR__ . '/../Tests/TestData/TestService.php';

function parseOptions(array $argv) {
    $options = array(
        'iterations' => 400,
        'warmup' => 40,
        'items' => 1000,
        'label' => 'serialize_large_dataset',
    );

    foreach ($argv as $argument) {
        if (strpos($argument, '--iterations=') === 0) {
            $options['iterations'] = max(1, (int) substr($argument, 13));
        } elseif (strpos($argument, '--warmup=') === 0) {
            $options['warmup'] = max(0, (int) substr($argument, 9));
        } elseif (strpos($argument, '--items=') === 0) {
            $options['items'] = max(1, (int) substr($argument, 8));
        } elseif (strpos($argument, '--label=') === 0) {
            $options['label'] = substr($argument, 8);
        }
    }

    return $options;
}

function percentile(array $values, $percentile) {
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }

    $index = ($count - 1) * $percentile;
    $lower = (int) floor($index);
    $upper = (int) ceil($index);
    if ($lower === $upper) {
        return $values[$lower];
    }

    $weight = $index - $lower;
    return $values[$lower] + (($values[$upper] - $values[$lower]) * $weight);
}

function summarizeDurations(array $durationsNs) {
    sort($durationsNs);
    $count = count($durationsNs);
    $totalNs = array_sum($durationsNs);
    $meanNs = $totalNs / $count;
    $varianceAccumulator = 0.0;

    foreach ($durationsNs as $durationNs) {
        $delta = $durationNs - $meanNs;
        $varianceAccumulator += $delta * $delta;
    }

    return array(
        'iterations' => $count,
        'mean_ms' => round($meanNs / 1000000, 6),
        'median_ms' => round(percentile($durationsNs, 0.5) / 1000000, 6),
        'p95_ms' => round(percentile($durationsNs, 0.95) / 1000000, 6),
        'min_ms' => round($durationsNs[0] / 1000000, 6),
        'max_ms' => round($durationsNs[$count - 1] / 1000000, 6),
        'stddev_ms' => round(sqrt($varianceAccumulator / $count) / 1000000, 6),
        'operations_per_second' => round(1000000000 / $meanNs, 3),
    );
}

$options = parseOptions(array_slice($argv, 1));
$service = new TestService();
$packet = new Amfphp_Core_Amf_Packet();
$packet->headers = array();
$packet->messages = array(
    new Amfphp_Core_Amf_Message('/1/onResult', 'null', $service->returnLargeDataSet($options['items']))
);

$runScenario = function () use ($packet) {
    $serializer = new Amfphp_Core_Amf_Serializer();
    return strlen($serializer->serialize($packet));
};

for ($i = 0; $i < $options['warmup']; $i++) {
    $runScenario();
}

$durationsNs = array();
$responseBytes = 0;
for ($i = 0; $i < $options['iterations']; $i++) {
    $startNs = hrtime(true);
    $responseBytes = $runScenario();
    $durationsNs[] = hrtime(true) - $startNs;
}

$summary = summarizeDurations($durationsNs);
$summary['label'] = $options['label'];
$summary['items'] = $options['items'];
$summary['response_bytes'] = $responseBytes;
$summary['php_version'] = PHP_VERSION;
$summary['timestamp_utc'] = gmdate('c');

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
