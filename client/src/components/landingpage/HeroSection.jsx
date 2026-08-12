import React from 'react';
import { Box, Button, Image, Rating, Text } from '@mantine/core';
import { motion } from 'framer-motion';

import chickenHero from '../../assets/chicken-hero.png';

export default function HeroSection({ onExplore }) {
  return (
    <Box
      id="home"
      data-scroll-section
      component="section"
      className="relative w-full overflow-hidden bg-cream pb-16 pt-28 lg:pb-0 lg:pt-[var(--spacing-header)] lg:min-h-screen"
    >
      <div className="mx-auto grid w-full max-w-[1500px] grid-cols-1 items-center gap-10 px-6 sm:px-10 lg:min-h-[calc(100vh-var(--spacing-header))] lg:grid-cols-[minmax(280px,360px)_minmax(0,1fr)] lg:gap-4 lg:px-12">

        <div className="relative z-10 order-2 max-w-[420px] lg:order-1">

          <motion.h1
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, ease: 'easeOut' }}
            className="m-0 font-display text-[44px] font-black leading-[1.03] tracking-[-1.8px] sm:text-[52px] lg:text-[48px] xl:text-[54px]"
          >
            <span className="block text-brand-700">Basta BES</span>
            <span className="block text-ink">da BEST!</span>
          </motion.h1>

          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.18, duration: 0.6, ease: 'easeOut' }}
          >
            <Text className="mt-6 max-w-[300px] font-display text-[14px] font-medium leading-[1.6] text-ink-500">
              Freshly prepared and delivered to your doorstep. Why settle for less when you can have the BES?
            </Text>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, x: -16 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ delay: 0.34, duration: 0.5, ease: 'easeOut' }}
            className="mt-8"
          >
            <Rating value={5} readOnly size="sm" color="#ffad2f" />

            <Text className="mt-1.5 font-display text-[11px] font-bold text-[#4d4d4d]">
              4.9 star rating
            </Text>

            <Text className="font-display text-[10px] text-ink-300">
              based on 1151 reviews
            </Text>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.48, duration: 0.5, ease: 'easeOut' }}
          >
            <Button
              onClick={onExplore}
              radius="xl"
              className="mt-9 h-[46px] w-[150px] bg-brand-500 font-display text-[14px] font-bold shadow-[0_6px_16px_rgba(255,139,43,0.32)] transition-transform duration-200 hover:-translate-y-1 hover:bg-brand-600"
            >
              Explore
            </Button>
          </motion.div>

        </div>

        <div className="relative order-1 flex items-center justify-center lg:order-2 lg:justify-end">
          <motion.div
            className="relative flex w-full justify-center lg:justify-end"
            initial={{ opacity: 0, scale: 0.86, rotate: -4 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            transition={{ duration: 0.85, type: 'spring', bounce: 0.35 }}
          >
            <motion.div
              animate={{ y: [0, -14, 0] }}
              transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
              className="w-full max-w-[720px]"
            >
              <Image
                src={chickenHero}
                alt="Mascot chicken riding a scooter with a bucket of fried chicken"
                w="100%"
                h="auto"
                fit="contain"
                className="pointer-events-none select-none drop-shadow-[0_24px_40px_rgba(65,33,17,0.12)]"
              />
            </motion.div>
          </motion.div>
        </div>

      </div>
    </Box>
  );
}
