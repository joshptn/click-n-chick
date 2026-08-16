import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { IconChevronLeft, IconChevronRight, IconPhoto } from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

import { fetchPosters } from "../../lib/menu";

const MotionDiv = motion.div;

const ROTATE_MS = 6000;

/**
 * The promotional banner strip above the category chips.
 *
 * A placeholder in the honest sense: the slot, its dimensions and its rotation
 * are real and driven by /api/posters, but a poster carries only an image. The
 * PWD/Senior artwork in the prototype shows discount controls drawn INTO the
 * image - none of that behaviour lives here, because discounts belong to the
 * discounts module. When that exists it renders over this, or replaces it.
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
    return <div className="h-[150px] w-full animate-pulse rounded-[20px] bg-[#f0e9df] sm:h-[186px]" />;
  }

  if (posters.length === 0) {
    return (
      <div className="grid h-[150px] w-full place-items-center rounded-[20px] border-2 border-dashed border-[#e6ded4] bg-[#faf7f3] sm:h-[186px]">
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
    <section aria-label="Promotions" className="relative overflow-hidden rounded-[20px] bg-[#f0e9df]">
      <AnimatePresence mode="wait" initial={false}>
        <MotionDiv
          key={poster.id}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.45, ease: "easeOut" }}
          className="h-[150px] w-full sm:h-[186px]"
        >
          <img
            src={poster.image}
            alt={poster.poster_name}
            loading="lazy"
            className="h-full w-full object-cover"
          />
        </MotionDiv>
      </AnimatePresence>

      {/* Scrim: poster artwork is uncontrolled, so the caption needs its own
          contrast rather than trusting the image behind it. */}
      <div className="pointer-events-none absolute inset-0 bg-gradient-to-r from-cocoa-900/75 via-cocoa-900/30 to-transparent" />

      <p className="absolute bottom-4 left-5 m-0 max-w-[60%] font-display text-[16px] font-extrabold leading-tight text-white drop-shadow-sm sm:text-[20px]">
        {poster.poster_name}
      </p>

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
                aria-label={`Show ${item.poster_name}`}
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
