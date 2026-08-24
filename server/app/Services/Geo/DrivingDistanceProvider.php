<?php

namespace App\Services\Geo;

interface DrivingDistanceProvider
{
    public function drivingDistanceKm(float $latitude, float $longitude): float;
}
