<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Central source-compatible projection shared by order and client responses. */
class OrderResponseSerializer
{
    public function one(object $order): array
    {
        $sqft = $order->totalSquareFeet !== null
            ? (float) $order->totalSquareFeet
            : (($order->height * $order->width * $order->quantity) / 144.0);
        $base = $order->baseAmount !== null ? (float) $order->baseAmount : (float) (string) BigDecimal::of((string) $sqft)->multipliedBy((string) $order->rate)->toScale(2, RoundingMode::HALF_UP);
        $components = $order->componentAmount === null ? 0.0 : (float) $order->componentAmount;
        $amount = $order->amount !== null ? (float) $order->amount : (float) (string) BigDecimal::of((string) $base)->plus((string) $components)->toScale(2, RoundingMode::HALF_UP);
        $client = DB::table('clients')->where('id', $order->client_id)->first();
        $category = isset($order->category_id) && $order->category_id ? DB::table('categories')->where('id', $order->category_id)->first() : null;
        $date = $order->date ? new \DateTimeImmutable($order->date) : null;

        $images = Schema::hasTable('order_images') ? DB::table('order_images')->where('order_id', $order->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($i) => [
            'id' => (int) $i->id, 'imageUrl' => $i->image_url, 'label' => $i->label, 'displayOrder' => (int) $i->display_order,
        ])->all() : [];
        $voiceNotes = Schema::hasTable('order_voice_notes') ? DB::table('order_voice_notes')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($n) => [
            'id' => (int) $n->id, 'label' => $n->label, 'mimeType' => $n->mime_type,
            'durationSeconds' => $n->duration_seconds === null ? null : (int) $n->duration_seconds,
            'sizeBytes' => $n->size_bytes === null ? null : (int) $n->size_bytes,
            'createdAt' => $n->created_at ? (new \DateTimeImmutable($n->created_at))->format('Y-m-d\TH:i:s') : null,
        ])->all() : [];

        return [
            'id' => (int) $order->id, 'description' => $order->description,
            'height' => (float) $order->height, 'width' => (float) $order->width,
            'quantity' => (int) $order->quantity, 'rate' => (float) $order->rate,
            'workerRatePerSquareFoot' => $order->workerRatePerSquareFoot === null ? null : (float) $order->workerRatePerSquareFoot,
            'totalSquareFeet' => $sqft, 'baseAmount' => $base, 'componentAmount' => $components,
            'componentCharges' => DB::table('order_component_charges')->where('order_id',$order->id)->orderBy('display_order')->orderBy('id')->get()->map(fn($c)=>['id'=>(int)$c->id,'componentNameSnapshot'=>$c->component_name_snapshot,'rateSnapshot'=>(float)$c->rate_snapshot,'quantitySnapshot'=>(int)$c->quantity_snapshot,'amount'=>(float)$c->amount,'displayOrder'=>(int)$c->display_order])->all(), 'amount' => $amount, 'billed' => (bool) $order->billed,
            'clientName' => $client?->clientName, 'clientId' => (int) $order->client_id,
            'categoryId' => $category ? (int) $category->id : null, 'categoryName' => $category?->name,
            'workerId' => $order->worker_id ? (int) $order->worker_id : null, 'workerName' => null,
            'assignedAt' => $order->assigned_at ? (new \DateTimeImmutable($order->assigned_at))->format('Y-m-d\TH:i:s') : null,
            'orderId' => $order->orderId, 'sequence' => (int) $order->sequence,
            'imagePath' => $order->imagePath, 'images' => $images, 'voiceNotes' => $voiceNotes, 'voiceNoteCount' => count($voiceNotes),
            'currentStage' => $order->currentStage, 'productionStageFlowId' => $order->production_stage_flow_id ? (int) $order->production_stage_flow_id : null,
            'productionStageFlowName' => $order->production_stage_flow_id ? DB::table('production_stage_flows')->where('id',$order->production_stage_flow_id)->value('name') : null, 'qrCodePath' => $order->qrCodePath,
            'mode' => $order->deleiverdThorugh,
            'date' => $date?->format('d M Y h:i A'), 'note' => $order->note,
            'deadline' => $order->deadline ? (new \DateTimeImmutable($order->deadline))->format('Y-m-d\TH:i:s') : null,
        ];
    }

    public function many(iterable $orders): array
    {
        $result = [];
        foreach ($orders as $order) $result[] = $this->one($order);
        return $result;
    }
}
