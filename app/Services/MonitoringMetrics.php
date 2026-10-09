<?php
namespace App\Services;

final class MonitoringMetrics
{
    public static function runningProgress(array $machines): array
    {
        $running=array_values(array_filter($machines,static fn(array $m): bool=>($m['status_code']??'')==='running'));
        return ['avg_all_machine'=>$running?round(array_sum(array_column($running,'production_percentage'))/count($running),1):0,'avg_running_count'=>count($running)];
    }
}
