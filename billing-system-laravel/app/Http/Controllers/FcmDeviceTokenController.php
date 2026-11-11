<?php
namespace App\Http\Controllers;
use App\Services\FcmDeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class FcmDeviceTokenController
{
    public function register(Request $r,FcmDeviceTokenService $s): JsonResponse { try { if($r->exists('clientId')) throw new \InvalidArgumentException('Client-scoped FCM tokens are not supported'); $x=$s->register($r->attributes->get('jwt_subject'),$r->input('token'),$r->input('platform'),$r->input('deviceId'),$r->input('userAgent')); return response()->json(['message'=>'FCM token registered successfully','tokenId'=>$x->id,'active'=>(bool)$x->active]); } catch(\Throwable $e){ return response()->json(['error'=>$e->getMessage()],400); } }
    public function deactivate(Request $r,FcmDeviceTokenService $s): JsonResponse { try { $s->deactivate($r->attributes->get('jwt_subject'),$r->input('token')); return response()->json(['message'=>'FCM token deactivated successfully']); } catch(\Throwable $e){ return response()->json(['error'=>$e->getMessage()],400); } }
    public function logout(Request $r,FcmDeviceTokenService $s): JsonResponse { try { $s->deactivate($r->attributes->get('jwt_subject'),$r->input('token')); return response()->json(['message'=>'Logout successful']); } catch(\InvalidArgumentException $e){ return response()->json(['error'=>$e->getMessage()],400); } }
}
