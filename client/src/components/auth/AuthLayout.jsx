import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import authMascot from '../../assets/auth-image.png';
import LogoIcon from '../../assets/logo-icon.png';
import { primeRecaptcha } from '../../lib/recaptcha';

const MotionDiv = motion.div;

function AuthLayout({ eyebrow = 'Welcome to', heading, children, footer }) {
  useEffect(() => {
    primeRecaptcha();
  }, []);

  return (
    <div className="h-dvh w-full overflow-hidden bg-[#fdf5ea] font-display">
      <div className="mx-auto flex h-full w-full max-w-[1440px] flex-col px-6 py-6 sm:px-10 lg:px-14">

        <Link
          to="/"
          aria-label="Click n Chick — back to home"
          className="inline-flex w-fit shrink-0 cursor-pointer items-center gap-3 rounded-xl no-underline transition-opacity duration-200 hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-500"
        >
          <img src={LogoIcon} alt="" draggable={false} className="h-10 w-10 shrink-0 select-none object-contain sm:h-11 sm:w-11" />

          <span className="font-logo text-[25px] font-bold leading-none tracking-[0.06em] text-brand-500 sm:text-[32px]">
            Click <span className="mx-0.5">n</span> Chick
          </span>
        </Link>

        <div className="grid min-h-0 flex-1 grid-cols-1 items-center gap-8 py-5 lg:grid-cols-2 lg:gap-16">

          <MotionDiv
            initial={{ opacity: 0, scale: 0.92 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.7, type: 'spring', bounce: 0.3 }}
            className="hidden min-h-0 items-center justify-center lg:flex"
          >
            <MotionDiv
              animate={{ y: [0, -14, 0] }}
              transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
              className="flex max-h-full w-full max-w-[620px] items-center justify-center"
            >
              <Link
                to="/"
                aria-label="Click n Chick — back to home"
                className="flex max-h-full w-full cursor-pointer items-center justify-center rounded-3xl transition-transform duration-300 hover:scale-[1.02] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-500"
              >
                <img
                  src={authMascot}
                  alt="Mascot chicken giving a thumbs up beside a scooter with a bucket of fried chicken"
                  draggable={false}
                  className="max-h-full w-full select-none object-contain drop-shadow-[0_24px_40px_rgba(65,33,17,0.12)]"
                />
              </Link>
            </MotionDiv>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: 'easeOut' }}
            className="flex min-h-0 w-full justify-center self-stretch lg:justify-start"
          >
            <div className="flex max-h-full w-full max-w-[460px] flex-col overflow-hidden rounded-[26px] bg-white shadow-[0_18px_50px_-20px_rgba(65,33,17,0.22)]">

              <div className="shrink-0 px-7 pt-8 sm:px-11">
                <p className="font-display text-[13px] font-medium text-[#6f6b68]">
                  {eyebrow} <span className="font-bold text-brand-600">Click n Chick</span>
                </p>

                <h1 className="mb-5 mt-1.5 font-display text-[28px] font-extrabold leading-tight tracking-[-0.6px] text-[#1f1d1b] sm:text-[34px]">
                  {heading}
                </h1>
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto px-7 pb-1 sm:px-11">
                {children}
              </div>

              {footer && (
                <div className="shrink-0 px-7 pb-8 pt-5 sm:px-11">
                  {footer}
                </div>
              )}
            </div>
          </MotionDiv>

        </div>
      </div>
    </div>
  );
}

export default AuthLayout;
