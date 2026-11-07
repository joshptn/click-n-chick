/**
 * The delivery map's code - Leaflet, its tiles layer and markers - loaded only
 * when a map is about to show. Calling it early (when the customer reaches for
 * Delivery) starts the download; the render reuses it.
 */
export const loadDeliveryMap = () => import("../components/checkout/DeliveryMap");

/** Warm the map's code early; a failure here is left for the render to retry. */
export const preloadDeliveryMap = () => {
  loadDeliveryMap().catch(() => {});
};
