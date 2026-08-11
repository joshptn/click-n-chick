<?php

namespace App\Utils; 


class Distance
{
    public static function getDistance($lat, $lng)
    {
        $origin_lat = 14.958753194320153;
        $origin_lng = 120.75846924744896;

        
        $latFrom = deg2rad($origin_lat);
        $lngFrom = deg2rad($origin_lng);
        $latTo = deg2rad($lat);
        $lngTo = deg2rad($lng);

        
        $earthRadius = 6371; 

        $latDelta = $latTo - $latFrom;
        $lngDelta = $lngTo - $lngFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lngDelta / 2) * sin($lngDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return $distance; 
    }
}