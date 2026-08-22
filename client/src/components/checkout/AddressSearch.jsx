import { useEffect, useRef, useState } from "react";
import { IconAlertCircle, IconLoader2, IconMapPin, IconSearch, IconX } from "@tabler/icons-react";

import { searchAddress } from "../../lib/checkout";

/**
 * Type-ahead address search (UC-DEL-002).
 *
 * Debounced at 450ms, which is longer than the menu search deliberately. Every
 * uncached keystroke here costs a request against Nominatim's shared public
 * budget of roughly one per second for the whole application (PRD C-02), so
 * the cost of an eager search is borne by every other customer checking out,
 * not just this one.
 *
 * Three further guards on top of the debounce:
 *   - a three-character floor, matching the server's;
 *   - the previous request is aborted when a new one starts, so a slow
 *     response cannot land after a newer one and overwrite it;
 *   - the service is allowed to be down. A 503 here is not an error state,
 *     it is a nudge toward the map, which needs no geocoder at all.
 */
const DEBOUNCE_MS = 450;
const MIN_QUERY = 3;

function AddressSearch({ onSelect, disabled = false }) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState([]);
  const [isSearching, setIsSearching] = useState(false);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(false);

  const abortRef = useRef(null);
  const boxRef = useRef(null);

  useEffect(() => {
    const trimmed = query.trim();

    if (trimmed.length < MIN_QUERY) {
      setResults([]);
      setError(null);
      setIsSearching(false);
      abortRef.current?.abort();

      return undefined;
    }

    const timer = window.setTimeout(async () => {
      abortRef.current?.abort();

      const controller = new AbortController();
      abortRef.current = controller;

      setIsSearching(true);
      setError(null);

      try {
        const payload = await searchAddress(trimmed, { signal: controller.signal });

        setResults(payload.results ?? []);
        setOpen(true);
      } catch (caught) {
        if (caught.name === "AbortError") return;

        setResults([]);
        setError(
          caught.status === 503
            ? "Address search is unavailable. Tap the map to drop a pin instead."
            : caught.message
        );
        setOpen(true);
      } finally {
        setIsSearching(false);
      }
    }, DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => () => abortRef.current?.abort(), []);

  // Close on an outside click so the list does not sit over the map.
  useEffect(() => {
    if (!open) return undefined;

    const onDocumentClick = (event) => {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpen(false);
    };

    document.addEventListener("mousedown", onDocumentClick);

    return () => document.removeEventListener("mousedown", onDocumentClick);
  }, [open]);

  const choose = (place) => {
    onSelect(place);
    setQuery("");
    setResults([]);
    setOpen(false);
  };

  return (
    <div ref={boxRef} className="relative">
      <div className="flex h-[46px] w-full items-center gap-2.5 rounded-[10px] border border-[#e8e4de] bg-white px-3.5 transition-colors focus-within:border-brand-500 focus-within:shadow-[0_0_0_3px_rgba(255,139,43,0.12)]">
        <IconSearch size={17} stroke={1.9} aria-hidden="true" className="shrink-0 text-[#b8b2aa]" />

        <input
          type="text"
          value={query}
          disabled={disabled}
          onChange={(event) => setQuery(event.target.value)}
          onFocus={() => results.length > 0 && setOpen(true)}
          placeholder="Search a street, barangay or landmark"
          aria-label="Search for your delivery address"
          autoComplete="off"
          className="h-full min-w-0 flex-1 bg-transparent font-display text-[14px] text-ink outline-none placeholder:text-[#b8b2aa] disabled:cursor-not-allowed disabled:opacity-60"
        />

        {isSearching && (
          <IconLoader2 size={16} className="shrink-0 animate-spin text-brand-500" aria-hidden="true" />
        )}

        {!isSearching && query && (
          <button
            type="button"
            onClick={() => setQuery("")}
            aria-label="Clear search"
            className="shrink-0 bg-transparent text-[#b8b2aa] transition-colors hover:text-ink"
          >
            <IconX size={15} stroke={2.4} />
          </button>
        )}
      </div>

      {open && (results.length > 0 || error || (!isSearching && query.trim().length >= MIN_QUERY)) && (
        <div className="absolute inset-x-0 top-[52px] z-[600] overflow-hidden rounded-[12px] border border-[#ece7e0] bg-white shadow-[0_10px_30px_-10px_rgba(0,0,0,0.25)]">
          {error && (
            <p className="m-0 flex items-start gap-2 px-4 py-3 font-display text-[12.5px] leading-snug text-[#8a6206]">
              <IconAlertCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
              {error}
            </p>
          )}

          {!error && results.length === 0 && !isSearching && (
            <p className="m-0 px-4 py-3 font-display text-[12.5px] text-[#8d8884]">
              Nothing found nearby. Try a landmark, or tap the map to drop a pin.
            </p>
          )}

          <ul className="m-0 max-h-[210px] list-none overflow-y-auto p-0">
            {results.map((place) => (
              <li key={`${place.place_id ?? ""}${place.latitude},${place.longitude}`}>
                <button
                  type="button"
                  onClick={() => choose(place)}
                  className="flex w-full items-start gap-2.5 border-b border-[#f5f0e9] bg-transparent px-4 py-2.5 text-left transition-colors last:border-b-0 hover:bg-[#faf7f3]"
                >
                  <IconMapPin
                    size={16}
                    stroke={1.9}
                    aria-hidden="true"
                    className={`mt-0.5 shrink-0 ${place.within_service_area ? "text-brand-500" : "text-[#c9c2b8]"}`}
                  />

                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-display text-[13px] font-semibold text-ink">
                      {place.label}
                    </span>
                    <span className="block truncate font-display text-[11.5px] text-[#8d8884]">
                      {place.full_address}
                    </span>
                  </span>

                  <span
                    className={`shrink-0 whitespace-nowrap font-display text-[11px] font-bold ${
                      place.within_service_area ? "text-[#2f9e44]" : "text-[#e5322d]"
                    }`}
                  >
                    {place.within_service_area ? `${place.distance_km} km` : "Too far"}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

export default AddressSearch;
