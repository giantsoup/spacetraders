<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\Windows\WindowlessConsole;
use PHPUnit\Framework\TestCase;

class WindowlessConsoleTest extends TestCase
{
    public function test_it_does_nothing_off_windows_and_keeps_an_existing_console(): void
    {
        $expected = PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi') ? WindowlessConsole::EXISTING : WindowlessConsole::UNSUPPORTED;

        $this->assertSame($expected, WindowlessConsole::attach());
    }

    public function test_console_programs_started_by_php_win_share_its_windowless_console(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('php-win.exe exists only on Windows.');
        }
        $phpWin = dirname(PHP_BINARY).'/php-win.exe';
        if (! is_file($phpWin)) {
            $this->markTestSkipped('No php-win.exe next to the PHP binary.');
        }
        $directory = sys_get_temp_dir().'/st-windowless-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $report = $directory.'/report.json';
        // The child lists the processes attached to its console. Sharing php-win's console
        // means Windows created no new console, and so no terminal window.
        $probe = '$t = Add-Type -PassThru -Name C -Namespace P -MemberDefinition \'[DllImport("kernel32.dll")] public static extern uint GetConsoleProcessList(uint[] l, uint n);\'; '
            .'$l = New-Object uint32[] 16; $n = $t::GetConsoleProcessList($l, 16); ($l[0..($n - 1)]) -join ","';
        file_put_contents($directory.'/probe.php', '<?php
require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';
$state = App\Gameplay\Engine\Windows\WindowlessConsole::attach();
$process = new Symfony\Component\Process\Process(["powershell.exe", "-NoLogo", "-NoProfile", "-NonInteractive", "-EncodedCommand", '.var_export(base64_encode(mb_convert_encoding($probe, 'UTF-16LE', 'UTF-8')), true).']);
$process->setTimeout(60)->run();
file_put_contents('.var_export($report, true).', json_encode(["pid" => getmypid(), "state" => $state, "attached" => trim($process->getOutput())]));
');
        try {
            $process = proc_open([$phpWin, $directory.'/probe.php'], [], $pipes);
            $this->assertSame(0, proc_close($process));
            $result = json_decode((string) file_get_contents($report), true);
            $this->assertSame(WindowlessConsole::CREATED, $result['state']);
            $this->assertContains((string) $result['pid'], explode(',', $result['attached']));
        } finally {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
    }
}
