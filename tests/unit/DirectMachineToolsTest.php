<?php
use App\Services\ProcessPlan;
use App\Services\MonitoringMetrics;
use App\Controllers\MachineUtilityController;
use CodeIgniter\Test\CIUnitTestCase;

final class DirectMachineToolsTest extends CIUnitTestCase
{
    public function testPlanUsesMillisecondsAndFloorsWholeParts(): void
    {
        $plan=ProcessPlan::calculate(ProcessPlan::milliseconds('30','Machine'),ProcessPlan::milliseconds('10','Loading'),3);
        $this->assertSame(40000,$plan['cycle_time_ms']);
        $this->assertSame(630,$plan['plan_per_shift']);
        $this->assertSame(1890,$plan['daily_plan']);
        $this->assertSame(763,ProcessPlan::calculate(32000,1000,2)['plan_per_shift']);
        $this->assertSame(1,ProcessPlan::calculate(25200000,0,1)['plan_per_shift']);
    }
    public function testPlanRejectsZeroCycle(): void
    {
        $this->expectException(DomainException::class);ProcessPlan::calculate(0,0,3);
    }
    public function testPlanRejectsUnavailableFourthShift(): void
    {
        $this->expectException(DomainException::class);ProcessPlan::calculate(30000,10000,4);
    }
    public function testAverageIncludesOnlyRunningProductionProgress(): void
    {
        $machines=[];foreach([['running',80],['running',60],['idle',20],['offline',100],['setting',40],['alarm',90]] as [$status,$progress]) $machines[]=['status_code'=>$status,'production_percentage'=>$progress];
        $this->assertSame(['avg_all_machine'=>70.0,'avg_running_count'=>2],MonitoringMetrics::runningProgress($machines));
        $this->assertSame(['avg_all_machine'=>0,'avg_running_count'=>0],MonitoringMetrics::runningProgress([]));
    }
    public function testTimelineCountsSettingAndLeavesFutureBlank(): void
    {
        $controller=new MachineUtilityController();$now=new DateTimeImmutable();$start=$now->modify('-30 seconds');$settingStart=$now->modify('-20 seconds');$end=$now->modify('+30 seconds');
        $compose=new ReflectionMethod($controller,'composeSegments');
        $segments=$compose->invoke($controller,$start,$end,[['at'=>$start,'state'=>'idle']], [['_start'=>$settingStart,'_end'=>$end,'state'=>'setting']]);
        $totals=(new ReflectionMethod($controller,'totals'))->invoke($controller,$segments);
        $this->assertSame(10000,$totals['idle']);$this->assertSame(20000,$totals['setting']);$this->assertSame(30000,array_sum($totals));
        $this->assertSame([], $compose->invoke($controller,$end,$end->modify('+30 seconds'),[],[]));
        $state=(new ReflectionMethod($controller,'effectiveState'))->invoke($controller,'offline',['state'=>'setting']);
        $this->assertSame('offline',$state[0]);
    }
}
