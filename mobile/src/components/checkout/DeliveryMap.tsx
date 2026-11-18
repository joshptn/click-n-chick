import { useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, View } from "react-native";
import { WebView, type WebViewMessageEvent } from "react-native-webview";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconCurrentLocation, IconMapPin } from "../icons";

export type Point = { latitude: number; longitude: number };

type DeliveryMapProps = {
  pin: Point | null;
  origin: Point | null;
  maxDrivingKm: number;
  /** [latitude, longitude] pairs from the delivery quote. */
  route: [number, number][] | null;
  withinServiceArea: boolean;
  /** Working out the address or the fee for a new pin. */
  resolving: boolean;
  locality: string | null;
  onPick: (point: Point) => void;
  onLocate: () => void;
  locating: boolean;
  /** True while a finger is on the map, so the page stops scrolling under it. */
  onTouching?: (touching: boolean) => void;
};

// react-native-webview 14 types its class as WebView<P = undefined> with props
// `WebViewProps & P`, which TypeScript 6 reduces to never; `object` keeps them.
const MapWebView = WebView<object>;
type MapWebViewRef = WebView<object>;

// Apalit, Pampanga: where the map starts before the store's own position arrives.
const START = { latitude: 14.9587, longitude: 120.7584 };

/**
 * The page inside the WebView: Leaflet on OpenStreetMap tiles, as the web's
 * DeliveryMap. It draws what the app sends (pin, store, service radius, route)
 * and reports taps and pin drags back. Leaflet comes from a pinned CDN
 * version; with no connection the map simply does not draw.
 */
function mapPage(start: Point) {
  return `<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
html,body,#map{margin:0;padding:0;height:100%;background:#eef3ea}
.leaflet-control-attribution{font-size:9px}
.pin{width:34px;height:34px;border-radius:50%;background:${colors.orange};border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}
.store{width:28px;height:28px;border-radius:50%;background:${colors.espressoButton};border:2px solid #fff;display:flex;align-items:center;justify-content:center}
</style></head>
<body><div id="map"></div>
<script>
(function(){
  function send(m){ if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(JSON.stringify(m)); }
  if (!window.L) { send({type:'failed'}); return; }
  var map = L.map('map', {zoomControl:false}).setView([${start.latitude}, ${start.longitude}], 15);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap'}).addTo(map);
  var pinSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="white" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>';
  var storeSvg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5"/><path d="M5 10v9h14v-9"/></svg>';
  var pinIcon = L.divIcon({className:'', html:'<div class="pin">'+pinSvg+'</div>', iconSize:[34,34], iconAnchor:[17,17]});
  var storeIcon = L.divIcon({className:'', html:'<div class="store">'+storeSvg+'</div>', iconSize:[28,28], iconAnchor:[14,14]});
  var pin = null, store = null, ring = null, routeUnder = null, routeLine = null, routeKey = null;

  map.on('click', function(e){ send({type:'pick', latitude:e.latlng.lat, longitude:e.latlng.lng}); });

  window.cncUpdate = function(s){
    if (s.origin && !store) {
      store = L.marker([s.origin.latitude, s.origin.longitude], {icon:storeIcon, interactive:false}).addTo(map);
      if (s.maxKm > 0) ring = L.circle([s.origin.latitude, s.origin.longitude], {radius:s.maxKm*1000, color:'${colors.brand500}', weight:1.2, opacity:.45, dashArray:'6 6', fill:false, interactive:false}).addTo(map);
      if (!s.pin) map.setView([s.origin.latitude, s.origin.longitude], 13);
    }
    if (s.pin) {
      var at = [s.pin.latitude, s.pin.longitude];
      if (!pin) {
        pin = L.marker(at, {icon:pinIcon, draggable:true}).addTo(map);
        pin.on('dragend', function(){ var p = pin.getLatLng(); send({type:'pick', latitude:p.lat, longitude:p.lng}); });
      } else { pin.setLatLng(at); }
      if (!s.route && !map.getBounds().pad(-0.25).contains(at)) map.panTo(at);
    } else if (pin) { map.removeLayer(pin); pin = null; }

    var key = s.route && s.route.length > 1 ? s.route.length + ':' + s.route[0] + ':' + s.route[s.route.length-1] : null;
    if (key !== routeKey) {
      if (routeUnder) { map.removeLayer(routeUnder); map.removeLayer(routeLine); routeUnder = routeLine = null; }
      routeKey = key;
      if (key) {
        routeUnder = L.polyline(s.route, {color:'#fff', weight:8, opacity:.9, interactive:false}).addTo(map);
        routeLine = L.polyline(s.route, {color:'#1d7fd4', weight:4, opacity:s.within ? .95 : .5, dashArray:s.within ? null : '7 7', interactive:false}).addTo(map);
        map.fitBounds(L.latLngBounds(s.route), {padding:[26,26], maxZoom:16});
      }
    }
  };
  send({type:'ready'});
})();
</script></body></html>`;
}

/**
 * The delivery pin (design of 2026-11-19, "Live Delivery Location Pin"): tap
 * the map or drag the pin, or use the phone's location. The badges and the
 * locate button are drawn by the app over the WebView.
 */
