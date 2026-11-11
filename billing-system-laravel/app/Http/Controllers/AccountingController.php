<?php

namespace App\Http\Controllers;

use App\Services\ClientBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AccountingController extends Controller
{
    public function __construct(private ClientBalanceService $balances) {}
    private function uid(Request $r): int { $id = DB::table('users')->where('email', $r->attributes->get('jwt_subject'))->value('id'); if (!$id) throw new \RuntimeException('User not found'); return (int) $id; }
    private function client(Request $r, int $id): object
    {
        $c = DB::table('clients')->where('id', $id)->where('user_id', $this->uid($r))->first();
        if (!$c) throw new InvalidArgumentException('Client not found');
        return $c;
    }
    private function error(\Throwable $e) { return response()->json(['error' => $e->getMessage()], 400); }
    private function guarded(callable $fn) { try { return $fn(); } catch (\Throwable $e) { return $this->error($e); } }
    private function dateParam(Request $r, string $a, string $b): ?string { $v = $r->query($a, $r->query($b)); return $v === null || trim($v) === '' ? null : date('Y-m-d', strtotime($v)); }

    public function summary(Request $r, int $customerId) { return $this->guarded(function () use ($r, $customerId) { $this->client($r, $customerId); return response()->json($this->balances->getBalance($this->uid($r), $customerId)); }); }
    public function balance(Request $r, int $customerId) { return $this->summary($r, $customerId); }
    public function balances(Request $r)
    {
        return $this->guarded(function () use ($r) {
            $uid = $this->uid($r);
            $latest = DB::table('client_balance_ledger')->select('client_id', DB::raw('MAX(entry_date) as latest_date'))->where('user_id', $uid)->groupBy('client_id');
            $clients = DB::table('clients as c')->leftJoinSub($latest, 'l', fn($j) => $j->on('c.id', '=', 'l.client_id'))->where('c.user_id', $uid)->orderByDesc('l.latest_date')->orderBy('c.clientName')->get(['c.id','c.clientName','c.phoneNumber','l.latest_date']);
            $rows = $clients->map(function ($c) use ($uid) { $b = $this->balances->getExistingBalanceOrZero($uid, (int)$c->id); $n = \Brick\Math\BigDecimal::of((string)$b['currentBalance']); return ['customerId' => (int)$c->id, 'clientName' => $c->clientName, 'phoneNumber' => $c->phoneNumber, 'currentBalance' => $b['currentBalance'], 'currentOrdersTotalAmount' => $b['currentOrdersTotalAmount'], 'status' => $n->compareTo(0) > 0 ? 'DUE' : ($n->compareTo(0) < 0 ? 'ADVANCE' : 'SETTLED')]; })->values();
            return response()->json(['customers' => $rows]);
        });
    }
    public function ledger(Request $r)
    {
        return $this->guarded(function () use ($r) {
            $uid = $this->uid($r); $q = DB::table('client_balance_ledger as l')->join('clients as c','c.id','=','l.client_id')->where('l.user_id',$uid);
            if ($r->filled('customerId')) { $this->client($r,(int)$r->query('customerId')); $q->where('l.client_id',(int)$r->query('customerId')); }
            $from=$this->dateParam($r,'fromDate','startDate'); $to=$this->dateParam($r,'toDate','endDate');
            if ($from) $q->whereDate('l.entry_date','>=',$from); if($to) $q->whereDate('l.entry_date','<=',$to);
            $type=strtoupper(trim((string)$r->query('type','ALL'))); $types=match($type){'ALL',''=>null,'MANUAL_RECEIVABLE'=>['MANUAL_RECEIVABLE','MANUAL_RECEIVABLE_DELETED'],'ADJUSTMENT','MANUAL_ADJUSTMENT'=>['MANUAL_ADJUSTMENT'],'PAYMENT'=>['PAYMENT_RECEIVED','PAYMENT_UPDATED','PAYMENT_DELETED'],'BILL_GENERATION'=>['BILL_GENERATION'],default=>throw new InvalidArgumentException('type must be one of ALL, MANUAL_RECEIVABLE, ADJUSTMENT, PAYMENT, BILL_GENERATION')};
            if ($types) $q->whereIn('l.entry_type',$types);
            $sort=(string)$r->query('sort','entryDate,desc'); [$prop,$dir]=array_pad(explode(',',$sort),2,'desc');
            $columns=['entryDate'=>'entry_date','createdAt'=>'created_at','amount'=>'amount','balanceAfter'=>'balance_after','entryType'=>'entry_type','id'=>'id'];
            $q->orderBy('l.'.($columns[$prop]??'entry_date'),strtolower($dir)==='asc'?'asc':'desc')->orderBy('l.id',strtolower($dir)==='asc'?'asc':'desc');
            $q->select('l.*','c.clientName'); $page=max(0,(int)$r->query('page',0)); $size=max(1,min(1000,(int)$r->query('size',500))); $all=$q->get(); $total=$all->count();
            $content=$all->slice($page*$size,$size)->map(fn($x)=>['customerId'=>(int)$x->client_id,'clientName'=>$x->clientName,'entryDate'=>$x->entry_date,'entryType'=>match($x->entry_type){'PAYMENT_RECEIVED','PAYMENT_UPDATED','PAYMENT_DELETED'=>'PAYMENT','MANUAL_ADJUSTMENT'=>'ADJUSTMENT',default=>$x->entry_type},'amount'=>(float)$x->amount,'balanceAfter'=>(float)$x->balance_after,'referenceType'=>$x->reference_type,'referenceId'=>$x->reference_id===null?null:(int)$x->reference_id,'note'=>$x->note,'reversedAt'=>$x->reversed_at,'reversalEntryId'=>$x->reversal_entry_id===null?null:(int)$x->reversal_entry_id])->values();
            return response()->json(['content'=>$content,'totalElements'=>$total,'number'=>$page,'size'=>$size,'totalPages'=>(int)ceil($total/$size)]);
        });
    }
    public function addReceivable(Request $r,int $customerId) { return $this->guarded(function()use($r,$customerId){$this->client($r,$customerId);if(!$r->exists('amount'))throw new InvalidArgumentException('Amount is required');return response()->json($this->balances->addManualReceivable($this->uid($r),$customerId,$r->input('amount'),$r->input('note')));}); }
    public function deleteReceivable(Request $r,int $customerId,int $receivableId) { return $this->guarded(function()use($r,$customerId,$receivableId){$this->client($r,$customerId);$b=$this->balances->deleteManualReceivable($this->uid($r),$customerId,$receivableId,$r->input('reason'));return response()->json(['message'=>'Manual receivable deleted successfully','balance'=>$b]);}); }
    public function adjustment(Request $r,int $customerId) { return $this->guarded(function()use($r,$customerId){$this->client($r,$customerId);if(!$r->exists('amount'))throw new InvalidArgumentException('Amount is required');return response()->json($this->balances->addManualAdjustment($this->uid($r),$customerId,$r->input('amount'),$r->input('note')));}); }
    public function customerLedger(Request $r,int $customerId)
    {
        return $this->guarded(function()use($r,$customerId){$this->client($r,$customerId);$q=DB::table('client_balance_ledger')->where('user_id',$this->uid($r))->where('client_id',$customerId);$from=$this->dateParam($r,'fromDate','startDate');$to=$this->dateParam($r,'toDate','endDate');if($from)$q->whereDate('entry_date','>=',$from);if($to)$q->whereDate('entry_date','<=',$to);$type=strtoupper(trim((string)$r->query('type','ALL')));if($type!==''&&$type!=='ALL')$q->where('entry_type',$type);$rows=$q->orderBy('entry_date')->orderBy('id')->get()->map(fn($x)=>['id'=>(int)$x->id,'entryDate'=>$x->entry_date,'entryType'=>$x->entry_type,'amount'=>(float)$x->amount,'balanceAfter'=>(float)$x->balance_after,'referenceType'=>$x->reference_type,'referenceId'=>$x->reference_id===null?null:(int)$x->reference_id,'note'=>$x->note,'reversedAt'=>$x->reversed_at,'reversalEntryId'=>$x->reversal_entry_id===null?null:(int)$x->reversal_entry_id])->values();$total=$rows->count();$out=['customerId'=>$customerId,'totalElements'=>$total];if($r->query->has('page')&&$r->query->has('size')){$p=max(0,(int)$r->query('page'));$s=max(1,(int)$r->query('size'));$rows=$rows->slice($p*$s,$s)->values();$out+=['content'=>$rows,'ledger'=>$rows,'number'=>$p,'size'=>$s,'totalPages'=>(int)ceil($total/$s)];}else{$out+=['content'=>$rows,'ledger'=>$rows];}return response()->json($out);});
    }
    public function dashboard(Request $r)
    {
        return $this->guarded(function()use($r){$uid=$this->uid($r);$billCount=Schema::hasTable('bills')?DB::table('bills')->where('user_id',$uid)->count():0;return response()->json(['currentBalance'=>(float)(DB::table('client_balances')->where('user_id',$uid)->sum('currentBalance')??0),'currentOrdersTotalAmount'=>(float)(DB::table('client_balances')->where('user_id',$uid)->sum('currentOrdersTotalAmount')??0),'totalBills'=>$billCount,'totalPayments'=>DB::table('payments')->where('user_id',$uid)->where('deleted',false)->count()]);});
    }
}
