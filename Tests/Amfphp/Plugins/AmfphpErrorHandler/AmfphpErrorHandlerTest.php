<?php
/**
 *  This file is part of amfPHP
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file license.txt.
 * @package Tests_Amfphp_Plugins_ErrorHandler
 */

/**
*  includes
*  */
require_once dirname(__FILE__) . '/../../../../Amfphp/Plugins/AmfphpErrorHandler/AmfphpErrorHandler.php';
require_once dirname(__FILE__) . '/../../../../Amfphp/ClassLoader.php';

/**
 * Test class for AmfphpErrorHandler.
 * @package Tests_Amfphp_Plugins_ErrorHandler
 * @author Ariel Sommeria-klein
 */
class AmfphpErrorHandlerTest extends PHPUnit_Framework_TestCase {

    /**
     * object
     * @var AmfphpErrorHandler
     */
    protected $object;
    

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp() {
        error_reporting(E_ALL ^ E_USER_NOTICE );
        $this->object = new AmfphpErrorHandler();
        //the handler is scoped to request handling: it is installed on FILTER_SERIALIZED_REQUEST
        $this->object->filterSerializedRequest('');

    }

    protected function tearDown(){
        //and removed on FILTER_SERIALIZED_RESPONSE
        $this->object->filterSerializedResponse('');
        error_reporting(E_ALL);

    }
    

    /**
     * test trigger error with error level => should throw exception
     * @expectedException Amfphp_Core_Exception
     * */
    public function testThrows(){
        trigger_error("oops", E_USER_ERROR);
    }

    /**
     * test trigger error with notice level => should not throw exception, as in set up notices are ignored
     * */
    public function testDoesntThrow(){
        trigger_error("oops", E_USER_NOTICE);
        $this->assertTrue(true);
    }

    /**
     * a response filter without a matching request filter (e.g. plugin
     * loading failed before the request was filtered) must not restore:
     * that would pop the host framework's error handler in a worker
     * */
    public function testResponseWithoutRequestKeepsForeignHandler(){
        $sentinel = function($errno, $errstr) {
        };
        set_error_handler($sentinel);
        $fresh = new AmfphpErrorHandler();
        $fresh->filterSerializedResponse('');
        $topHandler = set_error_handler(function($errno, $errstr) {
        });
        restore_error_handler();
        restore_error_handler();
        $this->assertTrue($topHandler === $sentinel);
    }

    /**
     * installing twice (nested gateway calls) must only push the handler
     * once, so a single response filter restores the foreign handler below
     * */
    public function testDoubleInstallNeedsSingleRestore(){
        $sentinel = function($errno, $errstr) {
        };
        set_error_handler($sentinel);
        $fresh = new AmfphpErrorHandler();
        $fresh->filterSerializedRequest('');
        $fresh->filterSerializedRequest('');
        $fresh->filterSerializedResponse('');
        $topHandler = set_error_handler(function($errno, $errstr) {
        });
        restore_error_handler();
        restore_error_handler();
        $this->assertTrue($topHandler === $sentinel);
    }

}

?>
