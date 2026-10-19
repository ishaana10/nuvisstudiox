<?php
// Nuvis StudioX - Plugin Manager (PHP 8.1+ compatible)

class NuPluginManager {
    private $plugins = [];
    private $hooks   = [];

    /**
     * Register a plugin by directory name.
     * @return bool
     */
    public function register($pluginName) {
        $pluginDir  = __DIR__ . '/../plugins/' . $pluginName;
        $pluginFile = $pluginDir . '/plugin.php';
        if (!file_exists($pluginFile)) {
            error_log("[PluginManager] Plugin file not found: {$pluginFile}");
            return false;
        }
        require_once $pluginFile;
        $className = 'Plugin_' . $pluginName;
        if (!class_exists($className)) {
            error_log("[PluginManager] Plugin class {$className} not found in {$pluginFile}");
            return false;
        }
        $instance = new $className();
        if (method_exists($instance, 'register')) {
            $instance->register($this);
        }
        $this->plugins[$pluginName] = $instance;
        return true;
    }

    /**
     * Add a hook callback.
     */
    public function addHook($hookName, $callback) {
        if (!isset($this->hooks[$hookName])) {
            $this->hooks[$hookName] = [];
        }
        $this->hooks[$hookName][] = $callback;
    }

    /**
     * Fire all callbacks for a hook, passing $data through.
     * @return mixed
     */
    public function fireHook($hookName, $data = null) {
        if (empty($this->hooks[$hookName])) return $data;
        foreach ($this->hooks[$hookName] as $cb) {
            $data = call_user_func($cb, $data);
        }
        return $data;
    }

    /**
     * @return array
     */
    public function getRegisteredPlugins() {
        return array_keys($this->plugins);
    }
}
