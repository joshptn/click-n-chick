import { useEffect, useState } from "react";
import {
  MapContainer,
  TileLayer,
  Marker,
  Popup,
  useMap,
  useMapEvents,
} from "react-leaflet";
import "leaflet/dist/leaflet.css";
import L from "leaflet";
import { OpenStreetMapProvider } from "leaflet-geosearch";
import "leaflet-routing-machine";
import "leaflet-routing-machine/dist/leaflet-routing-machine.css";


import { ClickHandler, customIcon, handleSearch, HOC_LOCATION, initLocation, SetViewOnLocation } from "../utils/Map";
import RouteToHOC from "./RouteToHOC";



// =============================
// 🔹 Main Map Component
// =============================
export default function UserLocationMap({ editMode, setLocation, location, user }) {
  const [search, setSearch] = useState("");
  const provider = new OpenStreetMapProvider();

  const [userLoc, setUserLoc] = useState({
    lat: 14.5995,
    lng: 120.9842,
    city: "",
    country: "",
    full: "",
  });

  useEffect(() => {
    initLocation(location, setUserLoc, setLocation);
  }, [location, user, setLocation]);

  useEffect(() => {
    if (user?.latitude && user?.longitude) {
      setLocation({
        lat: user.latitude,
        lng: user.longitude,
        city: user.location || "",
        country: "",
        full: user.location || "",
      });
    }
  }, [user, setLocation]);

  // =============================
  // 🔹 UI Render
  // =============================
  return (
    <div className="relative w-full h-full">
      {/* 🔍 Search bar */}
      {editMode && (
        <div className="absolute top-5 left-1/2 -translate-x-1/2 z-[1000] flex items-center bg-white rounded-full px-2 py-1 shadow-lg w-[320px]">
          
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="🔍 Search location..."
            className="flex-1 px-2 py-2 text-sm text-gray-700 border-none rounded-full outline-none"
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                handleSearch(e,search, setUserLoc, setLocation, provider);
              }
            }}
          />

          {search && (
            <button
              type="button"
              onClick={() => setSearch("")}
              className="mr-1 text-lg text-gray-500 bg-transparent"
            >
              ×
            </button>
          )}

          <button
            type="button"
            onClick={(e) => handleSearch(e,search, setUserLoc, setLocation, provider)}
            className="px-4 py-1 text-sm text-white transition bg-blue-600 rounded-full hover:bg-blue-700"
          >
            Go
          </button>
        </div>
      )}

      {/* 🗺️ Map */}
      <MapContainer
        center={[userLoc.lat, userLoc.lng]}
        zoom={13}
        className="w-full h-full rounded-lg"
      >
        <TileLayer
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          attribution='&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors'
        />

        {/* USER Marker */}
        {userLoc.lat && userLoc.lng && (
          <Marker position={[userLoc.lat, userLoc.lng]} icon={customIcon}>
            <Popup>
              📍 <b>{userLoc.city || "Unknown"}</b> <br />
              {userLoc.country} <br />
              <small>{userLoc.full}</small>
            </Popup>
          </Marker>
        )}

        {/* HOC Marker */}
        <Marker position={[HOC_LOCATION.lat, HOC_LOCATION.lng]} icon={customIcon}>
          <Popup>
            🍗 <b>{HOC_LOCATION.name}</b>
          </Popup>
        </Marker>

        {/* Route */}
        {userLoc.lat && userLoc.lng && <RouteToHOC userLoc={userLoc} />}

        <ClickHandler setLocation={setLocation} editMode={editMode} />
        <SetViewOnLocation position={[userLoc.lat, userLoc.lng]} />
      </MapContainer>
    </div>
  );

}
