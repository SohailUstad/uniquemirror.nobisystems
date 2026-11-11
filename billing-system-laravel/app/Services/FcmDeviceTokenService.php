<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class FcmDeviceTokenService
{
    private function required(?string $token): string { $token=trim((string)$token); if($token==='') throw new \InvalidArgumentException('Token is required'); return $token; }
    private function user(string $email): object { return DB::table('users')->where('email',$email)->first() ?? throw new \RuntimeException('User not found'); }
    public function register(string $email, ?string $token, ?string $platform, ?string $deviceId, ?string $userAgent): object
    {
        $token=$this->required($token); $hash=hash('sha256',$token); $user=$this->user($email); $now=now();
        $row=DB::table('fcm_device_tokens')->where('token_hash',$hash)->first();
        $values=['user_id'=>$user->id,'token'=>$token,'platform'=>($platform=trim((string)$platform))===''?null:$platform,'device_id'=>($deviceId=trim((string)$deviceId))===''?null:$deviceId,'user_agent'=>($userAgent=trim((string)$userAgent))===''?null:$userAgent,'active'=>true,'last_seen_at'=>$now,'logged_out_at'=>null,'updated_at'=>$now];
        if($row) {
            DB::table('fcm_device_tokens')->where('id',$row->id)->update($values);
        } else {
            $values['token_hash']=$hash; $values['created_at']=$now;
            DB::table('fcm_device_tokens')->insert($values);
        }
        return DB::table('fcm_device_tokens')->where('token_hash',$hash)->first();
    }
    public function deactivate(string $email, ?string $token): void
    {
        $token=$this->required($token); $row=DB::table('fcm_device_tokens')->where('token_hash',hash('sha256',$token))->first();
        if(!$row) return; $user=$this->user($email); if((int)$row->user_id!==(int)$user->id) throw new \InvalidArgumentException('Unauthorized: Token does not belong to this user');
        DB::table('fcm_device_tokens')->where('id',$row->id)->update(['active'=>false,'logged_out_at'=>now(),'updated_at'=>now()]);
    }
}
