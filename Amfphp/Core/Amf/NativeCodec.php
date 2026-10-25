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
 * Amfphp_Core_Amf_NativeDeserializer is a drop-in replacement for
 * Amfphp_Core_Amf_Deserializer backed by the native "amfext" extension
 * (FrankenPHP/Go codec). It is used by Amfphp_Core_Amf_Handler when the
 * extension is loaded and no VoConverter is configured.
 *
 * The native codec follows the same value semantics as the php codec
 * without a VoConverter: typed objects come out as stdClass carrying
 * Amfphp_Core_Amf_Constants::FIELD_EXPLICIT_TYPE.
 *
 * @package Amfphp_Core_Amf
 */
class Amfphp_Core_Amf_NativeDeserializer implements Amfphp_Core_Common_IDeserializer {

    /**
     * accepted for drop-in compatibility with Amfphp_Core_Amf_Deserializer, but IGNORED:
     * the native codec always deserializes to stdClass (with the explicit type field for
     * typed objects). The handler only uses this class when no VoConverter is configured.
     * @var Amfphp_Core_Common_IVoConverter
     */
    public $voConverter;

    /**
     * true if the amfext extension is loaded and exposes the packet deserializer function
     * @return bool
     */
    public static function isAvailable() {
        return extension_loaded('amfext') && function_exists('amfext_deserialize_packet');
    }

    /**
     * convert from text/binary to php object, using the native codec.
     * get/post data are ignored, as with Amfphp_Core_Amf_Deserializer
     * @see Amfphp_Core_Common_IDeserializer
     * @param array $getData
     * @param array $postData
     * @param string $rawPostData
     * @return Amfphp_Core_Amf_Packet
     */
    public function deserialize(array $getData, array $postData, $rawPostData) {
        return amfext_deserialize_packet($rawPostData);
    }

}

/**
 * Amfphp_Core_Amf_NativeSerializer is a drop-in replacement for
 * Amfphp_Core_Amf_Serializer backed by the native "amfext" extension
 * (FrankenPHP/Go codec).
 *
 * @package Amfphp_Core_Amf
 */
class Amfphp_Core_Amf_NativeSerializer implements Amfphp_Core_Common_ISerializer {

    /**
     * accepted for drop-in compatibility with Amfphp_Core_Amf_Serializer, but IGNORED:
     * the native codec handles stdClass and the explicit type field semantics itself.
     * The handler only uses this class when no VoConverter is configured.
     * @var Amfphp_Core_Common_IVoConverter
     */
    public $voConverter;

    /**
     * true if the amfext extension is loaded and exposes the packet serializer function
     * @return bool
     */
    public static function isAvailable() {
        return extension_loaded('amfext') && function_exists('amfext_serialize_packet');
    }

    /**
     * convert an Amfphp_Core_Amf_Packet to its binary representation, using the native codec
     * @see Amfphp_Core_Common_ISerializer
     * @param Amfphp_Core_Amf_Packet $data
     * @return string binary packet
     */
    public function serialize($data) {
        return amfext_serialize_packet($data);
    }

}

?>
