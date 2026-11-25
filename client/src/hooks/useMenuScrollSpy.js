import { useCallback, useEffect, useRef, useState } from "react";

import { sectionAnchor } from "../lib/menuSections";

function prefersReducedMotion() {
  return typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
}

/**
 * Which menu section is being read, for the sticky category bar.
 *
 * The active section is the last one whose top has passed `line` (pixels from
 * the top of the window, just under the header and the bar); at the very
 * bottom of the page it is the last section, which may be too short to reach
 * the line. Picking a tab scrolls to its section and keeps that tab active
 * while the page glides there, until the customer scrolls again themselves.
 */
export function useMenuScrollSpy(keys, line) {
  const [active, setActive] = useState(keys[0] ?? null);
  const picked = useRef(null);
  const signature = keys.join("|");

  useEffect(() => {
    if (keys.length === 0) return undefined;

    let frame = 0;

    const measure = () => {
      frame = 0;

      if (picked.current) return;

      let current = keys[0];

      for (const key of keys) {
        const element = document.getElementById(sectionAnchor(key));

        if (element && element.getBoundingClientRect().top <= line) current = key;
      }

      const bottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;

      setActive(bottom && window.scrollY > 0 ? keys[keys.length - 1] : current);
    };

    const onScroll = () => {
      if (!frame) frame = window.requestAnimationFrame(measure);
    };

    // The customer taking over the scroll ends a tab's glide.
    const release = () => {
      if (!picked.current) return;

      picked.current = null;
      onScroll();
    };

    measure();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);

    for (const type of ["wheel", "touchstart", "keydown", "mousedown"]) {
      window.addEventListener(type, release, { passive: true });
    }

    return () => {
      if (frame) window.cancelAnimationFrame(frame);
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);

      for (const type of ["wheel", "touchstart", "keydown", "mousedown"]) {
        window.removeEventListener(type, release);
      }
    };
    // `keys` is read through its signature, so a new array with the same
    // sections does not restart the listeners.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, line]);

  const pick = useCallback((key) => {
    const element = document.getElementById(sectionAnchor(key));

    if (!element) return;

    // The mousedown or keydown of this same click has already run the release.
    picked.current = key;
    setActive(key);
    element.scrollIntoView({ behavior: prefersReducedMotion() ? "auto" : "smooth", block: "start" });
  }, []);

  return { active: keys.includes(active) ? active : (keys[0] ?? null), pick };
}
