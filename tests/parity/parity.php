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
 * parity harness: semantic parity between the pure-php amf codec and the
 * native "amfext" extension (FrankenPHP/Go codec).
 *
 * For every corpus value (tests/parity/corpus.php) x amf version, three directions:
 *   A: amfphp-encode  -> amfext_decode   (cross)
 *   B: amfext_encode  -> amfphp-decode   (cross)
 *   C: amfphp-encode  -> amfphp-decode   (baseline self-check)
 * plus whole-packet legs:
 *   PA: php Serializer        -> amfext_deserialize_packet
 *   PB: amfext_serialize_packet -> php Deserializer (only leg where amfVersion survives;
 *       the php serializer always writes the version word as 00 00, see SPEC-packet D1)
 *   PC: php Serializer        -> php Deserializer (baseline)
 *
 * Byte-level equality is NOT checked (the Go codec deliberately fixes some amfphp
 * byte-level bugs); semantic equality after crossing IS. See
 * docs/SPEC-php-adapter.md §7-8 in the amf3-ext repo for the equality rules.
 *
 * usage: php parity.php
 * exit code: 0 = no failures (xfails allowed), 1 = at least one failure, 2 = misuse.
 * If the extension is absent, runs baseline mode (directions C and PC only) and says so.
 *
 * @package Tests
 */

if (php_sapi_name() != 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(2);
}
error_reporting(E_ALL);
ini_set('display_errors', 'stderr'); //keep the report table readable; the known-xfail entry emits warnings

require_once dirname(__FILE__) . '/../../Amfphp/ClassLoader.php';
require_once dirname(__FILE__) . '/corpus.php';

/* * *****************************************************************************
 * value-level access to the php codec (same wrapper pattern as Tests/Amfphp/Core/Amf)
 * ***************************************************************************** */

/**
 * exposes Amfphp_Core_Amf_Serializer value-level writing.
 * note: for amf3 the raw value is written WITHOUT the 0x11 packet-level escape marker,
 * because amfext_decode expects a bare amf3 value.
 */
class AmfphpParitySerializer extends Amfphp_Core_Amf_Serializer {

    /**
     * encode one value with the php codec
     * @param mixed $value
     * @param int $version 0 or 3
     * @return string binary
     */
    public function encodeValue($value, $version) {
        $this->packet = new Amfphp_Core_Amf_Packet();
        $this->packet->amfVersion = $version;
        $this->outBuffer = '';
        $this->resetReferences();
        if ($version == Amfphp_Core_Amf_Constants::AMF3_ENCODING) {
            $this->writeAmf3DataPublic($value);
        } else {
            $this->writeDataPublic($value);
        }
        return $this->outBuffer;
    }

    /**
     * write data (amf0 mode)
     * @param mixed $d
     */
    public function writeDataPublic($d) {
        parent::writeData($d);
    }

    /**
     * write amf3 data
     * @param mixed $d
     */
    public function writeAmf3DataPublic($d) {
        parent::writeAmf3Data($d);
    }

}

/**
 * exposes Amfphp_Core_Amf_Deserializer value-level reading
 */
class AmfphpParityDeserializer extends Amfphp_Core_Amf_Deserializer {

    /**
     * decode one value with the php codec
     * @param string $raw binary
     * @param int $version 0 or 3
     * @return mixed
     */
    public function decodeValue($raw, $version) {
        $this->rawData = $raw;
        $this->currentByte = 0;
        $this->resetReferences();
        if ($version == Amfphp_Core_Amf_Constants::AMF3_ENCODING) {
            return $this->readAmf3Data(); //reads the type marker itself
        }
        return $this->readData($this->readBytePublic());
    }

    /**
     * read byte
     * @return int
     */
    public function readBytePublic() {
        return parent::readByte();
    }

}

/* * *****************************************************************************
 * php codec helpers
 * ***************************************************************************** */

/**
 * encode a value with the pure-php codec. The value is deep-copied first, because the
 * amf0 typed-object writer unsets the explicit-type field on its input (Serializer.php:412)
 * @param mixed $value
 * @param int $version
 * @return string
 */
function amf_parity_php_encode($value, $version) {
    $work = unserialize(serialize($value));
    $serializer = new AmfphpParitySerializer();
    return $serializer->encodeValue($work, $version);
}

/**
 * decode a value with the pure-php codec
 * @param string $bin
 * @param int $version
 * @return mixed
 */
