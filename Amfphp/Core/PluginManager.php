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
 * Loads plugins for Amfphp. Plugins consist of a folder in the plugins folder. The folder and the class
 * should all have the same name. The file containing the class should be named with the class name with the '.php' suffix added.
 * It is the loaded class' responsability to load any  other resources that the plugin needs from the same folder.
 *  A plugin interacts with Amfphp by using the Amfphp_Core_FilterManager to register its functions
 * to be called at specific times with specific parameters during execution.
 * It's a singleton, so use getInstance
 *
 * @package Amfphp_Core
 * @author Ariel Sommeria-Klein
 */
class Amfphp_Core_PluginManager {

    /**
     * protected instance of singleton
     * @var Amfphp_Core_PluginManager
     *
     */
    protected static $instance = NULL;

    /**
     * plugin instances, keyed by plugin name
     * @var array
     */
    protected $pluginInstances;

    /**
     * fingerprint of the effective config each plugin instance was created
     * with, keyed by plugin name. Used to detect config changes in
     * long-running processes (workers), where instances outlive a request.
     * @var array
     */
    protected $pluginConfigFingerprints;

    /**
     * plugin names found per plugins folder, keyed by folder path. The set of
     * installed plugins does not change while a process runs, so the folder
     * scan is done once per folder instead of on every loadPlugins() call.
     * @var array
     */
    protected $pluginFolderListings;

    /**
     * arguments of the last loadPlugins() call whose outcome can be reused. The
     * gateway calls loadPlugins() on every request, in a long-running process
     * nearly always with identical arguments; an identical call (===) is then a
     * no-op. null when every plugin must be re-examined: before the first call,
     * after a call that threw, or while a plugin config has no fingerprint.
     * Objects inside the arguments compare by identity, so a config object that
     * is mutated in place is not noticed; pass a new object to reload plugins.
     * @var array|null
     */
    protected $lastLoadArguments;

    /**
     * constructor
     */
    protected function __construct() {
        $this->pluginInstances = array();
        $this->pluginConfigFingerprints = array();
        $this->pluginFolderListings = array();
    }

    /**
     * gives access to the singleton
     * @return Amfphp_Core_PluginManager
     */
    public static function getInstance() {
        if (self::$instance == NULL) {
            self::$instance = new Amfphp_Core_PluginManager();
        }
        return self::$instance;
    }

    /**
     * load the plugins
     * An identical repeated call returns immediately, see $lastLoadArguments.
     * @param array $pluginFolders where to load the plugins from. Absolute paths. For example Amfphp/Plugins/
     * @param array $pluginsConfig  optional. an array containing the plugin configuration, using the plugin name as key.
     * @param array $sharedConfig optional. if both a specific config and a shared config are available, concatenate them to create the plugin config. 
     * Otherwise use whatever is not null
     * @param array $disabledPlugins  optional.  an array of names of plugins to disable
     */
    public function loadPlugins($pluginFolders, ?array $pluginsConfig = null, ?array $sharedConfig = null, ?array $disabledPlugins = null) {
        $loadArguments = array($pluginFolders, $pluginsConfig, $sharedConfig, $disabledPlugins);
        if ($this->lastLoadArguments !== null && $this->lastLoadArguments === $loadArguments) {
            return;
        }
        $this->lastLoadArguments = null;
        $disabledPluginsLookup = $disabledPlugins ? array_flip($disabledPlugins) : array();
        foreach ($pluginFolders as $pluginsFolderRootPath) {
            if (!is_dir($pluginsFolderRootPath)) {
                throw new Amfphp_Core_Exception('invalid path for loading plugins at ' . $pluginsFolderRootPath);
            }
            if (!isset($this->pluginFolderListings[$pluginsFolderRootPath])) {
                $pluginNames = array();
                foreach (scandir($pluginsFolderRootPath) as $entry) {
                    //avoid system folders
                    if ($entry[0] == '.') {
                        continue;
                    }
                    if (is_dir($pluginsFolderRootPath . '/' . $entry)) {
                        $pluginNames[] = $entry;
                    }
                }
                $this->pluginFolderListings[$pluginsFolderRootPath] = $pluginNames;
            }

            foreach ($this->pluginFolderListings[$pluginsFolderRootPath] as $pluginName) {
                //check first if plugin is disabled. In a long-running worker the
                //plugin may already be loaded from an earlier request; unload it
                //so disabling takes effect instead of being silently ignored.
                if (isset($disabledPluginsLookup[$pluginName])) {
                    if (isset($this->pluginInstances[$pluginName])) {
                        $this->unloadPlugin($pluginName);
                    }
                    continue;
                }

                $pluginConfig = array();
                if ($pluginsConfig && isset($pluginsConfig[$pluginName])) {
                    $pluginConfig = $pluginsConfig[$pluginName];
                }
                if ($sharedConfig) {
                    $pluginConfig += $sharedConfig;
                }
                $configFingerprint = $this->fingerprintConfig($pluginConfig);

                // worker-mode: plugins live for the whole process lifetime in a
                // long-running worker (FrankenPHP/Octane). Re-instantiating with
                // the same config would re-register the plugin's filters in the
                // FilterManager singleton on every request (memory + CPU leak),
                // so only (re)create the instance when the config changed.
                if (isset($this->pluginInstances[$pluginName])
                        && $configFingerprint !== null
                        && isset($this->pluginConfigFingerprints[$pluginName])
                        && $this->pluginConfigFingerprints[$pluginName] === $configFingerprint) {
                    continue;
                }
                if (isset($this->pluginInstances[$pluginName])) {
                    $this->unloadPlugin($pluginName);
                }

                if (!class_exists($pluginName, false)) {
                    require_once $pluginsFolderRootPath . '/' . $pluginName . '/' . $pluginName . '.php';
                }

                $pluginInstance = new $pluginName($pluginConfig);
                $this->pluginInstances[$pluginName] = $pluginInstance;
                if ($configFingerprint !== null) {
                    $this->pluginConfigFingerprints[$pluginName] = $configFingerprint;
                }
            }
        }
        if (count($this->pluginInstances) === count($this->pluginConfigFingerprints)) {
            $this->lastLoadArguments = $loadArguments;
        }
    }

    /**
     * fingerprint an effective plugin config. Returns null when the config
     * cannot be serialized (e.g. it contains a closure); such plugins are
     * re-created on every loadPlugins() call, as before worker-mode caching.
     * @param array $pluginConfig
     * @return string|null
     */
    protected function fingerprintConfig(array $pluginConfig) {
        try {
            return serialize($pluginConfig);
        } catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * unload a plugin: unregister its filters and drop the instance, so a
     * later loadPlugins() call can create it again (or not, if disabled).
     * @param string $pluginName
     */
    protected function unloadPlugin($pluginName) {
        Amfphp_Core_FilterManager::getInstance()->removeObjectFilters($this->pluginInstances[$pluginName]);
        unset($this->pluginInstances[$pluginName]);
        unset($this->pluginConfigFingerprints[$pluginName]);
    }

}

?>
