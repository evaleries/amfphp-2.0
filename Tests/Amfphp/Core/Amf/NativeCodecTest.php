<?php
/**
 *  This file is part of amfPHP
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file license.txt.
 * @package Tests_Amfphp_Core_Amf
 */

/**
*  includes
*  */
require_once dirname(__FILE__) . '/../../../../Amfphp/ClassLoader.php';

/**
 * Test class for the native (amfext) codec adapters. The extension is
 * optional: without it the handler must fall back to the pure-php codec,
 * with it the handler must produce packets the php codec can read.
 * @package Tests_Amfphp_Core_Amf
 */
class NativeCodecTest extends PHPUnit_Framework_TestCase {

    public function testAdaptersImplementCodecInterfaces() {
        $deserializer = new Amfphp_Core_Amf_NativeDeserializer();
        $serializer = new Amfphp_Core_Amf_NativeSerializer();
        $this->assertTrue($deserializer instanceof Amfphp_Core_Common_IDeserializer);
        $this->assertTrue($serializer instanceof Amfphp_Core_Common_ISerializer);
    }

    public function testAvailabilityMatchesExtension() {
        $expectedDeserializer = extension_loaded('amfext') && function_exists('amfext_deserialize_packet');
        $expectedSerializer = extension_loaded('amfext') && function_exists('amfext_serialize_packet');
        $this->assertEquals($expectedDeserializer, Amfphp_Core_Amf_NativeDeserializer::isAvailable());
        $this->assertEquals($expectedSerializer, Amfphp_Core_Amf_NativeSerializer::isAvailable());
    }

    /**
     * whichever codec the handler selects, its output must stay readable
     * by the pure-php codec and carry the same values
     */
    public function testHandlerRoundTrip() {
        $packet = new Amfphp_Core_Amf_Packet();
        $packet->messages[] = new Amfphp_Core_Amf_Message('ExampleService.returnOneParam', '/1', array('hello world'));
        $phpSerializer = new Amfphp_Core_Amf_Serializer();
        $bin = $phpSerializer->serialize($packet);

        $handler = new Amfphp_Core_Amf_Handler(array());
        $decoded = $handler->deserialize(array(), array(), $bin);
        $this->assertEquals('ExampleService.returnOneParam', $decoded->messages[0]->targetUri);
        $this->assertEquals('hello world', $decoded->messages[0]->data[0]);

        $reBin = $handler->serialize($decoded);
        $phpDeserializer = new Amfphp_Core_Amf_Deserializer();
        $reDecoded = $phpDeserializer->deserialize(array(), array(), $reBin);
        $this->assertEquals('hello world', $reDecoded->messages[0]->data[0]);
    }

}

?>
