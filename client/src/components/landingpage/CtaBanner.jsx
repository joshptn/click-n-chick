import React from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';

const MotionDiv = motion.div;

export default function CtaBanner() {
  const navigate = useNavigate();

  return (
    <section className="relative overflow-hidden bg-[#ffb900] px-6 pb-8 pt-14 text-center lg:pt-[73px]">
      <MotionDiv
        initial={{ opacity: 0, y: 30 }}
        whileInView={{ opacity: 1, y: 0 }}
        viewport={{ once: true, amount: 0.4 }}
        transition={{ duration: 0.6 }}
      >
        <p className="m-0 font-display text-[20px] font-medium uppercase leading-[32px] text-[#1a1a1a] lg:text-[24.5px]">Hungry?</p>

        <h2 className="m-0 font-display text-[32px] font-bold leading-[1.2] text-[#1a1a1a] sm:text-[40px] lg:mt-px lg:text-[48px] lg:leading-[56px]">
          Order the best chicken in town
        </h2>

        <button
          type="button"
          onClick={() => navigate('/home')}
          className="mt-8 h-[60px] w-[200px] cursor-pointer rounded-full bg-[#4c1607] text-white shadow-[0_14px_22px_rgba(0,0,0,0.24)] transition-colors duration-200 hover:bg-[#3a1105] lg:mt-[44px] lg:h-[70px] lg:w-[223px]"
        >
          <span className="inline-block font-display text-[18px] font-bold uppercase lg:translate-x-[4.75px] lg:text-[20px]">Order Now</span>
        </button>
      </MotionDiv>
    </section>
  );
}
