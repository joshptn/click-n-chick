import React, { useState } from 'react';
import { ActionIcon, Avatar, Box, Card, Container, Flex, Grid, Group, Image, Text } from '@mantine/core';
import { Icon } from '@iconify/react';
import { AnimatePresence, motion } from 'framer-motion';

const REVIEWS = [
  {
    id: 1,
    text: 'The BES in its literal sense. Out of all the gravy that I have tasted, this one is by far the most magnificent gravy that I ever tasted. 10/10 Chicken too, to the point BES:HOC set my standard taste for a fried chicken.',
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
    <Box id="reviews" data-scroll-section component="section" className="overflow-hidden bg-cream py-24 md:py-32">
      <Container size="xl" px={{ base: 'md', sm: 'xl' }}>
        <Grid gutter={{ base: 48, md: 60 }} align="center">

          <Grid.Col span={{ base: 12, md: 5 }}>
            <motion.div
              initial={{ opacity: 0, x: -30 }}
              whileInView={{ opacity: 1, x: 0 }}
              viewport={{ once: true, amount: 0.3 }}
              transition={{ duration: 0.6 }}
            >
              <Text fw={700} className="mb-3 font-display text-[13px] uppercase tracking-[0.18em] text-accent">
                Testimonials
              </Text>

              <h2 className="m-0 font-display text-[34px] font-extrabold leading-[1.1] tracking-[-1px] text-[#2b2b2b] sm:text-[42px] lg:text-[48px]">
                Customer Review
              </h2>

              <div className="mt-5 text-accent" aria-hidden="true">
                <Icon icon="fa-solid:quote-left" width={40} height={40} className="opacity-25" />
              </div>

              <div className="relative mt-5 min-h-[132px]">
                <AnimatePresence mode="wait" initial={false} custom={direction}>
                  <motion.div
                    key={review.id}
                    custom={direction}
                    initial={{ opacity: 0, x: direction * 24 }}
                    animate={{ opacity: 1, x: 0 }}
                    exit={{ opacity: 0, x: direction * -24 }}
                    transition={{ duration: 0.32, ease: 'easeOut' }}
                  >
                    <Text className="font-display text-[13.5px] font-medium leading-[1.75] text-ink-500">
                      {review.text}
                    </Text>
                  </motion.div>
                </AnimatePresence>
              </div>

              <Flex justify="space-between" align="center" mt="xl" className="border-t border-[#e6ded2] pt-6">
                <Group gap="md" wrap="nowrap">
                  <Avatar src={review.avatar} alt="" size={44} radius="xl" />

                  <Text fw={600} className="font-display text-[14px] text-[#2b2b2b]">
                    {review.author}
                  </Text>
                </Group>

                <Group gap={4} wrap="nowrap">
                  <ActionIcon
                    onClick={() => paginate(-1)}
                    variant="subtle"
                    color="dark"
                    size="lg"
                    radius="xl"
                    aria-label="Previous review"
                    className="text-ink-500 transition-colors duration-200 hover:bg-brand-50 hover:text-brand-700"
                  >
                    <Icon icon="lucide:arrow-left" width={20} height={20} />
                  </ActionIcon>

                  <ActionIcon
                    onClick={() => paginate(1)}
                    variant="subtle"
                    color="dark"
                    size="lg"
                    radius="xl"
                    aria-label="Next review"
                    className="text-ink-500 transition-colors duration-200 hover:bg-brand-50 hover:text-brand-700"
                  >
                    <Icon icon="lucide:arrow-right" width={20} height={20} />
                  </ActionIcon>
                </Group>
              </Flex>
            </motion.div>
          </Grid.Col>

          <Grid.Col span={{ base: 12, md: 7 }}>
            <motion.div
              initial={{ opacity: 0, scale: 0.92 }}
              whileInView={{ opacity: 1, scale: 1 }}
              viewport={{ once: true, amount: 0.3 }}
              transition={{ duration: 0.7 }}
              className="relative pb-16 md:pb-0"
            >
              <div className="aspect-16/11 w-full overflow-hidden rounded-3xl shadow-[0_24px_60px_rgba(65,33,17,0.18)] md:ml-auto md:w-[92%]">
                <Image
                  src="https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=1000&auto=format&fit=crop"
                  alt="A platter of golden crispy fried chicken"
                  loading="lazy"
                  className="h-full w-full object-cover"
                />
              </div>

              <motion.div
                initial={{ opacity: 0, y: 30 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: 0.35 }}
                className="left-0 z-30 mx-auto -mt-12 w-full max-w-[300px] md:absolute md:bottom-[-28px] md:mx-0 md:mt-0"
              >
                <Card radius="lg" padding="lg" className="border-l-4 border-accent bg-white shadow-[0_18px_44px_rgba(65,33,17,0.18)]">
                  <Flex justify="space-between" align="center" mb={8} gap="sm">
                    <Text fw={800} className="font-display text-[15px] text-[#2b2b2b]">
                      Order now
                    </Text>

                    <Text fw={900} className="font-display text-[15px] text-accent">
                      ₱130.00
                    </Text>
                  </Flex>

                  <Group gap={4} mb={10} aria-label="Rated 5 out of 5">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <Icon key={star} icon="fa6-solid:star" width={14} height={14} className="text-accent" />
                    ))}
                  </Group>

                  <Text className="font-display text-[13px] leading-[1.5] text-ink-500">
                    Golden crispy outside, juicy inside.
                  </Text>
                </Card>
              </motion.div>
            </motion.div>
          </Grid.Col>

        </Grid>
      </Container>
    </Box>
  );
}
