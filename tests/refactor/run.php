<?php
// Destructive fixture setup: only a disposable *_test / *_qa database is allowed.
if (getenv('TPMS_REFACTOR_TEST') !== '1') { fwrite(STDERR,"Set TPMS_REFACTOR_TEST=1 and configure a disposable *_test / *_qa MySQL database.\n"); exit(2); }
$root=dirname(__DIR__,2);
define('FCPATH',$root.'/public/');
define('ENVIRONMENT','development');
require $root.'/app/Config/Paths.php';
$paths=new Config\Paths();
require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
session();
$db=db_connect();
if (!preg_match('/_(test|qa)$/D',$db->database)) { fwrite(STDERR,"Refusing non-test database.\n"); exit(2); }
$db->query('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->listTables() as $table) if ($table!=='migrations') $db->query('TRUNCATE TABLE `'.$table.'`');
$db->query('SET FOREIGN_KEY_CHECKS=1');
$db->table('tpms_runtime_lock')->insert(['id'=>1]);
$checks=0;
function ok($condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: '.$message); $checks++; echo "PASS $message\n"; }
function reject(callable $fn,int $code,string $message): void { try { $fn(); } catch (DomainException $e) { ok($e->getCode()===$code,$message.' ['.$e->getMessage().']'); return; } throw new RuntimeException('FAIL expected rejection: '.$message); }
function insert(string $table,array $data): int { global $db; $db->table($table)->insert($data); return (int)$db->insertID(); }
function callDevice(int $machine,string $action,array $payload=[],?string $event=null): array { static $n=0; $payload['mac_address']='AA:BB:CC:DD:EE:0'.$machine; $payload['event_id']=$event??'qa-'.(++$n); return (new App\Services\EspProductionService())->handle($action,$payload,'qa-token-'.$machine); }
function contract(array $state): array { return ['production_id'=>$state['production_id'],'production_shift_detail_id'=>$state['production_shift_detail_id'],'counter_epoch'=>$state['counter_epoch']]; }
$now=date('Y-m-d H:i:s');
$customer=insert('customers',['code'=>'QA','name'=>'QA','status'=>'active']);
$material=insert('materials',['code'=>'QA','name'=>'QA Material']);
foreach ([['S1','07:00:00','15:00:00'],['S2','15:00:00','23:00:00'],['S3','23:00:00','07:00:00']] as [$c,$s,$e]) insert('shifts',['code'=>$c,'name'=>$c,'start_time'=>$s,'end_time'=>$e]);
$type=insert('tool_types',['code'=>'DRILL','name'=>'Drill','status'=>'active']);
$extraType=insert('tool_types',['code'=>'REAMER','name'=>'Reamer','status'=>'active']);
$missingType=insert('tool_types',['code'=>'MILL','name'=>'Mill','status'=>'active']);
$tool=[];$machine=[];$slot=[];$secondaryTool=[];
for ($i=1;$i<=2;$i++) {
 $machine[$i]=insert('machines',['code'=>'QA-M'.$i,'name'=>'QA Machine '.$i,'registration_code'=>'QA-REG'.$i]);
 $slot[$i]=insert('production_slots',['slot_no'=>$i,'name'=>'QA Slot '.$i,'machine_id'=>$machine[$i]]);
 insert('tpms_devices',['mac_address'=>'AA:BB:CC:DD:EE:0'.$i,'token'=>'qa-token-'.$i,'current_slot_id'=>$slot[$i],'device_status'=>'idle','connection_status'=>'reachable','last_seen_at'=>$now]);
 $tool[$i]=insert('tools',['code'=>'QA-T'.$i,'name'=>'QA Drill '.$i,'tool_type_id'=>$type,'cutting_edge'=>3,'current_edge'=>1,'actual_lifetime'=>0,'default_lifetime'=>1000,'status'=>'ready']);
 $secondaryTool[$i]=insert('tools',['code'=>'QA-R'.$i,'name'=>'QA Reamer '.$i,'tool_type_id'=>$extraType,'cutting_edge'=>2,'current_edge'=>1,'actual_lifetime'=>0,'default_lifetime'=>1000,'status'=>'ready']);
}
$pic1=insert('employees',['nik'=>'QA-PIC1','name'=>'QA PIC 1','role'=>'PIC','rfid_uid'=>'11223344','status'=>'active']);
$pic2=insert('employees',['nik'=>'QA-PIC2','name'=>'QA PIC 2','role'=>'PIC','rfid_uid'=>'AABBCCDD','status'=>'active']);
$op1=insert('employees',['nik'=>'QA-OP1','name'=>'QA Operator 1','role'=>'Operator','rfid_uid'=>'55667788','status'=>'active']);
$op2=insert('employees',['nik'=>'QA-OP2','name'=>'QA Operator 2','role'=>'Operator','rfid_uid'=>'66778899','status'=>'active']);
$op3=insert('employees',['nik'=>'QA-OP3','name'=>'QA Operator Replacement','role'=>'Operator','rfid_uid'=>'77889900','status'=>'active']);
$currentShift=App\Services\ProductionRules::shift($db->table('shifts')->get()->getResultArray(),new DateTimeImmutable('now'));
foreach ([[1,$op1],[2,$op2]] as [$i,$operatorId]) {
 insert('employee_shift_assignments',['employee_id'=>$operatorId,'shift_id'=>$currentShift['id'],'assignment_role'=>'operator','machine_id'=>$machine[$i],'slot_id'=>$slot[$i],'assignment_date'=>$currentShift['work_date'],'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
}
$plan=App\Services\ProcessPlan::calculate(30000,10000,3);
ok($plan['plan_per_shift']===630 && $plan['daily_plan']===1890,'central 7-hour plan');
ok(App\Services\ProcessPlan::calculate(32000,1000,2)['plan_per_shift']===763,'plan floor rounding');
reject(fn()=>App\Services\ProcessPlan::calculate(0,0,3),422,'zero cycle rejected');
$assign=new App\Services\MachineToolService($db);
$assign->assign($tool[1],$machine[1]);
$assign->assign($tool[2],$machine[2]);
foreach([1,2] as $i) $assign->assign($secondaryTool[$i],$machine[$i]);
reject(fn()=>$assign->assign($tool[1],$machine[2]),409,'explicit reassign required');
ok((int)$db->table('machine_tools')->where('tool_id',$tool[1])->get()->getRow('machine_id')===$machine[1],'failed move preserves original assignment');
$assign->assign($tool[1],$machine[2],true);
ok((int)$db->table('machine_tools')->where('tool_id',$tool[1])->get()->getRow('machine_id')===$machine[2],'explicit physical Tool reassignment works');
$assign->assign($tool[1],$machine[1],true);
$partInput=['part_number'=>'QA-PART','name'=>'QA Part','customer_id'=>$customer,'material_id'=>$material,'process_type'=>'process_2_auto','shifts_per_day'=>3,'process_machine_time_seconds'=>[1=>30,2=>50],'process_loading_time_seconds'=>[1=>10,2=>30],'process_requirements'=>[1=>[['tool_type_id'=>$type,'position'=>'P01','set_lifetime'=>1000],['tool_type_id'=>$extraType,'position'=>'P02','set_lifetime'=>1000]],2=>[['tool_type_id'=>$missingType,'position'=>'P01','set_lifetime'=>500]]]];
$db->transBegin();(new App\Services\TpmsRuntimeLockService($db))->lockAllDevices();
$part=(new App\Services\PartConfigurationService($db))->save($partInput);$db->transCommit();
$processes=$db->table('part_processes')->where('part_id',$part)->orderBy('process_no')->get()->getResultArray();
$p1=(int)$processes[0]['id'];$p2=(int)$processes[1]['id'];
ok(count($processes)===2,'multi-process Part saved');
ok(count($assign->resolve($machine[1],$p1))===2,'multiple Required Tool Types resolve to physical tools');
reject(fn()=>callDevice(1,'start',['part_id'=>$part,'part_process_id'=>$p2,'operator_uid'=>'55667788','part_code'=>'QA-PART','tools_installed'=>true,'tool_ids'=>[]]),409,'production START rejects missing Tool Type');
ok(App\Services\ProcessPlan::calculate(50000,30000,3)['plan_per_shift']===315,'distinct process plan');
reject(fn()=>$assign->resolve($machine[1],$p2),409,'missing type clearly rejected');
reject(fn()=>callDevice(1,'pic',['pic_uid'=>'55667788']),403,'non-PIC RFID rejected');
$picCheck=callDevice(1,'pic',['pic_uid'=>'11223344']);
ok($picCheck['role']==='PIC' && $picCheck['name']==='QA PIC 1','active PIC RFID accepted without Planning');
$crossMachinePic=callDevice(1,'pic',['pic_uid'=>'AABBCCDD']);
ok($crossMachinePic['role']==='PIC' && $crossMachinePic['name']==='QA PIC 2','PIC is global and can support another Machine/shift without Planning');

reject(fn()=>callDevice(1,'operator',['operator_uid'=>'11223344']),403,'PIC RFID rejected as production Operator');
reject(fn()=>callDevice(1,'operator',['operator_uid'=>'66778899']),403,'Operator from another machine/shift rejected');
$operatorCheck=callDevice(1,'operator',['operator_uid'=>'55667788']);
ok($operatorCheck['role']==='Operator' && $operatorCheck['name']==='QA Operator 1','assigned Operator RFID accepted before Part Code');

$common1=['part_id'=>$part,'part_process_id'=>$p1,'operator_uid'=>'55667788','part_code'=>'QA-PART','tools_installed'=>true];
$common2=['part_id'=>$part,'part_process_id'=>$p1,'operator_uid'=>'66778899','part_code'=>'QA-PART','tools_installed'=>true];
reject(fn()=>callDevice(1,'start',['part_id'=>$part,'part_process_id'=>$p1,'part_code'=>'QA-PART','tools_installed'=>true,'tool_ids'=>[$tool[1],$secondaryTool[1]]]),422,'production START requires Operator RFID');
reject(fn()=>callDevice(1,'start',['part_id'=>$part,'part_process_id'=>$p1,'operator_uid'=>'55667788','tools_installed'=>true,'tool_ids'=>[$tool[1],$secondaryTool[1]]]),422,'production START requires Part Code');
$a=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]],'qa-start');
$beforeSettingDetail=(int)$a['production_shift_detail_id'];
$beforeSettingEpoch=$a['counter_epoch'];
$midSetting=callDevice(1,'setting-start',['part_code'=>'QA-PART','pic_uid'=>'11223344']);
ok($midSetting['state']==='setting' && $midSetting['production_id']===$a['production_id'] && $midSetting['production_shift_detail_id']===$beforeSettingDetail && $midSetting['reused_shift_detail']===true,'mid-production Setting reuses active production shift detail');
reject(fn()=>callDevice(1,'setting-finish',['production_id'=>$a['production_id'],'pic_uid'=>'11223344']),409,'mid-production Setting cannot finish before Tool position confirmation');
$midConfigured=callDevice(1,'setting-configure',['production_id'=>$a['production_id'],'pic_uid'=>'11223344','part_process_id'=>$p1,'tools_position_confirmed'=>true,'tool_ids'=>[$tool[1],$secondaryTool[1]]]);
ok($midConfigured['setting_configured']===true && $midConfigured['production_shift_detail_id']===$beforeSettingDetail,'mid-production Setting confirms Process/Tool on same detail');
$midFinished=callDevice(1,'setting-finish',['production_id'=>$a['production_id'],'pic_uid'=>'11223344']);
ok($midFinished['state']==='running' && $midFinished['production_shift_detail_id']===$beforeSettingDetail && $midFinished['counter_epoch']===$beforeSettingEpoch,'mid-production Setting resumes previous production without new shift detail or counter epoch');
$b=callDevice(2,'start',$common2+['tool_ids'=>[$tool[2],$secondaryTool[2]]]);
ok($a['production_id']!==$b['production_id'] && $a['plan_qty']===630,'same Part/process simultaneous on two machines');
$replay=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]],'qa-start');
ok($replay['replayed']===true && $replay['production_id']===$a['production_id'],'START retry idempotent');
reject(fn()=>callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]],'part_code'=>'OTHER'],'qa-start'),409,'event ID payload collision rejected');
reject(fn()=>$assign->unassign($tool[1]),409,'active physical tool assignment protected');
$usage=$db->table('production_tool_usages')->where('production_shift_detail_id',$a['production_shift_detail_id'])->get()->getRowArray();
ok($usage['tool_code_snapshot']==='QA-T1' && (int)$usage['current_edge_snapshot']===1,'immutable tool metadata snapshot created');
$c=contract($a);
$cy=callDevice(1,'cycle-start',$c+['trigger_ms'=>1000]);
$stop=callDevice(1,'cycle-stop',$c+['cycle_id'=>$cy['cycle']['id'],'trigger_ms'=>32000],'qa-cycle-stop');
$stopReplay=callDevice(1,'cycle-stop',$c+['cycle_id'=>$cy['cycle']['id'],'trigger_ms'=>32000],'qa-cycle-stop');
ok($stop['gross_qty']===1 && $stopReplay['gross_qty']===1,'STOP counts once including retry');
$cy=callDevice(1,'cycle-start',$c+['trigger_ms'=>44000]);
$stop=callDevice(1,'cycle-stop',$c+['cycle_id'=>$cy['cycle']['id'],'trigger_ms'=>77000]);
ok($stop['cycle']['machine_time_ms']===33000 && $stop['cycle']['loading_time_ms']===12000,'two-trigger actual machine/loading/cycle timing');
reject(fn()=>callDevice(1,'count',array_merge($c,['counter_epoch'=>'obsolete','counter_total'=>2])),409,'stale counter epoch rejected');
$pause=callDevice(1,'pause',$c+['counter_total'=>2,'message'=>'QA break']);
ok($pause['state']==='paused','pause preserves existing flow');
reject(fn()=>callDevice(1,'count',$c+['counter_total'=>3]),409,'count blocked while paused');
$resume=callDevice(1,'resume',$c+['alarm_id'=>$pause['pause_alarm_id'],'message'=>'QA resume']);
ok($resume['state']==='running','resume preserves existing flow');
$done=callDevice(1,'finish',$c+['counter_total'=>2,'operator_uid'=>'55667788','defects'=>['Dimensi'=>1]]);
ok($done['state']==='awaiting_next_shift' && $done['good_qty']===1 && $done['reject_qty']===1,'finish closes shift detail but keeps unfinished Production reusable');
ok((int)$db->table('tools')->where('id',$tool[1])->get()->getRow('actual_lifetime')===2,'physical tool lifetime uses gross output');
$sameShiftRestart=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]]);
ok($sameShiftRestart['production_id']===$a['production_id'] && $sameShiftRestart['production_shift_detail_id']===$a['production_shift_detail_id'] && $sameShiftRestart['counter_epoch']===$a['counter_epoch'] && $sameShiftRestart['reused_shift_detail']===true,'same work_date + shift + operator reuses Production and Shift Detail');
$sameKeyDetailCount=$db->table('production_shift_details')->where('production_id',$a['production_id'])->where('work_date',$currentShift['work_date'])->where('shift_id',$currentShift['id'])->where('operator_employee_id',$op1)->countAllResults();
ok($sameKeyDetailCount===1,'composite production/work_date/shift/operator has one shift detail');
$sameJobProductionCount=$db->table('productions')->where('machine_id',$machine[1])->where('part_id',$part)->where('part_process_id',$p1)->where('mode','production')->countAllResults();
ok($sameJobProductionCount===1,'same Machine/Part/Process does not create duplicate Production header');
callDevice(1,'finish',contract($sameShiftRestart)+['counter_total'=>2,'operator_uid'=>'55667788','defects'=>['Dimensi'=>1]]);
callDevice(2,'finish',contract($b)+['counter_total'=>$b['counter_expected_total'],'operator_uid'=>'66778899','defects'=>[]]);

