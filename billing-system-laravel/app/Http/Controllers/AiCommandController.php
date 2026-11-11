<?php

namespace App\Http\Controllers;

use App\Services\AiCommandService;
use App\Services\AiConfirmationExpiredException;
use App\Services\AiConfirmationNotFoundException;
use App\Services\AiPaymentConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiCommandController extends Controller
{
    public function __construct(private AiCommandService $commands, private AiPaymentConfirmationService $payments) {}

    private function owner(Request $request): int
    {
        $id = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
        if (!$id) throw new \InvalidArgumentException('Authenticated user not found');
        return (int)$id;
    }

    public function command(Request $request)
    {
        try {
            $command = $request->input('command');
            if (!is_string($command) || trim($command)==='') return response()->json(['message'=>'command is required'],400);
            $uid = $this->owner($request);
            [$name,$args] = $this->commands->route($command);
            if ($name === 'getCurrentTime') {
                $time = now('Asia/Kolkata')->format('Y-m-d\TH:i:sP').'[Asia/Kolkata]';
                return response()->json($this->response('COMPLETED','GET_CURRENT_TIME','Current time retrieved',['time'=>$time]));
            }
            if ($name === 'countOrders') {
                $stage = isset($args['stage']) && trim((string)$args['stage']) !== '' ? trim((string)$args['stage']) : null;
                $query = DB::table('orders')->where('user_id',$uid);
                if ($stage !== null) $query->where('currentStage',$stage);
                return response()->json($this->response('COMPLETED','COUNT_ORDERS','Order count retrieved',['stage'=>$stage ?? 'ALL','count'=>$query->count()]));
            }
            return response()->json($this->payments->prepare($uid,$args));
        } catch (\InvalidArgumentException $e) {
            return response()->json($this->error($e->getMessage()),422);
        } catch (Throwable $e) {
            return response()->json(['message'=>'AI command could not be processed'],503);
        }
    }

    public function confirm(Request $request, string $confirmationId)
    {
        try { return response()->json($this->payments->confirm($confirmationId,$this->owner($request))); }
        catch (AiConfirmationNotFoundException $e) { return response()->json($this->error($e->getMessage()),404); }
        catch (AiConfirmationExpiredException $e) { return response()->json($this->error($e->getMessage()),410); }
        catch (\InvalidArgumentException $e) { return response()->json($this->error($e->getMessage()),422); }
        catch (Throwable $e) { return response()->json(['message'=>'Payment confirmation could not be processed'],500); }
    }

    private function response(string $status,string $action,string $message,mixed $data): array
    { return ['status'=>$status,'action'=>$action,'message'=>$message,'confirmationId'=>null,'expiresAt'=>null,'data'=>$data]; }
    private function error(string $message): array
    { return ['status'=>'ERROR','action'=>null,'message'=>$message,'confirmationId'=>null,'expiresAt'=>null,'data'=>null]; }
}
