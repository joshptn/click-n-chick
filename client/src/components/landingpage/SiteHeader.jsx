import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Burger, Drawer } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { motion } from 'framer-motion';

import LogoIcon from '../../assets/logo-icon.png';
import { NAV_LINKS } from './menuData';

const MotionHeader = motion.header;

export function Wordmark({ className = '' }) {
  return (
    <span className={`font-logo font-semibold leading-none text-[#f58c2f] [word-spacing:0.24em] ${className}`}>
      Click n Chick
    </span>
  );
}

export default function SiteHeader({ active, onNavigate, scrolled }) {
  const navigate = useNavigate();
  const [mobileOpen, { open: openMobile, close: closeMobile }] = useDisclosure(false);

  const go = (id) => {
    closeMobile();
    onNavigate(id);
  };

  return (
    <>
      <MotionHeader
        className={`fixed inset-x-0 top-0 z-50 transition-[padding,background-color,box-shadow] duration-300 ${
          scrolled
            ? 'bg-[#fff7ed]/95 py-3 shadow-[0_2px_20px_rgba(65,33,17,0.08)] backdrop-blur-md'
            : 'bg-transparent py-4 lg:py-9'
        }`}
        initial={{ y: -100 }}
        animate={{ y: 0 }}
        transition={{ duration: 0.5, ease: 'easeOut' }}
      >
        <div className="mx-auto flex max-w-[1199px] items-center justify-between gap-4 px-4 sm:px-6">
          <button
            type="button"
            onClick={() => go('home')}
            aria-label="Click n Chick, back to the top"
            className="flex shrink-0 cursor-pointer items-center gap-3 bg-transparent lg:gap-5"
          >
            <img src={LogoIcon} alt="" className="h-8 w-8 select-none object-contain sm:h-9 sm:w-9 lg:-ml-px lg:h-[49.5px] lg:w-[49.5px] lg:translate-y-[0.5px]" />
            <Wordmark className="text-[22px] sm:text-[26px] md:text-[30px] lg:text-[42.5px]" />
          </button>

          <div className="flex items-center gap-3 md:gap-6 lg:gap-[34px]">
            <nav aria-label="Sections" className="hidden items-center md:flex lg:gap-[18.5px]">
              {NAV_LINKS.map(([id, label]) => {
                const isActive = active === id;

                return (
                  <button
                    key={id}
                    type="button"
                    onClick={() => go(id)}
                    aria-current={isActive ? 'true' : undefined}
                    className={`relative cursor-pointer bg-transparent px-3 transition-colors duration-200 lg:px-[19px] ${
                      isActive ? 'text-[#f58c2f]' : 'text-black hover:text-[#f58c2f]'
                    }`}
                  >
                    {/* On the span: Mantine's global CSS resets type on <button>. */}
                    <span className="block font-display text-[16px] font-normal leading-[32px] lg:text-[22px]">{label}</span>
                    <span
                      aria-hidden="true"
                      className={`absolute inset-x-0 top-[calc(100%+8px)] h-1 bg-[#f58c2f] transition-opacity duration-200 lg:top-[calc(100%+14.5px)] ${
                        isActive ? 'opacity-100' : 'opacity-0'
                      }`}
                    />
                  </button>
                );
              })}
            </nav>

            <button
              type="button"
              onClick={() => navigate('/home')}
              className="h-10 shrink-0 cursor-pointer rounded-[8px] bg-[#f58c2f] px-3.5 text-[#fdfdfd] shadow-[0_4px_4px_rgba(0,0,0,0.12)] transition-colors duration-200 hover:bg-[#e67d20] md:h-12 md:w-[140px] lg:h-[67px] lg:w-[188px]"
            >
              <span className="inline-block whitespace-nowrap font-display text-[14px] font-semibold md:text-[16px] lg:translate-y-px lg:text-[20px]">ORDER NOW</span>
            </button>

            {/* Mantine's sm is 48em, the same 768px as Tailwind's md that shows the nav. */}
            <Burger opened={mobileOpen} onClick={openMobile} size="sm" color="#412111" hiddenFrom="sm" aria-label="Open navigation" />
          </div>
        </div>
      </MotionHeader>

      <Drawer
        opened={mobileOpen}
        onClose={closeMobile}
        position="right"
        size="72%"
        padding="lg"
        hiddenFrom="sm"
        title={<Wordmark className="text-[24px]" />}
        classNames={{ content: 'bg-[#fff7ed]', header: 'bg-[#fff7ed]' }}
      >
        <nav aria-label="Sections" className="mt-4 flex flex-col gap-5">
          {NAV_LINKS.map(([id, label]) => (
            <button
              key={id}
              type="button"
              onClick={() => go(id)}
              className={`w-full cursor-pointer border-b border-[#ffe8d1] bg-transparent pb-3 text-left ${
                active === id ? 'text-[#f58c2f]' : 'text-black'
              }`}
            >
              <span className="font-display text-[16px] font-semibold">{label}</span>
            </button>
          ))}

          <button
            type="button"
            onClick={() => {
              closeMobile();
              navigate('/home');
            }}
            className="mt-2 h-[46px] w-full cursor-pointer rounded-[8px] bg-[#f58c2f] text-[#fdfdfd]"
          >
            <span className="font-display text-[15px] font-semibold">ORDER NOW</span>
          </button>
        </nav>
      </Drawer>
    </>
  );
}