$edge=callDevice(1,'change-edge',['tool_id'=>$tool[1],'current_edge'=>2,'pic_uid'=>'11223344','reason'=>'QA edge change']);
ok((int)$edge['current_edge']===2 && (int)$edge['actual_lifetime']===0 && $edge['maintenance_pic']['name']==='QA PIC 1','TPMS edge change resets lifetime and records PIC');
reject(fn()=>callDevice(1,'change-edge',['tool_id'=>$tool[1],'current_edge'=>4,'pic_uid'=>'11223344','reason'=>'QA invalid']),422,'edge exceeding total rejected');

$settingCatalogue=callDevice(1,'parts',['mode'=>'setting','part_id'=>$part]);
ok($settingCatalogue['parts'][0]['processes'][0]['selectable']===true && count($settingCatalogue['parts'][0]['processes'][0]['tools'])===2,'Setting catalogue resolves Tool and position without lifetime blocking');
$setting=callDevice(1,'setting-start',['part_code'=>'QA-PART','pic_uid'=>'11223344']);
ok($setting['mode']==='setting' && $setting['setting_configured']===false && $setting['gross_qty']===0,'Setting timeline starts from PIC + Part Code');
$settingDetail=$db->table('production_shift_details')->where('id',$setting['production_shift_detail_id'])->get()->getRowArray();
ok((int)$settingDetail['pic_employee_id']===$pic1 && empty($settingDetail['operator_employee_id']),'Setting stores PIC separately from Operator');
$configured=callDevice(1,'setting-configure',['production_id'=>$setting['production_id'],'pic_uid'=>'11223344','part_process_id'=>$p1,'tools_position_confirmed'=>true,'tool_ids'=>[$tool[1],$secondaryTool[1]]]);
ok($configured['setting_configured']===true && count($configured['tools'])===2,'Setting confirms Process, Tool and position');
ok(callDevice(1,'state')['state']==='setting','Setting survives state recovery');
reject(fn()=>callDevice(1,'count',['production_id'=>$setting['production_id'],'counter_total'=>1]),409,'Setting cannot count');
reject(fn()=>callDevice(1,'cycle-start',['production_id'=>$setting['production_id']]),409,'Setting cannot generate cycles');
reject(fn()=>callDevice(1,'setting-finish',['production_id'=>$setting['production_id'],'pic_uid'=>'AABBCCDD']),403,'Setting finish rejects a different PIC even though PIC access is global');
$end=callDevice(1,'setting-finish',['production_id'=>$setting['production_id'],'pic_uid'=>'11223344']);
ok($end['state']==='completed' && $end['good_qty']===0,'Setting finish records zero quantity');
$interval=$db->table('production_runtime_intervals')->where('production_id',$setting['production_id'])->get()->getRowArray();
ok($interval['state']==='setting' && $interval['ended_at']!==null,'Setting runtime interval recorded and closed');
$settingAlert=$db->table('production_alarms')->where('production_shift_detail_id',$setting['production_shift_detail_id'])->where('kind','setting')->get()->getRowArray();
ok($settingAlert && $settingAlert['resolved_at']!==null && str_contains($settingAlert['message'],'QA PIC 1'),'Setting alert records PIC and is resolved on finish');

