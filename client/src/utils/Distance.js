export function Distance(lat,lng){
        const origin_lat = 14.958753194320153;
        const origin_lng = 120.75846924744896;

        
        const latFrom = origin_lat * Math.PI / 180;
        const lngFrom = origin_lng * Math.PI / 180;
        const latTo = lat * Math.PI / 180;
        const lngTo = lng * Math.PI / 180;

        // Haversine formula
        const earthRadius = 6371; 

        const latDelta = latTo - latFrom;
        const lngDelta = lngTo - lngFrom;

        const a =
            Math.sin(latDelta / 2) ** 2 +
            Math.cos(latFrom) * Math.cos(latTo) *
            Math.sin(lngDelta / 2) ** 2;

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        const distance = earthRadius * c;

        return distance; // km
}