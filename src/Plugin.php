<?php
namespace ContentMcpBridge;

class Plugin {
    public function __construct() {
        new Settings();
        new Role();
        new AbilityRegistrar();
        new Server();
    }
}
