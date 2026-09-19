import { useEffect, useRef } from "react";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

/**
 * Affiche une trace de vol (points lat/lon) sur une carte Leaflet +
 * tuiles OpenStreetMap. Volontairement en Leaflet pur (pas react-leaflet)
 * pour éviter tout souci de compatibilité de peer-dependency avec React 19.
 *
 * points : tableau d'objets avec au moins { latitude, longitude } —
 * c'est exactement la forme renvoyée par /api/sessions/{id}/trace,
 * elle-même basée sur SimBitReportParser::RAW_DATA_COLUMNS.
 */
function FlightMap({ points }) {
  const containerRef = useRef(null);

  useEffect(() => {
    if (!containerRef.current || !points || points.length === 0) return;

    const validPoints = points
      .filter(
        (p) =>
          typeof p.latitude === "number" && typeof p.longitude === "number",
      )
      .map((p) => [p.latitude, p.longitude]);

    if (validPoints.length === 0) return;

    const map = L.map(containerRef.current, {
      scrollWheelZoom: false,
    });

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 18,
    }).addTo(map);

    const polyline = L.polyline(validPoints, {
      color: "#667eea",
      weight: 3,
    }).addTo(map);

    map.fitBounds(polyline.getBounds(), { padding: [20, 20] });

    L.marker(validPoints[0]).addTo(map).bindPopup("Départ");
    L.marker(validPoints[validPoints.length - 1])
      .addTo(map)
      .bindPopup("Arrivée");

    // Nettoyage indispensable : sans ça, StrictMode (qui monte/démonte les
    // effets deux fois en dev) fait planter Leaflet avec "Map container
    // is already initialized" au deuxième montage sur le même noeud DOM.
    return () => {
      map.remove();
    };
  }, [points]);

  if (!points || points.length === 0) {
    return null;
  }

  return <div ref={containerRef} className="flight-map-container" />;
}

export default FlightMap;
