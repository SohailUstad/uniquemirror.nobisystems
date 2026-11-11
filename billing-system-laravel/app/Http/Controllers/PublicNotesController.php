<?php
namespace App\Http\Controllers;
use App\Services\NotesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class PublicNotesController
{
    public function create(Request $request, NotesService $notes): JsonResponse
    {
        try { return response()->json(['status'=>'success','data'=>$notes->createPublic((int)$request->input('orderId'),(string)$request->input('note'))]); }
        catch (\Throwable $e) { return response()->json(['status'=>'error','message'=>$e->getMessage()],400); }
    }
    public function byOrder(int $orderId, NotesService $notes): JsonResponse
    {
        try { return response()->json(['status'=>'success','data'=>$notes->publicByOrder($orderId)]); }
        catch (\Throwable $e) { return response()->json(['status'=>'error','message'=>$e->getMessage()],400); }
    }
}