function amf_parity_php_decode($bin, $version) {
    $deserializer = new AmfphpParityDeserializer();
    return $deserializer->decodeValue($bin, $version);
}

/* * *****************************************************************************
 * semantic equality (see docs/SPEC-php-adapter.md §7 for the normalization rules)
 * ***************************************************************************** */

/**
 * normalize xml the way the php serializer does (lossy, so both sides are normalized)
 * @param string $s
 * @return string
 */
function amf_parity_normalize_xml($s) {
    return preg_replace('/\>(\n|\r|\r\n| |\t)*\</', '><', trim($s));
}

/**
 * terminal kind of a value, or null if it is a composite (array / stdClass / Vector)
 * @param mixed $v
 * @return string|null
 */
function amf_parity_terminal_kind($v) {
    if (is_null($v)) {
        return 'null';
    }
    if (is_bool($v)) {
        return 'bool';
    }
    if (is_int($v) || is_float($v)) {
        //amf0 sends all numbers as doubles; amf3 ints outside +-2^28 become doubles too.
        //int and float are therefore ONE numeric kind, compared as float
        return 'num';
    }
    if (is_string($v)) {
        return 'str';
    }
    if ($v instanceof Amfphp_Core_Amf_Types_Undefined) {
        return 'undef';
    }
    if ($v instanceof Amfphp_Core_Amf_Types_Date) {
        return 'date';
    }
    if ($v instanceof Amfphp_Core_Amf_Types_ByteArray) {
        return 'ba';
    }
    if ($v instanceof Amfphp_Core_Amf_Types_XmlDocument) {
        return 'xmldoc';
    }
    if ($v instanceof Amfphp_Core_Amf_Types_Xml) {
        return 'xml';
    }
    return null; //arrays, stdClass, Vector, other objects: composites
}

/**
 * collection kind: 'list' for dense arrays (keys 0..n-1), 'map' for everything else
 * (assoc/mixed/sparse arrays, all objects). The empty array is an empty map, so that
 * it compares equal to an empty anonymous object: AMF has no assoc-array type, and
 * php assoc arrays cross the wire as objects (see SPEC §7)
 * @param mixed $v
 * @return string
 */
function amf_parity_collection_kind($v) {
    if (is_array($v) && count($v) > 0) {
        $i = 0;
        foreach (array_keys($v) as $k) {
            if ($k !== $i++) {
                return 'map';
            }
        }
        return 'list';
    }
    return 'map';
}

/**
 * properties of a composite as string-keyed array
 * @param mixed $v array or object
 * @return array
 */
function amf_parity_props($v) {
    $raw = is_array($v) ? $v : get_object_vars($v);
    $out = array();
    foreach ($raw as $k => $val) {
        $out[(string) $k] = $val;
    }
    return $out;
}

/**
 * compact value dump for failure messages (print_r is recursion-safe)
 * @param mixed $v
 * @return string
 */
function amf_parity_dump($v) {
    return substr(str_replace(array("\n", "\r"), ' ', print_r($v, true)), 0, 200);
}

/**
 * semantic equality between an expected and an actual value.
 * Object graphs are compared by dual descent with identity pairing: when both sides are
 * objects, the expected object's hash is bound to the actual object's hash on first
 * encounter; re-encounters must hit the same pair. This verifies aliasing (shared
 * sub-objects) and cycles structurally, not just by value.
 *
 * @param mixed $e expected
 * @param mixed $a actual
 * @param array $pairs spl_object_hash(expected) => spl_object_hash(actual)
 * @param string $path for error messages
 * @param string $msg failure detail (out)
 * @return bool
 */
