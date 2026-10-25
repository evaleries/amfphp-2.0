<?php

/**
 *  This file is part of amfPHP
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file license.txt.
 */

/**
 * wall-clock benchmark: pure-php amf codec vs the native "amfext" extension.
 * Serialize and deserialize are measured separately, on whole AMF3 packets
 * (one message, realistic game-response payload), for 3 payload sizes.
 *
 * usage: php bench.php [multiplier]   (multiplier scales the iteration counts, default 1)
 * If the extension is absent, runs the php baseline only and says so.
 *
 * @package Tests
 */

if (php_sapi_name() != 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(2);
}
error_reporting(E_ALL);

require_once dirname(__FILE__) . '/../../Amfphp/ClassLoader.php';
require_once dirname(__FILE__) . '/corpus.php';

$multiplier = isset($argv[1]) ? floatval($argv[1]) : 1.0;
if ($multiplier <= 0) {
    $multiplier = 1.0;
}

$extAvailable = extension_loaded('amfext')
        && function_exists('amfext_serialize_packet') && function_exists('amfext_deserialize_packet');

echo "=== amfext benchmark ===\n";
echo 'AMFEXT: ' . ($extAvailable ? 'loaded' : 'NOT LOADED — php baseline only') . ', iteration multiplier: ' . $multiplier . "\n\n";

$payloads = array(
    array('label' => 'small: character (~1KB)', 'n' => 1000, 'make' => function () { return amf_parity_make_character(7, true); }),
    array('label' => 'medium: 40-char roster (~25KB)', 'n' => 200, 'make' => function () { return amf_parity_make_roster(40, false); }),
    array('label' => 'large: 400-char roster (~200KB)', 'n' => 20, 'make' => function () { return amf_parity_make_roster(400, true); }),
);

/**
 * time $n iterations of $fn, in seconds
 * @param int $n
 * @param Closure $fn
 * @return float
 */
function amf_bench_time($n, $fn) {
    $t0 = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    return microtime(true) - $t0;
}

/**
 * report one timing row
 */
function amf_bench_row($label, $seconds, $n) {
    $ms = $seconds * 1000;
    $perOp = $n > 0 ? $ms / $n : 0;
    $opsPerMs = $seconds > 0 ? $n / $ms : 0;
    echo '  ' . str_pad($label, 12) . str_pad(number_format($ms, 2) . ' ms', 14)
    . str_pad(number_format($perOp, 4) . ' ms/op', 16) . number_format($opsPerMs, 2) . " ops/ms\n";
}

foreach ($payloads as $conf) {
    $n = max(1, (int) round($conf['n'] * $multiplier));

    $packet = new Amfphp_Core_Amf_Packet();
    $packet->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
    $packet->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/1', array(call_user_func($conf['make'])));

    //one shared input blob for both deserializers (parity between codecs is checked by parity.php)
    $bin = $extAvailable ? amfext_serialize_packet($packet) : (new Amfphp_Core_Amf_Serializer())->serialize($packet);
    $size = strlen($bin);

    //sanity: the blob decodes to a packet with 1 message in both codecs
    $checkPhp = (new Amfphp_Core_Amf_Deserializer())->deserialize(array(), array(), $bin);
    if (count($checkPhp->messages) !== 1) {
        echo "SANITY FAILURE: php decode yielded " . count($checkPhp->messages) . " messages\n";
        exit(1);
    }
    if ($extAvailable) {
        $checkExt = amfext_deserialize_packet($bin);
        if (count($checkExt->messages) !== 1) {
            echo "SANITY FAILURE: amfext decode yielded " . count($checkExt->messages) . " messages\n";
            exit(1);
        }
    }

    echo $conf['label'] . '  [' . $size . ' bytes, N=' . $n . "]\n";

    //warmup
    for ($i = 0; $i < 3; $i++) {
        (new Amfphp_Core_Amf_Serializer())->serialize($packet);
        (new Amfphp_Core_Amf_Deserializer())->deserialize(array(), array(), $bin);
    }

    $phpSer = amf_bench_time($n, function () use ($packet) {
        (new Amfphp_Core_Amf_Serializer())->serialize($packet);
    });
    amf_bench_row('php ser', $phpSer, $n);
    if ($extAvailable) {
        $extSer = amf_bench_time($n, function () use ($packet) {
            amfext_serialize_packet($packet);
        });
        amf_bench_row('amfext ser', $extSer, $n);
        echo '  serialize speedup: ' . number_format($phpSer / max($extSer, 1e-9), 2) . "x\n";
    }

    $phpDeser = amf_bench_time($n, function () use ($bin) {
        (new Amfphp_Core_Amf_Deserializer())->deserialize(array(), array(), $bin);
    });
    amf_bench_row('php deser', $phpDeser, $n);
    if ($extAvailable) {
        $extDeser = amf_bench_time($n, function () use ($bin) {
            amfext_deserialize_packet($bin);
        });
        amf_bench_row('amfext deser', $extDeser, $n);
        echo '  deserialize speedup: ' . number_format($phpDeser / max($extDeser, 1e-9), 2) . "x\n";
    }
    echo "\n";
}

echo "done\n";
exit(0);

?>
