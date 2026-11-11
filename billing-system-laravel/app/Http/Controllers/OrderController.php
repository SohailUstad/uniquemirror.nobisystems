<?php

namespace App\Http\Controllers;

use App\Services\OrderResponseSerializer;
use App\Services\OrderQrCodeService;
use App\Services\OrdersCounterService;
use App\Services\OrderMediaCleanupService;
use App\Services\OrderPricingService;
use App\Services\ProductionFlowProgressService;
use App\Jobs\SendOrderNotification;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private OrderResponseSerializer $serializer, private OrderQrCodeService $qr, private OrdersCounterService $counters, private OrderMediaCleanupService $mediaCleanup, private OrderPricingService $pricing, private ProductionFlowProgressService $flowProgress) {}

    private function ownerId(Request $request): int
    {
        $id = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
        if (!$id) throw new \InvalidArgumentException('User not found');
        return (int) $id;
    }

    private function owned(int $id, int $userId): object
    {
        $row = DB::table('orders')->where('id', $id)->first();
        if (!$row) throw new \InvalidArgumentException('Order not found');
        if ((int) $row->user_id !== $userId) throw new \InvalidArgumentException('Unauthorized: Order does not belong to this user');
        return $row;
    }

    private function error(\Throwable $e): JsonResponse { return response()->json(['error' => $e->getMessage()], 400); }

    private function adjustClientOrdersTotal(int $userId, int $clientId, string|float $delta): void
    {
        DB::table('client_balances')->insertOrIgnore(['user_id' => $userId, 'client_id' => $clientId,
            'currentBalance' => 0, 'currentOrdersTotalAmount' => 0, 'createdAt' => now(), 'updatedAt' => now()]);
        $balance = DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->first();
        $nextDecimal = BigDecimal::of((string) $balance->currentOrdersTotalAmount)->plus((string) $delta)->toScale(2, RoundingMode::HALF_UP);
        $next = $nextDecimal->isNegative() ? '0.00' : (string) $nextDecimal;
        DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)
            ->update(['currentOrdersTotalAmount' => $next, 'updatedAt' => now()]);
    }

    private function money(BigDecimal|string|float|int $value): string
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of((string) $value);
        return (string) $decimal->toScale(2, RoundingMode::HALF_UP);
    }

    private function parseDeadline(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $text = trim((string) $value);
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?(Z|[+-]\d{2}:\d{2})?$/';
        if (!preg_match($pattern, $text, $parts)) throw new \InvalidArgumentException('Invalid deadline: '.$text);
        $year = (int) $parts[1]; $month = (int) $parts[2]; $day = (int) $parts[3];
        $hour = (int) $parts[4]; $minute = (int) $parts[5]; $second = isset($parts[6]) && $parts[6] !== '' ? (int) $parts[6] : 0;
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) throw new \InvalidArgumentException('Invalid deadline: '.$text);
        $zone = $parts[8] ?? '';
        if ($zone !== '' && $zone !== 'Z') {
            $offsetHour = (int) substr($zone, 1, 2); $offsetMinute = (int) substr($zone, 4, 2);
            if ($offsetHour > 14 || $offsetMinute > 59 || ($offsetHour === 14 && $offsetMinute !== 0)) throw new \InvalidArgumentException('Invalid deadline: '.$text);
        }
        try {
            $sourceZone = $zone === '' ? new \DateTimeZone((string) config('app.timezone', 'UTC')) : new \DateTimeZone($zone === 'Z' ? 'UTC' : $zone);
            $dateTime = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $sourceZone);
            return $dateTime->setTimezone(new \DateTimeZone((string) config('app.timezone', 'UTC')))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid deadline: '.$text);
        }
    }

    private function values(Request $request, string $singular, string $plural): array
    {
        $all = [];
        foreach ([$singular, $plural] as $key) foreach ((array) $request->query($key, []) as $part)
            foreach (explode(',', (string) $part) as $value) if (trim($value) !== '') $all[] = trim($value);
        return array_values(array_unique($all));
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $this->ownerId($request); $q = DB::table('orders')->where('user_id', $userId);
            if ($request->filled('categoryId')) {
                $categoryId = (int) $request->query('categoryId');
                if (!DB::table('categories')->where('id', $categoryId)->where('user_id', $userId)->exists()) throw new \InvalidArgumentException('Category not found');
                $q->where('category_id', $categoryId);
            }
            $stages = $this->values($request, 'stage', 'stages'); if ($stages) $q->whereIn('currentStage', $stages);
            $workers = $this->values($request, 'workerId', 'workerIds');
            $flows = $this->values($request, 'productionStageFlowId', 'productionStageFlowIds');
            foreach (array_merge($workers, $flows) as $value) if (!ctype_digit($value)) throw new \InvalidArgumentException('Invalid worker or production stage flow id');
            if ($workers) $q->whereIn('worker_id', array_map('intval', $workers));
            if ($flows) $q->whereIn('production_stage_flow_id', array_map('intval', $flows));
            if ($workers) $q->orderByDesc('assigned_at')->orderByDesc('id'); else $q->orderByDesc('id');
            $rows = $q->get();
            return response()->json(['message' => 'Orders retrieved successfully', 'count' => $rows->count(), 'orders' => $this->serializer->many($rows)]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        try { $row = $this->owned($orderId, $this->ownerId($request));
            return response()->json(['message' => 'Order retrieved successfully', 'order' => $this->serializer->one($row)]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function byClient(Request $request, int $clientId): JsonResponse
    {
        try { $userId = $this->ownerId($request);
            $client = DB::table('clients')->where('id', $clientId)->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            if ((int) $client->user_id !== $userId) throw new \InvalidArgumentException('Unauthorized: Client does not belong to this user');
            $rows = DB::table('orders')->where('user_id', $userId)->where('client_id', $clientId)->orderByDesc('id')->get();
            return response()->json(['message' => 'Orders retrieved successfully', 'count' => $rows->count(), 'orders' => $this->serializer->many($rows)]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function byStage(Request $request): JsonResponse
    {
        try { $stage = $request->query('stage'); if ($stage === null) throw new \InvalidArgumentException('stage is required');
            $rows = DB::table('orders')->where('user_id', $this->ownerId($request))->where('currentStage', $stage)->orderByDesc('id')->get();
            return response()->json(['message' => 'Orders retrieved successfully', 'count' => $rows->count(), 'orders' => $this->serializer->many($rows)]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $u = $this->ownerId($request); $v = $request->all();
            if (!isset($v['description']) || !is_string($v['description']) || trim($v['description']) === '') throw new \InvalidArgumentException('Description is mandatory');
            foreach (['height', 'width', 'quantity', 'rate', 'clientId'] as $key)
                if (!array_key_exists($key, $v) || $v[$key] === null) throw new \InvalidArgumentException("{$key} is required");
            foreach (['height', 'width', 'quantity', 'rate'] as $key)
                if (!is_numeric($v[$key]) || (float) $v[$key] <= 0) throw new \InvalidArgumentException(ucfirst($key).' must be greater than 0');
            if (isset($v['workerRatePerSquareFoot']) && (!is_numeric($v['workerRatePerSquareFoot']) || (float) $v['workerRatePerSquareFoot'] < 0)) throw new \InvalidArgumentException('Order worker rate cannot be negative');
            if (!isset($v['imagePath']) || !is_string($v['imagePath']) || trim($v['imagePath']) === '') throw new \InvalidArgumentException('Image is mandatory');
            $client = DB::table('clients')->where('id', (int) $v['clientId'])->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            if ((int) $client->user_id !== $u) throw new \InvalidArgumentException('Client does not belong to this user');
            if (isset($v['categoryId']) && !DB::table('categories')->where('id', $v['categoryId'])->where('user_id', $u)->exists()) throw new \InvalidArgumentException('Category not found');
            $id = DB::transaction(function () use ($u, $v, $client) {
                DB::table('client_bill_sequence')->insertOrIgnore(['client_id' => $client->id, 'last_seq' => 0]);
                $sequence = DB::table('client_bill_sequence')->where('client_id', $client->id)->lockForUpdate()->value('last_seq') + 1;
                DB::table('client_bill_sequence')->where('client_id', $client->id)->update(['last_seq' => $sequence]);
                $sqft = ((float) $v['height'] * (float) $v['width'] * (int) $v['quantity']) / 144;
                $base = $this->money(BigDecimal::of((string) $sqft)->multipliedBy((string) $v['rate']));
                $now = now();
                $id = DB::table('orders')->insertGetId([
                    'description' => trim($v['description']), 'height' => $v['height'], 'width' => $v['width'],
                    'quantity' => $v['quantity'], 'rate' => $v['rate'], 'user_id' => $u, 'client_id' => $client->id,
                    'category_id' => $v['categoryId'] ?? null,
                    'workerRatePerSquareFoot' => $v['workerRatePerSquareFoot'] ?? null,
                    'sequence' => $sequence, 'orderId' => $client->id.'-'.$sequence,
                    'imagePath' => $v['imagePath'], 'currentStage' => 'Pending', 'qrCodePath' => null,
                    'amount' => $base, 'baseAmount' => $base, 'componentAmount' => 0, 'totalSquareFeet' => $sqft,
                    'note' => $v['notes'] ?? null, 'deadline' => $this->parseDeadline($v['deadline'] ?? null), 'billed' => false,
                    'date' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $qrPath = $this->qr->create((int) $id);
                DB::table('orders')->where('id', $id)->update(['qrCodePath' => $qrPath]);
                $pricing = $this->pricing->initialize($u, (int)$id, isset($v['productionStageFlowId']) ? (int)$v['productionStageFlowId'] : null);
                $this->adjustClientOrdersTotal($u, (int) $client->id, $pricing['amount']);
                $this->counters->increment('Pending', $u);
                DB::table('order_tracking')->insert(['order_id' => $id, 'user_id' => $u, 'status' => 'created', 'tracking_time' => $now]);
                return $id;
            });
            $row = DB::table('orders')->where('id', $id)->first();
            $clientName = $client->clientName ?: 'customer';
            $orderLabel = $row->orderId ?: (string) $row->id;
            $registeredClientId = $client->registered_client_id === null ? null : (int) $client->registered_client_id;
            $personalizedName = trim((string) $client->clientName);
            $clientBody = 'Hi '.($personalizedName === '' ? 'customer' : $personalizedName).', your order #'.$orderLabel.' has been created.';
            DB::afterCommit(fn () => SendOrderNotification::dispatch((int) $u, 'Order created', 'Order #'.$orderLabel.' created for '.$clientName,
                $registeredClientId, $registeredClientId === null ? null : $clientBody)->afterResponse());
            return response()->json(['message' => 'Order added successfully', 'order' => $this->serializer->one($row),
                'totalSquareFeet' => (float) $row->totalSquareFeet, 'amount' => (float) $row->amount], 201);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function update(Request $request, int $orderId): JsonResponse
    {
        try {
            $row = $this->owned($orderId, $this->ownerId($request)); $v = $request->all();
            if ((bool) $row->billed) throw new \InvalidArgumentException('Cannot update order that is already billed');
            if (isset($v['clientId']) && (int) $v['clientId'] !== (int) $row->client_id) throw new \InvalidArgumentException('Cannot change client for an order. Client must remain the same.');
            $h = isset($v['height']) && (float) $v['height'] > 0 ? (float) $v['height'] : (float) $row->height;
            $w = isset($v['width']) && (float) $v['width'] > 0 ? (float) $v['width'] : (float) $row->width;
            $q = isset($v['quantity']) && (int) $v['quantity'] > 0 ? (int) $v['quantity'] : (int) $row->quantity;
            $rate = isset($v['rate']) && (float) $v['rate'] > 0 ? (float) $v['rate'] : (float) $row->rate;
            $sizeChanged = ((isset($v['height']) && (float) $v['height'] > 0 && (float) $v['height'] != (float) $row->height)
                || (isset($v['width']) && (float) $v['width'] > 0 && (float) $v['width'] != (float) $row->width)
                || (isset($v['quantity']) && (int) $v['quantity'] > 0 && (int) $v['quantity'] != (int) $row->quantity));
            $rateChanged = isset($v['rate']) && (float) $v['rate'] > 0 && (float) $v['rate'] != (float) $row->rate;
            $requestedSquareFeet = array_key_exists('totalSquareFeet', $v) && $v['totalSquareFeet'] !== null ? (float) $v['totalSquareFeet'] : null;
            $squareFeetChanged = $requestedSquareFeet !== null && $requestedSquareFeet != (float) ($row->totalSquareFeet ?? ($row->height * $row->width * $row->quantity / 144));
            $pricingChanged = $sizeChanged || $rateChanged || $squareFeetChanged;
            $sf = $requestedSquareFeet ?? ($sizeChanged ? null : $row->totalSquareFeet);
            $sf ??= $h * $w * $q / 144;
            if ($sf <= 0) throw new \InvalidArgumentException('Total square feet must be greater than 0');
            $requestedBase = array_key_exists('baseAmount', $v) ? $v['baseAmount'] : ($v['amount'] ?? null);
            if ($requestedBase !== null) {
                $base = $this->money($requestedBase);
            } elseif ($pricingChanged) {
                $base = $this->money(BigDecimal::of((string) $sf)->multipliedBy((string) $rate));
            } elseif ($row->baseAmount !== null) {
                $base = $this->money($row->baseAmount);
            } elseif ($row->amount !== null) {
                $base = $this->money(BigDecimal::of((string) $row->amount)->minus((string) ($row->componentAmount ?? '0')));
            } else {
                $base = $this->money(BigDecimal::of((string) $sf)->multipliedBy((string) $rate));
            }
            if (BigDecimal::of($base)->isNegative()) throw new \InvalidArgumentException('Order base amount cannot be negative');
            $update = ['height' => $h, 'width' => $w, 'quantity' => $q, 'rate' => $rate,
                'baseAmount' => $base, 'amount' => (string) BigDecimal::of($base)->plus((string) ($row->componentAmount ?? '0'))->toScale(2, RoundingMode::HALF_UP), 'updated_at' => now()];
            if ($requestedSquareFeet !== null) $update['totalSquareFeet'] = $requestedSquareFeet;
            elseif ($sizeChanged) $update['totalSquareFeet'] = null;
            foreach (['description', 'imagePath', 'note'] as $key) if (isset($v[$key]) && trim((string) $v[$key]) !== '') $update[$key] = $v[$key];
            if (array_key_exists('categoryId', $v)) {
                if ($v['categoryId'] !== null && !DB::table('categories')->where('id', $v['categoryId'])->where('user_id', $row->user_id)->exists()) throw new \InvalidArgumentException('Category not found');
                $update['category_id'] = $v['categoryId'];
            }
            if (array_key_exists('notes', $v) && !array_key_exists('note', $v) && $v['notes'] !== null) $update['note'] = $v['notes'];
            if (array_key_exists('workerRatePerSquareFoot', $v)) {
                if ($v['workerRatePerSquareFoot'] !== null && !is_numeric($v['workerRatePerSquareFoot'])) throw new \InvalidArgumentException('Invalid request: workerRatePerSquareFoot');
                if ($v['workerRatePerSquareFoot'] !== null && (float) $v['workerRatePerSquareFoot'] < 0) throw new \InvalidArgumentException('Order worker rate cannot be negative');
                $update['workerRatePerSquareFoot'] = $v['workerRatePerSquareFoot'];
            }
            $currentEffectiveFlow = $this->flowProgress->resolveFlowId((int)$row->user_id, $row->production_stage_flow_id === null ? null : (int)$row->production_stage_flow_id);
            $requestedFlow = array_key_exists('productionStageFlowId', $v) && $v['productionStageFlowId'] !== null ? (int)$v['productionStageFlowId'] : null;
            $requestedEffectiveFlow = array_key_exists('productionStageFlowId', $v)
                ? $this->flowProgress->resolveFlowId((int)$row->user_id, $requestedFlow)
                : $currentEffectiveFlow;
            $flowChanged = array_key_exists('productionStageFlowId', $v) && $requestedEffectiveFlow !== $currentEffectiveFlow;
            if (array_key_exists('deadline', $v)) $update['deadline'] = $this->parseDeadline($v['deadline']);
            $flowProvided = array_key_exists('productionStageFlowId', $v);
            DB::transaction(function () use ($orderId, $row, $update, $flowChanged, $flowProvided, $requestedEffectiveFlow, $v, $requestedBase, $pricingChanged) {
                if ($flowProvided && $v['productionStageFlowId'] !== null) {
                    $selected = DB::table('production_stage_flows')->where('id',(int)$v['productionStageFlowId'])->where('user_id',$row->user_id)->first();
                    if (!$selected) throw new \InvalidArgumentException('Stage flow not found');
                    if (!$selected->active) throw new \InvalidArgumentException('Inactive stage flow cannot be used');
                }
                $draft = $row->bill_id === null ? null : DB::table('bills')->where('id', $row->bill_id)
                    ->where('user_id', $row->user_id)->where('status', 'DRAFT')->lockForUpdate()->first();
                DB::table('orders')->where('id', $orderId)->update($update);
                if ($flowProvided && !$flowChanged) DB::table('orders')->where('id',$orderId)->update(['production_stage_flow_id'=>$v['productionStageFlowId']]);
                if ($flowChanged) {
                    $flow = $this->flowProgress->resolveFlowId((int)$row->user_id, $v['productionStageFlowId'] === null ? null : (int)$v['productionStageFlowId']);
                    DB::table('orders')->where('id',$orderId)->update(['production_stage_flow_id'=>$flow]);
                    $this->pricing->replaceComponents((int)$row->user_id,$orderId,$flow);
                    DB::table('orders')->where('id',$orderId)->update(['flow_progress_flow_id'=>null,'last_completed_production_stage_id'=>null]);
                }
                $this->pricing->recalculate((int)$row->user_id,$orderId,$requestedBase,$pricingChanged);
                $fresh = DB::table('orders')->where('id',$orderId)->first();
                $oldAmount = (string) ($row->amount ?? $row->baseAmount ?? '0');
                $delta = (string) BigDecimal::of((string) $fresh->amount)->minus($oldAmount);
                $this->adjustClientOrdersTotal((int) $row->user_id, (int) $row->client_id, $delta);
                if ($draft) {
                    $subtotal = BigDecimal::zero();
                    foreach (DB::table('orders')->where('bill_id', $draft->id)->get() as $billOrder) {
                        $subtotal = $subtotal->plus((string) ($billOrder->amount ?? '0'));
                    }
                    $subtotal = $subtotal->toScale(2, RoundingMode::HALF_UP);
                    $discount = BigDecimal::of((string) $draft->discount_amount);
                    if ($discount->isGreaterThan($subtotal)) $discount = $subtotal;
                    DB::table('bills')->where('id', $draft->id)->update([
                        'subtotal_amount' => (string) $subtotal,
                        'discount_amount' => (string) $discount,
                        'total_amount' => (string) $subtotal->minus($discount),
                        'version' => DB::raw('version + 1'),
                    ]);
                }
            });
            return response()->json(['message' => 'Order updated successfully', 'order' => $this->serializer->one(DB::table('orders')->where('id', $orderId)->first())]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function destroy(Request $request, int $orderId): JsonResponse
    {
        try { $row = $this->owned($orderId, $this->ownerId($request));
            if ((bool) $row->billed) throw new \InvalidArgumentException('Cannot delete order that is already billed');
            $files = $this->mediaCleanup->captureOrderFiles($orderId);
            DB::transaction(function () use ($row, $orderId) {
                $this->adjustClientOrdersTotal((int) $row->user_id, (int) $row->client_id, (string) BigDecimal::of((string) ($row->amount ?? $row->baseAmount ?? '0'))->negated());
                if ($row->currentStage) $this->counters->decrement($row->currentStage, (int) $row->user_id);
                foreach (['order_stage_assignments', 'worker_order_assignments', 'dashboard_order_refs', 'order_component_charges'] as $table) {
                    DB::table($table)->where('order_id', $orderId)->delete();
                }
                DB::table('order_tracking')->where('order_id', $orderId)->where('user_id', $row->user_id)->delete();
                if (\Illuminate\Support\Facades\Schema::hasTable('order_images')) DB::table('order_images')->where('order_id', $orderId)->delete();
                if (\Illuminate\Support\Facades\Schema::hasTable('order_voice_notes')) DB::table('order_voice_notes')->where('order_id', $orderId)->delete();
                DB::table('orders')->where('id', $orderId)->delete();
            });
            $this->mediaCleanup->deleteCapturedFiles($files);
            return response()->json(['message' => 'Order deleted successfully']);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function reset(Request $request, int $orderId): JsonResponse
    {
        try { $row = $this->owned($orderId, $this->ownerId($request));
            if ((bool) $row->billed) throw new \InvalidArgumentException('Billed orders cannot be reset');
            DB::transaction(function () use ($row, $orderId) {
                if ($row->bill_id !== null && DB::table('bills')->where('id', $row->bill_id)
                    ->where('user_id', $row->user_id)->where('status', 'DRAFT')->lockForUpdate()->exists()) {
                    throw new \InvalidArgumentException('Remove this order from its draft bill before resetting it');
                }
                app(\App\Services\WorkerCompensationService::class)->reverseOrderEarningsForReset((int) $row->user_id, $orderId);
                foreach (['order_stage_assignments', 'worker_order_assignments', 'dashboard_order_refs'] as $table) {
                    DB::table($table)->where('order_id', $orderId)->delete();
                }
                if ($row->currentStage !== 'Pending') {
                    if ($row->currentStage) $this->counters->decrement($row->currentStage, (int) $row->user_id);
                    $this->counters->increment('Pending', (int) $row->user_id);
                }
                DB::table('order_tracking')->where('order_id', $orderId)->where('user_id', $row->user_id)->delete();
                DB::table('orders')->where('id', $orderId)->update(['currentStage' => 'Pending', 'worker_id' => null, 'assigned_at' => null,
                    'deleiverdThorugh' => null, 'flow_progress_flow_id' => null, 'last_completed_production_stage_id' => null, 'billed' => false, 'updated_at' => now()]);
                DB::table('order_tracking')->insert(['order_id' => $orderId, 'user_id' => $row->user_id, 'status' => 'created', 'tracking_time' => $row->date]);
            });
            return response()->json(['order' => $this->serializer->one(DB::table('orders')->where('id', $orderId)->first())]);
        } catch (\Throwable $e) { return $this->error($e); }
    }

    public function updateStage(Request $request, int $orderId): JsonResponse|\Illuminate\Http\Response
    {
        try { $row = $this->owned($orderId, $this->ownerId($request));
            if ((bool) $row->billed) throw new \InvalidArgumentException('Cannot update order that is already billed');
            $stage = $request->query('stage'); if ($stage === null || trim($stage) === '') throw new \InvalidArgumentException('stage is required');
            $trackingStage = $stage === 'Delivered' ? $request->query('mode') : $stage;
            $changed = DB::transaction(function () use ($orderId, $stage, $trackingStage, $request, $row) {
            $this->flowProgress->recordCompletion((int)$row->user_id,$orderId,$trackingStage);
            $progress = DB::table('orders')->where('id',$orderId)->first(['flow_progress_flow_id','last_completed_production_stage_id']);
            if ($row->currentStage === $stage
                && $progress->flow_progress_flow_id == $row->flow_progress_flow_id
                && $progress->last_completed_production_stage_id == $row->last_completed_production_stage_id) return false;
            if (strcasecmp((string)$row->currentStage,'Completed')===0 && strcasecmp($stage,'Completed')!==0 && strcasecmp($stage,'Delivered')!==0) app(\App\Services\WorkerCompensationService::class)->assertCanLeaveCompleted((int)$row->user_id,$orderId);
            $this->cancelOpenStageAssignments((int)$row->user_id,$orderId);
            DB::table('orders')->where('id', $orderId)->update(['currentStage' => $stage, 'deleiverdThorugh' => $stage === 'Delivered' ? $request->query('mode') : $row->deleiverdThorugh,
                'billed' => $stage === 'Billed' ? true : (bool) $row->billed, 'updated_at' => now()]);
            if ($row->currentStage !== $stage) {
                if ($row->currentStage) $this->counters->decrement($row->currentStage, (int) $row->user_id);
                $this->counters->increment($stage, (int) $row->user_id);
            }
            if ($stage === 'Billed' && $row->currentStage !== 'Billed') $this->adjustClientOrdersTotal((int) $row->user_id, (int) $row->client_id, (string) BigDecimal::of((string) ($row->amount ?? $row->baseAmount ?? '0'))->negated());
            DB::table('order_tracking')->insert(['order_id' => $orderId, 'user_id' => $row->user_id, 'status' => $trackingStage, 'tracking_time' => now()]);
            return true;
            });
            if ($changed && strcasecmp($stage, 'Billed') !== 0) {
                $client = DB::table('clients')->where('id', $row->client_id)->first(['clientName', 'registered_client_id']);
                $clientName = $client?->clientName ?: 'Client';
                $registeredClientId = $client?->registered_client_id === null ? null : (int) $client->registered_client_id;
                $personalizedName = trim((string) $client?->clientName);
                if ($personalizedName === '') $personalizedName = 'customer';
                DB::afterCommit(fn () => SendOrderNotification::dispatch((int) $row->user_id, null,
                    'Order #'.$row->id.' for '.$clientName.' is now '.$stage.'.', $registeredClientId,
                    $registeredClientId === null ? null : 'Hi '.$personalizedName.', your order #'.$row->id.' is now '.$stage.'.')->afterResponse());
            }
            return response()->noContent();
        } catch (\Throwable $e) { return $this->error($e); }
    }

    private function cancelOpenStageAssignments(int $userId,int $orderId): void
    {
        $open=DB::table('order_stage_assignments')->where('user_id',$userId)->where('order_id',$orderId)->whereIn('status',['PICKED','IN_PROGRESS'])->get();
        foreach($open as $a){DB::table('order_stage_assignments')->where('id',$a->id)->update(['status'=>'CANCELLED','updated_at'=>now()]);DB::table('worker_order_assignments')->where('user_id',$userId)->where('worker_id',$a->worker_id)->where('order_id',$orderId)->where('current_assignment',true)->update(['current_assignment'=>false,'unassigned_at'=>now()]);DB::table('orders')->where('id',$orderId)->where('worker_id',$a->worker_id)->update(['worker_id'=>null,'assigned_at'=>null,'updated_at'=>now()]);}
    }
}