function amf_parity_eq($e, $a, &$pairs, $path, &$msg) {
    $te = amf_parity_terminal_kind($e);
    $ta = amf_parity_terminal_kind($a);
    if ($te !== null || $ta !== null) {
        if ($te !== $ta) {
            $msg = $path . ': kind mismatch, expected [' . $te . '] ' . amf_parity_dump($e) . ', got [' . $ta . '] ' . amf_parity_dump($a);
            return false;
        }
        switch ($te) {
            case 'null':
            case 'undef':
                return true;
            case 'bool':
                if ($e === $a) {
                    return true;
                }
                $msg = $path . ': bool mismatch, expected ' . var_export($e, true) . ', got ' . var_export($a, true);
                return false;
            case 'num':
                $fe = (float) $e;
                $fa = (float) $a;
                if (is_nan($fe) || is_nan($fa)) {
                    if (is_nan($fe) && is_nan($fa)) {
                        return true;
                    }
                    $msg = $path . ': number mismatch (NaN on one side)';
                    return false;
                }
                if ($fe === $fa) {
                    return true;
                }
                $msg = $path . ': number mismatch, expected ' . var_export($e, true) . ', got ' . var_export($a, true);
                return false;
            case 'str':
                if ($e === $a) {
                    return true;
                }
                $msg = $path . ': string mismatch, expected (' . strlen($e) . ' bytes) "' . substr($e, 0, 80) . '", got (' . strlen($a) . ' bytes) "' . substr($a, 0, 80) . '"';
                return false;
            case 'date':
                if ((float) $e->timeStamp === (float) $a->timeStamp) {
                    return true;
                }
                $msg = $path . ': Date mismatch, expected ' . var_export($e->timeStamp, true) . ', got ' . var_export($a->timeStamp, true);
                return false;
            case 'ba':
                if ($e->data === $a->data) {
                    return true;
                }
                $msg = $path . ': ByteArray mismatch (' . strlen($e->data) . ' vs ' . strlen($a->data) . ' bytes)';
                return false;
            case 'xml':
            case 'xmldoc':
                if (amf_parity_normalize_xml($e->data) === amf_parity_normalize_xml($a->data)) {
                    return true;
                }
                $msg = $path . ': ' . $te . ' mismatch after whitespace normalization';
                return false;
        }
    }

    //vectors are composites with extra header fields
    $ve = $e instanceof Amfphp_Core_Amf_Types_Vector;
    $va = $a instanceof Amfphp_Core_Amf_Types_Vector;
    if ($ve || $va) {
        if (!$ve || !$va) {
            $msg = $path . ': expected a Vector on one side only (' . gettype($e) . ' vs ' . gettype($a) . ')';
            return false;
        }
        if ((int) $e->type !== (int) $a->type) {
            $msg = $path . ': Vector type mismatch, expected ' . $e->type . ', got ' . $a->type;
            return false;
        }
        if ((bool) $e->fixed !== (bool) $a->fixed) {
            $msg = $path . ': Vector fixed flag mismatch';
            return false;
        }
        if ((string) $e->className !== (string) $a->className) {
            $msg = $path . ': Vector className mismatch, expected "' . $e->className . '", got "' . $a->className . '"';
            return false;
        }
        if (count($e->data) !== count($a->data)) {
            $msg = $path . ': Vector length mismatch';
            return false;
        }
        $num = count($e->data);
        for ($i = 0; $i < $num; $i++) {
            if (!amf_parity_eq($e->data[$i], $a->data[$i], $pairs, $path . '[' . $i . ']', $msg)) {
                return false;
            }
        }
        return true;
    }

    //remaining composites: arrays and objects (stdClass or typed)
    if ((is_array($e) || is_object($e)) && (is_array($a) || is_object($a))) {
        if (is_object($e) && is_object($a)) {
            $ide = spl_object_hash($e);
            $ida = spl_object_hash($a);
            if (isset($pairs[$ide])) {
                if ($pairs[$ide] === $ida) {
                    return true; //already compared (or cycle): aliasing matches
                }
                $msg = $path . ': aliasing mismatch (a shared/referenced object maps to different objects)';
                return false;
            }
            $pairs[$ide] = $ida;
        }
        $ke = amf_parity_collection_kind($e);
        $ka = amf_parity_collection_kind($a);
        if ($ke !== $ka) {
            $msg = $path . ': collection kind mismatch, expected ' . $ke . ', got ' . $ka . ' (' . amf_parity_dump($a) . ')';
            return false;
        }
        if ($ke === 'list') {
            if (count($e) !== count($a)) {
                $msg = $path . ': list length mismatch, expected ' . count($e) . ', got ' . count($a);
                return false;
            }
            $num = count($e);
            for ($i = 0; $i < $num; $i++) {
                if (!amf_parity_eq($e[$i], $a[$i], $pairs, $path . '[' . $i . ']', $msg)) {
                    return false;
                }
            }
            return true;
        }
        $pe = amf_parity_props($e);
        $pa = amf_parity_props($a);
        ksort($pe, SORT_STRING);
        ksort($pa, SORT_STRING);
        $keysE = array_keys($pe);
        $keysA = array_keys($pa);
        if ($keysE !== $keysA) {
            $msg = $path . ': property keys differ, expected [' . implode(',', $keysE) . '], got [' . implode(',', $keysA) . ']';
            return false;
        }
        foreach ($pe as $k => $v) {
            if (!amf_parity_eq($v, $pa[$k], $pairs, $path . '->' . $k, $msg)) {
                return false;
            }
        }
        return true;
    }

    $msg = $path . ': incomparable values (' . gettype($e) . ' vs ' . gettype($a) . ')';
    return false;
}

