<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class ToolEdgeService
{
    public function __construct(private BaseConnection $db) {}

    // Caller owns the transaction and holds the appropriate per-device/runtime barrier.
    public function change(int $toolId,int $edge,string $reason,string $source,array $actor=[]): void
    {
        $tool=$this->db->table('tools')->where('id',$toolId)->get()->getRowArray();
        if (!$tool) throw new DomainException('Tool tidak ditemukan.',404);
        if ($edge <= (int)$tool['current_edge'] || $edge > (int)$tool['cutting_edge']) throw new DomainException('Current Edge harus lebih besar dari edge aktif dan tidak melebihi Cutting Edge. Ganti tool fisik untuk kembali ke edge 1.',422);
        $usage=$this->db->table('production_tool_usages u')->select('u.id,d.status')->join('production_shift_details d','d.id=u.production_shift_detail_id')->where('u.tool_id',$toolId)->whereIn('d.status',['running','paused','service_required','awaiting_defects','operator_change_required','setting'])->orderBy('d.id','DESC')->get()->getRowArray();
        if ($usage && $usage['status']!=='service_required') throw new DomainException('Change Edge hanya boleh ketika machine idle atau Service Required.',409);
        $now=date('Y-m-d H:i:s');
        $this->db->table('tools')->where('id',$toolId)->update(['current_edge'=>$edge,'actual_lifetime'=>0,'status'=>'ready','updated_at'=>$now]);
        if ($usage) $this->db->table('production_tool_usages')->where('id',$usage['id'])->update(['end_lifetime'=>0,'updated_at'=>$now]);
        $actorLabel = ! empty($actor['pic_name'])
            ? sprintf(' PIC %s (%s).', $actor['pic_name'], $actor['pic_nik'] ?? '-')
            : '';
        $message=sprintf('Edge %d/%d → %d/%d. Lifetime edge baru = 0. %s%s',$tool['current_edge'],$tool['cutting_edge'],$edge,$tool['cutting_edge'],$reason,$actorLabel);
        $this->db->table('tool_lifetime_logs')->insert(['tool_id'=>$toolId,'event_type'=>'edge_change','previous_lifetime'=>$tool['actual_lifetime'],'new_lifetime'=>0,'reason'=>$message,'actor_user_id'=>$source==='web'?(session()->get('user_id')?:null):null,'occurred_at'=>$now,'created_at'=>$now]);
        (new ActivityLogService())->tool($toolId,'edge_change',$tool,$this->db->table('tools')->where('id',$toolId)->get()->getRowArray(),[
            'source'=>$source,
            'actor_type'=>!empty($actor['pic_employee_id'])?'employee':($source==='web'?'user':'system'),
            'message'=>$message,
            'previous_lifetime'=>$tool['actual_lifetime'],
            'new_lifetime'=>0,
            'metadata'=>array_merge(['previous_edge'=>(int)$tool['current_edge'],'new_edge'=>$edge],$actor),
        ]);
        // Alarm remains active until service-complete confirms physical maintenance.
    }
}
