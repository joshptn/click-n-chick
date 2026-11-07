import { useLayoutEffect, useRef, useState } from "react";
import { IconPhoto, IconPhotoOff } from "@tabler/icons-react";

import { responsiveImage } from "../../lib/images";

function Photo({ url, alt, widths, sizes, fallback, priority, className, iconSize }) {
  const [status, setStatus] = useState(url ? "loading" : "failed");
  const ref = useRef(null);

  // A photo already in the browser cache is complete before its load event can
  // be heard; show it straight away rather than fading it in again.
  useLayoutEffect(() => {
    const img = ref.current;

    if (img?.complete && img.naturalWidth > 0) setStatus("loaded");
  }, []);

  const image = responsiveImage(url, { widths, sizes, fallback });

  return (
    <>
      {status !== "loaded" && (
        <span aria-hidden="true" className="absolute inset-0 grid place-items-center text-[#c9c2b8]">
          {status === "failed" ? <IconPhotoOff size={iconSize} stroke={1.6} /> : <IconPhoto size={iconSize} stroke={1.6} />}
        </span>
      )}

      {status !== "failed" && (
        <img
          ref={ref}
          {...image}
          alt={alt}
          loading={priority ? "eager" : "lazy"}
          fetchPriority={priority ? "high" : undefined}
          decoding="async"
          onLoad={() => setStatus("loaded")}
          onError={() => setStatus("failed")}
          // A quick fade in; the slower ease is for the card's hover zoom.
          style={{ transition: "opacity 200ms ease-out, transform 500ms ease" }}
          className={`relative h-full w-full object-cover ${status === "loaded" ? "opacity-100" : "opacity-0"} ${className}`}
        />
      )}
    </>
  );
}

/**
 * A dish photo inside an existing, already-sized image box (which must be
 * `relative`): a quiet photo icon while it loads, a fade when it arrives, and a
 * "no photo" icon if it fails or there is none. The box keeps its size
 * throughout, so nothing around it moves. Sized through Cloudinary per `widths`
 * and `sizes`; `priority` is for the first cards on screen.
 */
function DishImage({ url, alt, widths, sizes, fallback, priority = false, className = "", iconSize = 26 }) {
  // Keyed by URL: a new photo starts over from its placeholder.
  return (
    <Photo
      key={url ?? "none"}
      url={url}
      alt={alt}
      widths={widths}
      sizes={sizes}
      fallback={fallback}
      priority={priority}
      className={className}
      iconSize={iconSize}
    />
  );
}

export default DishImage;
