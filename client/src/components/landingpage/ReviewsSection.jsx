import React, { useState } from 'react';
import { Box, Container, Grid, Flex, Text, Group, Avatar, ActionIcon, Card, Image } from '@mantine/core';
import { Icon } from '@iconify/react';
import { motion, AnimatePresence } from 'framer-motion';

const REVIEWS = [
  {
    id: 1,
    text: "The BBQ sub is out of all things my that I have tasted. It is scenic by far the most magnificent gravy that I ever tasted. I still Chicken too, to the point update, but my standard sides for a fried chicken.",
    author: "Customer",
    avatar: "https://images.unsplash.com/photo-1599566150163-29194dcaad36?q=80&w=150&auto=format&fit=crop"
  },
  {
    id: 2,
    text: "Absolutely incredible service and the crunch on the chicken is unmatched! Will definitely be ordering from here for all our family gatherings.",
    author: "Loyal Diner",
    avatar: "https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?q=80&w=150&auto=format&fit=crop"
  }
];

export default function ReviewsSection() {
  const [activeReviewIndex, setActiveReviewIndex] = useState(0);

  const nextReview = () => setActiveReviewIndex((prev) => (prev + 1) % REVIEWS.length);
  const prevReview = () => setActiveReviewIndex((prev) => (prev - 1 + REVIEWS.length) % REVIEWS.length);

  const currentReview = REVIEWS[activeReviewIndex];

  return (
    <Box id="reviews" className="py-24 md:py-32 bg-[#FAF6F0] overflow-hidden">
      <Container size="xl">
        <Grid gutter={60} align="center">
          
          <Grid.Col span={{ base: 12, md: 5 }}>
            <motion.div
              initial={{ opacity: 0, x: -30 }}
              whileInView={{ opacity: 1, x: 0 }}
              viewport={{ once: true, amount: 0.3 }}
              transition={{ duration: 0.6 }}
            >
              <Flex direction="column" gap="md">
                <div>
                  <Text fw={800} size="md" className="text-[#F08E35] mb-2 uppercase tracking-widest">
                    Testimonials
                  </Text>
                  {/* Increased Text Size */}
                  <Text className="text-5xl md:text-6xl font-extrabold tracking-tight" style={{ color: '#2B2B2B' }}>
                    Customer Review
                  </Text>
                </div>

                <div className="text-[#F08E35] mt-2 mb-2">
                  <Icon icon="fa-solid:quote-left" width={48} height={48} className="opacity-20" />
                </div>

                <AnimatePresence mode="wait">
                  <motion.div
                    key={activeReviewIndex}
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -10 }}
                    transition={{ duration: 0.3 }}
                    className="min-h-[140px]"
                  >
                    <Text size="lg" className="text-gray-600 leading-relaxed italic">
                      "{currentReview.text}"
                    </Text>
                  </motion.div>
                </AnimatePresence>

                <Flex justify="space-between" align="center" mt="xl" className="border-t border-gray-200 pt-6">
                  <Group gap="md">
                    <Avatar src={currentReview.avatar} size="xl" radius="xl" />
                    <div>
                      <Text fw={800} size="md" color="#2B2B2B">
                        {currentReview.author}
                      </Text>
                    </div>
                  </Group>

                  <Group gap="sm">
                    <ActionIcon onClick={prevReview} variant="light" color="orange" size="xl" radius="xl" className="hover:scale-105 transition-transform">
                      <Icon icon="lucide:arrow-left" width={24} height={24} />
                    </ActionIcon>
                    <ActionIcon onClick={nextReview} variant="filled" color="orange" size="xl" radius="xl" className="bg-[#F08E35] hover:scale-105 transition-transform">
                      <Icon icon="lucide:arrow-right" width={24} height={24} />
                    </ActionIcon>
                  </Group>
                </Flex>
              </Flex>
            </motion.div>
          </Grid.Col>

          <Grid.Col span={{ base: 12, md: 7 }}>
            <motion.div
              initial={{ opacity: 0, scale: 0.9 }}
              whileInView={{ opacity: 1, scale: 1 }}
              viewport={{ once: true, amount: 0.3 }}
              transition={{ duration: 0.7 }}
              className="relative flex justify-end"
            >
              <div className="w-[90%] rounded-3xl overflow-hidden shadow-2xl aspect-[16/11]">
                <Image 
                  src="https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=1000&auto=format&fit=crop" 
                  alt="Crispy Fried Chicken"
                  className="w-full h-full object-cover"
                />
              </div>

              <motion.div
                initial={{ opacity: 0, y: 30 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: 0.4 }}
                className="absolute bottom-[-30px] left-0 z-30"
              >
                <Card className="max-w-[300px] bg-white shadow-2xl p-6 border-l-4 border-[#F08E35]" radius="lg">
                  <Flex justify="space-between" align="center" mb={8}>
                    <Text fw={800} size="lg" color="#2B2B2B">
                      Order now
                    </Text>
                    <Text fw={900} size="lg" className="text-[#F08E35]">
                      ₱130.00
                    </Text>
                  </Flex>
                  <Group gap={4} mb={10}>
                    {[1, 2, 3, 4, 5].map((s) => (
                      <Icon key={s} icon="fa6-solid:star" width={16} height={16} className="text-[#F08E35]" />
                    ))}
                  </Group>
                  <Text size="sm" color="dimmed" lh={1.4}>
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