$alarmStart=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]]);
$alarmContract=contract($alarmStart);
$alarmCounter=$alarmStart['counter_expected_total'];
$alarmed=callDevice(1,'alarm',$alarmContract+['counter_total'=>$alarmCounter,'kind'=>'machine_fault','severity'=>'critical','message'=>'QA critical machine fault']);
ok($alarmed['state']==='service_required','critical machine alarm still blocks production');
$alarmId=(int)$alarmed['alarms'][0]['id'];
reject(fn()=>callDevice(1,'cycle-start',$alarmContract+['trigger_ms'=>90000]),409,'cycle blocked during critical service');
$service=callDevice(1,'service-complete',$alarmContract+['counter_total'=>$alarmCounter,'alarm_id'=>$alarmId,'pic_uid'=>'11223344','message'=>'QA service complete']);
ok($service['state']==='running','PIC service-complete restores running');
callDevice(1,'finish',$alarmContract+['counter_total'=>$alarmCounter,'operator_uid'=>'55667788','defects'=>[]]);

// Operator sakit / replacement: critical stop -> PIC resolve -> Admin Planning -> Operator baru resume detail lama.
$swapStart=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]]);
$swapContract=contract($swapStart);
$swapCounter=$swapStart['counter_expected_total'];
reject(fn()=>callDevice(1,'operator-sick',$swapContract+['counter_total'=>$swapCounter,'operator_uid'=>'66778899','reason'=>'QA wrong operator']),403,'Operator Sick requires RFID active Operator');
$sick=callDevice(1,'operator-sick',$swapContract+['counter_total'=>$swapCounter,'operator_uid'=>'55667788','reason'=>'Operator sakit saat shift']);
ok($sick['state']==='operator_change_required' && $sick['production_shift_detail_id']===$swapStart['production_shift_detail_id'] && $sick['counter_epoch']===$swapStart['counter_epoch'] && $sick['operator_sick_resolved']===false,'Operator Sick locks production without creating new shift detail/counter epoch');
$sickAlarm=$db->table('production_alarms')->where('production_shift_detail_id',$swapStart['production_shift_detail_id'])->where('kind','operator_sick')->orderBy('id','DESC')->get()->getRowArray();
ok($sickAlarm && $sickAlarm['severity']==='critical' && $sickAlarm['effect']==='operator_stop' && empty($sickAlarm['resolved_at']),'Operator Sick creates unresolved critical operator_stop alert');
reject(fn()=>callDevice(1,'operator-replacement',['production_id'=>$swapStart['production_id'],'operator_uid'=>'77889900']),409,'replacement blocked before PIC resolves Operator Sick alert');
$resolvedSick=callDevice(1,'operator-sick-resolve',['production_id'=>$swapStart['production_id'],'alarm_id'=>(int)$sickAlarm['id'],'pic_uid'=>'11223344','message'=>'QA replacement approved']);
ok($resolvedSick['state']==='operator_change_required' && $resolvedSick['operator_sick_resolved']===true && $resolvedSick['planning_change_required']===true && $resolvedSick['operator_replacement_ready']===false,'PIC resolve keeps detail locked until Planning replacement');
reject(fn()=>callDevice(1,'operator-replacement',['production_id'=>$swapStart['production_id'],'operator_uid'=>'77889900']),403,'replacement RFID rejected before Admin changes Planning');
$db->table('employee_shift_assignments')
    ->where('machine_id',$machine[1])->where('assignment_date',$currentShift['work_date'])->where('shift_id',$currentShift['id'])->where('assignment_role','operator')
    ->update(['employee_id'=>$op3,'updated_at'=>$now]);
