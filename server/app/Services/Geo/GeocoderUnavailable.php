<?php

namespace App\Services\Geo;

use RuntimeException;

/**
 * The address service could not answer.
 *
 * Distinct from "no results" on purpose. A customer whose search returned
 * nothing should refine it; a customer whose search could not run should be
 * pointed at the map instead, because retrying spends a shared request budget
 * that is already exhausted.
 */
class GeocoderUnavailable extends RuntimeException {}
