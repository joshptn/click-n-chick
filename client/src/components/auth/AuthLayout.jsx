import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';

import authMascot from '../../assets/auth-image.png';
import LogoIcon from '../../assets/logo-icon.png';

const MotionDiv = motion.div;

function AuthLayout({ eyebrow = 'Welcome to', heading, children, footer }) {
  return (
    <div className="min-h-screen w-full bg-[#fdf5ea] font-display">
      <div className="mx-auto flex min-h-screen w-full max-w-[1440px] flex-col px-6 py-7 sm:px-10 lg:px-14">

        <Link to="/" className="inline-flex w-fit items-center gap-3 no-underline">
          <img src={LogoIcon} alt="" className="h-11 w-11 shrink-0 object-contain" />

          <span className="font-logo text-[27px] font-bold leading-none tracking-[0.06em] text-brand-500 sm:text-[32px]">
            Click <span className="mx-0.5">n</span> Chick
          </span>
        </Link>

        <div className="grid flex-1 grid-cols-1 items-center gap-10 py-10 lg:grid-cols-2 lg:gap-16">

          <MotionDiv
            initial={{ opacity: 0, scale: 0.92 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.7, type: 'spring', bounce: 0.3 }}
            className="hidden justify-center lg:flex"
          >
            <MotionDiv
              animate={{ y: [0, -14, 0] }}
              transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
              className="w-full max-w-[620px]"
            >
              <img
                src={authMascot}
                alt="Mascot chicken giving a thumbs up beside a scooter with a bucket of fried chicken"
                className="pointer-events-none w-full select-none object-contain drop-shadow-[0_24px_40px_rgba(65,33,17,0.12)]"
              />
            </MotionDiv>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: 'easeOut' }}
            className="flex w-full justify-center lg:justify-start"
          >
            <div className="w-full max-w-[460px] rounded-[26px] bg-white px-7 py-10 shadow-[0_18px_50px_-20px_rgba(65,33,17,0.22)] sm:px-11">

              <p className="font-display text-[13px] font-medium text-[#6f6b68]">
                {eyebrow} <span className="font-bold text-brand-600">Click n Chick</span>
              </p>

              <h1 className="mb-7 mt-1.5 font-display text-[30px] font-extrabold leading-tight tracking-[-0.6px] text-[#1f1d1b] sm:text-[34px]">
                {heading}
              </h1>

              {children}

              {footer && <div className="mt-6">{footer}</div>}
            </div>
          </MotionDiv>

        </div>
      </div>
    </div>
  );
}

export default AuthLayout;
