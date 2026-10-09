<?php

namespace App\Services;

class DeviceReachabilityService
{
    public function ping(string $ip, int $timeoutSeconds = 1): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP) || ! function_exists('exec')) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf('ping -n 1 -w %d %s', $timeoutSeconds * 1000, escapeshellarg($ip));
        } else {
            $command = sprintf('ping -c 1 -W %d %s', $timeoutSeconds, escapeshellarg($ip));
        }

        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        return $exitCode === 0;
    }
}
