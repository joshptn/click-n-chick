<?php

namespace App\Services\Geo;

/**
 * The routing engine answered, and there is no road route to that point.
 *
 * A subclass of RoutingUnavailable so every existing catch still holds, but
 * distinguished because the advice is opposite. An outage is worth retrying;
 * this is not - the pin is somewhere a car cannot reach, and only moving it
 * helps.
 *
 * Common around Apalit, where the fishponds and the river delta mean a pin a
 * few hundred metres off a road can genuinely have no route at all.
 */
class RouteNotFound extends RoutingUnavailable {}