$waitingReplacement=callDevice(1,'state');
ok($waitingReplacement['state']==='operator_change_required' && $waitingReplacement['planning_change_required']===false && $waitingReplacement['operator_replacement_ready']===true,'state survives polling/reboot after Admin Planning replacement and waits RFID Operator baru');
$replacement=callDevice(1,'operator-replacement',['production_id'=>$swapStart['production_id'],'operator_uid'=>'77889900']);
ok($replacement['state']==='running' && $replacement['production_shift_detail_id']===$swapStart['production_shift_detail_id'] && $replacement['counter_epoch']===$swapStart['counter_epoch'],'replacement Operator resumes same shift detail/counter epoch');
$swapDetail=$db->table('production_shift_details')->where('id',$swapStart['production_shift_detail_id'])->get()->getRowArray();
ok((int)$swapDetail['operator_employee_id']===$op3,'main shift detail stores current/final replacement Operator');
$operatorHistories=$db->table('production_operator_histories')->where('production_shift_detail_id',$swapStart['production_shift_detail_id'])->orderBy('id')->get()->getResultArray();
$lastTwoHistories=array_slice($operatorHistories,-2);
ok(count($lastTwoHistories)===2 && (int)$lastTwoHistories[0]['employee_id']===$op1 && !empty($lastTwoHistories[0]['ended_at']) && (int)$lastTwoHistories[1]['employee_id']===$op3 && empty($lastTwoHistories[1]['ended_at']),'operator history preserves previous Operator and opens replacement history');
callDevice(1,'finish',$swapContract+['counter_total'=>$swapCounter,'operator_uid'=>'77889900','defects'=>[]]);
$closedReplacementHistory=$db->table('production_operator_histories')->where('production_shift_detail_id',$swapStart['production_shift_detail_id'])->where('employee_id',$op3)->orderBy('id','DESC')->get()->getRowArray();
ok(!empty($closedReplacementHistory['ended_at']),'replacement Operator history closes when shift finishes');
$db->table('employee_shift_assignments')
    ->where('machine_id',$machine[1])->where('assignment_date',$currentShift['work_date'])->where('shift_id',$currentShift['id'])->where('assignment_role','operator')
    ->update(['employee_id'=>$op1,'updated_at'=>$now]);

