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
 * fixture generator for the FrankenPHP E2E tests (amf3-ext repo).
 * Writes binary AMF request packets produced by the PURE-PHP serializer
 * (no amfext needed), so the E2E tests can verify the native path against
 * known-good php output.
 *
 * Fixtures (target dir: /home/ubuntu/workspace/ns/amf3-ext/frankenphp/tests/fixtures/):
 *   a) request_v0_string_param.amf      v0 AMF0 packet, one message
 *                                       ExampleService.returnOneParam('hello world')
 *   b) request_v3_typed_param_header.amf v3 packet (AMF3 bodies, version word 00 03),
 *                                       typed-object param (_explicitType) + a header
 *   c) request_v3_mixed_types.amf       v3 packet, message data carries a ByteArray,
 *                                       a Date and a nested assoc array
 *
 * note: the php serializer always writes the packet version word as 00 00
 * (Serializer.php:79). Real AMF3 clients send 00 03, so for the v3 fixtures the
 * version word is patched after serialization. This is legitimate: the bodies are
 * already 0x11-wrapped AMF3, only the envelope word is aligned with the client layout.
 *
 * usage: php make_fixtures.php
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

$outDir = '/home/ubuntu/workspace/ns/amf3-ext/frankenphp/tests/fixtures';
if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0755, true)) {
        fwrite(STDERR, "could not create $outDir\n");
        exit(1);
    }
}

/**
 * serialize a packet with the pure-php codec and write it to a fixture file
 * @param string $outDir
 * @param string $name file name
 * @param Amfphp_Core_Amf_Packet $packet
 * @param bool $patchVersion3 patch the version word to 00 03 (see file docblock)
 */
function amf_fixture_write($outDir, $name, $packet, $patchVersion3) {
    $serializer = new Amfphp_Core_Amf_Serializer();
    $bin = $serializer->serialize($packet);
    if ($patchVersion3) {
        $bin[0] = "\x00";
        $bin[1] = "\x03";
    }
    $path = $outDir . '/' . $name;
    if (file_put_contents($path, $bin) === false) {
        fwrite(STDERR, "could not write $path\n");
        exit(1);
    }
    $size = strlen($bin);
    $headLen = min($size, 96);
    echo "$name: $size bytes\n";
    echo '  head: ' . trim(chunk_split(bin2hex(substr($bin, 0, $headLen)), 2, ' ')) . ($size > $headLen ? ' …' : '') . "\n";
    if ($size <= 512) {
        echo '  full: ' . trim(chunk_split(bin2hex($bin), 2, ' ')) . "\n";
    }
}

//(a) v0 AMF0 packet, one message ExampleService.returnOneParam with a string param
$packetA = new Amfphp_Core_Amf_Packet();
$packetA->amfVersion = Amfphp_Core_Amf_Constants::AMF0_ENCODING;
$packetA->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/1', array('hello world'));
amf_fixture_write($outDir, 'request_v0_string_param.amf', $packetA, false);

//(b) v3 packet: typed-object param (_explicitType) + a header
$packetB = new Amfphp_Core_Amf_Packet();
$packetB->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
$packetB->headers[] = new Amfphp_Core_Amf_Header('authToken', true, 'tok-fixture-01');
$packetB->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/1', array(amf_parity_make_character(3, true)));
amf_fixture_write($outDir, 'request_v3_typed_param_header.amf', $packetB, true);

//(c) v3 packet: message data contains a ByteArray + Date + nested assoc array
$data = new stdClass();
$data->payload = new Amfphp_Core_Amf_Types_ByteArray("\x00\x01\x02" . md5('fixture-c', true) . "\xff\x00\x7f");
$data->takenAt = new Amfphp_Core_Amf_Types_Date(1609459200000);
$data->attributes = array(
    'sector' => 'north',
    'slot' => 3,
    'tags' => array('alpha', 'beta'),
    'nested' => array('deep' => array('x' => 1, 'y' => 2.5)),
);
$packetC = new Amfphp_Core_Amf_Packet();
$packetC->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
$packetC->messages[] = new Amfphp_Core_Amf_Message('ExampleService.upload', '/1', array($data));
amf_fixture_write($outDir, 'request_v3_mixed_types.amf', $packetC, true);

echo "fixtures written to $outDir\n";
exit(0);

?>
