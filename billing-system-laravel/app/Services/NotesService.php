<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class NotesService
{
    public function userForSubject(string $email): object { return DB::table('users')->where('email',$email)->first() ?? throw new \RuntimeException('User not found'); }
    private function ownedOrder(int $oid,int $uid): object { return DB::table('orders')->where('id',$oid)->where('user_id',$uid)->first() ?? throw new \RuntimeException('Order not found or access denied'); }
    private function order(int $oid): object { return DB::table('orders')->where('id',$oid)->first() ?? throw new \RuntimeException('Order not found'); }
    private function map(object $r): array { return ['id'=>(int)$r->id,'userId'=>(int)$r->user_id,'orderId'=>(int)$r->order_id,'note'=>$r->note,'timeStamp'=>str_replace(' ','T',$r->time_stamp)]; }
    private function row(int $uid,int $oid,string $text): array { $now=now()->format('Y-m-d H:i:s'); $id=DB::table('notes')->insertGetId(['user_id'=>$uid,'order_id'=>$oid,'note'=>$text,'time_stamp'=>$now]); return $this->map(DB::table('notes')->where('id',$id)->first()); }
    public function createOwned(int $uid,int $oid,string $text): array { $this->ownedOrder($oid,$uid); return $this->row($uid,$oid,$text); }
    public function ownedByOrder(int $uid,int $oid): array { $this->ownedOrder($oid,$uid); return DB::table('notes')->where('order_id',$oid)->where('user_id',$uid)->orderByDesc('id')->get()->map(fn($r)=>$this->map($r))->all(); }
    public function createPublic(int $oid,string $text): array { $o=$this->order($oid); return $this->row((int)$o->user_id,$oid,$text); }
    public function publicByOrder(int $oid): array { $this->order($oid); return DB::table('notes')->where('order_id',$oid)->orderByDesc('id')->get()->map(fn($r)=>$this->map($r))->all(); }
}
