import { useEffect } from "react";
import { useMap, useMapEvents } from "react-leaflet";
import markerIcon from "../assets/custom_marker.svg";

// =============================
// 🔹 HOC Destination
// =============================
export const HOC_LOCATION = {
  lat: 14.958753194320153,
  lng: 120.75846924744896,
  name: "HOC - House of Chicken",
};

// =============================
// 🔹 Custom Leaflet Marker Icon
// =============================
export const customIcon = new L.Icon({
  iconUrl: markerIcon,
  iconSize: [40, 40], 
  iconAnchor: [20, 40], 
  popupAnchor: [0, -38], 
  className: "custom-marker",
});

export async function ReverseGeolocation(lat, lng){
  const res = await fetch(
        `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`
      );
      const data = await res.json();
      const newLoc = {
        lat,
        lng,
        city:
          data.address.city ||
          data.address.town ||
          data.address.village ||
          "",
        country: data.address.country || "",
        full: data.display_name || "",
      };
    return newLoc
}

//this will initialize the location para i set ung current user location sa maps
export const initLocation = async (location, setUserLoc, setLocation) => {
      if (location?.lat && location?.lng) {
        setUserLoc(location);
        return;
      }

      if (user?.latitude && user?.longitude) {
        const newLoc = {
          lat: user.latitude,
          lng: user.longitude,
          city: user.location || "",
          country: "",
          full: user.location || "",
        };
        setUserLoc(newLoc);
        setLocation(newLoc);
        return;
      }

      if ("geolocation" in navigator) {
        navigator.geolocation.getCurrentPosition(
          async (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            try {
              var newLoc = ReverseGeolocation(lat, lng) 
              setUserLoc(newLoc);
              setLocation(newLoc);
            } catch {
              setUserLoc({ lat, lng, city: "", country: "", full: "" });
              setLocation({ lat, lng, city: "", country: "", full: "" });
            }
          },
          (err) => console.error("Geolocation error:", err)
        );
      }
    };

// =============================
// 🔹 Helper component: Update map center when location changes
// =============================
export function SetViewOnLocation({ position }) {
  const map = useMap();
  useEffect(() => {
    if (position?.lat && position?.lng) {
      map.flyTo(position, 15, { duration: 1.2 });
    }
  }, [map, position]);
  return null;
}


// =============================
// 🔹 Handle map clicks (only in edit mode)::Component
// =============================
export function ClickHandler({ setLocation, editMode }) {
  useMapEvents({
    click: async (e) => {
      if (!editMode) return;
      const { lat, lng } = e.latlng;

      try {
        const res = await fetch(
          `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`
        );
        const data = await res.json();

        setLocation({
          lat,
          lng,
          city:
            data.address.city ||
            data.address.town ||
            data.address.village ||
            "",
          country: data.address.country || "",
          full: data.display_name || "",
        });
      } catch {
        setLocation({ lat, lng, city: "", country: "", full: "" });
      }
    },
  });
  return null;
}


  // =============================
  // 🔸 Handle Search Input
  // =============================
  export const handleSearch = async (e, search, setUserLoc, setLocation, provider) => {
    e.preventDefault();
    if (!search.trim()) return;

    const results = await provider.search({ query: search });
    if (results.length > 0) {
      const { x: lng, y: lat, label } = results[0];
      const newLoc = {
        lat,
        lng,
        city: label.split(",")[0] || "",
        country: label.split(",").pop() || "",
        full: label,
      };

      setUserLoc(newLoc);
      setLocation(newLoc);
    }
  };



