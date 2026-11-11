<?php
namespace App\Http\Controllers;

use App\Services\NotesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotesController
{
    public function create(Request $request, NotesService $notes): JsonResponse
    {
        try {
            $user = $notes->userForSubject($request->attributes->get('jwt_subject'));
            $row = $notes->createOwned($user->id, (int) $request->input('orderId'), (string) $request->input('note'));
            return response()->json(['status'=>'success','data'=>$row]);
        } catch (\Throwable $e) { return response()->json(['status'=>'error','message'=>$e->getMessage()], 400); }
    }
    public function byOrder(int $orderId, Request $request, NotesService $notes): JsonResponse
    {
        try { $user=$notes->userForSubject($request->attributes->get('jwt_subject')); return response()->json(['status'=>'success','data'=>$notes->ownedByOrder($user->id,$orderId)]); }
        catch (\Throwable $e) { return response()->json(['status'=>'error','message'=>$e->getMessage()],400); }
    }
}
