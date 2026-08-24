import { useEffect, useMemo, useRef } from "react";
import { Circle, MapContainer, Marker, Polyline, TileLayer, useMap, useMapEvents } from "react-leaflet";
import L from "leaflet";
import { IconCurrentLocation, IconMinus, IconPlus } from "@tabler/icons-react";

import "leaflet/dist/leaflet.css";

const ROUTE_COLOR = "#1d7fd4";

const pinIcon = L.divIcon({
  className: "cnc-pin",
  html: `
    <span class="cnc-pin__shape">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="white" stroke-width="2.4"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" fill="none" />
        <circle cx="12" cy="10" r="2.6" />
      </svg>
    </span>`,
  iconSize: [34, 34],
  iconAnchor: [17, 17],
});

const storeIcon = L.divIcon({
  className: "cnc-pin",
  html: `
    <span class="cnc-pin__shape cnc-pin__shape--store">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="white" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 10.5 12 4l9 6.5" />
        <path d="M5 10v9h14v-9" />
      </svg>
    </span>`,
  iconSize: [30, 30],
  iconAnchor: [15, 15],
});

function ClickToPin({ onPick, disabled }) {
  useMapEvents({
    click(event) {
      if (disabled) return;
      onPick({ latitude: event.latlng.lat, longitude: event.latlng.lng });
    },
  });

  return null;
}

function FollowSelection({ position, route }) {
  const map = useMap();
  const lastRouteKey = useRef(null);

  useEffect(() => {
    if (route && route.length >= 2) {
      const key = `${route.length}:${route[0]}:${route[route.length - 1]}`;

      if (key === lastRouteKey.current) return;
      lastRouteKey.current = key;

      map.fitBounds(L.latLngBounds(route), { padding: [26, 26], animate: true, maxZoom: 16 });

      return;
    }

    lastRouteKey.current = null;

    if (!position) return;

    const target = L.latLng(position[0], position[1]);

    if (!map.getBounds().pad(-0.25).contains(target)) {
      map.panTo(target, { animate: true, duration: 0.4 });
    }
  }, [map, position, route]);

  return null;
}

function ZoomControl({ disabled }) {
  const map = useMap();

  return (
    <div className="absolute left-3 top-3 z-[500] flex flex-col overflow-hidden rounded-[9px] border border-[#ece7e0] bg-white shadow-[0_2px_10px_-2px_rgba(0,0,0,0.18)]">
      <button
        type="button"
        onClick={() => map.zoomIn()}
        disabled={disabled}
        aria-label="Zoom in"
        className="grid h-8 w-8 place-items-center border-b border-[#f0e9df] bg-transparent text-ink transition-colors hover:bg-[#faf7f3] disabled:cursor-not-allowed disabled:text-[#c9c2b8]"
      >
        <IconPlus size={15} stroke={2.6} />
      </button>

      <button
        type="button"
        onClick={() => map.zoomOut()}
        disabled={disabled}
        aria-label="Zoom out"
        className="grid h-8 w-8 place-items-center bg-transparent text-ink transition-colors hover:bg-[#faf7f3] disabled:cursor-not-allowed disabled:text-[#c9c2b8]"
      >
        <IconMinus size={15} stroke={2.6} />
      </button>
    </div>
  );
}

function InvalidateOnMount() {
  const map = useMap();

  useEffect(() => {
    const timer = window.setTimeout(() => map.invalidateSize(), 120);

    return () => window.clearTimeout(timer);
  }, [map]);

  return null;
}

function RecentreControl({ onLocate, isLocating, disabled }) {
  return (
    <button
      type="button"
      onClick={onLocate}
      disabled={isLocating || disabled}
      title="Use my current location"
      aria-label="Use my current location"
      className="absolute bottom-3 right-3 z-[500] grid h-9 w-9 place-items-center rounded-full border border-[#ece7e0] bg-white text-brand-600 shadow-[0_2px_10px_-2px_rgba(0,0,0,0.18)] transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:text-[#c9c2b8]"
    >
      <IconCurrentLocation size={17} stroke={2} className={isLocating ? "animate-spin" : ""} />
    </button>
  );
}

