import React, { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';

import { useQuery } from '@tanstack/react-query';

import { cloudinaryUrl } from '../../lib/images';
import { fetchFoods, formatPeso } from '../../lib/menu';

const MotionDiv = motion.div;

const VISIBLE_COUNT = 3;
const ROTATE_INTERVAL_MS = 3000;

const CARD_WIDTH = 640;

function MenuCard({ item, onSelect }) {
  return (
    <button type="button" className="group block w-full cursor-pointer bg-transparent text-left" onClick={onSelect}>
      <div className="relative aspect-[368/296] overflow-hidden rounded-[16px]">
        <img
          src={cloudinaryUrl(item.thumbnail, CARD_WIDTH)}
          alt={item.food_name}
          loading="lazy"
          className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-110"
        />

        {item.is_best_seller && (
          <span className="absolute bottom-[15px] left-[18px] z-10 flex h-5 items-center rounded-[4px] bg-[#f58c2f] px-[15px] font-display text-[10px] font-bold uppercase leading-none text-white">
            Best Seller
          </span>
        )}
      </div>

      <div className="mt-[15px] flex items-start justify-between gap-4">
        <div className="ml-[17px] flex min-w-0 items-stretch gap-2">
          <span className="w-[2px] shrink-0 bg-[#f58c2f]" aria-hidden="true" />

          <div className="min-w-0 pb-[6.5px] pl-[0.5px] pt-[8.5px]">
            <p className="m-0 font-display text-[20px] font-bold uppercase leading-[22px] text-white">{item.food_name}</p>
            <p className="m-0 line-clamp-2 font-display text-[15px] font-semibold leading-[18px] text-[#f58c2f]">
              {item.category?.name ?? item.description}
            </p>
          </div>
        </div>

        <p className="m-0 mr-[13px] shrink-0 pt-[13.3px] font-display text-[18px] font-bold leading-[24px] text-[#ffb900]">
          {formatPeso(item.price)}
        </p>
      </div>
    </button>
  );
}

export default function MenuSection() {
  const navigate = useNavigate();
  const reduceMotion = useReducedMotion();

  const [offset, setOffset] = useState(0);
  const [paused, setPaused] = useState(false);

  const { data } = useQuery({
    queryKey: ['foods', 'landing'],
    queryFn: () => fetchFoods({ bestSeller: true }),
    staleTime: 5 * 60 * 1000,
  });

  const items = useMemo(() => data?.data ?? [], [data]);

  const sectionRef = useRef(null);
  const [onScreen, setOnScreen] = useState(false);

  useEffect(() => {
    const section = sectionRef.current;

    if (!section || onScreen) return undefined;

    if (typeof IntersectionObserver === 'undefined') {
      setOnScreen(true);
      return undefined;
    }

    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) setOnScreen(true);
    });

    observer.observe(section);

    return () => observer.disconnect();
  }, [onScreen]);

  useEffect(() => {
    if (!onScreen) return;

    items.forEach((item) => {
      if (!item.thumbnail) return;
      const preload = new window.Image();
      preload.src = cloudinaryUrl(item.thumbnail, CARD_WIDTH);
    });
  }, [items, onScreen]);

  // A shorter catalogue than the rail is wide must not repeat a card.
  const visibleCount = Math.min(VISIBLE_COUNT, items.length);

  useEffect(() => {
    if (paused || reduceMotion || items.length <= visibleCount) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      setOffset((prev) => (prev + 1) % items.length);
    }, ROTATE_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [paused, reduceMotion, items.length, visibleCount]);

  const visibleItems = useMemo(
    () => Array.from({ length: visibleCount }, (_, slot) => items[(offset + slot) % items.length]),
    [items, offset, visibleCount]
  );

  return (
    <section ref={sectionRef} id="menu" data-scroll-section className="bg-[#4c1607] pb-10 pt-16 text-white lg:pb-[29px] lg:pt-[67.5px]">
      <div className="mx-auto max-w-[1328px] px-6">
        <div className="flex flex-col items-start justify-between gap-6 md:flex-row">
          <MotionDiv
            initial={{ opacity: 0, x: -30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6 }}
          >
            <h2 className="m-0 w-fit font-display text-[38px] font-bold leading-[1.1] text-[#f0f0f0] sm:text-[50px] lg:ml-px lg:text-center lg:text-[72px] lg:leading-[79px]">
              Our <span className="text-[#f58c2f]">BEST</span> Delivered
              <br />
              Categories
            </h2>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, x: 30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6, delay: 0.2 }}
            className="shrink-0 md:mt-[18.5px] lg:mr-[2px]"
          >
            <button
              type="button"
              onClick={() => navigate('/home')}
              className="h-[50px] w-[196px] cursor-pointer rounded-full border border-[#e2e8f0] bg-[#460809] text-white transition-colors duration-200 hover:bg-[#5a1410]"
            >
              <span className="inline-block font-display text-[14.5px] font-semibold uppercase tracking-[0.6px] lg:translate-x-[5.5px]">Explore Full Menu</span>
            </button>
          </MotionDiv>
        </div>

        {items.length === 0 ? (
          <p className="m-0 py-10 text-center font-display text-[14px] text-white/60">
            Our menu is being prepared. Check back shortly.
          </p>
        ) : (
          <div
            className="mx-auto mt-12 max-w-[1216px] lg:mt-[95.5px]"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocusCapture={() => setPaused(true)}
            onBlurCapture={() => setPaused(false)}
          >
            <div className="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-3 lg:gap-[56px]">
              {visibleItems.map((item, slot) => (
                <MotionDiv
                  key={slot}
                  initial={{ opacity: 0, y: 40 }}
                  whileInView={{ opacity: 1, y: 0 }}
                  viewport={{ once: true, amount: 0.2 }}
                  transition={{ duration: 0.5, delay: slot * 0.15 }}
                >
                  <AnimatePresence mode="wait" initial={false}>
                    <MotionDiv
                      key={item.id}
                      initial={{ opacity: 0, y: 18 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: -18 }}
                      transition={{ duration: 0.35, delay: slot * 0.07, ease: 'easeOut' }}
                    >
                      <MenuCard item={item} onSelect={() => navigate('/home')} />
                    </MotionDiv>
                  </AnimatePresence>
                </MotionDiv>
              ))}
            </div>

            <div className="mt-[29px] flex h-2 justify-center gap-2.5">
              {items.map((item, index) => (
                <button
                  key={item.id}
                  type="button"
                  aria-label={`Show ${item.food_name}`}
                  aria-current={index === offset}
                  onClick={() => setOffset(index)}
                  className={`h-2 cursor-pointer rounded-full transition-all duration-300 ${index === offset ? 'w-7 bg-[#f58c2f]' : 'w-2 bg-white/25 hover:bg-white/50'}`}
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
