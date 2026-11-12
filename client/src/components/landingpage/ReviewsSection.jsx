import React, { useState } from 'react';
import { IconArrowLeft, IconArrowRight, IconQuote, IconStarFilled } from '@tabler/icons-react';
import { AnimatePresence, motion } from 'framer-motion';

const MotionDiv = motion.div;

const REVIEWS = [
  {
    id: 1,
    text: 'The BES in its literal sense. Out of all the gravy that I have tasted, this one is by far the most magnificent gravy that I ever tasted! 10/10 Chicken too, to the point BES:HOC set my standard taste for a fried chicken.',
    author: 'Customer',
    avatar: 'https://images.unsplash.com/photo-1599566150163-29194dcaad36?q=80&w=150&auto=format&fit=crop',
  },
  {
    id: 2,
    text: 'Absolutely incredible service and the crunch on the chicken is unmatched. Delivery was quicker than promised and everything arrived hot. Will definitely be ordering from here for all our family gatherings.',
    author: 'Loyal Diner',
    avatar: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?q=80&w=150&auto=format&fit=crop',
  },
];

export default function ReviewsSection() {
  const [index, setIndex] = useState(0);
  const [direction, setDirection] = useState(1);

  const paginate = (step) => {
    setDirection(step);
    setIndex((prev) => (prev + step + REVIEWS.length) % REVIEWS.length);
  };

  const review = REVIEWS[index];

  return (
    <section id="reviews" data-scroll-section className="overflow-hidden bg-[#fff7ed] pb-16 pt-16 lg:pb-[68px] lg:pt-[84px]">
      <div className="mx-auto grid max-w-[1328px] grid-cols-1 gap-14 px-6 lg:grid-cols-[533px_minmax(0,1fr)] lg:gap-[58.5px]">
        <MotionDiv
          initial={{ opacity: 0, x: -30 }}
          whileInView={{ opacity: 1, x: 0 }}
          viewport={{ once: true, amount: 0.3 }}
          transition={{ duration: 0.6 }}
          className="min-w-0"
        >
          <div className="flex items-start gap-[15px]">
            <p className="m-0 mt-[5.7px] font-display text-[16.5px] font-bold leading-[24px] text-[#f58c2f]">Testimonials</p>
            <IconQuote size={70} stroke={0.95} className="-mb-[23px] -ml-[14px] -mt-[15px] text-[#f58c2f]" aria-hidden="true" />
          </div>

          <h2 className="m-0 font-['Roboto',sans-serif] text-[40px] font-bold leading-[1.15] text-[#333333] lg:text-[48px] lg:leading-[56px]">
            Customer
            <br />
            Review
          </h2>

          <div className="relative mt-6 min-h-[96px] lg:mt-[35px] lg:min-h-[72px]">
            <AnimatePresence mode="wait" initial={false} custom={direction}>
              <MotionDiv
                key={review.id}
                custom={direction}
                initial={{ opacity: 0, x: direction * 24 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: direction * -24 }}
                transition={{ duration: 0.32, ease: 'easeOut' }}
              >
                <p className="m-0 max-w-[530px] font-display text-[13px] font-normal leading-[24px] text-[#828282]">{review.text}</p>
              </MotionDiv>
            </AnimatePresence>
          </div>

          <div className="mt-10 flex items-center justify-between lg:mt-[70.5px]">
            <div className="flex items-center gap-[16.5px]">
              <img src={review.avatar} alt="" className="h-[72px] w-[72px] rounded-full object-cover" />
              <p className="m-0 font-display text-[16px] font-normal text-[#828282]">{review.author}</p>
            </div>

            <div className="-mr-[6px] flex items-center gap-[2px]">
              <button
                type="button"
                onClick={() => paginate(-1)}
                aria-label="Previous review"
                className="cursor-pointer bg-transparent text-[#828282] transition-colors duration-200 hover:text-[#f58c2f]"
              >
                <IconArrowLeft size={29} stroke={1.25} />
              </button>

              <button
                type="button"
                onClick={() => paginate(1)}
                aria-label="Next review"
                className="cursor-pointer bg-transparent text-[#333333] transition-colors duration-200 hover:text-[#f58c2f]"
              >
                <IconArrowRight size={29} stroke={1.25} />
              </button>
            </div>
          </div>
        </MotionDiv>

        <MotionDiv
          initial={{ opacity: 0, scale: 0.92 }}
          whileInView={{ opacity: 1, scale: 1 }}
          viewport={{ once: true, amount: 0.3 }}
          transition={{ duration: 0.7 }}
          className="relative lg:pb-[26px]"
        >
          <div className="aspect-[651.5/453] w-full overflow-hidden lg:ml-auto lg:w-[94.63%]">
            <img
              src="https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=1300&auto=format&fit=crop"
              alt="A platter of golden crispy fried chicken"
              loading="lazy"
              className="h-full w-full object-cover"
            />
          </div>

          <MotionDiv
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5, delay: 0.35 }}
            className="relative z-10 mx-auto -mt-12 w-full max-w-[420.5px] lg:absolute lg:bottom-0 lg:left-0 lg:mx-0 lg:mt-0"
          >
            <div className="rounded-[2px] bg-white pb-8 pl-[30px] pr-[36px] pt-[36px] shadow-[0_4px_10px_rgba(0,0,0,0.1)] lg:h-[213px]">
              <div className="flex items-start justify-between gap-3">
                <p className="m-0 font-display text-[20px] font-bold leading-[28px] text-[#333333]">Order now</p>
                <p className="m-0 -mt-[1.75px] font-display text-[15px] font-bold leading-[20px] text-[#f58c2f]">₱130.00</p>
              </div>

              <div className="mt-[10.5px] flex gap-[4.3px]" role="img" aria-label="Rated 5 out of 5">
                {[0, 1, 2, 3, 4].map((star) => (
                  <IconStarFilled key={star} size={19} className="text-[#f58c2f]" aria-hidden="true" />
                ))}
              </div>

              <p className="m-0 mt-[23px] font-display text-[18px] font-normal leading-[30px] text-[#828282] lg:text-[20px]">
                Golden crispy outside, juicy inside.
              </p>
            </div>
          </MotionDiv>
        </MotionDiv>
      </div>
    </section>
  );
}
