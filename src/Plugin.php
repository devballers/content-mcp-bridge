<?php
namespace ContentMcpBridge;

class Plugin {
    public function __construct() {
        new Settings();
        new AbilityRegistrar();
        new Server();
    }
}
