import React from 'react';
import { Box, Container, Flex, Grid, Group, Image, Text } from '@mantine/core';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

import LogoIcon from '../../assets/logo-icon.png';

const CONTACT_ROWS = [
  { icon: 'carbon:location-filled', lines: ['Sampaloc, Apalit', 'Pampanga, Philippines'] },
  { icon: 'carbon:phone-filled', lines: ['+63 123 456 789'] },
  { icon: 'carbon:email', lines: ['info@houseofchicken.com'] },
];

const LEGAL_LINKS = ['Privacy Policy', 'Terms of Service', 'Cookie Policy'];

function IconBadge({ icon }) {
  return (
    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent text-white" aria-hidden="true">
      <Icon icon={icon} width={17} height={17} />
    </span>
  );
}

function ColumnHeading({ children }) {
  return (
    <div className="mb-6">
      <Text fw={800} className="font-display text-[19px] tracking-[-0.2px] text-white">
        {children}
      </Text>

      <span className="mt-2 block h-[3px] w-14 rounded-full bg-accent" aria-hidden="true" />
    </div>
  );
}

export default function SiteFooter() {
  return (
    <Box
      id="contact"
      data-scroll-section
      component="footer"
      className="border-t-[6px] border-accent bg-cocoa-700 pb-10 pt-20 text-white"
    >
      <Container size="xl" px={{ base: 'md', sm: 'xl' }}>
        <motion.div
          initial={{ opacity: 0, y: 24 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true, amount: 0.2 }}
          transition={{ duration: 0.6 }}
        >
          <Grid gutter={{ base: 40, md: 60 }}>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <Flex direction="column">
                <Group gap="sm" wrap="nowrap">
                  <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-1.5 shadow-sm">
                    <Image src={LogoIcon} alt="" w={34} h={34} fit="contain" />
                  </div>

                  <Text component="span" fw={700} className="font-logo text-[23px] leading-none text-accent-strong">
                    Click <span className="text-cocoa-300">n</span> Chick
                  </Text>
                </Group>

                <span className="mt-6 block h-[3px] w-14 rounded-full bg-accent" aria-hidden="true" />

                <Text className="mt-5 max-w-[300px] font-display text-[14px] leading-[1.7] text-cocoa-300">
                  Freshly prepared and delivered to your doorstep. Basta BES, da best — always.
                </Text>
              </Flex>
            </Grid.Col>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <ColumnHeading>Contact Information</ColumnHeading>

              <Flex direction="column" gap="lg">
                {CONTACT_ROWS.map((row) => (
                  <Group key={row.icon} gap="md" align="center" wrap="nowrap">
                    <IconBadge icon={row.icon} />

                    <div>
                      {row.lines.map((line) => (
                        <Text key={line} fw={500} className="font-display text-[14px] leading-[1.45] text-white">
                          {line}
                        </Text>
                      ))}
                    </div>
                  </Group>
                ))}
              </Flex>
            </Grid.Col>

            <Grid.Col span={{ base: 12, md: 4 }}>
              <Flex direction="column" gap={40}>

                <div>
                  <ColumnHeading>Follow US</ColumnHeading>

                  <a
                    href="https://www.facebook.com/"
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex items-center gap-4 text-white no-underline transition-colors duration-200 hover:text-accent"
                  >
                    <IconBadge icon="fa-brands:facebook-f" />

                    <Text fw={500} className="font-display text-[14px]">
                      House of Chicken
                    </Text>
                  </a>
                </div>

                <div>
                  <Text fw={800} className="mb-4 font-display text-[13px] uppercase tracking-[0.18em] text-accent">
                    Open Hours
                  </Text>

                  <Group gap="md" align="center" wrap="nowrap">
                    <IconBadge icon="carbon:time" />

                    <div>
                      <Text fw={500} className="font-display text-[14px] text-white">
                        Monday - Sunday
                      </Text>

                      <Text fw={500} className="font-display text-[14px] tracking-[0.05em] text-white">
                        9:00 AM - 9:00 PM
                      </Text>
                    </div>
                  </Group>
                </div>

              </Flex>
            </Grid.Col>

          </Grid>
        </motion.div>

        <Box className="mt-16 border-t border-cocoa-600 pt-6">
          <Grid align="center" gutter="md">

            <Grid.Col span={{ base: 12, md: 6 }}>
              <Text className="text-center font-display text-[12px] text-cocoa-300 md:text-left">
                © 2026 House of Chicken. All Rights Reserved.
              </Text>
            </Grid.Col>

            <Grid.Col span={{ base: 12, md: 6 }}>
              <Group gap="xl" justify="center" className="md:justify-end">
                {LEGAL_LINKS.map((item) => (
                  <Text
                    key={item}
                    component="button"
                    type="button"
                    className="cursor-pointer bg-transparent font-display text-[12px] text-cocoa-300 transition-colors duration-200 hover:text-accent"
                  >
                    {item}
                  </Text>
                ))}
              </Group>
            </Grid.Col>

          </Grid>
        </Box>

      </Container>
    </Box>
  );
}
