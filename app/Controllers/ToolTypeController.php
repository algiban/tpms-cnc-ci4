<?php
namespace App\Controllers;
use App\Services\ProductionRules;
use DomainException;
use Throwable;

final class ToolTypeController extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $builder = $db->table('tool_types tt')->select('tt.*');
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('tt.code', $q)->orLike('tt.name', $q)->groupEnd();
        }
        $status = strtolower(trim((string) $this->request->getGet('status')));
        if (in_array($status, ['active', 'inactive'], true)) {
            $builder->where('tt.status', $status);
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'code' => 'tt.code', 'name' => 'tt.name', 'status' => 'tt.status',
        ], 'code', 'tt.id');
        $typeStats = $db->table('tool_types')
            ->select("COUNT(*) total, SUM(status = 'active') active", false)
            ->get()->getRowArray();
        return view('master-data/tool-types', [
            'title' => 'Tool Types', 'types' => $pagination['rows'],
            'pagination' => $pagination, 'typeStats' => $typeStats,
        ]);
    }

    public function save(?int $id = null)
    {
        $db=db_connect();$db->transBegin();
        try {
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();
            $code=strtoupper(ProductionRules::text($this->request->getPost('code'),'Tool Type Code',100));
            $q=$db->table('tool_types')->where('code',$code);if ($id) $q->where('id !=',$id);
            if ($q->countAllResults()) throw new DomainException('Tool Type Code sudah digunakan.');
            $status=(string)($this->request->getPost('status')?:'active');
            if (!in_array($status,['active','inactive'],true)) throw new DomainException('Status Tool Type tidak valid.');
            if ($id) {
                if (!$db->table('tool_types')->where('id',$id)->countAllResults()) throw new DomainException('Tool Type tidak ditemukan.');
                $active=$db->table('process_tool_requirements r')->join('productions p','p.part_process_id=r.part_process_id')->join('production_shift_details d','d.production_id=p.id')->where('r.tool_type_id',$id)->whereIn('d.status',['running','paused','setting','service_required','awaiting_defects','operator_change_required'])->countAllResults();
                if ($active) throw new DomainException('Tool Type sedang dipakai sesi aktif.');
            }
            $data=['code'=>$code,'name'=>ProductionRules::text($this->request->getPost('name'),'Tool Type Name',150),'status'=>$status,'updated_at'=>date('Y-m-d H:i:s')];
            if ($id) $db->table('tool_types')->where('id',$id)->update($data);
            else $db->table('tool_types')->insert($data+['created_at'=>date('Y-m-d H:i:s')]);
            if (!$db->transStatus()) throw new \RuntimeException('Database gagal.');
            $db->transCommit(); return redirect()->back()->with('success','Tool Type berhasil disimpan.');
        } catch (Throwable $e) { $db->transRollback(); if (!$e instanceof DomainException) log_message('error','Tool Type: {message}',['message'=>$e->getMessage()]); return redirect()->back()->withInput()->with('error',$e instanceof DomainException?$e->getMessage():'Penyimpanan Tool Type gagal.'); }
    }
}
