<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    private function userId(Request $r): int
    {
        $id=DB::table('users')->where('email',$r->attributes->get('jwt_subject'))->value('id');
        if (!$id) throw new \RuntimeException('User not found'); return (int)$id;
    }
    private function err(\Throwable $e): JsonResponse { return response()->json(['error'=>$e->getMessage()],400); }
    private function date(?string $v): ?string
    {
        if (!$v) return null;
        return (new \DateTimeImmutable($v))->format('Y-m-d\TH:i:s');
    }
    private function data(object $b): array
    {
        $client=DB::table('clients')->where('id',$b->client_id)->first();
        return ['id'=>(int)$b->id,'date'=>$this->date($b->date),'formattedDate'=>(new \DateTimeImmutable($b->date))->format('d-m-Y H:i:s'),
            'totalAmount'=>(float)$b->total_amount,'subtotalAmount'=>(float)$b->subtotal_amount,'discountAmount'=>(float)$b->discount_amount,
            'description'=>$b->description,'status'=>$b->status,'confirmedAt'=>$this->date($b->confirmed_at),
            'clientId'=>$client?(int)$client->id:null,'clientName'=>$client?->clientName,'orders'=>$this->billing->orders((int)$b->id),'qrCodePath'=>$b->qr_code_path];
    }

    public function store(Request $r): JsonResponse
    {
        try {
            $uid=$this->userId($r); $v=$r->all();
            if (!isset($v['clientId']) || !is_numeric($v['clientId'])) throw new \RuntimeException('Invalid request: clientId is invalid');
            $ids=isset($v['orderIds']) && is_array($v['orderIds']) ? array_map(static fn($x)=>(int)$x,$v['orderIds']) : [];
            $b=$this->billing->create($ids,(int)$v['clientId'],$uid,(string)($v['discountAmount']??'0'),isset($v['description'])?(string)$v['description']:null);
            return response()->json(['message'=>'Bill generated successfully','bill'=>$this->data($b),'billId'=>(int)$b->id,
                'formattedDate'=>(new \DateTimeImmutable($b->date))->format('d-m-Y H:i:s'),'totalAmount'=>(float)$b->total_amount,'orderCount'=>count($this->billing->orders((int)$b->id))],201);
        } catch(\InvalidArgumentException $e) { return response()->json(['error'=>$e->getMessage()],400); }
          catch(\Throwable $e) { return response()->json(['error'=>'Invalid request: '.$e->getMessage()],400); }
    }
    public function generated(Request $r): JsonResponse
    {
        try { $rows=$this->billing->generated($this->userId($r)); return response()->json(array_map(function($b) {
            $client=DB::table('clients')->where('id',$b->client_id)->first();
            return ['id'=>(int)$b->id,'date'=>$this->date($b->date),'totalAmount'=>(float)$b->total_amount,
                'subtotalAmount'=>(float)$b->subtotal_amount,'discountAmount'=>(float)$b->discount_amount,'description'=>$b->description,
                'status'=>$b->status,'confirmedAt'=>$this->date($b->confirmed_at),'client'=>$client?['id'=>(int)$client->id,'clientName'=>$client->clientName,
                    'phoneNumber'=>$client->phoneNumber,'address'=>$client->address,'user'=>null]:null,'orders'=>$this->billing->orders((int)$b->id),'qrCodePath'=>$b->qr_code_path];
        },$rows)); } catch(\Throwable $e){return $this->err($e);}
    }
    public function show(Request $r,int $billId)
    {
        try { $uid=$this->userId($r); $b=$this->billing->bill($billId); if (!$b) return response('',404);
            if ((int)$b->user_id!==$uid) return response()->json(['error'=>'Unauthorized: Bill does not belong to the authenticated user'],403);
            return response()->json(['bill'=>$this->data($b),'formattedDate'=>(new \DateTimeImmutable($b->date))->format('d-m-Y H:i:s'),'totalAmount'=>(float)$b->total_amount,'orderCount'=>count($this->billing->orders($billId))]);
        } catch(\Throwable $e){return $this->err($e);}
    }
    public function byClient(Request $r,int $clientId): JsonResponse
    {
        try { $uid=$this->userId($r); $c=DB::table('clients')->where('id',$clientId)->first(); if (!$c || (int)$c->user_id!==$uid) return response()->json(['error'=>'Client not found'],400);
            $rows=$this->billing->listByClient($clientId); return response()->json(['bills'=>$rows->map(fn($b)=>$this->data($b))->all(),'billCount'=>$rows->count()]);
        } catch(\Throwable $e){return $this->err($e);}
    }
    public function index(Request $r): JsonResponse { try{$rows=$this->billing->listByUser($this->userId($r));return response()->json(['bills'=>$rows->map(fn($b)=>$this->data($b))->all(),'billCount'=>$rows->count()]);}catch(\Throwable $e){return $this->err($e);} }
    public function destroy(Request $r,int $billId)
    {
        try{$uid=$this->userId($r);$b=$this->billing->bill($billId);if(!$b)return response('',404);if((int)$b->user_id!==$uid)return response()->json(['error'=>'Unauthorized: Bill does not belong to the authenticated user'],403);
            $this->billing->delete($billId,$uid);return response()->json(['message'=>'Bill deleted successfully']);}catch(\Throwable $e){return $this->err($e);}
    }
    public function update(Request $r,int $billId): JsonResponse
    {
        try{$b=$this->billing->updateDraft($billId,$this->userId($r),(string)($r->input('discountAmount')??'0'),$r->input('description'));return response()->json(['message'=>'Draft bill updated successfully','bill'=>$this->data($b)]);}catch(\Throwable $e){return $this->err($e);}
    }
    public function confirm(Request $r,int $billId): JsonResponse
    {
        try{$b=$this->billing->confirm($billId,$this->userId($r));return response()->json(['message'=>'Bill confirmed successfully','bill'=>$this->data($b)]);}catch(\Throwable $e){return $this->err($e);}
    }
}
