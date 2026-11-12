import React from 'react';
import { IconStarFilled } from '@tabler/icons-react';
import { motion } from 'framer-motion';

import chickenHero from '../../assets/chicken-hero.png';

const MotionDiv = motion.div;
const MotionH1 = motion.h1;

const ART = { width: '121.37%', left: '-13.10%', top: '-9.93%' };

export default function HeroSection({ onExplore }) {
  return (
    <section id="home" data-scroll-section className="relative w-full overflow-hidden bg-[#fff7ed] pb-16 pt-24 lg:pb-[94px] lg:pt-[165.5px]">
      <div className="mx-auto grid max-w-[1199px] grid-cols-1 gap-8 px-6 lg:grid-cols-[329px_minmax(0,1fr)] lg:gap-0">
        {/* Centred below lg, where the column is the full width; the design's left alignment from lg up. */}
        <div className="relative z-10 order-2 text-center lg:order-1 lg:pl-[5.5px] lg:pt-[65.5px] lg:text-left">
          <MotionH1
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, ease: 'easeOut' }}
            className="m-0 whitespace-nowrap font-display text-[46px] font-bold leading-[1.2] sm:text-[58px] lg:text-[70px] lg:leading-[88px]"
          >
            <span className="block text-[#ff7e00] lg:pl-[5px] lg:tracking-[-1.2px]">Basta BES</span>
            <span className="block text-[#2f2f2f] lg:tracking-[1.2px]">da BEST!</span>
          </MotionH1>

          <MotionDiv
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.18, duration: 0.6, ease: 'easeOut' }}
          >
            <p className="m-0 mx-auto mt-6 max-w-[340px] lg:mx-0 lg:w-[345px] lg:max-w-none font-display text-[17px] font-normal leading-[28px] text-[#666666] lg:mt-[38.5px] lg:text-[20px] lg:leading-[32px]">
              Freshly prepared and delivered to your doorstep. Why settle for less when you can have the BES?
            </p>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, x: -16 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ delay: 0.34, duration: 0.5, ease: 'easeOut' }}
            className="mt-10 lg:mt-[64.5px]"
          >
            <div className="flex justify-center gap-[2px] lg:justify-start" role="img" aria-label="Rated 4.9 out of 5">
              {[0, 1, 2, 3, 4].map((star) => (
                <IconStarFilled key={star} size={28} className="text-[#febb41]" aria-hidden="true" />
              ))}
            </div>

            <p className="m-0 mt-[11px] font-display text-[14px] font-medium leading-[20px] text-[#2f2f2f]">4.9 star rating</p>
            <p className="m-0 mt-[2px] font-display text-[12px] font-light leading-[17px] text-[#909090]">based on 1151 reviews</p>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.48, duration: 0.5, ease: 'easeOut' }}
          >
            <button
              type="button"
              onClick={onExplore}
              className="mt-10 h-[58px] w-[160px] cursor-pointer rounded-[8px] bg-[#f58c2f] text-[#fdfdfd] shadow-[0_4px_4px_rgba(0,0,0,0.12)] transition-colors duration-200 hover:bg-[#e67d20] lg:mt-[65px] lg:h-[70px] lg:w-[188px]"
            >
              <span className="inline-block font-display text-[18px] font-semibold lg:translate-x-[5.75px] lg:translate-y-[2.5px] lg:text-[20px]">Explore</span>
            </button>
          </MotionDiv>
        </div>

        <div className="order-1 flex justify-center lg:order-2 lg:block">
          <MotionDiv
            className="w-full max-w-[440px] lg:max-w-[781.5px]"
            initial={{ opacity: 0, scale: 0.86, rotate: -4 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            transition={{ duration: 0.85, type: 'spring', bounce: 0.35 }}
          >
            <MotionDiv animate={{ y: [0, -14, 0] }} transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}>
              <div className="relative aspect-[781.5/764.5] w-full">
                <img
                  src={chickenHero}
                  // The page's largest picture: fetched ahead of everything below it.
                  fetchPriority="high"
                  alt="Mascot chicken riding a scooter with a bucket of fried chicken"
                  style={ART}
                  className="pointer-events-none absolute h-auto max-w-none select-none"
                />
              </div>
            </MotionDiv>
          </MotionDiv>
        </div>
      </div>
    </section>
  );
}
