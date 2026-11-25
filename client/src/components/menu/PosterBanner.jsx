import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { IconChevronLeft, IconChevronRight, IconPhoto } from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

import { responsiveImage } from "../../lib/images";
import { fetchPosters } from "../../lib/menu";

const MotionDiv = motion.div;

const ROTATE_MS = 6000;

/**
 * The homepage posters above the category chips (/api/posters).
 *
 * One fixed shape, 3:1, scaling with the screen: posters are cropped to it
 * when the Store Manager adds them, so each shows exactly as cropped on every
 * screen. A poster is only an image: not clickable, no caption.
 */
function PosterBanner() {
  const { data, isLoading } = useQuery({
    queryKey: ["posters"],
    queryFn: fetchPosters,
    staleTime: 5 * 60 * 1000,
  });

  const posters = data?.data ?? [];
  const [index, setIndex] = useState(0);

  // A poster being taken down must not leave the strip pointing past the end.
  useEffect(() => {
    if (index > posters.length - 1) setIndex(0);
  }, [posters.length, index]);

  useEffect(() => {
    if (posters.length < 2) return undefined;

    const timer = window.setInterval(() => {
      setIndex((prev) => (prev + 1) % posters.length);
    }, ROTATE_MS);

    return () => window.clearInterval(timer);
  }, [posters.length]);

  if (isLoading) {
    return <div className="aspect-[3/1] w-full animate-pulse rounded-[20px] bg-[#f0e9df]" />;
  }

  if (posters.length === 0) {
    return (
      <div className="grid aspect-[3/1] w-full place-items-center rounded-[20px] border-2 border-dashed border-[#e6ded4] bg-[#faf7f3]">
        <div className="text-center">
          <IconPhoto size={26} stroke={1.6} aria-hidden="true" className="mx-auto text-[#c9c2b8]" />
          <p className="mt-2 font-display text-[13px] font-semibold text-[#8d8884]">No promotions right now</p>
          <p className="m-0 font-display text-[12px] text-[#a39f9b]">Posters added by the store appear here.</p>
        </div>
      </div>
    );
  }

  const poster = posters[index] ?? posters[0];

  const step = (delta) => setIndex((prev) => (prev + delta + posters.length) % posters.length);

  return (
    <section aria-label="Promotions" className="relative aspect-[3/1] w-full overflow-hidden rounded-[20px] bg-[#f0e9df]">
      <AnimatePresence mode="wait" initial={false}>
        <MotionDiv
          key={poster.id}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.45, ease: "easeOut" }}
          className="absolute inset-0"
        >
          {/* The banner leads the page: the first poster loads at once and
              ahead of the dish photos, at the banner's width (the full column:
              about 1016px beside the docked cart, up to 1216px without it). */}
          <img
            {...responsiveImage(poster.image, {
              widths: [640, 960, 1280, 1920],
              sizes: "(min-width: 1280px) 1016px, 100vw",
              fallback: 1280,
            })}
            alt="Store promotion"
            fetchPriority={index === 0 ? "high" : undefined}
            className="h-full w-full object-cover"
          />
        </MotionDiv>
      </AnimatePresence>

      {posters.length > 1 && (
        <>
          <button
            type="button"
            onClick={() => step(-1)}
            aria-label="Previous promotion"
            className="absolute left-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-cocoa-900 transition-colors hover:bg-white"
          >
            <IconChevronLeft size={17} stroke={2.2} />
          </button>

          <button
            type="button"
            onClick={() => step(1)}
            aria-label="Next promotion"
            className="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-cocoa-900 transition-colors hover:bg-white"
          >
            <IconChevronRight size={17} stroke={2.2} />
          </button>

          <div className="absolute bottom-4 right-4 flex gap-1.5">
            {posters.map((item, dot) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setIndex(dot)}
                aria-label={`Show promotion ${dot + 1}`}
                aria-current={dot === index}
                className={`h-1.5 rounded-full transition-all duration-300 ${dot === index ? "w-5 bg-white" : "w-1.5 bg-white/50 hover:bg-white/80"}`}
              />
            ))}
          </div>
        </>
      )}
    </section>
  );
}

export default PosterBanner;