function DeliveryMap({
  selected,
  origin,
  maxDrivingKm,
  withinServiceArea = true,
  route = null,
  localityLabel,
  onPick,
  onLocate,
  isLocating = false,
  isResolving = false,
  disabled = false,
  height = 260,
}) {
  const mapRef = useRef(null);

  const centre = useMemo(() => {
    if (selected?.latitude != null) return [selected.latitude, selected.longitude];
    if (origin?.latitude != null) return [origin.latitude, origin.longitude];

    return [14.9587, 120.7584];
  }, [selected, origin]);

  const position = selected?.latitude != null ? [selected.latitude, selected.longitude] : null;
  const originPosition = origin?.latitude != null ? [origin.latitude, origin.longitude] : null;

  const routeLine = useMemo(() => {
    if (!Array.isArray(route) || route.length < 2) return null;

    const points = route.filter(
      (point) => Array.isArray(point) && Number.isFinite(point[0]) && Number.isFinite(point[1])
    );

    return points.length >= 2 ? points : null;
  }, [route]);

  return (
    <div
      className="relative overflow-hidden rounded-[12px] border border-[#ece7e0]"
      style={{ height }}
    >
      <MapContainer
        ref={mapRef}
        center={centre}
        zoom={15}
        scrollWheelZoom={true}
        zoomControl={true}
        attributionControl
        className="h-full w-full"
        style={{ background: "#eef3ea" }}
      >
        <TileLayer
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
          maxZoom={19}
        />

        <InvalidateOnMount />
        <FollowSelection position={position} route={routeLine} />
        <ClickToPin onPick={onPick} disabled={disabled} />
        <ZoomControl disabled={disabled} />

        {routeLine && (
          <>
            <Polyline
              positions={routeLine}
              pathOptions={{ color: "#ffffff", weight: 8, opacity: 0.9, lineCap: "round", lineJoin: "round" }}
              interactive={false}
            />
            <Polyline
              positions={routeLine}
              pathOptions={{
                color: ROUTE_COLOR,
                weight: 4,
                opacity: withinServiceArea ? 0.95 : 0.5,
                lineCap: "round",
                lineJoin: "round",
                dashArray: withinServiceArea ? undefined : "7 7",
              }}
              interactive={false}
            />
          </>
        )}

        {originPosition && <Marker position={originPosition} icon={storeIcon} interactive={false} />}

        {originPosition && maxDrivingKm > 0 && (
          <Circle
            center={originPosition}
            radius={maxDrivingKm * 1000}
            pathOptions={{
              color: "#ff8b2b",
              weight: 1.2,
              opacity: 0.45,
              dashArray: "6 6",
              fill: false,
            }}
            interactive={false}
          />
        )}

        {position && (
          <Marker
            position={position}
            icon={pinIcon}
            draggable={!disabled}
            eventHandlers={{
              dragend: (event) => {
                const { lat, lng } = event.target.getLatLng();
                onPick({ latitude: lat, longitude: lng });
              },
            }}
          />
        )}
      </MapContainer>

      <span
        className={`pointer-events-none absolute right-3 top-3 z-[500] inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-display text-[11px] font-bold shadow-[0_1px_6px_-1px_rgba(0,0,0,0.15)] ${
          isResolving
            ? "bg-white text-[#8d8884]"
            : withinServiceArea
              ? "bg-white text-[#2f9e44]"
              : "bg-[#fff1f1] text-[#e5322d]"
        }`}
      >
        <span
          className={`h-1.5 w-1.5 rounded-full ${
            isResolving ? "animate-pulse bg-[#c9c2b8]" : withinServiceArea ? "bg-[#2f9e44]" : "bg-[#e5322d]"
          }`}
        />
        {isResolving ? "Locating" : withinServiceArea ? "Live" : "Too far"}
      </span>

      {localityLabel && (
        <span className="pointer-events-none absolute bottom-3 left-3 z-[500] inline-flex max-w-[62%] items-center gap-1 truncate rounded-full bg-white/95 px-2.5 py-1 font-display text-[11px] font-semibold text-ink shadow-[0_1px_6px_-1px_rgba(0,0,0,0.15)]">
          📍 {localityLabel}
        </span>
      )}

      <RecentreControl onLocate={onLocate} isLocating={isLocating} disabled={disabled} />

      {!position && (
        <div className="pointer-events-none absolute  inset-x-0 top-7 z-[400] -translate-y-1/2 px-6 text-center">
          <span className="inline-block rounded-full bg-white/95 px-3.5 py-1.5 font-display text-[11.5px] font-semibold text-[#6f6b68] shadow-[0_1px_8px_-2px_rgba(0,0,0,0.2)]">
            Search, tap the map, or use your location
          </span>
        </div>
      )}
    </div>
  );
}

export default DeliveryMap;
