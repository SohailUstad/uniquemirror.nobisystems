<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Jackson-style order getter projection for Client.orders, distinct from the admin DTO. */
class ClientNestedOrderSerializer
{
    public function serialize(int $clientId): array
    {
        return DB::table('orders')->where('client_id', $clientId)->orderBy('id')->get()->map(function ($o) {
            $sf = $o->totalSquareFeet !== null ? (float) $o->totalSquareFeet : ($o->height * $o->width * $o->quantity / 144);
            $base = $o->baseAmount !== null ? (float) $o->baseAmount : (float) BigDecimal::of((string) $sf)->multipliedBy((string) $o->rate)->toScale(2, RoundingMode::HALF_UP);
            $amount = $o->amount === null
                ? (float) BigDecimal::of((string) $base)->plus((string) ($o->componentAmount ?? '0'))->toScale(2, RoundingMode::HALF_UP)
                : (float) $o->amount;
            $category = $o->category_id ? DB::table('categories')->where('id', $o->category_id)->first() : null;
            $images = Schema::hasTable('order_images') ? DB::table('order_images')->where('order_id', $o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($i) => [
                'id' => (int) $i->id, 'imageUrl' => $i->image_url, 'label' => $i->label,
                'displayOrder' => (int) $i->display_order,
            ])->all() : [];
            $voiceNotes = Schema::hasTable('order_voice_notes') ? DB::table('order_voice_notes')->where('order_id', $o->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($n) => [
                'id' => (int) $n->id, 'storageKey' => $n->storage_key, 'mimeType' => $n->mime_type, 'label' => $n->label,
                'durationSeconds' => $n->duration_seconds === null ? null : (int) $n->duration_seconds,
                'sizeBytes' => $n->size_bytes === null ? null : (int) $n->size_bytes,
                'createdAt' => $n->created_at ? (new \DateTimeImmutable($n->created_at))->format('Y-m-d\TH:i:s') : null,
            ])->all() : [];
            $componentCharges = Schema::hasTable('order_component_charges') ? DB::table('order_component_charges')->where('order_id', $o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($c) => [
                'id' => (int) $c->id, 'componentNameSnapshot' => $c->component_name_snapshot,
                'rateSnapshot' => (float) $c->rate_snapshot, 'quantitySnapshot' => (int) $c->quantity_snapshot,
                'amount' => (float) $c->amount, 'displayOrder' => (int) $c->display_order,
            ])->all() : [];
            return [
                'id' => (int) $o->id, 'description' => $o->description, 'height' => (float) $o->height,
                'width' => (float) $o->width, 'quantity' => (int) $o->quantity, 'rate' => (float) $o->rate,
                'workerRatePerSquareFoot' => $o->workerRatePerSquareFoot === null ? null : (float) $o->workerRatePerSquareFoot,
                // These associations are nullable and their owner tables are later phase integrations.
                'category' => $category ? [
                    'id' => (int) $category->id, 'name' => $category->name, 'active' => (bool) $category->active,
                    'createdAt' => $category->created_at ? (new \DateTimeImmutable($category->created_at))->format('Y-m-d\TH:i:s') : null,
                    'updatedAt' => $category->updated_at ? (new \DateTimeImmutable($category->updated_at))->format('Y-m-d\TH:i:s') : null,
                ] : null,
                'worker' => null,
                'productionStageFlow' => null,
                'assignedAt' => $o->assigned_at ? (new \DateTimeImmutable($o->assigned_at))->format('Y-m-d\TH:i:s') : null,
                'sequence' => (int) $o->sequence, 'orderId' => $o->orderId,
                'imagePath' => $o->imagePath, 'images' => $images, 'voiceNotes' => $voiceNotes,
                'currentStage' => $o->currentStage, 'qrCodePath' => $o->qrCodePath,
                'deleiverdThorugh' => $o->deleiverdThorugh, 'amount' => $amount,
                'baseAmount' => $base, 'componentAmount' => (float) ($o->componentAmount ?? 0),
                'componentCharges' => $componentCharges,
                'totalSquareFeet' => $sf,
                'date' => $o->date ? (new \DateTimeImmutable($o->date))->format('Y-m-d\TH:i:s') : null,
                'note' => $o->note,
                'deadline' => $o->deadline ? (new \DateTimeImmutable($o->deadline))->format('Y-m-d\TH:i:s') : null,
                'billed' => (bool) $o->billed,
            ];
        })->all();
    }
}