// Lifetime flow: warning at 5, last warning at 1, critical/service stop at 0.
$db->table('tools')->where('id',$tool[1])->update(['actual_lifetime'=>994,'status'=>'ready','current_edge'=>2,'updated_at'=>$now]);
$lifetimeStart=callDevice(1,'start',$common1+['tool_ids'=>[$tool[1],$secondaryTool[1]]]);
$lc=contract($lifetimeStart);
$warning=callDevice(1,'count',$lc+['counter_total'=>1]);
ok($warning['state']==='running','remaining 5 warns but production remains running');
$warningAlarm=$db->table('production_alarms')->where('production_shift_detail_id',$lifetimeStart['production_shift_detail_id'])->where('tool_id',$tool[1])->where('kind','lifetime')->get()->getRowArray();
ok($warningAlarm['severity']==='warning' && $warningAlarm['effect']==='notify','remaining 5 creates warning notify');
$last=callDevice(1,'count',$lc+['counter_total'=>5]);
ok($last['state']==='running','remaining 1 keeps production running');
$lastAlarm=$db->table('production_alarms')->where('id',$warningAlarm['id'])->get()->getRowArray();
ok($lastAlarm['severity']==='danger' && $lastAlarm['effect']==='notify','remaining 1 upgrades to danger notify');
$critical=callDevice(1,'count',$lc+['counter_total'=>6]);
ok($critical['state']==='service_required','remaining 0 triggers critical service stop');
$criticalAlarm=$db->table('production_alarms')->where('id',$warningAlarm['id'])->get()->getRowArray();
ok($criticalAlarm['severity']==='critical' && $criticalAlarm['effect']==='service_stop','remaining 0 upgrades lifetime alarm to critical');
$reset=callDevice(1,'reset-tool',['tool_id'=>$tool[1],'pic_uid'=>'11223344','reason'=>'QA replace physical tool']);
ok((int)$reset['actual_lifetime']===0 && (int)$reset['current_edge']===1 && $reset['maintenance_pic']['name']==='QA PIC 1','critical Reset Tool resets lifetime/edge and records PIC');
$service=callDevice(1,'service-complete',$lc+['counter_total'=>6,'alarm_id'=>(int)$criticalAlarm['id'],'pic_uid'=>'11223344','message'=>'Tool replaced']);
ok($service['state']==='running','service closes after reset Tool');
callDevice(1,'finish',$lc+['counter_total'=>6,'operator_uid'=>'55667788','defects'=>[]]);