/* * *****************************************************************************
 * packet-level parity
 * ***************************************************************************** */

/**
 * @return array of packet entries ('name', 'make' closure returning a fresh Amfphp_Core_Amf_Packet)
 */
function amf_parity_make_packets() {
    return array(
        array('name' => 'v0 two messages', 'make' => function () {
                $p = new Amfphp_Core_Amf_Packet();
                $p->amfVersion = Amfphp_Core_Amf_Constants::AMF0_ENCODING;
                $p->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/1', array('hello world'));
                $p->messages[] = new Amfphp_Core_Amf_Message('ExampleService.sum', '/2', array(1, 2, 3));
                return $p;
            }),
        array('name' => 'v0 headers + mixed body', 'make' => function () {
                $p = new Amfphp_Core_Amf_Packet();
                $p->amfVersion = Amfphp_Core_Amf_Constants::AMF0_ENCODING;
                $p->headers[] = new Amfphp_Core_Amf_Header('sessionId', true, 'sess-é-123');
                $p->headers[] = new Amfphp_Core_Amf_Header('debugFlag', false, true);
                $p->messages[] = new Amfphp_Core_Amf_Message('ExampleService.echo', '/1', array('a' => 1, 'b' => array(1, 2)));
                return $p;
            }),
        array('name' => 'v3 typed param + header', 'make' => function () {
                $p = new Amfphp_Core_Amf_Packet();
                $p->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
                $p->headers[] = new Amfphp_Core_Amf_Header('authToken', true, 'tok-' . amf_parity_token(1));
                $p->messages[] = new Amfphp_Core_Amf_Message('game.CharacterService.save', '/1', array(amf_parity_make_character(3, true)));
                return $p;
            }),
        array('name' => 'v3 bytearray/date/assoc + two messages', 'make' => function () {
                $p = new Amfphp_Core_Amf_Packet();
                $p->amfVersion = Amfphp_Core_Amf_Constants::AMF3_ENCODING;
                $data = new stdClass();
                $data->payload = new Amfphp_Core_Amf_Types_ByteArray("\x00\x01\x02binary\xff\x00");
                $data->takenAt = new Amfphp_Core_Amf_Types_Date(1609459200000);
                $data->attributes = array('sector' => 'north', 'slot' => 3, 'tags' => array('alpha', 'beta'));
                $p->messages[] = new Amfphp_Core_Amf_Message('ExampleService.upload', '/1', array($data));
                $p->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/2', array('second message'));
                return $p;
            }),
    );
}

/**
 * semantic equality between two packets
 * @param Amfphp_Core_Amf_Packet $e expected
 * @param mixed $a actual
 * @param bool $checkVersion false on legs through the php serializer, which always
 *        writes the version word as 00 00 (SPEC-packet D1), so a v3 packet reads back as 0
 * @param string $msg failure detail (out)
 * @return bool
 */
