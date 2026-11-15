<?php

namespace App\Gameplay\Engine\Windows;

/**
 * Gives a console-less Windows CLI process (php-win.exe) a console with no window.
 *
 * php-win has no console, so every console program it starts (powershell.exe, cmd.exe,
 * schtasks.exe, taskkill.exe) would get a new console of its own. Windows opens that console
 * in a visible Windows Terminal window that steals focus, even when the child asks to be
 * hidden. A child started after this call attaches to the windowless console instead.
 */
final class WindowlessConsole
{
    public const UNSUPPORTED = 'unsupported';

    public const CREATED = 'created';

    public const EXISTING = 'existing';

    public const FAILED = 'failed';

    public static function attach(): string
    {
        if (PHP_OS_FAMILY !== 'Windows' || PHP_SAPI !== 'cli' || ! extension_loaded('ffi')) {
            return self::UNSUPPORTED;
        }
        try {
            // AllocConsoleWithOptions ships with Windows 11 24H2 and later. Older builds fail
            // to resolve it and keep the previous behaviour.
            $kernel = \FFI::cdef('typedef struct { int mode; int useShowWindow; unsigned short showWindow; } ALLOC_CONSOLE_OPTIONS;
                long AllocConsoleWithOptions(ALLOC_CONSOLE_OPTIONS* options, int* result);', 'kernel32.dll');
            $options = $kernel->new('ALLOC_CONSOLE_OPTIONS');
            $options->mode = 2; // ALLOC_CONSOLE_MODE_NO_WINDOW
            $result = $kernel->new('int');
            if ($kernel->AllocConsoleWithOptions(\FFI::addr($options), \FFI::addr($result)) !== 0) {
                return self::FAILED;
            }

            return match ($result->cdata) {
                1 => self::CREATED,
                2 => self::EXISTING,
                default => self::FAILED,
            };
        } catch (\Throwable) {
            return self::FAILED;
        }
    }
}
