<?php
namespace App\Controllers;
use App\Models\CustomerModel;
class CustomerController extends BaseController
{
    public function store(){ if(!$this->validate(['code'=>'required|max_length[50]|is_unique[customers.code]','name'=>'required|max_length[150]','email'=>'permit_empty|valid_email'])) return redirect()->back()->withInput()->with('errors',$this->validator->getErrors()); (new CustomerModel())->insert($this->payload()); return redirect()->to('/master-data/customers')->with('success','Customer berhasil ditambahkan.'); }
    public function update(int $id){ $m=new CustomerModel(); if(!$m->find($id)) return redirect()->back()->with('error','Customer tidak ditemukan.'); if(!$this->validate(['code'=>"required|max_length[50]|is_unique[customers.code,id,{$id}]",'name'=>'required|max_length[150]','email'=>'permit_empty|valid_email'])) return redirect()->back()->withInput()->with('errors',$this->validator->getErrors()); $m->update($id,$this->payload()); return redirect()->to('/master-data/customers')->with('success','Customer berhasil diperbarui.'); }
    public function delete(int $id){ $db=db_connect(); if($db->table('parts')->where('customer_id',$id)->countAllResults()>0) return redirect()->back()->with('error','Customer masih memiliki part dan tidak dapat dihapus.'); (new CustomerModel())->delete($id); return redirect()->to('/master-data/customers')->with('success','Customer berhasil dihapus.'); }
    private function payload():array{ return ['code'=>strtoupper(trim((string)$this->request->getPost('code'))),'name'=>trim((string)$this->request->getPost('name')),'contact_person'=>trim((string)$this->request->getPost('contact_person'))?:null,'email'=>trim((string)$this->request->getPost('email'))?:null,'phone'=>trim((string)$this->request->getPost('phone'))?:null,'address'=>trim((string)$this->request->getPost('address'))?:null,'status'=>$this->request->getPost('status')?:'active']; }
}
