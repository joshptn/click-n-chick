import L from "leaflet";
import { useMap } from "react-leaflet";
import "leaflet-routing-machine/dist/leaflet-routing-machine.css";
import "leaflet-routing-machine";
import { useEffect } from "react";
import { customIcon, HOC_LOCATION } from "../utils/Map";
import markerIcon from "../assets/custom_marker.svg";

function RouteToHOC({ userLoc }) {
  const map = useMap();

  useEffect(() => {
    if (!userLoc?.lat || !userLoc?.lng) return;

    const routingControl = L.Routing.control({
      waypoints: [
        L.latLng(userLoc.lat, userLoc.lng),
        L.latLng(HOC_LOCATION.lat, HOC_LOCATION.lng),
      ],
      lineOptions: {
        addWaypoints: false,
        styles: [{ color: "#007bff", weight: 4 }],
      },
      draggableWaypoints: false,
      fitSelectedRoutes: true,
      show: false, 
      createMarker: function (i, wp, nWps) {
        
        let icon = customIcon;
        if (i === 1) {
          
          icon = L.icon({
            iconUrl: markerIcon, 
            iconSize: [40, 40],
            iconAnchor: [20, 40],
          });
        }
        return L.marker(wp.latLng, { icon });
      },
    }).addTo(map);

    return () => map.removeControl(routingControl);
  }, [userLoc, map]);

  return null;
}

export default RouteToHOC