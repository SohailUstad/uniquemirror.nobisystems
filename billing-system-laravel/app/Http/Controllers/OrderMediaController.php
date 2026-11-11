<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderMediaController
{
    private const AUDIO_TYPES = ['audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','audio/aac'=>'aac','audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav'];

    public function upload(Request $request): \Illuminate\Http\Response
    {
        $this->validateInput($request, ['file'=>['required','file']]);
        $file=$request->file('file');
        $name=(string)Str::uuid().'_'.basename($file->getClientOriginalName());
        $key=$file->storeAs('uploads',$name,'local');
        return response($this->publicFileUrl(basename($key)),200,['Content-Type'=>'text/plain; charset=UTF-8']);
    }

    public function addImages(int $orderId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id);
        $data=$this->validateInput($request,['images'=>['required','array','min:1'],'images.*.imageUrl'=>['required','string','max:1000'],'images.*.label'=>['nullable','string','max:255'],'images.*.displayOrder'=>['nullable','integer']]);
        $items=DB::transaction(function() use($data,$orderId): array { $rows=[]; foreach($data['images'] as $image){$id=DB::table('order_images')->insertGetId(['order_id'=>$orderId,'image_url'=>trim($image['imageUrl']),'label'=>isset($image['label'])&&trim($image['label'])!==''?trim($image['label']):null,'display_order'=>$image['displayOrder']??0,'created_at'=>now()]);$rows[]=$this->image($id);}return $rows;});
        return response()->json(['message'=>'Order images added successfully','images'=>$items],201);
    }

    public function updateImage(int $orderId,int $imageId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id);
        $data=$this->validateInput($request,['imageUrl'=>['required','string','max:1000'],'label'=>['nullable','string','max:255'],'displayOrder'=>['nullable','integer']]);
        if (!DB::table('order_images')->where('id',$imageId)->where('order_id',$orderId)->exists()) $this->fail('Order image not found');
        DB::table('order_images')->where('id',$imageId)->update(['image_url'=>trim($data['imageUrl']),'label'=>isset($data['label'])&&trim($data['label'])!==''?trim($data['label']):null,'display_order'=>$data['displayOrder']??0]);
        return response()->json(['message'=>'Order image updated successfully','image'=>$this->image($imageId)]);
    }

    public function deleteImage(int $orderId,int $imageId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id);
        if (!DB::table('order_images')->where('id',$imageId)->where('order_id',$orderId)->exists()) $this->fail('Order image not found');
        DB::table('order_images')->where('id',$imageId)->delete();
        return response()->json(['message'=>'Order image deleted successfully']);
    }

    public function voiceNotes(int $orderId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id);
        $rows=DB::table('order_voice_notes')->where('order_id',$orderId)->orderBy('created_at')->orderBy('id')->get();
        return response()->json(['voiceNotes'=>$rows->map(fn($r)=>$this->voiceNote($r))->all()]);
    }

    public function addVoiceNote(int $orderId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id);
        $data=$this->validateInput($request,['file'=>['required','file'],'label'=>['nullable','string','max:255'],'durationSeconds'=>['nullable','integer','min:0']]);
        $file=$request->file('file'); $rawMime=(string)$file->getClientMimeType(); $mime=strtolower(trim(explode(';',$rawMime)[0]));
        if (!isset(self::AUDIO_TYPES[$mime])) $this->fail('Unsupported audio format: '.($mime?:'unknown'));
        $key='voice-notes/'.Str::uuid().'.'.self::AUDIO_TYPES[$mime]; $disk=Storage::disk('local');
        abort_unless($disk->put($key,file_get_contents($file->getRealPath())),500,'Could not store the recording');
        try{$id=DB::table('order_voice_notes')->insertGetId(['order_id'=>$orderId,'storage_key'=>$key,'mime_type'=>$rawMime,'label'=>isset($data['label'])&&trim($data['label'])!==''?trim($data['label']):null,'duration_seconds'=>$data['durationSeconds']??null,'size_bytes'=>$file->getSize(),'created_at'=>now()]);}
        catch(\Throwable $e){$disk->delete($key);throw $e;}
        return response()->json(['message'=>'Voice note added successfully','voiceNote'=>$this->voiceNote(DB::table('order_voice_notes')->where('id',$id)->first())],201);
    }

    public function streamVoiceNote(int $orderId,int $noteId,Request $request): BinaryFileResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id); $note=DB::table('order_voice_notes')->where('id',$noteId)->where('order_id',$orderId)->first(); if (!$note) $this->fail('Voice note not found');
        $disk=Storage::disk('local'); if (!$disk->exists($note->storage_key)) $this->fail('Recording file is missing',404);
        $response = response()->file($disk->path($note->storage_key),['Content-Type'=>$note->mime_type?:'application/octet-stream','Content-Disposition'=>'inline','X-Content-Type-Options'=>'nosniff']);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        return $response;
    }

    public function deleteVoiceNote(int $orderId,int $noteId,Request $request): JsonResponse
    {
        $this->ownedOrder($orderId,$this->user($request)->id); $note=DB::table('order_voice_notes')->where('id',$noteId)->where('order_id',$orderId)->first(); if (!$note) $this->fail('Voice note not found');
        DB::table('order_voice_notes')->where('id',$noteId)->delete(); Storage::disk('local')->delete($note->storage_key);
        return response()->json(['message'=>'Voice note deleted successfully']);
    }

    public function tracking(int $orderId,Request $request): JsonResponse
    {
        $user=$this->user($request); $order=$this->ownedOrder($orderId,$user->id); $rows=DB::table('order_tracking')->where('order_id',$orderId)->orderBy('tracking_time')->orderBy('id')->get();
        $timeline=$rows->map(function($r) {
            $item=['id'=>(int)$r->id,'orderId'=>(int)$r->order_id,'userId'=>$r->user_id===null?null:(int)$r->user_id,'status'=>$r->status,'trackingTime'=>$r->tracking_time?str_replace(' ','T',$r->tracking_time):null];
            $workerName=$this->workerNameForTracking($r);
            if($workerName!==null)$item['workerName']=$workerName;
            return $item;
        })->all();
        $progress=app(\App\Services\ProductionFlowProgressService::class);
        $order->production_stage_flow_id=$progress->resolveFlowId((int)$user->id,$order->production_stage_flow_id===null?null:(int)$order->production_stage_flow_id);
        $next=$progress->nextStageId($order);
        if($next!==null){$steps=DB::table('production_stage_flow_steps')->where('flow_id',$order->production_stage_flow_id)->where('stage_id',$next)->first();$start=$steps?->sequence;
            foreach(DB::table('production_stage_flow_steps as fs')->join('production_stages as s','s.id','=','fs.stage_id')->where('fs.flow_id',$order->production_stage_flow_id)->where('fs.sequence','>=',$start)->orderBy('fs.sequence')->orderBy('fs.id')->select('fs.sequence','s.id','s.name')->get() as $stage)$timeline[]=['id'=>'pending-stage-'.$stage->id,'status'=>$stage->name,'trackingTime'=>null,'pending'=>true,'timelineState'=>'PENDING','sequence'=>(int)$stage->sequence];}
        return response()->json($timeline);
    }

    private function workerNameForTracking(object $tracking): ?string
    {
        if($tracking->status===null || trim((string)$tracking->status)==='') return null;
        {
            $name=DB::table('order_stage_assignments as a')->join('workers as w','w.id','=','a.worker_id')
                ->where('a.order_id',$tracking->order_id)->whereRaw('LOWER(a.stage_name_snapshot)=LOWER(?)',[(string)$tracking->status])
                ->where('a.status','COMPLETED')->orderByDesc('a.completed_at')->orderByDesc('a.id')->value('w.name');
            if($name!==null)return $name;
        }
        if($tracking->tracking_time===null)return null;
        return DB::table('worker_order_assignments as a')->join('workers as w','w.id','=','a.worker_id')
            ->where('a.order_id',$tracking->order_id)->where('a.assigned_at','<=',$tracking->tracking_time)
            ->where(function($q)use($tracking){$q->whereNull('a.unassigned_at')->orWhere('a.unassigned_at','>',$tracking->tracking_time);})
            ->orderByDesc('a.assigned_at')->orderByDesc('a.id')->value('w.name');
    }

    public function publicFile(string $filename): BinaryFileResponse
    {
        abort_if($filename!==basename($filename)||str_contains($filename,'..'),404); $path=Storage::disk('local')->path('uploads/'.$filename); abort_unless(is_file($path),404);
        return response()->file($path,['Cache-Control'=>'public, max-age=86400','X-Content-Type-Options'=>'nosniff']);
    }

    private function fail(string $message,int $status=400): never { throw new HttpResponseException(response()->json(['error'=>$message],$status)); }
    private function validateInput(Request $request,array $rules): array
    {
        $validator=Validator::make($request->all(),$rules); if ($validator->fails()) $this->fail($validator->errors()->first()); return $validator->validated();
    }
    private function user(Request $request): object { $user=DB::table('users')->where('email',$request->attributes->get('jwt_subject'))->first(); if (!$user) $this->fail('User not found'); return $user; }
    private function ownedOrder(int $orderId,int $userId): object { $order=DB::table('orders')->where('id',$orderId)->where('user_id',$userId)->first(); if (!$order) $this->fail('Order not found'); return $order; }
    private function image(int $id): array { $r=DB::table('order_images')->where('id',$id)->first();return ['id'=>(int)$r->id,'imageUrl'=>$r->image_url,'label'=>$r->label,'displayOrder'=>(int)$r->display_order]; }
    private function voiceNote(object $r): array { return ['id'=>(int)$r->id,'label'=>$r->label,'mimeType'=>$r->mime_type,'durationSeconds'=>$r->duration_seconds===null?null:(int)$r->duration_seconds,'sizeBytes'=>$r->size_bytes===null?null:(int)$r->size_bytes,'createdAt'=>str_replace(' ','T',$r->created_at)]; }
    private function publicFileUrl(string $filename): string { return rtrim(config('app.url'),'/').'/files/'.$filename; }
}
