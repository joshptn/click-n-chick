<?php

namespace App\Utils; 

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class Websocket
{
    
    public static function broadcast($type,$event,$data, $user_id = null)
    {
        if(!$user_id){
            $data_sent = [
                        "event" => $event,
                        $type  => $data
                    ];
        }else{
            $data_sent = [
                        "event" => $event,
                        'user_id' => $user_id,
                        $type  => $data
                    ];
        }

        
        $websocketUrl = config('services.websocket.http_url');
            if ($websocketUrl) {
                try {
                    Http::post($websocketUrl . "/broadcast/{$type}", $data_sent);
                } catch (\Exception $e) {
                    Log::warning('Websocket broadcast failed: ' . $e->getMessage());
                }
            }
    }
}