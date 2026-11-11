<?php

namespace App\Http\Controllers;

use App\Services\ClientBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function __construct(private ClientBalanceService $balances) {}
    private function uid(Request $r): int { $id=DB::table('users')->where('email',$r->attributes->get('jwt_subject'))->value('id'); if(!$id) throw new \RuntimeException('User not found'); return (int)$id; }
    private function client(Request $r, int $id): object
    {
        $c=DB::table('clients')->where('id',$id)->where('user_id',$this->uid($r))->first();
        if(!$c) throw new InvalidArgumentException('Client not found'); return $c;
    }
    private function payment(int $id,int $uid): object
    {
        $p=DB::table('payments')->where('id',$id)->first();
        if($p && ((int)$p->user_id!==$uid || $p->deleted)) throw new InvalidArgumentException('Unauthorized: Payment does not belong to this user');
        if(!$p) throw new InvalidArgumentException('Payment not found'); return $p;
    }
    private function serialized(object $p): array
    {
        $client=DB::table('clients')->where('id',$p->client_id)->first();
        $user=DB::table('users')->where('id',$p->user_id)->first();
        $flow=null;
        if($user && !empty($user->default_production_stage_flow_id) && \Illuminate\Support\Facades\Schema::hasTable('production_stage_flows')) {
            $f=DB::table('production_stage_flows')->where('id',$user->default_production_stage_flow_id)->first();
            if($f){$steps=[];if(\Illuminate\Support\Facades\Schema::hasTable('production_stage_flow_steps')&&\Illuminate\Support\Facades\Schema::hasTable('production_stages')){$steps=DB::table('production_stage_flow_steps as fs')->join('production_stages as s','s.id','=','fs.stage_id')->where('fs.flow_id',$f->id)->orderBy('fs.sequence')->orderBy('fs.id')->get()->map(fn($x)=>['id'=>(int)$x->id,'stage'=>['id'=>(int)$x->stage_id,'name'=>$x->name,'displayOrder'=>(int)$x->display_order,'active'=>(bool)$x->active,'createdAt'=>$x->created_at,'updatedAt'=>$x->updated_at],'sequence'=>(int)$x->sequence])->values()->all();}$flow=['id'=>(int)$f->id,'name'=>$f->name,'active'=>(bool)$f->active,'createdAt'=>$f->created_at,'updatedAt'=>$f->updated_at,'steps'=>$steps];}
        }
        return ['id'=>(int)$p->id,'user'=>$user?['id'=>(int)$user->id,'email'=>$user->email,'globalWorkerRatePerSquareFoot'=>$user->global_worker_rate_per_square_foot===null?null:(float)$user->global_worker_rate_per_square_foot,'defaultProductionStageFlow'=>$flow]:null,
            'client'=>$client?['id'=>(int)$client->id,'clientName'=>$client->clientName,'phoneNumber'=>$client->phoneNumber,'address'=>$client->address]:null,
            'amount'=>(float)$p->amount,'allocatedAmount'=>(float)$p->allocated_amount,'unallocatedAmount'=>(float)$p->unallocated_amount,
            'paymentDate'=>$p->payment_date,'paymentMode'=>$p->payment_mode,'referenceNo'=>$p->reference_no,'note'=>$p->note,
            'createdAt'=>$p->created_at,'updatedAt'=>$p->updated_at,'deleted'=>(bool)$p->deleted];
    }
    private function responseList($rows) { return response()->json(['message'=>'Payments retrieved successfully','count'=>$rows->count(),'payments'=>$rows->map(fn($p)=>$this->serialized($p))->values()]); }
    private function err(\Throwable $e) { return response()->json(['error'=>$e->getMessage()],400); }
    private function mode(mixed $mode): string
    {
        $value=strtoupper((string)$mode); if(!in_array($value,['CASH','UPI','BANK_TRANSFER','CARD','CHEQUE','OTHER'],true)) throw new InvalidArgumentException('No enum constant PaymentMode.' . $value); return $value;
    }
    private function validDate(mixed $date): string { if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!\DateTime::createFromFormat('!Y-m-d',$date))throw new InvalidArgumentException('Text \' '.(string)$date.' \' could not be parsed');return $date; }

    public function create(Request $r)
    {
        try {
            $uid=$this->uid($r);$clientId=$r->input('customerId',$r->input('clientId'));if($clientId===null)throw new InvalidArgumentException('Customer is required');$this->client($r,(int)$clientId);
            $amount=\Brick\Math\BigDecimal::of((string)$r->input('amount'))->toScale(2,\Brick\Math\RoundingMode::HALF_UP);if($amount->compareTo(0)<=0)throw new InvalidArgumentException('Payment amount must be greater than zero');
            $date=$this->validDate($r->input('paymentDate'));$mode=$this->mode($r->input('paymentMode'));$now=now()->format('Y-m-d H:i:s');
            $id=DB::transaction(function()use($uid,$clientId,$amount,$date,$mode,$r,$now){$id=DB::table('payments')->insertGetId(['user_id'=>$uid,'client_id'=>(int)$clientId,'amount'=>(string)$amount,'allocated_amount'=>'0.00','unallocated_amount'=>'0.00','payment_date'=>$date,'payment_mode'=>$mode,'reference_no'=>$r->input('referenceNo'),'note'=>$r->input('note'),'created_at'=>$now,'updated_at'=>$now,'deleted'=>false]);$this->balances->recordPaymentReceived($uid,(int)$clientId,(string)$amount,(int)$id,$r->input('note'));return $id;});
            $p=DB::table('payments')->where('id',$id)->first();return response()->json(['message'=>'Payment created successfully','payment'=>$this->serialized($p)],201);
        } catch(InvalidArgumentException $e) { return $this->err($e); } catch(\Throwable $e) { return $this->err(new InvalidArgumentException('Invalid request: '.$e->getMessage(),0,$e)); }
    }
    public function byCustomer(Request $r,int $customerId) { try{$this->client($r,$customerId);return $this->responseList(DB::table('payments')->where('client_id',$customerId)->where('user_id',$this->uid($r))->where('deleted',false)->orderBy('payment_date')->orderBy('created_at')->get());}catch(\Throwable $e){return $this->err($e);} }
    public function index(Request $r) { try{return $this->responseList(DB::table('payments')->where('user_id',$this->uid($r))->where('deleted',false)->orderBy('payment_date')->orderBy('created_at')->get());}catch(\Throwable $e){return $this->err($e);} }
    public function update(Request $r,int $paymentId)
    {
        try {
            $uid=$this->uid($r);$p=$this->payment($paymentId,$uid);$old=$p->amount;$attrs=[];
            if($r->input('amount')!==null){$amount=\Brick\Math\BigDecimal::of((string)$r->input('amount'))->toScale(2,\Brick\Math\RoundingMode::HALF_UP);if($amount->compareTo(0)<=0)throw new InvalidArgumentException('Payment amount must be greater than zero');$attrs['amount']=(string)$amount;$attrs['unallocated_amount']='0.00';}
            if($r->input('paymentDate')!==null)$attrs['payment_date']=$this->validDate($r->input('paymentDate'));
            if($r->input('paymentMode')!==null)$attrs['payment_mode']=$this->mode($r->input('paymentMode'));
            if($r->input('referenceNo')!==null)$attrs['reference_no']=$r->input('referenceNo');if($r->input('note')!==null)$attrs['note']=$r->input('note');$attrs['updated_at']=now()->format('Y-m-d H:i:s');
            DB::transaction(function()use($attrs,$paymentId,$uid,$p,$r){DB::table('payments')->where('id',$paymentId)->where('user_id',$uid)->update($attrs);if(array_key_exists('amount',$attrs))$this->balances->recordPaymentUpdated($uid,(int)$p->client_id,$p->amount,$attrs['amount'],$paymentId,$r->input('note'));});
            return response()->json(['message'=>'Payment updated successfully','payment'=>$this->serialized(DB::table('payments')->where('id',$paymentId)->first())]);
        }catch(InvalidArgumentException $e){return $this->err($e);}catch(\Throwable $e){return $this->err(new InvalidArgumentException('Invalid request: '.$e->getMessage(),0,$e));}
    }
    public function delete(Request $r,int $paymentId)
    {
        try{$uid=$this->uid($r);$p=$this->payment($paymentId,$uid);DB::transaction(function()use($uid,$p,$paymentId){DB::table('payments')->where('id',$paymentId)->where('user_id',$uid)->update(['deleted'=>true,'updated_at'=>now()->format('Y-m-d H:i:s')]);$this->balances->recordPaymentDeleted($uid,(int)$p->client_id,$p->amount,$paymentId,$p->note);});return response()->json(['message'=>'Payment deleted successfully']);}catch(\Throwable $e){return $this->err($e);}
    }
}
