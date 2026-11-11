<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PublicOrderService
{
    public function __construct(private readonly OrderResponseSerializer $serializer) {}

    public function ordersForPhone(string $phone, int $page): array
    {
        $query = DB::table('orders')->join('clients', 'clients.id', '=', 'orders.client_id')
            ->where('clients.phoneNumber', $phone);
        return $this->page($query, $page);
    }

    public function billOrders(int $billId): ?array
    {
        if (!DB::table('bills')->where('id', $billId)->exists()) return null;
        $orders = DB::table('orders')->where('bill_id', $billId)->orderBy('id')->get();
        return $this->pageShape($this->serializer->many($orders), count($orders), 0, max(count($orders), 1), true);
    }

    public function order(int $id): ?object
    {
        return DB::table('orders')->where('id', $id)->first();
    }

    public function serializedOrder(int $id): ?array
    {
        $order = $this->order($id);
        return $order ? $this->serializer->one($order) : null;
    }

    public function voiceNotes(int $id): ?array
    {
        if (!$this->order($id)) return null;
        return DB::table('order_voice_notes')->where('order_id', $id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id, 'label' => $row->label, 'mimeType' => $row->mime_type,
                'durationSeconds' => $row->duration_seconds === null ? null : (int) $row->duration_seconds,
                'sizeBytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
                'createdAt' => $row->created_at ? (new \DateTimeImmutable($row->created_at))->format('Y-m-d\\TH:i:s') : null,
            ])->all();
    }

    public function voiceNote(int $orderId, int $noteId): ?object
    {
        return DB::table('order_voice_notes')->where('order_id', $orderId)->where('id', $noteId)->first();
    }

    public function saveTracking(array $data): void
    {
        DB::table('order_tracking')->insert([
            'order_id' => $data['orderId'] ?? null, 'user_id' => $data['userId'] ?? null,
            'status' => $data['status'] ?? null,
            'tracking_time' => isset($data['trackingTime']) ? str_replace('T', ' ', $data['trackingTime']) : null,
        ]);
    }

    public function tracking(int $orderId): array
    {
        return DB::table('order_tracking')->where('order_id', $orderId)->orderBy('tracking_time')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id, 'orderId' => $row->order_id === null ? null : (int) $row->order_id,
                'userId' => $row->user_id === null ? null : (int) $row->user_id,
                'status' => $row->status,
                'trackingTime' => $row->tracking_time ? (new \DateTimeImmutable($row->tracking_time))->format('Y-m-d\\TH:i:s') : null,
            ])->all();
    }

    private function page($query, int $page): array
    {
        $total = (clone $query)->count();
        $orders = $query->select('orders.*')->orderByDesc('orders.id')->offset($page * 20)->limit(20)->get();
        return $this->pageShape($this->serializer->many($orders), $total, $page, 20, false);
    }

    private function pageShape(array $content, int $total, int $page, int $size, bool $unpaged): array
    {
        $pages = $unpaged ? 1 : (int) ceil($total / $size);
        $sort = ['empty' => !$unpaged ? false : true, 'sorted' => !$unpaged, 'unsorted' => $unpaged];
        $pageable = $unpaged ? ['sort' => $sort, 'offset' => 0, 'paged' => false, 'unpaged' => true]
            : ['pageNumber' => $page, 'pageSize' => $size, 'sort' => $sort, 'offset' => $page * $size, 'paged' => true, 'unpaged' => false];
        return ['content' => $content, 'pageable' => $pageable, 'totalPages' => $pages,
            'totalElements' => $total, 'last' => $unpaged || $page >= $pages - 1, 'size' => $unpaged ? $total : $size,
            'number' => $unpaged ? 0 : $page, 'sort' => $sort, 'numberOfElements' => count($content),
            'first' => $page === 0, 'empty' => count($content) === 0];
    }
}
