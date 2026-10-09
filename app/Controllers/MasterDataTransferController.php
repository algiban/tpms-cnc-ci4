<?php

namespace App\Controllers;

use App\Services\ProductionRules;
use DomainException;
use RuntimeException;
use Throwable;

class MasterDataTransferController extends BaseController
{
    private const IMPORTABLE = [
        'customers', 'materials', 'slots', 'machines', 'tools',
        'tool-types', 'machine-tools', 'parts', 'employees', 'tpms', 'device-assignments',
    ];

    private const EXPORTABLE = [
        'customers', 'materials', 'slots', 'machines', 'tools',
        'tool-types', 'machine-tools', 'parts', 'employees', 'tpms', 'device-assignments',
    ];

    public function import(string $resource)
    {
        if (! in_array($resource, self::IMPORTABLE, true)) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'message' => 'Resource import tidak dikenal.']);
        }

        try {
            $payload = json_decode($this->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rows = $payload['rows'] ?? null;
            if (! is_array($rows) || $rows === []) {
                throw new DomainException('File import tidak memiliki data.', 422);
            }
            if (count($rows) > 2000) {
                throw new DomainException('Import maksimal 2000 baris per file.', 422);
            }

            $db = db_connect();
            if (! $db->transBegin()) {
                throw new RuntimeException('Tidak dapat memulai transaksi import.');
            }

            try {
                (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();
                $count = 0;
                foreach ($rows as $index => $row) {
                    if (! is_array($row)) {
                        throw new DomainException('Baris ' . ($index + 2) . ' tidak valid.', 422);
                    }
                    $normalized = [];
                    foreach ($row as $key => $value) {
                        $normalized[strtolower(trim((string) $key))] = is_string($value) ? trim($value) : $value;
                    }
                    if ($this->blankRow($normalized)) {
                        continue;
                    }
                    $method = 'import' . str_replace(' ', '', ucwords(str_replace('-', ' ', $resource)));
                    $this->{$method}($db, $normalized, $index + 2);
                    $count++;
                }

                if ($count === 0) {
                    throw new DomainException('Tidak ada baris berisi data untuk diimport.', 422);
                }

                if (! $db->transStatus() || ! $db->transCommit()) {
                    throw new RuntimeException('Commit import gagal.');
                }

                return $this->response->setJSON([
                    'ok' => true,
                    'message' => "Import {$resource} berhasil: {$count} baris.",
                    'data' => ['imported' => $count],
                ]);
            } catch (Throwable $e) {
                $db->transRollback();
                throw $e;
            }
        } catch (\JsonException $e) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => 'Payload import tidak valid.']);
        } catch (DomainException $e) {
            $code = in_array((int) $e->getCode(), [400, 409, 422], true) ? (int) $e->getCode() : 422;
            return $this->response->setStatusCode($code)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            log_message('error', 'Master import {resource}: {message}', ['resource' => $resource, 'message' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Import gagal. Periksa log aplikasi.']);
        }
    }

    public function export(string $resource)
    {
        if (! in_array($resource, self::EXPORTABLE, true)) {
            return $this->response->setStatusCode(404)->setBody('Resource export tidak dikenal.');
        }

        $rows = $this->exportRows($resource);
        $filename = 'tpms_' . str_replace('-', '_', $resource) . '_' . date('Ymd_His') . '.csv';

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        if ($rows !== []) {
            fputcsv($stream, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($stream, array_values($row));
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv ?: '');
    }

    private function importCustomers($db, array $r, int $line): void
    {
        $code = $this->required($r, 'code', $line, true);
        $name = $this->required($r, 'name', $line);
        $this->assertUnique($db, 'customers', 'code', $code, $line);
        $db->table('customers')->insert([
            'code' => $code, 'name' => $name,
            'contact_person' => $this->nullable($r, 'contact_person'),
            'email' => $this->nullable($r, 'email'), 'phone' => $this->nullable($r, 'phone'),
            'address' => $this->nullable($r, 'address'), 'status' => $this->value($r, 'status', 'active'),
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function importMaterials($db, array $r, int $line): void
    {
        $code = $this->required($r, 'code', $line, true);
        $this->assertUnique($db, 'materials', 'code', $code, $line);
        $db->table('materials')->insert([
            'code' => $code,
            'name' => $this->required($r, 'name', $line),
            'description' => $this->nullable($r, 'description'),
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function importSlots($db, array $r, int $line): void
    {
        $slot = $this->positiveInt($r['slot_no'] ?? null, "Baris {$line}: slot_no");
        $this->assertUnique($db, 'production_slots', 'slot_no', $slot, $line);
        $db->table('production_slots')->insert([
            'slot_no' => $slot, 'name' => $this->nullable($r, 'name'), 'area' => $this->nullable($r, 'area'),
            'machine_id' => null, 'status' => $this->value($r, 'status', 'offline'),
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function importMachines($db, array $r, int $line): void
    {
        $code = $this->required($r, 'code', $line, true);
        $this->assertUnique($db, 'machines', 'code', $code, $line);
        $registration=$this->required($r,'registration_code',$line,true);
        $this->assertUnique($db,'machines','registration_code',$registration,$line);
        $serial = $this->nullable($r, 'serial_number');
        if ($serial !== null) $this->assertUnique($db, 'machines', 'serial_number', $serial, $line);
        $db->table('machines')->insert([
            'registration_code'=>$registration, 'code' => $code, 'name' => $this->required($r, 'name', $line),
            'maker' => $this->nullable($r, 'maker'), 'model' => $this->nullable($r, 'model'),
            'type' => $this->nullable($r, 'type'), 'power' => $this->nullable($r, 'power'),
            'serial_number' => $serial,
            'year' => $this->nullableInt($r['year'] ?? null),
            'status' => $this->value($r, 'status', 'available'),
            'disposed_at' => null, 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function importTools($db,array $r,int $line): void
    {
        $code=$this->required($r,'code',$line,true);$this->assertUnique($db,'tools','code',$code,$line);
        $type=$db->table('tool_types')->where('code',$this->required($r,'tool_type_code',$line,true))->where('status','active')->get()->getRowArray();
        if (!$type) throw new DomainException("Baris {$line}: Tool Type tidak ditemukan/aktif.",422);
        $edges=ProductionRules::integer($r['cutting_edge']??1,'Cutting Edge',1);$current=ProductionRules::integer($r['current_edge']??1,'Current Edge',1);
        if ($edges>65535 || $current>$edges) throw new DomainException("Baris {$line}: Current Edge harus 1..Cutting Edge dan Cutting Edge maksimal 65535.",422);
        $status=$this->value($r,'status','ready');
        if (!in_array($status,['ready','warning','broken','maintenance','inactive'],true)) throw new DomainException("Baris {$line}: status tool tidak valid.",422);
        $db->table('tools')->insert(['code'=>$code,'name'=>$this->required($r,'name',$line),'tool_type_id'=>$type['id'],'cutting_edge'=>$edges,'current_edge'=>$current,'holder'=>$this->nullable($r,'holder'),'actual_lifetime'=>ProductionRules::integer($r['actual_lifetime']??0,'Actual Lifetime'),'default_lifetime'=>empty($r['default_lifetime'])?null:ProductionRules::integer($r['default_lifetime'],'Default Lifetime',1),'status'=>$status,'notes'=>$this->nullable($r,'notes'),'created_at'=>$this->now(),'updated_at'=>$this->now()]);
    }

    private function importToolTypes($db,array $r,int $line): void
    {
        $code=$this->required($r,'code',$line,true);$this->assertUnique($db,'tool_types','code',$code,$line);
        $status=$this->value($r,'status','active');
        if (!in_array($status,['active','inactive'],true)) throw new DomainException("Baris {$line}: status Tool Type tidak valid.",422);
        $db->table('tool_types')->insert(['code'=>$code,'name'=>$this->required($r,'name',$line),'status'=>$status,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
    }

    private function importMachineTools($db,array $r,int $line): void
    {
        $tool=$db->table('tools')->where('code',$this->required($r,'tool_code',$line,true))->get()->getRowArray();
        $machine=$db->table('machines')->where('code',$this->required($r,'machine_code',$line,true))->where('disposed_at',null)->get()->getRowArray();
        if (!$tool || !$machine) throw new DomainException("Baris {$line}: Tool/Machine tidak ditemukan.",422);
        $existing=$db->table('machine_tools')->where('tool_id',$tool['id'])->get()->getRowArray();
        if ($existing && (int)$existing['machine_id'] !== (int)$machine['id']) {
            throw new DomainException("Baris {$line}: tool {$tool['code']} sudah dimiliki Machine lain. Lepas dari Machine lama terlebih dahulu.",409);
        }
        if (!$existing) (new \App\Services\MachineToolService($db))->assign((int)$tool['id'],(int)$machine['id']);
    }

    private function importEmployees($db, array $r, int $line): void
    {
        $nik = $this->required($r, 'nik', $line, true);
        $this->assertUnique($db, 'employees', 'nik', $nik, $line);
        $uidRaw = $this->nullable($r, 'rfid_uid');
        $uid = $uidRaw !== null ? ProductionRules::uid($uidRaw) : null;
        if ($uid !== null) $this->assertUnique($db, 'employees', 'rfid_uid', $uid, $line);
        $roleKey = strtolower(str_replace([' ', '-'], '_', $this->value($r, 'role', 'operator')));
        $role = match ($roleKey) {
            'operator' => 'Operator',
            'kanit', 'unit_head', 'unithead', 'kepala_unit' => 'Kanit',
            'pic' => 'PIC',
            default => throw new DomainException(
                "Baris {$line}: role hanya Operator, Kanit/Unit Head, atau PIC.",
                422
            ),
        };
        $db->table('employees')->insert([
            'nik' => $nik, 'name' => $this->required($r, 'name', $line),
            'department' => $this->required($r, 'department', $line), 'role' => $role,
            'rfid_uid' => $uid, 'status' => $this->value($r, 'status', 'active'),
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function importParts($db,array $r,int $line): void
    {
        $customer=$db->table('customers')->where('code',$this->required($r,'customer_code',$line,true))->get()->getRowArray();
        if (!$customer) throw new DomainException("Baris {$line}: Customer tidak ditemukan.",422);
        $material=null;
        if ($this->nullable($r,'material_code')) {
            $material=$db->table('materials')->where('code',strtoupper($r['material_code']))->get()->getRowArray();
            if (!$material) throw new DomainException("Baris {$line}: Material tidak ditemukan.",422);
        }
        $type=$this->value($r,'process_type','single_auto');preg_match('/^process_(\d)_/',$type,$match);$count=(int)($match[1]??1);
        $machine=explode(';',(string)($r['process_machine_time_seconds']??''));$loading=explode(';',(string)($r['process_loading_time_seconds']??''));
        if (count($machine)!==$count || count($loading)!==$count) throw new DomainException("Baris {$line}: jumlah Machine/Loading Time harus sesuai jumlah process.",422);
        $requirements=[];
        foreach ($this->parseSetup($this->nullable($r,'process_tool_types')) as $setup) {
            if (count($setup)!==4) throw new DomainException("Baris {$line}: format process_tool_types = process_no|TYPE_CODE|POSITION|set_lifetime.",422);
            $no=ProductionRules::integer($setup[0],'Process No',1);
            if ($no>$count) throw new DomainException("Baris {$line}: Process No tidak sesuai Part.",422);
            $tt=$db->table('tool_types')->where('code',strtoupper($setup[1]))->where('status','active')->get()->getRowArray();
            if (!$tt) throw new DomainException("Baris {$line}: Tool Type {$setup[1]} tidak aktif/ditemukan.",422);
            $requirements[$no][]=['tool_type_id'=>$tt['id'],'position'=>$setup[2],'set_lifetime'=>$setup[3]];
        }
        (new \App\Services\PartConfigurationService($db))->save(['part_number'=>$r['part_number']??null,'name'=>$r['name']??null,'customer_id'=>$customer['id'],'material_id'=>$material['id']??null,'process_type'=>$type,'next_grinding'=>$r['next_grinding']??0,'shifts_per_day'=>$r['shifts_per_day']??3,'process_machine_time_seconds'=>array_combine(range(1,$count),$machine),'process_loading_time_seconds'=>array_combine(range(1,$count),$loading),'process_requirements'=>$requirements]);
    }

    private function importTpms($db, array $r, int $line): void
    {
        $mac = strtoupper($this->required($r, 'mac_address', $line));
        $this->assertUnique($db, 'tpms_devices', 'mac_address', $mac, $line);
        $slotId = null;
        if ($this->nullable($r, 'slot_no') !== null) {
            $slot = $db->table('production_slots')->where('slot_no', (int) $r['slot_no'])->get()->getRowArray();
            if (! $slot) throw new DomainException("Baris {$line}: slot_no tidak ditemukan.", 422);
            $slotId = (int) $slot['id'];
        }
        $token = $this->nullable($r, 'token') ?: bin2hex(random_bytes(32));
        $this->assertUnique($db, 'tpms_devices', 'token', $token, $line);
        $db->table('tpms_devices')->insert([
            'mac_address'=>$mac,'ip_address'=>$this->nullable($r,'ip_address'),'firmware_version'=>$this->nullable($r,'firmware_version'),
            'hmi_version'=>$this->nullable($r,'hmi_version'),'token'=>$token,'current_slot_id'=>$slotId,
            'device_status'=>$this->value($r,'device_status','offline'),'connection_status'=>$this->value($r,'connection_status','unknown'),
            'registered_at'=>$this->now(),'last_seen_at'=>null,'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ]);
    }

    private function importDeviceAssignments($db, array $r, int $line): void
    {
        $mac = strtoupper($this->required($r,'mac_address',$line));
        $slotNo = $this->positiveInt($r['slot_no'] ?? null, "Baris {$line}: slot_no");
        $device = $db->table('tpms_devices')->where('mac_address',$mac)->get()->getRowArray();
        $slot = $db->table('production_slots')->where('slot_no',$slotNo)->get()->getRowArray();
        if (! $device) throw new DomainException("Baris {$line}: TPMS {$mac} tidak ditemukan.",422);
        if (! $slot) throw new DomainException("Baris {$line}: slot {$slotNo} tidak ditemukan.",422);
        $other = $db->table('tpms_devices')->where('current_slot_id',$slot['id'])->where('id !=',$device['id'])->get()->getRowArray();
        if ($other) throw new DomainException("Baris {$line}: slot {$slotNo} sudah dipakai TPMS {$other['mac_address']}.",409);
        (new \App\Services\ProductionRuntimeGuard($db))->assertDeviceMutable((int)$device['id']);
        (new \App\Services\ProductionRuntimeGuard($db))->assertSlotMutable((int)$slot['id']);
        $from = $device['current_slot_id'];
        $db->table('tpms_devices')->where('id',$device['id'])->update(['current_slot_id'=>(int)$slot['id'],'updated_at'=>$this->now()]);
        $db->table('tpms_assignment_histories')->insert([
            'tpms_device_id'=>(int)$device['id'],'from_slot_id'=>$from ?: null,'to_slot_id'=>(int)$slot['id'],
            'event_type'=>'import_assign','token'=>$device['token'] ?? null,'ip_address'=>$device['ip_address'] ?? null,
            'notes'=>'Assignment dari Master Data Import.','happened_at'=>$this->now(),'created_at'=>$this->now(),'updated_at'=>$this->now(),
        ]);
    }

    private function exportRows(string $resource): array
    {
        $db = db_connect();
        return match ($resource) {
            'customers' => $db->table('customers')->select('code,name,contact_person,email,phone,address,status,created_at,updated_at')->orderBy('code')->get()->getResultArray(),
            'materials' => $db->table('materials')->select('code,name,description,created_at,updated_at')->orderBy('code')->get()->getResultArray(),
            'slots' => $db->table('production_slots s')->select('s.slot_no,s.name,s.area,s.status,m.code machine_code')->join('machines m','m.id=s.machine_id','left')->orderBy('s.slot_no')->get()->getResultArray(),
            'machines' => $db->table('machines')->select('registration_code,code,name,maker,model,type,power,serial_number,year,status,disposed_at,created_at,updated_at')->orderBy('code')->get()->getResultArray(),
            'tools' => $db->table('tools t')->select('t.code,t.name,tt.code tool_type_code,t.cutting_edge,t.current_edge,t.holder,t.actual_lifetime,t.default_lifetime,t.status,t.notes')->join('tool_types tt','tt.id=t.tool_type_id')->orderBy('t.code')->get()->getResultArray(),
            'tool-types' => $db->table('tool_types')->select('code,name,status')->orderBy('code')->get()->getResultArray(),
            'machine-tools' => $db->table('machine_tools mt')->select('m.code machine_code,t.code tool_code')->join('machines m','m.id=mt.machine_id')->join('tools t','t.id=mt.tool_id')->orderBy('m.code')->orderBy('t.code')->get()->getResultArray(),

            'parts' => $this->exportParts($db),
            'employees' => $db->table('employees')->select('nik,name,department,role,rfid_uid,status,created_at,updated_at')->orderBy('nik')->get()->getResultArray(),
            'tpms' => $db->table('tpms_devices d')->select('d.mac_address,d.ip_address,d.firmware_version,d.hmi_version,d.token,s.slot_no,d.device_status,d.connection_status,d.registered_at,d.last_seen_at')->join('production_slots s','s.id=d.current_slot_id','left')->orderBy('d.mac_address')->get()->getResultArray(),
            'device-assignments' => $db->table('tpms_devices d')->select('d.mac_address,s.slot_no,m.code machine_code,m.name machine_name,d.updated_at')->join('production_slots s','s.id=d.current_slot_id','left')->join('machines m','m.id=s.machine_id','left')->orderBy('s.slot_no')->get()->getResultArray(),
            default => [],
        };
    }

    private function exportParts($db): array
    {
        $parts=$db->table('parts p')->select('p.*,c.code customer_code,m.code material_code')->join('customers c','c.id=p.customer_id')->join('materials m','m.id=p.material_id','left')->orderBy('p.part_number')->get()->getResultArray();$out=[];
        foreach ($parts as $p) {
            $processes=$db->table('part_processes')->where('part_id',$p['id'])->where('status','active')->orderBy('process_no')->get()->getResultArray();$requirements=[];$plans=[];
            foreach ($processes as $process) {
                foreach ($db->table('process_tool_requirements r')->select('r.position,r.set_lifetime,tt.code')->join('tool_types tt','tt.id=r.tool_type_id')->where('r.part_process_id',$process['id'])->orderBy('r.position')->orderBy('tt.code')->get()->getResultArray() as $req) $requirements[]=$process['process_no'].'|'.$req['code'].'|'.$req['position'].'|'.$req['set_lifetime'];
                try { $plans[]=\App\Services\ProcessPlan::calculate((int)$process['machine_time_target_ms'],(int)$process['loading_time_target_ms'],(int)$p['shifts_per_day'])['plan_per_shift']; }
                catch (DomainException $e) { $plans[]=''; }
            }
            $out[]=['part_number'=>$p['part_number'],'name'=>$p['name'],'customer_code'=>$p['customer_code'],'material_code'=>$p['material_code'],'process_type'=>$p['process_type'],'next_grinding'=>$p['next_grinding'],'shifts_per_day'=>$p['shifts_per_day'],'process_machine_time_seconds'=>implode(';',array_map(static fn($x)=>$x['machine_time_target_ms']/1000,$processes)),'process_loading_time_seconds'=>implode(';',array_map(static fn($x)=>$x['loading_time_target_ms']/1000,$processes)),'process_tool_types'=>implode(';',$requirements),'plan_per_shift'=>implode(';',$plans),'status'=>$p['status']];
        }
        return $out;
    }

    private function blankRow(array $row): bool { foreach ($row as $v) if ($v !== '' && $v !== null) return false; return true; }
    private function required(array $r,string $key,int $line,bool $upper=false): string { $v=trim((string)($r[$key]??'')); if($v==='') throw new DomainException("Baris {$line}: {$key} wajib diisi.",422); return $upper?strtoupper($v):$v; }
    private function nullable(array $r,string $key): ?string { $v=trim((string)($r[$key]??'')); return $v===''?null:$v; }
    private function value(array $r,string $key,string $default): string { $v=trim((string)($r[$key]??'')); return $v===''?$default:$v; }
    private function positiveInt($v,string $label): int { return ProductionRules::integer($v,$label,1); }
    private function nullableInt($v): ?int { return $v===''||$v===null?null:(int)$v; }
    private function assertUnique($db,string $table,string $field,$value,int $line): void { if($db->table($table)->where($field,$value)->countAllResults()>0) throw new DomainException("Baris {$line}: {$field} '{$value}' sudah ada.",409); }
    private function now(): string { return date('Y-m-d H:i:s'); }
    private function boolValue($v): bool { return in_array(strtolower(trim((string)$v)),['1','true','yes','y','iya','ya'],true); }
    private function parseSetup(?string $v): array { if($v===null||trim($v)==='') return []; $out=[]; foreach(explode(';',$v) as $item){$item=trim($item);if($item==='')continue;$parts=array_map('trim',explode('|',$item));if(($parts[0]??'')!=='')$out[]=$parts;} return $out; }
    private function numberList($v,int $count): array { $parts=is_string($v)?array_map('trim',explode(';',$v)):[(string)$v]; if(count($parts)===1)$parts=array_fill(0,$count,$parts[0]); while(count($parts)<$count)$parts[]=$parts[count($parts)-1]??'0'; return array_map(fn($x)=>max(0,(float)$x),array_slice($parts,0,$count)); }
}
