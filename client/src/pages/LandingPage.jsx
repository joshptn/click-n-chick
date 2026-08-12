import React, { useCallback, useEffect, useState } from 'react';
import { Box } from '@mantine/core';

import SiteHeader from '../components/landingpage/SiteHeader';
import HeroSection from '../components/landingpage/HeroSection';
import MenuSection from '../components/landingpage/MenuSection';
import CtaBanner from '../components/landingpage/CtaBanner';
import ReviewsSection from '../components/landingpage/ReviewsSection';
import SiteFooter from '../components/landingpage/SiteFooter';

const SECTION_IDS = ['home', 'menu', 'reviews', 'contact'];

export default function LandingPage() {
  const [scrolled, setScrolled] = useState(false);
  const [active, setActive] = useState('home');

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 20);

    handleScroll();
    window.addEventListener('scroll', handleScroll, { passive: true });

    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  useEffect(() => {
    // Elements are captured into a local array so the cleanup unobserves the
    // exact nodes that were observed, rather than re-reading a ref that may
    // have changed by teardown.
    const elements = SECTION_IDS.map((id) => document.getElementById(id)).filter(Boolean);

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setActive(entry.target.id);
          }
        });
      },
      { root: null, rootMargin: '-45% 0px -45% 0px', threshold: 0 }
    );

    elements.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  const scrollToSection = useCallback((id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, []);

  return (
    <Box className="min-h-screen overflow-x-hidden bg-cream font-display text-ink">
      <SiteHeader active={active} onNavigate={scrollToSection} scrolled={scrolled} />

      <main>
        <HeroSection onExplore={() => scrollToSection('menu')} />
        <MenuSection />
        <CtaBanner />
        <ReviewsSection />
      </main>

      <SiteFooter />
    </Box>
  );
}