$assign->assign($tool[1],$machine[2],true);
$old=$db->table('production_tool_usages')->where('id',$usage['id'])->get()->getRowArray();
ok($old['position_snapshot']==='P01' && (int)$old['current_edge_snapshot']===1,'reassignment and edge change preserve production snapshot');
$assign->assign($tool[1],$machine[1],true);
reject(fn()=>callDevice(1,'start',['mode'=>'setting']),422,'production endpoint rejects inconsistent mode');
$report=(new App\Services\CycleTimeReport($db))->rows('2000-01-01','2099-12-31',$machine[1],$part,$p1);
ok(count($report)===1 && (int)$report[0]['sample_count']===2 && (int)$report[0]['loading_sample_count']===1,'cycle report groups machine/Part/process excluding first loading sample');
ok((int)$report[0]['machine_variance_ms']===2000 && (int)$report[0]['loading_variance_ms']===2000 && (int)$report[0]['cycle_variance_ms']===5000,'cycle report computes standard/actual/variance');
// Verify actual database constraints via a separate connection (keeps service transaction state clean).
$raw=new mysqli($db->hostname,$db->username,$db->password,$db->database,$db->port);
foreach (["INSERT INTO machine_tools(machine_id,tool_id,assigned_at) VALUES ({$machine[1]},{$tool[1]},NOW())"=>'unique physical tool',"UPDATE tools SET current_edge=4 WHERE id={$tool[1]}"=>'edge range check',"UPDATE machines SET registration_code='QA-REG1' WHERE id={$machine[2]}"=>'unique registration code'] as $sql=>$label) { try { $raw->query($sql); throw new RuntimeException('FAIL constraint '.$label); } catch (mysqli_sql_exception $e) { ok(true,'database '.$label.' constraint'); } }
(new App\Database\Seeds\AdminSeeder(config('Database')))->run();
echo "\n$checks integration assertions passed.\n";
