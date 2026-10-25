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
 * shared value corpus for the amfext parity harness (plain PHP CLI, no framework).
 *
 * amf_parity_corpus() returns an array of entries. Each entry:
 *   'name'     => string label
 *   'versions' => array of amf versions (0 and/or 3) in which the value is exercised
 *   'make'     => closure returning a FRESH value (graphs are rebuilt on each call so
 *                 identity/cycles are per-run and codecs can't leak state between runs)
 *   'xfail'    => optional array(direction => reason) for documented divergences
 *                 (see docs/SPEC-php-adapter.md §7 in the amf3-ext repo)
 *
 * versions guidance: Amfphp_Core_Amf_Types_ByteArray/Vector/XmlDocument have no AMF0
 * representation in the php codec (they would degrade to anonymous objects), so they
 * are AMF3-only. Date/Xml/Undefined exist in AMF0 too.
 *
 * @package Tests
 */

/**
 * deterministic pseudo-random string (no mt_rand: output must be reproducible)
 * @param int $i
 * @param string $salt
 * @return string
 */
function amf_parity_token($i, $salt = '') {
    return substr(md5($salt . ':' . $i), 0, 10);
}

/**
 * a typed game item (stdClass + _explicitType), as services build them
 * @param int $i
 * @return stdClass
 */
function amf_parity_make_item($i) {
    $item = new stdClass();
    $item->_explicitType = 'game.Item';
    $item->id = 5000 + $i;
    $item->name = 'Item-' . $i . '-' . amf_parity_token($i, 'item');
    $item->quantity = ($i % 9) + 1;
    $item->price = 9.99 + $i * 0.5;
    $item->rarity = ($i % 2) ? 'rare' : 'common';
    $item->tradable = ($i % 3) != 0;
    return $item;
}

/**
 * a realistic character value. $rich adds AMF3-only fields (ByteArray, Vector);
 * without it the value is AMF0-safe.
 * @param int $i
 * @param bool $rich
 * @return stdClass typed game.Character
 */
function amf_parity_make_character($i, $rich = true) {
    $c = new stdClass();
    $c->_explicitType = 'game.Character';
    $c->id = 1000 + $i;
    $c->name = 'Hero-' . $i . '-é日😀'; // multibyte on purpose
    $c->level = ($i % 60) + 1;
    $c->hp = 1000.5 + $i;
    $c->mp = 250 + 3 * $i;
    $c->vip = ($i % 3) == 0;
    $c->clan = ($i % 4) ? 'clan-' . ($i % 7) : null;
    $c->stats = array(
        'strength' => 10 + ($i % 20),
        'agility' => 8 + ($i % 17),
        'intellect' => 5 + ($i % 23),
        'luck' => 0.5 + ($i % 10) * 0.25,
    );
    $c->inventory = array();
    $numItems = 3 + ($i % 5);
    for ($j = 0; $j < $numItems; $j++) {
        $c->inventory[] = amf_parity_make_item($i * 10 + $j);
    }
    $c->quests = array();
    for ($j = 0; $j < 3; $j++) {
        $q = new stdClass();
        $q->questId = 100 * $i + $j;
        $q->title = 'Quest ' . amf_parity_token($j, 'q' . $i);
        $q->progress = fmod($j * 33.333, 100);
        $q->done = $j == 0;
        $c->quests[] = $q;
    }
    $pos = new stdClass();
    $pos->x = -500.25 + $i;
    $pos->y = 1200.5 - $i;
    $pos->zone = 'zone_' . ($i % 12);
    $c->position = $pos;
    if ($i % 2) {
        $g = new stdClass();
        $g->_explicitType = 'game.Guild';
        $g->name = 'Guild-' . ($i % 9);
        $g->rank = $i % 5;
        $c->guild = $g;
    } else {
        $c->guild = null;
    }
    $c->lastLogin = new Amfphp_Core_Amf_Types_Date(1609459200000 + $i * 60000);
    if ($rich) {
        //deterministic binary junk, incl. 0x00 bytes
        $c->portrait = new Amfphp_Core_Amf_Types_ByteArray("\x00\x01\x02" . md5('portrait' . $i, true) . "\x00\x00");
        $skills = new Amfphp_Core_Amf_Types_Vector();
        $skills->type = Amfphp_Core_Amf_Types_Vector::VECTOR_INT;
        $skills->data = array(10 + $i, -3, 0, 127, 128, -30000, 2147483647, -2147483648);
        $c->skills = $skills;
    }
    return $c;
}

/**
 * a roster of $count characters — the "big realistic game-response" shape
 * @param int $count
 * @param bool $rich
 * @return array
 */
function amf_parity_make_roster($count, $rich = true) {
    $ret = array();
    for ($i = 0; $i < $count; $i++) {
        $ret[] = amf_parity_make_character($i, $rich);
    }
    return $ret;
}

/**
 * the corpus
 * @return array of entries
 */
function amf_parity_corpus() {
    $entries = array();
    $add = function ($name, $versions, $make, $xfail = array()) use (&$entries) {
        $entries[] = array('name' => $name, 'versions' => $versions, 'make' => $make, 'xfail' => $xfail);
    };
    $both = array(0, 3);
    $amf3only = array(3);

    // ---- scalars ----
    $add('null', $both, function () { return null; });
    $add('bool true', $both, function () { return true; });
    $add('bool false', $both, function () { return false; });
    $add('int 0', $both, function () { return 0; });
    $add('int 1', $both, function () { return 1; });
    $add('int -1', $both, function () { return -1; });
    $add('int 127 (1-byte u29 max)', $both, function () { return 127; });
    $add('int 128 (2-byte u29 min)', $both, function () { return 128; });
    $add('int 16383 (0x3FFF)', $both, function () { return 16383; });
    $add('int 16384 (0x4000)', $both, function () { return 16384; });
    $add('int 2097151 (0x1FFFFF)', $both, function () { return 2097151; });
    $add('int 2097152 (0x200000)', $both, function () { return 2097152; });
    $add('int 268435455 (amf3 int max)', $both, function () { return 268435455; });
    $add('int -268435456 (amf3 int min)', $both, function () { return -268435456; });
    $add('int 268435456 (amf3 overflows to double)', $both, function () { return 268435456; });
    $add('int -268435457 (amf3 overflows to double)', $both, function () { return -268435457; });
    $add('int 2^40 (double)', $both, function () { return 1099511627776; });
    $add('float 0.0', $both, function () { return 0.0; });
    $add('float 1.5', $both, function () { return 1.5; });
    $add('float -1.5', $both, function () { return -1.5; });
    $add('float pi', $both, function () { return 3.141592653589793; });
    $add('float 1e300', $both, function () { return 1e300; });
    $add('float 5e-324 (denormal min)', $both, function () { return 5e-324; });
    $add('float INF', $both, function () { return INF; });
    $add('float -INF', $both, function () { return -INF; });
    $add('float NAN', $both, function () { return NAN; });

    // ---- strings ----
    $add('string empty', $both, function () { return ''; });
    $add('string ascii', $both, function () { return 'hello world'; });
    $add('string utf8 accents', $both, function () { return 'héllo wörld — cœur ß'; });
    $add('string utf8 cjk', $both, function () { return '日本語テスト文字列です'; });
    $add('string emoji', $both, function () { return '😀🎮🔥✨'; });
    $add('string with NUL bytes', $both, function () { return "a\0b\0\0c"; });
    $add('string 87k bytes (long string in amf0)', $both, function () { return str_repeat('Ab3é', 17500); });

    // ---- arrays ----
    $add('array empty', $both, function () { return array(); });
    $add('array dense ints', $both, function () { return array(1, 2, 3, 4, 5); });
    $add('array nested lists', $both, function () { return array(array(1, 2), array(3, array(4, 5)), array()); });
    $add('array assoc (string keys)', $both, function () { return array('name' => 'Gandalf', 'level' => 20); });
    $add('array mixed (int + string keys)', $both, function () { return array(0 => 'a', 'x' => 1, 1 => 'b'); });
    $add('array sparse (gaps)', $both, function () { return array(0 => 'a', 5 => 'b'); });
    $add('array negative key', $both, function () { return array(-1 => 'neg', 0 => 'zero'); });
    $add('array deep nest', $both, function () {
        return array('l1' => array('l2' => array('l3' => array('l4' => array(1, 2, array('leaf' => true))))));
    });

    // ---- objects / graphs ----
    $add('object stdClass simple', $both, function () {
        $o = new stdClass();
        $o->a = 1;
        $o->b = 'two';
        return $o;
    });
    $add('object stdClass nested', $both, function () {
        $o = new stdClass();
        $o->inner = new stdClass();
        $o->inner->value = 42;
        $o->list = array(new stdClass(), new stdClass());
        return $o;
    });
    $add('object typed (game.Character)', $both, function () {
        return amf_parity_make_character(1, false);
    });
    $add('object two typed, same class (traits reference path)', $both, function () {
        $o = new stdClass();
        $o->first = amf_parity_make_item(1);
        $o->second = amf_parity_make_item(2);
        return $o;
    });
    //documented divergence: the php serializer dedups traits by class NAME only
    //(Serializer.php className2TraitsInfo), so the second object is written with the
    //first object's member list and corrupts the stream. amfext dedups by full traits
    //value and is correct. XFAIL on every leg that passes through the php encoder.
    $add('object two typed, same class, DIFFERENT members (php traits bug)', $amf3only, function () {
        $o = new stdClass();
        $a = new stdClass();
        $a->_explicitType = 'game.Shape';
        $a->width = 1;
        $a->height = 2;
        $b = new stdClass();
        $b->_explicitType = 'game.Shape';
        $b->radius = 3;
        $b->color = 'red';
        $o->first = $a;
        $o->second = $b;
        return $o;
    }, array(
        'A' => 'php encoder corrupts same-name traits (SPEC-core Q8)',
        'C' => 'php encoder corrupts same-name traits (SPEC-core Q8)',
    ));
    $add('object shared sub-reference (first === second)', $both, function () {
        $shared = new stdClass();
        $shared->v = 'shared';
        $o = new stdClass();
        $o->first = $shared;
        $o->second = $shared;
        return $o;
    });
    $add('object self reference (cycle)', $both, function () {
        $o = new stdClass();
        $o->name = 'loop';
        $o->self = $o;
        return $o;
    });
    $add('object 2-node cycle', $both, function () {
        $a = new stdClass();
        $a->name = 'a';
        $b = new stdClass();
        $b->name = 'b';
        $a->other = $b;
        $b->other = $a;
        return $a;
    });

    // ---- Amfphp_Core_Amf_Types_* ----
    $add('ByteArray binary junk incl 0x00', $amf3only, function () {
        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }
        return new Amfphp_Core_Amf_Types_ByteArray($data . $data . "\x00\x00\x00");
    });
    $add('Date 2021-01-01', $both, function () { return new Amfphp_Core_Amf_Types_Date(1609459200000); });
    $add('Date ms precision', $both, function () { return new Amfphp_Core_Amf_Types_Date(1609459200123); });
    //AMF0 has a single xml marker (0x0F): the php decoder reads it into Types_Xml, the Go
    //codec decodes it into Types_XmlDocument (AMF0 spec naming) — documented asymmetry,
    //SPEC-amf0 §3. The amf0 dir-A legs therefore XFAIL; amf3 legs keep distinct xml/xmldoc
    //markers (0x0B/0x07) and pass. Dir B amf0 passes: the Go amf0 encoder accepts Types_Xml
    //and emits 0x0F (php writeXML parity, amf0/encoder.go:177-180).
    $add('Xml simple', $amf3only, function () {
        return new Amfphp_Core_Amf_Types_Xml('<root><a x="1">text</a><b/></root>');
    });
    $add('Xml simple (amf0)', array(0), function () {
        return new Amfphp_Core_Amf_Types_Xml('<root><a x="1">text</a><b/></root>');
    }, array(
        'A' => 'amf0 0x0F decodes as XmlDocument in the Go codec (documented asymmetry, SPEC-amf0 §3)',
    ));
    $add('Xml pretty-printed (whitespace normalized both sides)', $amf3only, function () {
        return new Amfphp_Core_Amf_Types_Xml("<root>\n    <a>1</a>\n    <b>2</b>\n</root>");
    });
    $add('Xml pretty-printed (whitespace normalized both sides) (amf0)', array(0), function () {
        return new Amfphp_Core_Amf_Types_Xml("<root>\n    <a>1</a>\n    <b>2</b>\n</root>");
    }, array(
        'A' => 'amf0 0x0F decodes as XmlDocument in the Go codec (documented asymmetry, SPEC-amf0 §3)',
    ));
    $add('XmlDocument', $amf3only, function () {
        return new Amfphp_Core_Amf_Types_XmlDocument('<legacy><node attr="v">old</node></legacy>');
    });
    $add('Vector int', $amf3only, function () {
        $v = new Amfphp_Core_Amf_Types_Vector();
        $v->type = Amfphp_Core_Amf_Types_Vector::VECTOR_INT;
        $v->data = array(1, -1, 0, 2147483647, -2147483648);
        return $v;
    });
    $add('Vector uint (>2^31 as float)', $amf3only, function () {
        $v = new Amfphp_Core_Amf_Types_Vector();
        $v->type = Amfphp_Core_Amf_Types_Vector::VECTOR_UINT;
        //php has no uint32; large values are floats (see Deserializer::readAmf3VectorValue)
        $v->data = array(0, 1, 65535, 4294967295.0);
        return $v;
    });
    $add('Vector double', $amf3only, function () {
        $v = new Amfphp_Core_Amf_Types_Vector();
        $v->type = Amfphp_Core_Amf_Types_Vector::VECTOR_DOUBLE;
        $v->fixed = Amfphp_Core_Amf_Types_Vector::VECTOR_FIXED_LENGTH;
        $v->data = array(1.5, -2.5, 3e300, 0.0);
        return $v;
    });
    $add('Vector object (typed items)', $amf3only, function () {
        $v = new Amfphp_Core_Amf_Types_Vector();
        $v->type = Amfphp_Core_Amf_Types_Vector::VECTOR_OBJECT;
        $v->className = 'game.Item';
        $v->data = array(amf_parity_make_item(100), amf_parity_make_item(101));
        return $v;
    });
    $add('Undefined', $both, function () { return new Amfphp_Core_Amf_Types_Undefined(); });

    // ---- realistic game-response shapes ----
    $add('character sheet single (rich, ~1KB)', $amf3only, function () {
        return amf_parity_make_character(7, true);
    });
    $add('character sheet single (amf0-safe)', $both, function () {
        return amf_parity_make_character(8, false);
    });
    $add('roster medium 40 characters (~25KB)', $both, function () {
        return amf_parity_make_roster(40, false);
    });
    $add('roster large 400 characters (rich, ~200KB)', $amf3only, function () {
        return amf_parity_make_roster(400, true);
    });

    return $entries;
}

?>
