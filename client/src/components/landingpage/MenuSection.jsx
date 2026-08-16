import React, { useEffect, useMemo, useState } from 'react';
import { Badge, Box, Button, Container, Flex, Grid, Group, Image, Text } from '@mantine/core';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';

import { useQuery } from '@tanstack/react-query';

import { fetchFoods, formatPeso } from '../../lib/menu';

const MotionDiv = motion.div;

const VISIBLE_COUNT = 3;
const ROTATE_INTERVAL_MS = 3000;

function MenuCard({ item, onSelect }) {
  return (
    <div className="group cursor-pointer" onClick={onSelect}>
      <div className="relative aspect-4/3 overflow-hidden rounded-3xl border border-cocoa-600 shadow-2xl">
        {item.is_best_seller && (
          <Badge
            radius="sm"
            className="absolute bottom-4 left-4 z-20 bg-accent-strong px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-white"
          >
            Best Seller
          </Badge>
        )}

        <Image
          src={item.thumbnail}
          alt={item.food_name}
          loading="lazy"
          className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-110"
        />
      </div>

      <Flex justify="space-between" align="flex-start" gap="md" mt="lg">
        <Group gap="sm" align="stretch" wrap="nowrap">
          <span className="mt-1 w-[3px] shrink-0 rounded-full bg-accent-strong" aria-hidden="true" />

          <div>
            <Text fw={800} className="font-display text-[15px] uppercase leading-tight tracking-[0.06em] text-white">
              {item.food_name}
            </Text>

            <Text lineClamp={2} className="mt-1 max-w-[28ch] font-display text-[13px] font-medium text-accent-strong">
              {item.category?.name ?? item.description}
            </Text>
          </div>
        </Group>

        <Text fw={900} className="shrink-0 font-display text-[20px] leading-none text-accent-strong">
          {formatPeso(item.price)}
        </Text>
      </Flex>
    </div>
  );
}

export default function MenuSection() {
  const navigate = useNavigate();
  const reduceMotion = useReducedMotion();

  const [offset, setOffset] = useState(0);
  const [paused, setPaused] = useState(false);

  // The same catalogue the customer home page reads. Best sellers only: this
  // is a marketing rail, not the full menu.
  const { data } = useQuery({
    queryKey: ['foods', 'landing'],
    queryFn: () => fetchFoods({ bestSeller: true }),
    staleTime: 5 * 60 * 1000,
  });

  const items = useMemo(() => data?.data ?? [], [data]);

  useEffect(() => {
    items.forEach((item) => {
      if (!item.thumbnail) return;
      const preload = new window.Image();
      preload.src = item.thumbnail;
    });
  }, [items]);

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
    <Box id="menu" data-scroll-section component="section" className="bg-cocoa-700 py-24 text-white md:py-32">
      <Container size="xl" px={{ base: 'md', sm: 'xl' }}>

        <Flex justify="space-between" align={{ base: 'flex-start', md: 'center' }} direction={{ base: 'column', md: 'row' }} gap="xl" mb={50}>
          <MotionDiv
            initial={{ opacity: 0, x: -30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6 }}
          >
            <h2 className="m-0 max-w-[13ch] font-display text-[38px] font-extrabold leading-[1.08] tracking-[-0.5px] sm:text-[46px] lg:text-[56px]">
              Our <span className="text-accent">BEST</span> Delivered Categories
            </h2>
          </MotionDiv>

          <MotionDiv
            initial={{ opacity: 0, x: 30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6, delay: 0.2 }}
            className="shrink-0"
          >
            <Button
              variant="outline"
              radius="xl"
              size="md"
              onClick={() => navigate('/login')}
              className="border-white/70 px-7 font-display text-[12px] font-semibold tracking-wider text-white transition-colors duration-200 hover:border-white hover:bg-white/10"
            >
              EXPLORE FULL MENU
            </Button>
          </MotionDiv>
        </Flex>

        {items.length === 0 ? (
          <p className="m-0 py-10 text-center font-display text-[14px] text-white/60">
            Our menu is being prepared. Check back shortly.
          </p>
        ) : (
        <div
          onMouseEnter={() => setPaused(true)}
          onMouseLeave={() => setPaused(false)}
          onFocusCapture={() => setPaused(true)}
          onBlurCapture={() => setPaused(false)}
        >
          <Grid gutter={{ base: 'xl', md: 40 }}>
            {visibleItems.map((item, slot) => (
              <Grid.Col key={slot} span={{ base: 12, sm: 6, md: 4 }}>
                <MotionDiv
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
                      <MenuCard item={item} onSelect={() => navigate('/login')} />
                    </MotionDiv>
                  </AnimatePresence>
                </MotionDiv>
              </Grid.Col>
            ))}
          </Grid>

          <Group justify="center" gap={10} mt={48}>
            {items.map((item, index) => (
              <button
                key={item.id}
                type="button"
                aria-label={`Show ${item.food_name}`}
                aria-current={index === offset}
                onClick={() => setOffset(index)}
                className={`h-2 rounded-full transition-all duration-300 ${index === offset ? 'w-7 bg-accent-strong' : 'w-2 bg-white/25 hover:bg-white/50'}`}
              />
            ))}
          </Group>
        </div>
        )}

      </Container>
    </Box>
  );
}
