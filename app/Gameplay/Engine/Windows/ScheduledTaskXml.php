<?php

namespace App\Gameplay\Engine\Windows;

final class ScheduledTaskXml
{
    public static function render(string $name, string $sid, string $php, string $base, string $marker): string
    {
        $escape = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $arguments = '"'.$base.'/scripts/engine-launch.php" "'.$marker.'"';
        $xml = '<?xml version="1.0" encoding="UTF-16"?>'
            .'<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">'
            .'<RegistrationInfo><URI>\\'.$escape($name).'</URI></RegistrationInfo>'
            .'<Principals><Principal id="Owner"><UserId>'.$escape($sid).'</UserId>'
            .'<LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>'
            .'<Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>'
            .'<DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>'
            .'<AllowHardTerminate>true</AllowHardTerminate><IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd></IdleSettings>'
            .'<ExecutionTimeLimit>PT0S</ExecutionTimeLimit><Priority>5</Priority></Settings>'
            .'<Actions Context="Owner"><Exec><Command>'.$escape($php).'</Command><Arguments>'.$escape($arguments).'</Arguments>'
            .'<WorkingDirectory>'.$escape($base).'</WorkingDirectory></Exec></Actions></Task>';

        return "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
    }
}
