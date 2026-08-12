import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Box, Button, Container, Text } from '@mantine/core';
import { motion } from 'framer-motion';

const MotionDiv = motion.div;

export default function CtaBanner() {
  const navigate = useNavigate();

  return (
    <Box component="section" className="relative overflow-hidden bg-sun py-20 text-center md:py-24">
      <Container size="md" className="relative z-10">
        <MotionDiv
          initial={{ opacity: 0, y: 30 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true, amount: 0.4 }}
          transition={{ duration: 0.6 }}
        >
          <Text fw={800} className="mb-4 font-display text-[13px] uppercase tracking-[0.32em] text-cocoa-700">
            Hungry?
          </Text>

          <h2 className="m-0 mb-10 font-display text-[34px] font-extrabold leading-[1.12] tracking-[-1px] text-ink-900 sm:text-[44px] md:text-[54px]">
            Order the best chicken in town
          </h2>

          <Button
            onClick={() => navigate('/login')}
            radius="xl"
            size="xl"
            className="bg-cocoa-700 px-12 font-display text-[14px] font-bold tracking-[0.14em] text-white shadow-[0_10px_28px_rgba(65,33,17,0.28)] transition-transform duration-200 hover:scale-105 hover:bg-cocoa-900"
          >
            ORDER NOW
          </Button>
        </MotionDiv>
      </Container>
    </Box>
  );
}
