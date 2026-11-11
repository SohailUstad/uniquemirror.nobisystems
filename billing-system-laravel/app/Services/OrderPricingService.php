<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Snapshots active component rates at order creation or deliberate flow change. */
class OrderPricingService
{
    public function __construct(private ProductionFlowProgressService $progress) {}

    public function initialize(int $userId, int $orderId, ?int $requestedFlowId = null): array
    {
        $flow = $this->progress->resolveFlowId($userId, $requestedFlowId);
        if ($requestedFlowId !== null) {
            $selected = DB::table('production_stage_flows')->where('id',$flow)->where('user_id',$userId)->first();
            if (!$selected) throw new \InvalidArgumentException('Stage flow not found');
            if (!$selected->active) throw new \InvalidArgumentException('Inactive stage flow cannot be used');
        }
        DB::table('orders')->where('id',$orderId)->update(['production_stage_flow_id'=>$flow]);
        $this->replaceComponents($userId,$orderId,$flow);
        return $this->recalculate($userId,$orderId,null,true);
    }

    public function replaceComponents(int $userId, int $orderId, ?int $flowId): void
    {
        DB::table('order_component_charges')->where('order_id',$orderId)->delete();
        if ($flowId !== null) {
            $rows = DB::table('production_stage_flow_components as fc')->join('production_components as c','c.id','=','fc.component_id')
                ->where('fc.user_id',$userId)->where('fc.flow_id',$flowId)->where('c.user_id',$userId)->where('c.active',true)
                ->orderBy('fc.display_order')->orderBy('fc.id')->select('fc.display_order','c.id','c.name','c.fixed_rate')->get();
            foreach ($rows as $r) DB::table('order_component_charges')->insert(['user_id'=>$userId,'order_id'=>$orderId,'component_id'=>$r->id,'component_name_snapshot'=>$r->name,'rate_snapshot'=>$this->money($r->fixed_rate),'quantity_snapshot'=>0,'amount'=>'0.00','display_order'=>$r->display_order]);
        }
    }

    public function recalculate(int $userId, int $orderId, mixed $requestedBase = null, bool $recalculateBase = false): array
    {
        $o=DB::table('orders')->where('id',$orderId)->where('user_id',$userId)->first() ?? throw new \InvalidArgumentException('Order not found');
        $sf=$o->totalSquareFeet !== null ? (string)$o->totalSquareFeet : (string)(((float)$o->height*(float)$o->width*(int)$o->quantity)/144);
        if ($requestedBase !== null) $base=$this->money($requestedBase);
        elseif ($recalculateBase) $base=$this->money(BigDecimal::of($sf)->multipliedBy((string)$o->rate));
        elseif ($o->baseAmount !== null) $base=$this->money($o->baseAmount);
        else $base=$this->money(BigDecimal::of((string)($o->amount??'0'))->minus((string)($o->componentAmount??'0')));
        if (BigDecimal::of($base)->isNegative()) throw new \InvalidArgumentException('Order base amount cannot be negative');
        $sum=BigDecimal::zero();
        foreach(DB::table('order_component_charges')->where('order_id',$orderId)->orderBy('display_order')->orderBy('id')->get() as $c){$amount=$this->money(BigDecimal::of((string)$c->rate_snapshot)->multipliedBy((string)$o->quantity));DB::table('order_component_charges')->where('id',$c->id)->update(['quantity_snapshot'=>$o->quantity,'amount'=>$amount]);$sum=$sum->plus($amount);}
        $component=$this->money($sum);$amount=$this->money(BigDecimal::of($base)->plus($component));
        DB::table('orders')->where('id',$orderId)->update(['baseAmount'=>$base,'componentAmount'=>$component,'amount'=>$amount]);
        return ['baseAmount'=>$base,'componentAmount'=>$component,'amount'=>$amount];
    }

    private function money(mixed $value): string { return (string)BigDecimal::of((string)($value??'0'))->toScale(2,RoundingMode::HALF_UP); }
}
