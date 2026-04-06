<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../Amfphp/ClassLoader.php';
require_once __DIR__ . '/../Tests/TestData/AmfTestData.php';
require_once __DIR__ . '/../Tests/TestData/TestServicesConfig.php';

function parseOptions(array $argv) {
    $options = array(
        'iterations' => 1500,
        'warmup' => 150,
        'label' => 'gateway_test_service',
    );

    foreach ($argv as $argument) {
        if (strpos($argument, '--iterations=') === 0) {
            $options['iterations'] = max(1, (int) substr($argument, 13));
        } elseif (strpos($argument, '--warmup=') === 0) {
            $options['warmup'] = max(0, (int) substr($argument, 9));
        } elseif (strpos($argument, '--label=') === 0) {
            $options['label'] = substr($argument, 8);
        }
    }

    return $options;
}

function setStaticProperty($className, $propertyName, $value) {
    if (!class_exists($className, false)) {
        return;
    }

    $property = new ReflectionProperty($className, $propertyName);
    if (PHP_VERSION_ID < 80100) {
        $property->setAccessible(true);
    }
    $property->setValue(null, $value);
}

function resetGatewayState() {
    setStaticProperty('Amfphp_Core_FilterManager', 'instance', null);
    setStaticProperty('Amfphp_Core_PluginManager', 'instance', null);
}

function buildBenchmarkConfig() {
    $config = new TestServicesConfig();
    $config->pluginsFolders = array_values(array_filter($config->pluginsFolders, 'is_dir'));
    $config->disabledPlugins[] = 'AmfphpMonitor';
    $config->disabledPlugins[] = 'AmfphpLogger';
    $config->disabledPlugins = array_values(array_unique($config->disabledPlugins));

    return $config;
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
        'requests_per_second' => round(1000000000 / $meanNs, 3),
    );
}

$options = parseOptions(array_slice($argv, 1));
$testData = new AmfTestData();
$expectedResponse = bin2hex($testData->testServiceResponsePacket);

$runScenario = function () use ($testData, $expectedResponse) {
    resetGatewayState();
    $config = buildBenchmarkConfig();
    $gateway = new Amfphp_Core_Gateway(
        array(),
        array(),
        $testData->testServiceRequestPacket,
        Amfphp_Core_Amf_Constants::CONTENT_TYPE,
        $config
    );
    $response = $gateway->service();
    if (bin2hex($response) !== $expectedResponse) {
        throw new RuntimeException('Gateway benchmark response did not match the expected packet');
    }

    return strlen($response);
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
$summary['response_bytes'] = $responseBytes;
$summary['php_version'] = PHP_VERSION;
$summary['timestamp_utc'] = gmdate('c');

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
