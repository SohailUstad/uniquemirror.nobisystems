<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiPaymentConfirmationService
{
    public function __construct(private ClientBalanceService $balances) {}

    public function prepare(int $userId, array $args): array
    {
        $clientId = isset($args['clientId']) ? (int) $args['clientId'] : null;
        $clientName = isset($args['clientName']) ? trim((string) $args['clientName']) : '';
        if (!$clientId && $clientName === '') throw new InvalidArgumentException('Client ID or exact client name is required');
        $byId = $clientId ? DB::table('clients')->where('user_id',$userId)->where('id',$clientId)->first() : null;
        if ($clientId && !$byId) throw new InvalidArgumentException('Client not found');
        $byName = null;
        if ($clientName !== '') {
            $matches = DB::table('clients')->where('user_id',$userId)->whereRaw('LOWER(clientName) = ?', [mb_strtolower($clientName)])->get();
            if ($matches->isEmpty()) throw new InvalidArgumentException('Client not found');
            if ($matches->count() > 1) throw new InvalidArgumentException('Client name is ambiguous; use the client ID');
            $byName = $matches->first();
        }
        if ($byId && $byName && (int)$byId->id !== (int)$byName->id) throw new InvalidArgumentException('Client ID and client name refer to different clients');
        $client = $byId ?: $byName;
        $rawAmount = $args['amount'] ?? null;
        if (!is_numeric($rawAmount) || (float)$rawAmount <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero');
        try { $amount = BigDecimal::of((string)$rawAmount)->toScale(2, RoundingMode::UNNECESSARY); }
        catch (\Throwable) { throw new InvalidArgumentException('Payment amount cannot have more than two decimal places'); }
        $date = trim((string)($args['paymentDate'] ?? ''));
        if ($date === '') $date = now()->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !\DateTime::createFromFormat('!Y-m-d',$date)) throw new InvalidArgumentException('Payment date must use YYYY-MM-DD format');
        $mode = strtoupper(str_replace(['-',' '], '_', trim((string)($args['paymentMode'] ?? 'CASH'))));
        if (in_array($mode,['BANK','TRANSFER'],true)) $mode = 'BANK_TRANSFER';
        if (!in_array($mode,['CASH','UPI','BANK_TRANSFER','CARD','CHEQUE','OTHER'],true)) throw new InvalidArgumentException('Unsupported payment mode');
        $reference = $this->optional($args['referenceNo'] ?? null, 255, 'Reference number');
        $note = $this->optional($args['note'] ?? null, 1000, 'Note');
        $id = (string) Str::uuid(); $expires = now()->addMinutes((int)config('services.ai.confirmation_ttl_minutes',10));
        DB::table('ai_pending_actions')->insert(['id'=>$id,'user_id'=>$userId,'client_id'=>$client->id,'action_type'=>'RECORD_CLIENT_PAYMENT','status'=>'PENDING','amount'=>(string)$amount,'payment_date'=>$date,'payment_mode'=>$mode,'reference_no'=>$reference,'note'=>$note,'expires_at'=>$expires->format('Y-m-d H:i:s'),'created_at'=>now()->format('Y-m-d H:i:s')]);
        return ['status'=>'CONFIRMATION_REQUIRED','action'=>'RECORD_CLIENT_PAYMENT','message'=>'Confirm the payment preview before it is recorded','confirmationId'=>$id,'expiresAt'=>$expires->format('Y-m-d\TH:i:s'),'data'=>['clientId'=>(int)$client->id,'clientName'=>$client->clientName,'amount'=>(float)(string)$amount,'paymentDate'=>$date,'paymentMode'=>$mode,'referenceNo'=>$reference,'note'=>$note]];
    }

    public function confirm(string $confirmationId, int $userId): array
    {
        $result = DB::transaction(function () use ($confirmationId,$userId): array {
            $action = DB::table('ai_pending_actions')->where('id',$confirmationId)->where('user_id',$userId)->lockForUpdate()->first();
            if (!$action) throw new AiConfirmationNotFoundException('Payment confirmation not found');
            if ($action->status === 'COMPLETED') {
                $payment = DB::table('payments')->where('id',$action->confirmed_payment_id)->where('user_id',$userId)->first();
                if (!$payment) throw new \RuntimeException('Completed confirmation has no payment');
                return $this->completed($payment);
            }
            if ($action->status === 'EXPIRED' || strtotime($action->expires_at) <= time()) {
                DB::table('ai_pending_actions')->where('id',$confirmationId)->update(['status'=>'EXPIRED','version'=>$action->version+1]);
                return ['__expired' => true];
            }
            if ($action->action_type !== 'RECORD_CLIENT_PAYMENT') throw new InvalidArgumentException('Unsupported pending action type');
            $now = now()->format('Y-m-d H:i:s');
            $paymentId = DB::table('payments')->insertGetId(['user_id'=>$userId,'client_id'=>$action->client_id,'amount'=>$action->amount,'allocated_amount'=>'0.00','unallocated_amount'=>'0.00','payment_date'=>$action->payment_date,'payment_mode'=>$action->payment_mode,'reference_no'=>$action->reference_no,'note'=>$action->note,'created_at'=>$now,'updated_at'=>$now,'deleted'=>false]);
            $this->balances->recordPaymentReceived($userId,(int)$action->client_id,$action->amount,(int)$paymentId,$action->note);
            DB::table('ai_pending_actions')->where('id',$confirmationId)->where('user_id',$userId)->update(['status'=>'COMPLETED','confirmed_payment_id'=>$paymentId,'confirmed_at'=>$now,'version'=>$action->version+1]);
            return $this->completed(DB::table('payments')->where('id',$paymentId)->first());
        });
        // The source transaction commits the EXPIRED state even though the HTTP
        // operation returns 410. Throw only after the transaction commits.
        if (($result['__expired'] ?? false) === true) throw new AiConfirmationExpiredException('Payment confirmation has expired');
        return $result;
    }

    private function completed(object $payment): array
    {
        $client = DB::table('clients')->where('id',$payment->client_id)->first();
        return ['status'=>'COMPLETED','action'=>'RECORD_CLIENT_PAYMENT','message'=>'Payment recorded successfully','confirmationId'=>null,'expiresAt'=>null,'data'=>['paymentId'=>(int)$payment->id,'clientId'=>(int)$payment->client_id,'clientName'=>$client?->clientName,'amount'=>(float)$payment->amount,'paymentDate'=>$payment->payment_date,'paymentMode'=>$payment->payment_mode,'referenceNo'=>$payment->reference_no,'note'=>$payment->note]];
    }

    private function optional(mixed $value,int $max,string $label): ?string
    {
        if (!is_string($value) || trim($value)==='') return null;
        $value=trim($value); if (mb_strlen($value)>$max) throw new InvalidArgumentException("{$label} cannot exceed {$max} characters"); return $value;
    }
}
