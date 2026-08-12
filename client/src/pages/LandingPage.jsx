import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Box,
  Container,
  Group,
  Text,
  Button,
  Grid,
  Flex,
  Rating,
  Image,
} from '@mantine/core';
import { Icon } from '@iconify/react';
import { IconArrowRight } from '@tabler/icons-react';
import { motion } from 'framer-motion';

import MenuSection from '../components/landingpage/MenuSection';
import ReviewsSection from '../components/landingpage/ReviewsSection';
import chickenHero from '../assets/chicken-hero.png';
import LogoIcon from '../assets/logo-icon.png';

export default function LandingPage() {
  const navigate = useNavigate();

  const [scrolled, setScrolled] = useState(false);
  const [active, setActive] = useState('home');
  const sectionsRef = useRef({});

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 20);

    window.addEventListener('scroll', handleScroll);

    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  useEffect(() => {
    const ids = ['home', 'menu', 'reviews', 'contact'];

    ids.forEach((id) => {
      const el = document.getElementById(id);

      if (el) {
        sectionsRef.current[id] = el;
      }
    });

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setActive(entry.target.id);
          }
        });
      },
      {
        root: null,
        rootMargin: '-40% 0px -40% 0px',
        threshold: 0.1,
      }
    );

    Object.values(sectionsRef.current).forEach((el) => {
      observer.observe(el);
    });

    return () => {
      Object.values(sectionsRef.current).forEach((el) => {
        observer.unobserve(el);
      });
    };
  }, []);

  const scrollToSection = (id) => {
    document.getElementById(id)?.scrollIntoView({
      behavior: 'smooth',
    });
  };

  return (
    <Box className="min-h-screen overflow-x-hidden bg-[#FAF6F0] font-sans">
      <motion.header
        className={`fixed left-0 top-0 z-50 w-full transition-all duration-300 ${
          scrolled
            ? 'bg-[#FAF6F0]/95 py-3 shadow-sm backdrop-blur-md'
            : 'bg-transparent py-5'
        }`}
        initial={{ y: -100 }}
        animate={{ y: 0 }}
        transition={{ duration: 0.5 }}
      >
        <Container size="xl">
          <Group justify="space-between" align="center">
            <Group gap="sm" className="cursor-pointer" onClick={() => scrollToSection('home')}>
              <div className="flex h-10 w-10 items-center justify-center">
                <Image src={LogoIcon} alt="Click n Chick" w={50} h={50} fit="contain" />
              </div>

              <Text fw={900} className="text-[21px] tracking-[-0.5px] text-[#ff7900]">
                Click <span className="text-[#292929]">n</span> Chick
              </Text>
            </Group>

            <Group gap={40} className="hidden md:flex">
              {[
                ['menu', 'Menu'],
                ['reviews', 'Reviews'],
                ['contact', 'Contact'],
              ].map(([id, label]) => (
                <Text
                  key={id}
                  fw={500}
                  className={`cursor-pointer border-b-2 pb-1 text-[13px] transition-all ${
                    active === id
                      ? 'border-[#ff7900] text-[#ff7900]'
                      : 'border-transparent text-[#292929] hover:border-[#ff7900] hover:text-[#ff7900]'
                  }`}
                  onClick={() => scrollToSection(id)}
                >
                  {label}
                </Text>
              ))}
            </Group>

            <Button
              onClick={() => navigate('/login')}
              radius="xl"
              className="h-[40px] bg-[#ff8b2b] px-7 text-[11px] font-extrabold shadow-[0_4px_10px_rgba(255,139,43,0.22)] transition-transform hover:scale-105 hover:bg-[#f27d1d]"
            >
              ORDER NOW
            </Button>

          </Group>
        </Container>
      </motion.header>

      <Box id="home" className="w-full">
        <section className="grid min-h-[600px] w-full grid-cols-1 items-center px-5 pb-16 pt-32 sm:px-8 lg:h-[calc(100vh-82px)] lg:grid-cols-[330px_minmax(0,1fr)] lg:px-10 lg:pb-0 lg:pt-0">

          <div className="relative z-10 max-w-[315px]">

            <motion.div
              initial={{ opacity: 0, y: 20 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.6 }}
            >
              <h1 className="m-0 text-[42px] font-black leading-[1.04] tracking-[-1.8px] sm:text-[44px]">
                <span className="block text-[#ff7900]">Basta BES</span>
                <span className="block text-[#292929]">da BEST!</span>
              </h1>
            </motion.div>

            <motion.div
              initial={{ opacity: 0 }}
              whileInView={{ opacity: 1 }}
              viewport={{ once: true }}
              transition={{ delay: 0.3, duration: 0.6 }}
            >
              <Text className="mt-7 max-w-[285px] text-[14px] font-medium leading-[1.55] text-[#6f6b68]">
                Freshly prepared and delivered to your doorstep. Why settle for less when you can have the BES?
              </Text>
            </motion.div>

            <motion.div
              id="reviews"
              initial={{ opacity: 0, x: -20 }}
              whileInView={{ opacity: 1, x: 0 }}
              viewport={{ once: true }}
              transition={{ delay: 0.5, duration: 0.5 }}
              className="mt-8"
            >
              <Rating value={5} readOnly size="sm" color="#ffad2f" />

              <Text className="mt-1 text-[9px] font-bold text-[#4d4d4d]">
                4.9 star rating
              </Text>

              <Text className="text-[8px] text-[#a39f9b]">
                based on 1151 reviews
              </Text>
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 20 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ delay: 0.7, duration: 0.5 }}
            >
              <Button
                onClick={() => scrollToSection('menu')}
                radius="md"
                rightSection={<IconArrowRight size={15} stroke={2.5} />}
                className="mt-9 h-[41px] w-[111px] bg-[#ff8b2b] text-[12px] font-extrabold shadow-[0_4px_8px_rgba(255,139,43,0.20)] transition-transform hover:-translate-y-1 hover:bg-[#f27d1d]"
              >
                Explore
              </Button>
            </motion.div>

          </div>

          <div className="relative flex h-full min-h-[400px] items-center justify-center lg:-ml-8 lg:justify-end">
            <motion.div
              className="relative flex w-full max-w-[700px] justify-center"
              initial={{ opacity: 0, scale: 0.8, rotate: -5 }}
              whileInView={{ opacity: 1, scale: 1, rotate: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.8, type: 'spring', bounce: 0.4 }}
            >
              <Image
                src={chickenHero}
                alt="Chicken delivering fried chicken"
                w={660}
                h="auto"
                fit="contain"
                className="pointer-events-none max-w-none sm:w-[620px] lg:w-[660px]"
              />
            </motion.div>
          </div>

        </section>
      </Box>
      <MenuSection />
      <Box className="relative overflow-hidden bg-[#FFB800] py-20 text-center">
        <Container size="md" className="relative z-10">
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.6 }}
          >
            <Text fw={900} size="md" className="mb-4 uppercase tracking-[0.3em] text-[#412111]">
              HUNGRY?
            </Text>

            <Text className="mb-10 text-4xl font-extrabold tracking-tight md:text-6xl" style={{ color: '#1A1A1A' }}>
              Order the best chicken in town
            </Text>

            <Button
              onClick={() => navigate('/login')}
              radius="xl"
              size="xl"
              className="bg-[#412111] px-14 font-bold tracking-widest text-white shadow-2xl transition-transform hover:scale-105 hover:bg-[#2B150A]"
            >
              ORDER NOW
            </Button>
          </motion.div>
        </Container>
      </Box>

      <ReviewsSection />

      <Box id="contact" className="border-t-[6px] border-[#F08E35] bg-[#412111] pb-10 pt-20 text-white">
        <Container size="xl">
          <Grid gutter={60}>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <Flex direction="column" gap="md">
                <Group gap="sm">
                  <div className="flex h-12 w-12 items-center justify-center rounded-md bg-white shadow-sm">
                    <Icon icon="fluent-emoji:poultry-leg" width={32} height={32} />
                  </div>

                  <Text size="xl" fw={800} className="text-[#E88B23]">
                    Click <span className="text-[#D3A982]">n</span> Chick
                  </Text>
                </Group>

                <Text size="sm" className="mt-4 leading-relaxed text-[#D3A982]">
                  Freshly prepared and delivered to your doorstep. Basta BES, da best — always.
                </Text>
              </Flex>
            </Grid.Col>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <Text fw={800} size="lg" className="mb-6 tracking-wide text-white">
                Contact Information
              </Text>

              <Flex direction="column" gap="lg">

                <Group gap="md" align="flex-start" wrap="nowrap">
                  <div className="mt-1 text-[#F08E35]">
                    <Icon icon="carbon:location-filled" width={24} height={24} />
                  </div>

                  <div>
                    <Text size="sm" fw={600} className="text-white">
                      Sampaloc, Apalit
                    </Text>

                    <Text size="sm" fw={600} className="text-white">
                      Pampanga, Philippines
                    </Text>
                  </div>
                </Group>

                <Group gap="md" align="flex-start" wrap="nowrap">
                  <div className="mt-1 text-[#F08E35]">
                    <Icon icon="carbon:phone-filled" width={24} height={24} />
                  </div>

                  <div>
                    <Text size="sm" fw={600} className="text-white">
                      +63 123 456 789
                    </Text>
                  </div>
                </Group>

                <Group gap="md" align="flex-start" wrap="nowrap">
                  <div className="mt-1 text-[#F08E35]">
                    <Icon icon="carbon:email" width={24} height={24} />
                  </div>

                  <div>
                    <Text size="sm" fw={600} className="text-white">
                      info@houseofchicken.com
                    </Text>
                  </div>
                </Group>

              </Flex>
            </Grid.Col>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <Flex direction="column" gap="xl">

                <div>
                  <Text fw={800} size="lg" className="mb-6 tracking-wide text-white">
                    Follow US
                  </Text>

                  <Group className="group cursor-pointer text-white transition-all hover:text-[#F08E35]">
                    <div className="text-[#F08E35]">
                      <Icon icon="fa-brands:facebook" width={24} height={24} />
                    </div>

                    <Text size="sm" fw={600}>
                      House of Chicken
                    </Text>
                  </Group>
                </div>

                <div>
                  <Text fw={800} size="sm" className="mb-4 uppercase tracking-widest text-[#F08E35]">
                    OPEN HOURS
                  </Text>

                  <Group gap="md" align="flex-start" wrap="nowrap">
                    <div className="mt-1 text-white">
                      <Icon icon="carbon:time" width={24} height={24} />
                    </div>

                    <div>
                      <Text size="sm" fw={600} className="text-white">
                        Monday - Sunday
                      </Text>

                      <Text size="sm" fw={600} className="mt-1 tracking-widest text-white">
                        9:00 AM - 9:00 PM
                      </Text>
                    </div>
                  </Group>
                </div>

              </Flex>
            </Grid.Col>

          </Grid>

          <Box className="mt-16 border-t border-[#5A321D] pt-6">
            <Grid align="center">

              <Grid.Col span={{ base: 12, md: 6 }}>
                <Text size="xs" className="text-center text-[#D3A982] md:text-left">
                  © 2026 House of Chicken. All Rights Reserved.
                </Text>
              </Grid.Col>

              <Grid.Col span={{ base: 12, md: 6 }}>
                <Group gap="xl" justify="center" className="md:justify-end">
                  {['Privacy Policy', 'Terms of Service', 'Cookie Policy'].map((item) => (
                    <Text key={item} size="xs" className="cursor-pointer text-[#D3A982] hover:text-[#F08E35]">
                      {item}
                    </Text>
                  ))}
                </Group>
              </Grid.Col>

            </Grid>
          </Box>

        </Container>
      </Box>

    </Box>
  );
}