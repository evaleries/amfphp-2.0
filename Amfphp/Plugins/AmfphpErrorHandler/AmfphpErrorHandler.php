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
 * sets a custom error handler to catch notices and such and transform them to exceptions.
 *
 * The handler is only active while the gateway handles a request: it is installed just before
 * deserialization and removed right after serialization (the serialized response filter also
 * runs on the exception path). In a long-running worker (FrankenPHP/Octane) plugin instances
 * live for the whole process, so installing the handler in the constructor would hijack error
 * handling for every subsequent request in the process, including framework code (e.g. Laravel).
 *
 * @package Amfphp_Plugins_ErrorHandler
 * @author Ariel Sommeria-Klein
 */

class AmfphpErrorHandler {
     /**
      * constructor. Add filters on the FilterManager.
      * @param array $config optional key/value pairs in an associative array. Used to override default configuration values.
      */
    public function  __construct(?array $config = null) {
        $filterManager = Amfphp_Core_FilterManager::getInstance();
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_SERIALIZED_REQUEST, $this, 'filterSerializedRequest', 0);
        $filterManager->addFilter(Amfphp_Core_Gateway::FILTER_SERIALIZED_RESPONSE, $this, 'filterSerializedResponse', 100);
    }

    /**
     * installs the error handler for the duration of the request.
     * Runs first (priority 0) so that all other request filters are covered.
     * @param string $rawData the raw http data
     * @return string
     */
    public function filterSerializedRequest($rawData) {
        set_error_handler('custom_warning_handler');
        return $rawData;
    }

    /**
     * restores the previous error handler.
     * Runs last (priority 100) so that all other response filters are still covered.
     * @param string $rawData the raw http data
     * @return string
     */
    public function filterSerializedResponse($rawData) {
        restore_error_handler();
        return $rawData;
    }
}

/**
 * Only throw an exception if the error level would report it
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @throws Exception
 */
function custom_warning_handler($errno, $errstr, $errfile, $errline, $errcontext = null) {
    if ($errno & error_reporting()) {
        $contextString = $errcontext === null ? '' : "\n<br>context: " . print_r($errcontext, true);
        throw new Amfphp_Core_Exception("$errstr . \n<br>file:  $errfile \n<br>line: $errline$contextString", $errno);
    }
}

?>