export function DeliveryMap({
  pin,
  origin,
  maxDrivingKm,
  route,
  withinServiceArea,
  resolving,
  locality,
  onPick,
  onLocate,
  locating,
  onTouching,
}: DeliveryMapProps) {
  const web = useRef<MapWebViewRef>(null);
  const [ready, setReady] = useState(false);
  const [failed, setFailed] = useState(false);
  // Built once: a new page would reload the map on every change.
  const [page] = useState(() => mapPage(pin ?? origin ?? START));

  const state = useMemo(
    () => JSON.stringify({ pin, origin, maxKm: maxDrivingKm, route, within: withinServiceArea }),
    [pin, origin, maxDrivingKm, route, withinServiceArea]
  );

  useEffect(() => {
    if (ready) web.current?.injectJavaScript(`window.cncUpdate && window.cncUpdate(${state}); true;`);
  }, [ready, state]);

  const onMessage = (event: WebViewMessageEvent) => {
    try {
      const message = JSON.parse(event.nativeEvent.data) as { type: string; latitude?: number; longitude?: number };

      if (message.type === "ready") setReady(true);
      else if (message.type === "failed") setFailed(true);
      else if (message.type === "pick" && typeof message.latitude === "number" && typeof message.longitude === "number") {
        onPick({ latitude: message.latitude, longitude: message.longitude });
      }
    } catch {
      // Not ours.
    }
  };

  const badge = resolving
    ? { text: "Locating", tone: colors.authHint, fill: colors.white }
    : withinServiceArea
      ? { text: "Live", tone: colors.success, fill: colors.white }
      : { text: "Too far", tone: colors.danger, fill: colors.dangerSurface };

  return (
    <View
      style={styles.frame}
      onTouchStart={() => onTouching?.(true)}
      onTouchEnd={() => onTouching?.(false)}
      onTouchCancel={() => onTouching?.(false)}
      testID="delivery-map"
    >
      <MapWebView
        ref={web}
        source={{ html: page }}
        originWhitelist={["*"]}
        onMessage={onMessage}
        onError={() => setFailed(true)}
        javaScriptEnabled
        scrollEnabled={false}
        overScrollMode="never"
        setBuiltInZoomControls={false}
        style={styles.web}
      />

      {!ready && !failed && (
        <View style={styles.cover} pointerEvents="none">
          <ActivityIndicator color={colors.orange} />
        </View>
      )}

      {failed && (
        <View style={styles.cover}>
          <AppText family="display" size={12.5} color={colors.authMuted} style={styles.center}>
            The map could not load. Use your location or search your address instead.
          </AppText>
        </View>
      )}

      {pin && (
        <View style={[styles.badge, { backgroundColor: badge.fill }]} pointerEvents="none">
          <View style={[styles.badgeDot, { backgroundColor: badge.tone }]} />
          <AppText family="display" weight="semibold" size={11} color={badge.tone}>
            {badge.text}
          </AppText>
        </View>
      )}

      {!pin && ready && (
        <View style={styles.hint} pointerEvents="none">
          <AppText family="display" weight="medium" size={11.5} color={colors.authMuted}>
            Tap the map, search, or use your location
          </AppText>
        </View>
      )}

      {locality ? (
        <View style={styles.locality} pointerEvents="none">
          <IconMapPin size={12} color={colors.orange} strokeWidth={2.4} />
          <AppText family="display" weight="medium" size={11} color={colors.authInk} numberOfLines={1} style={styles.shrink}>
            {locality}
          </AppText>
        </View>
      ) : null}

      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Use my current location"
        disabled={locating}
        onPress={onLocate}
        style={styles.locate}
        hitSlop={6}
        testID="use-my-location"
      >
        {locating ? (
          <ActivityIndicator size="small" color={colors.orange} />
        ) : (
          <IconCurrentLocation size={19} color={colors.orange} strokeWidth={2} />
        )}
      </Pressable>
    </View>
  );
}

const floating = {
  position: "absolute" as const,
  elevation: 3,
  shadowColor: "#000",
  shadowOpacity: 0.12,
  shadowRadius: 4,
  shadowOffset: { width: 0, height: 1 },
};

const styles = StyleSheet.create({
  frame: { height: 230, borderRadius: 14, overflow: "hidden", borderWidth: 1, borderColor: "#ece7e0", backgroundColor: "#eef3ea" },
  web: { flex: 1, backgroundColor: "#eef3ea" },
  cover: { ...StyleSheet.absoluteFill, alignItems: "center", justifyContent: "center", padding: 24, backgroundColor: "#eef3ea" },
  center: { textAlign: "center" },
  shrink: { flexShrink: 1 },
  badge: { ...floating, top: 10, right: 10, flexDirection: "row", alignItems: "center", gap: 5, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999 },
  badgeDot: { width: 6, height: 6, borderRadius: 3 },
  hint: { ...floating, top: 10, left: 10, right: 70, alignSelf: "center", paddingHorizontal: 12, paddingVertical: 6, borderRadius: 999, backgroundColor: "rgba(255,255,255,0.95)" },
  locality: {
    ...floating,
    bottom: 10,
    left: 10,
    maxWidth: "65%",
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 999,
    backgroundColor: colors.white,
  },
  locate: {
    ...floating,
    bottom: 10,
    right: 10,
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
});

export default DeliveryMap;
