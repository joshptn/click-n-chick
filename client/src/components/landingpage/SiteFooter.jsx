import React from 'react';
import { IconBrandFacebookFilled, IconClock, IconMail, IconMapPinFilled, IconPhoneFilled } from '@tabler/icons-react';
import { motion } from 'framer-motion';

import LogoIcon from '../../assets/logo-icon.png';
import { Wordmark } from './SiteHeader';

const MotionDiv = motion.div;

const LEGAL_LINKS = [
  ['Privacy Policy', 'xl:tracking-[2.2px]'],
  ['Terms of Service', 'xl:tracking-[3.4px]'],
  ['Cookie Policy', 'xl:tracking-[3.4px]'],
];

const ROW_TEXT = 'm-0 font-display font-bold text-white text-[15px] leading-[24px] tracking-[0.12em] xl:text-[20px] xl:leading-[30px] xl:tracking-[0.16em]';

function IconDisc({ children }) {
  return (
    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-[#f58c2f] text-white xl:h-[51px] xl:w-[51px]" aria-hidden="true">
      {children}
    </span>
  );
}

function Heading({ children, className = '' }) {
  return (
    <div className={className}>
      <h3 className="m-0 font-display text-[26px] font-bold leading-[40px] text-[#f58c2f] xl:text-[34.5px] xl:tracking-[0.85px]">{children}</h3>
      <span className="mt-3 block h-[3px] w-[111px] bg-[#f58c2f] xl:mt-[12.6px]" aria-hidden="true" />
    </div>
  );
}

export default function SiteFooter() {
  return (
    <footer id="contact" data-scroll-section className="bg-[#5e2d1e] pb-[34px] pt-12 text-white xl:pt-[55px]">
      <MotionDiv
        initial={{ opacity: 0, y: 24 }}
        whileInView={{ opacity: 1, y: 0 }}
        viewport={{ once: true, amount: 0.2 }}
        transition={{ duration: 0.6 }}
        className="grid grid-cols-1 gap-12 px-6 lg:grid-cols-[minmax(0,510fr)_minmax(0,472fr)_minmax(0,438fr)] lg:gap-0 lg:pl-5 lg:pr-0"
      >
        {/* Brand */}
        <div>
          <div className="flex items-center gap-4 xl:gap-[20px]">
            <img src={LogoIcon} alt="" className="h-16 w-16 object-contain xl:-ml-[3px] xl:-mt-[5px] xl:h-[93px] xl:w-[93px]" />
            <Wordmark className="text-[32px] xl:-translate-y-1 xl:text-[42.5px]" />
          </div>

          <span className="mt-8 block h-[3px] w-[111px] bg-[#f58c2f] xl:-ml-px xl:mt-[39px]" aria-hidden="true" />

          <p className="m-0 mt-6 max-w-[420px] xl:max-w-none font-display text-[18px] font-normal leading-[32px] tracking-[0.08em] text-white xl:mt-[31.4px] xl:pl-[14.5px] xl:text-[23.5px] xl:leading-[38.5px] xl:tracking-[0.165em]">
            Freshly prepared and
            <br className="hidden xl:block" /> delivered to your doorstep.
            <br className="hidden xl:block" /> Basta BES, da best —
            <br className="hidden xl:block" /> always.
          </p>
        </div>

        {/* Contact */}
        <div className="xl:pt-[21.4px]">
          <Heading>Contact Information</Heading>

          <ul className="m-0 mt-8 list-none p-0 xl:mt-[38px] xl:pl-2">
            <li className="flex items-center gap-[8.5px]">
              <IconDisc>
                <IconMapPinFilled size={22} />
              </IconDisc>
              <p className={ROW_TEXT}>
                Sampaloc, Apalit
                <br />
                Pampanga, Philippines
              </p>
            </li>

            <li className="mt-6 flex items-center gap-[8.5px] xl:mt-[31px]">
              <IconDisc>
                <IconPhoneFilled size={22} />
              </IconDisc>
              <p className={ROW_TEXT}>+63 123 456 789</p>
            </li>

            <li className="mt-6 flex items-center gap-[8.5px] xl:mt-[30px]">
              <IconDisc>
                <IconMail size={22} stroke={1.75} />
              </IconDisc>
              <p className={`${ROW_TEXT} break-all`}>info@houseofchicken.com</p>
            </li>
          </ul>
        </div>

        {/* Follow and hours */}
        <div className="xl:pt-[21.4px]">
          <Heading className="xl:pl-[5px]">Follow US</Heading>

          <a
            href="https://www.facebook.com/"
            target="_blank"
            rel="noreferrer noopener"
            className="mt-8 flex w-fit items-center gap-3 text-white no-underline transition-colors duration-200 hover:text-[#f58c2f] xl:ml-[5px] xl:mt-[42px] xl:gap-[12.5px]"
          >
            <IconDisc>
              <span className="grid h-5 w-5 place-items-center rounded-full bg-white text-[#f58c2f]">
                <IconBrandFacebookFilled size={14} />
              </span>
            </IconDisc>
            <span className="font-display text-[15px] font-bold tracking-[0.12em] xl:text-[19.5px] xl:tracking-[2.3px]">House of Chicken</span>
          </a>

          <span className="mt-6 block h-1 w-full max-w-[345px] bg-[#f58c2f] xl:mt-[23px]" aria-hidden="true" />

          <p className="m-0 mt-6 font-display text-[20px] font-bold uppercase leading-[32px] tracking-[0.18em] text-[#f58c2f] xl:mt-[26px] xl:pl-[5px] xl:text-[28px] xl:tracking-[4.5px]">
            Open Hours
          </p>

          <div className="mt-5 flex items-center gap-3 xl:mt-[25px] xl:gap-[11px]">
            <span className="relative grid h-[52px] w-[52px] shrink-0 place-items-center rounded-full bg-[#f58c2f] text-white xl:h-[60px] xl:w-[60px]" aria-hidden="true">
              <IconClock className="h-[52px] w-[52px] xl:h-[60px] xl:w-[60px]" stroke={2} />
            </span>
            <p className="m-0 font-display text-[16px] font-semibold leading-[28px] tracking-[0.15em] text-white xl:text-[21.5px] xl:leading-[32.5px] xl:tracking-[0.18em]">
              Monday - Sunday
              <br />
              <span className="xl:tracking-[0.235em]">9:00 AM - 9:00 PM</span>
            </p>
          </div>
        </div>
      </MotionDiv>

      <div className="mx-auto mt-12 h-px w-[calc(100%-48px)] max-w-[1185px] bg-white xl:mt-[125px]" aria-hidden="true" />

      <div className="mt-8 flex flex-col items-center gap-4 px-6 md:flex-row md:justify-between xl:mt-[49.5px] xl:pl-[129.5px] xl:pr-[90.5px]">
        <p className="m-0 text-center font-display text-[13px] font-normal tracking-[0.1em] text-white xl:text-[15px] xl:tracking-[2.1px]">
          © 2026 House of Chicken. All Rights Reserved.
        </p>

        <div className="flex flex-wrap justify-center gap-5 xl:gap-[22px]">
          {LEGAL_LINKS.map(([item, tracking]) => (
            <button
              key={item}
              type="button"
              className="cursor-pointer bg-transparent text-white transition-colors duration-200 hover:text-[#f58c2f]"
            >
              <span className={`font-display text-[13px] font-normal tracking-[0.1em] xl:text-[15px] ${tracking}`}>{item}</span>
            </button>
          ))}
        </div>
      </div>
    </footer>
  );
}