function amf_parity_packet_eq($e, $a, $checkVersion, &$msg) {
    if (!($a instanceof Amfphp_Core_Amf_Packet)) {
        $msg = 'not an Amfphp_Core_Amf_Packet: ' . (is_object($a) ? get_class($a) : gettype($a));
        return false;
    }
    if ($checkVersion && (int) $e->amfVersion !== (int) $a->amfVersion) {
        $msg = 'amfVersion mismatch, expected ' . $e->amfVersion . ', got ' . $a->amfVersion;
        return false;
    }
    if (count($e->headers) !== count($a->headers)) {
        $msg = 'header count mismatch, expected ' . count($e->headers) . ', got ' . count($a->headers);
        return false;
    }
    $num = count($e->headers);
    for ($i = 0; $i < $num; $i++) {
        $he = $e->headers[$i];
        $ha = $a->headers[$i];
        if ($he->name !== $ha->name) {
            $msg = 'header ' . $i . ' name mismatch, expected "' . $he->name . '", got "' . $ha->name . '"';
            return false;
        }
        if ((bool) $he->required !== (bool) $ha->required) {
            $msg = 'header "' . $he->name . '" required flag mismatch';
            return false;
        }
        $pairs = array();
        if (!amf_parity_eq($he->data, $ha->data, $pairs, 'header[' . $he->name . ']', $msg)) {
            return false;
        }
    }
    if (count($e->messages) !== count($a->messages)) {
        $msg = 'message count mismatch, expected ' . count($e->messages) . ', got ' . count($a->messages);
        return false;
    }
    $num = count($e->messages);
    for ($i = 0; $i < $num; $i++) {
        $me = $e->messages[$i];
        $ma = $a->messages[$i];
        if ($me->targetUri !== $ma->targetUri) {
            $msg = 'message ' . $i . ' targetUri mismatch, expected "' . $me->targetUri . '", got "' . $ma->targetUri . '"';
            return false;
        }
        if ($me->responseUri !== $ma->responseUri) {
            $msg = 'message ' . $i . ' responseUri mismatch, expected "' . $me->responseUri . '", got "' . $ma->responseUri . '"';
            return false;
        }
        $pairs = array();
        if (!amf_parity_eq($me->data, $ma->data, $pairs, 'message[' . $me->targetUri . '].data', $msg)) {
            return false;
        }
    }
    return true;
}

/* * *****************************************************************************
 * runner
 * ***************************************************************************** */

$extAvailable = extension_loaded('amfext')
        && function_exists('amfext_encode') && function_exists('amfext_decode')
        && function_exists('amfext_serialize_packet') && function_exists('amfext_deserialize_packet');

echo "=== amfext parity harness ===\n";
echo 'AMFEXT: ' . ($extAvailable ? 'loaded — full cross-parity mode' : 'NOT LOADED — baseline mode (php self-check only; directions A/B, PA/PB skipped)') . "\n\n";

$failures = array();
$notes = array();
$counts = array('PASS' => 0, 'FAIL' => 0, 'SKIP' => 0, 'XFAIL' => 0, 'XPASS' => 0);

/**
 * record one check result
 */
function amf_parity_record(&$counts, &$failures, &$notes, $context, $ok, $msg, $xfailReason) {
    if ($ok === null) { //exception/error
        $ok = false;
        $msg = $msg === '' ? 'unknown error' : $msg;
    }
    if ($xfailReason !== null) {
        if ($ok) {
            $counts['XPASS'] ++;
            $notes[] = 'XPASS (expected to fail, passed — investigate): ' . $context . ' [' . $xfailReason . ']';
            return 'XPASS';
        }
        $counts['XFAIL'] ++;
        $notes[] = 'XFAIL: ' . $context . ' — ' . $xfailReason . ($msg ? ' (' . $msg . ')' : '');
        return 'XFAIL';
    }
    if ($ok) {
        $counts['PASS'] ++;
        return 'PASS';
    }
    $counts['FAIL'] ++;
    $failures[] = 'FAIL: ' . $context . ($msg ? ' — ' . $msg : '');
    return 'FAIL';
}

// ---- value-level ----
echo "VALUE PARITY  (A: amfphp>amfext  B: amfext>amfphp  C: amfphp>amfphp baseline)\n";
echo str_pad('entry', 62) . str_pad('ver', 5) . str_pad('A', 8) . str_pad('B', 8) . str_pad('C', 8) . "\n";
echo str_repeat('-', 91) . "\n";

