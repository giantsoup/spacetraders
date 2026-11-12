<?php

namespace App\Gameplay\Engine\Windows;

use App\Gameplay\Engine\HostCommands;
use App\Gameplay\Engine\HostResult;

final class PowerShell
{
    public function __construct(private readonly HostCommands $commands) {}

    public static function executable(string $name): string
    {
        return (getenv('SystemRoot') ?: 'C:/Windows').'/System32/'.$name;
    }

    public function run(string $script, array $env = []): HostResult
    {
        return $this->commands->run([
            self::executable('WindowsPowerShell/v1.0/powershell.exe'),
            '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(mb_convert_encoding('$ErrorActionPreference = \'Stop\''."\n".$script, 'UTF-16LE', 'UTF-8')),
        ], 10, $env);
    }
}
