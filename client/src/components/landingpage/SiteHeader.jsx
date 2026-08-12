import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Burger, Button, Container, Drawer, Group, Image, Stack, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { motion } from 'framer-motion';

import LogoIcon from '../../assets/logo-icon.png';
import { NAV_LINKS } from './menuData';

const MotionHeader = motion.header;

function Wordmark({ onClick }) {
  return (
    <Group gap="xs" wrap="nowrap" className="cursor-pointer select-none" onClick={onClick}>
      <Image src={LogoIcon} alt="" w={40} h={40} fit="contain" className="shrink-0" />

      <Text component="span" fw={700} className="font-logo text-[22px] leading-none tracking-[-0.5px] text-brand-700">
        Click <span className="text-ink">n</span> Chick
      </Text>
    </Group>
  );
}

export default function SiteHeader({ active, onNavigate, scrolled }) {
  const navigate = useNavigate();
  const [mobileOpen, { open: openMobile, close: closeMobile }] = useDisclosure(false);

  const go = (id) => {
    closeMobile();
    onNavigate(id);
  };

  return (
    <>
      <MotionHeader
        className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${scrolled ? 'bg-cream/95 py-3 shadow-[0_2px_20px_rgba(65,33,17,0.08)] backdrop-blur-md' : 'bg-transparent py-5'}`}
        initial={{ y: -100 }}
        animate={{ y: 0 }}
        transition={{ duration: 0.5, ease: 'easeOut' }}
      >
        <Container size="xl" px={{ base: 'md', sm: 'xl' }}>
          <Group justify="space-between" align="center" wrap="nowrap">
            <Wordmark onClick={() => go('home')} />

            <Group gap={40} visibleFrom="md">
              {NAV_LINKS.map(([id, label]) => (
                <Text
                  key={id}
                  component="button"
                  type="button"
                  fw={500}
                  onClick={() => go(id)}
                  className={`cursor-pointer border-b-2 bg-transparent pb-1 text-[14px] transition-colors duration-200 ${active === id ? 'border-brand-700 text-brand-700' : 'border-transparent text-ink hover:border-brand-300 hover:text-brand-700'}`}
                >
                  {label}
                </Text>
              ))}
            </Group>

            <Group gap="xs" wrap="nowrap">
              <Button
                onClick={() => navigate('/login')}
                radius="xl"
                className="h-[42px] bg-brand-500 px-6 text-[12px] font-extrabold tracking-wider shadow-[0_4px_12px_rgba(255,139,43,0.28)] transition-transform duration-200 hover:scale-105 hover:bg-brand-600 sm:px-8"
              >
                ORDER NOW
              </Button>

              <Burger opened={mobileOpen} onClick={openMobile} size="sm" color="#412111" hiddenFrom="md" aria-label="Open navigation" />
            </Group>
          </Group>
        </Container>
      </MotionHeader>

      <Drawer
        opened={mobileOpen}
        onClose={closeMobile}
        position="right"
        size="72%"
        padding="lg"
        hiddenFrom="md"
        title={<Wordmark onClick={() => go('home')} />}
        classNames={{ content: 'bg-cream', header: 'bg-cream' }}
      >
        <Stack gap="lg" mt="md">
          {NAV_LINKS.map(([id, label]) => (
            <Text
              key={id}
              component="button"
              type="button"
              fw={600}
              onClick={() => go(id)}
              className={`w-full cursor-pointer border-b border-brand-100 bg-transparent pb-3 text-left text-[16px] ${active === id ? 'text-brand-700' : 'text-ink'}`}
            >
              {label}
            </Text>
          ))}

          <Button onClick={() => navigate('/login')} radius="xl" fullWidth className="mt-2 h-[46px] bg-brand-500 font-extrabold tracking-wider hover:bg-brand-600">
            ORDER NOW
          </Button>
        </Stack>
      </Drawer>
    </>
  );
}
