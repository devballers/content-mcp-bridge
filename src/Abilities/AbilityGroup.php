<?php
namespace ContentMcpBridge\Abilities;

interface AbilityGroup {
    public function registerReadOnly(): void;

    public function registerWrite(): void;
}
