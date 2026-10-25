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
 * just a type to distinguish between Amfphp exceptions and any other exceptions
 *
 * @package Amfphp_Core
 * @author Ariel Sommeria-klein
 */
class Amfphp_Core_Exception extends Exception {

    /**
     * declared so the VoConverter can mark the explicit type when an
     * exception is serialized as rootCause in a fault packet, instead of
     * creating a dynamic property (deprecated since PHP 8.2)
     * @var string
     */
    public $_explicitType;
}
?>