$valueDirections = array('A', 'B', 'C');
foreach (amf_parity_corpus() as $entry) {
    foreach ($entry['versions'] as $version) {
        $cells = array();
        foreach ($valueDirections as $dir) {
            $context = "'" . $entry['name'] . "' amf" . $version . ' dir ' . $dir;
            $xfailReason = isset($entry['xfail'][$dir]) ? $entry['xfail'][$dir] : null;
            if (($dir === 'A' || $dir === 'B') && !$extAvailable) {
                $counts['SKIP'] ++;
                $cells[$dir] = 'SKIP';
                continue;
            }
            $value = call_user_func($entry['make']);
            $ok = null;
            $msg = '';
            try {
                if ($dir === 'A') {
                    $bin = amf_parity_php_encode($value, $version);
                    $out = amfext_decode($bin, $version);
                } elseif ($dir === 'B') {
                    $bin = amfext_encode($value, $version);
                    $out = amf_parity_php_decode($bin, $version);
                } else {
                    $bin = amf_parity_php_encode($value, $version);
                    $out = amf_parity_php_decode($bin, $version);
                }
                $pairs = array();
                $ok = amf_parity_eq($value, $out, $pairs, '$', $msg);
            } catch (Exception $ex) {
                $ok = null;
                $msg = 'exception ' . get_class($ex) . ': ' . $ex->getMessage();
            } catch (Throwable $ex) {
                $ok = null;
                $msg = 'error ' . get_class($ex) . ': ' . $ex->getMessage();
            }
            $cells[$dir] = amf_parity_record($counts, $failures, $notes, $context, $ok, $msg, $xfailReason);
        }
        echo str_pad(substr($entry['name'], 0, 60), 62) . str_pad('amf' . $version, 5)
        . str_pad($cells['A'], 8) . str_pad($cells['B'], 8) . str_pad($cells['C'], 8) . "\n";
    }
}

// ---- packet-level ----
echo "\nPACKET PARITY  (PA: php>amfext  PB: amfext>php, amfVersion checked  PC: php>php baseline)\n";
echo str_pad('packet', 62) . str_pad('PA', 8) . str_pad('PB', 8) . str_pad('PC', 8) . "\n";
echo str_repeat('-', 86) . "\n";

$packetDirections = array(
    //the php serializer always writes the version word as 00 00 (SPEC-packet D1), so the
    //version can only be checked on PB, where amfext writes the honest version word
    'PA' => false,
    'PB' => true,
    'PC' => false,
);
foreach (amf_parity_make_packets() as $entry) {
    $cells = array();
    foreach ($packetDirections as $dir => $checkVersion) {
        $context = "packet '" . $entry['name'] . "' dir " . $dir;
        if (($dir === 'PA' || $dir === 'PB') && !$extAvailable) {
            $counts['SKIP'] ++;
            $cells[$dir] = 'SKIP';
            continue;
        }
        $packet = call_user_func($entry['make']);
        $ok = null;
        $msg = '';
        try {
            if ($dir === 'PA') {
                $work = unserialize(serialize($packet));
                $bin = (new Amfphp_Core_Amf_Serializer())->serialize($work);
                $out = amfext_deserialize_packet($bin);
            } elseif ($dir === 'PB') {
                $bin = amfext_serialize_packet($packet);
                $out = (new Amfphp_Core_Amf_Deserializer())->deserialize(array(), array(), $bin);
            } else {
                $work = unserialize(serialize($packet));
                $bin = (new Amfphp_Core_Amf_Serializer())->serialize($work);
                $out = (new Amfphp_Core_Amf_Deserializer())->deserialize(array(), array(), $bin);
            }
            $ok = amf_parity_packet_eq($packet, $out, $checkVersion, $msg);
        } catch (Exception $ex) {
            $ok = null;
            $msg = 'exception ' . get_class($ex) . ': ' . $ex->getMessage();
        } catch (Throwable $ex) {
            $ok = null;
            $msg = 'error ' . get_class($ex) . ': ' . $ex->getMessage();
        }
        $cells[$dir] = amf_parity_record($counts, $failures, $notes, $context, $ok, $msg, null);
    }
    echo str_pad(substr($entry['name'], 0, 60), 62) . str_pad($cells['PA'], 8) . str_pad($cells['PB'], 8) . str_pad($cells['PC'], 8) . "\n";
}

// ---- report ----
if (count($notes) > 0) {
    echo "\nNOTES:\n";
    foreach ($notes as $note) {
        echo '  ' . $note . "\n";
    }
}
if (count($failures) > 0) {
    echo "\nFAILURES:\n";
    foreach ($failures as $failure) {
        echo '  ' . $failure . "\n";
    }
}
echo "\nSUMMARY: " . $counts['PASS'] . ' pass, ' . $counts['FAIL'] . ' fail, '
. $counts['XFAIL'] . ' xfail, ' . $counts['XPASS'] . ' xpass, ' . $counts['SKIP'] . ' skip' . "\n";

if ($counts['FAIL'] > 0) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: OK" . ($extAvailable ? '' : ' (baseline only — extension not loaded)') . "\n";
exit(0);

?>
