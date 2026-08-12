import React from 'react';
import { Box, Container, Group, Text, Button, Grid, Badge, Image, Flex } from '@mantine/core';
import { motion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';

const CATEGORIES = [
  {
    id: 1,
    name: "WHOLE ROAST",
    description: "The Original Recipe",
    price: "350",
    image: "https://images.unsplash.com/photo-1598514982205-f36b96d1e8d4?q=80&w=800&auto=format&fit=crop", 
  },
  {
    id: 2,
    name: "CHICKEN",
    description: "Good for 4-6 persons",
    price: "550",
    image: "https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=800&auto=format&fit=crop", 
  },
  {
    id: 3,
    name: "BUFFALO WINGS",
    description: "Good for 4-5 persons",
    price: "550",
    image: "https://images.unsplash.com/photo-1569691899455-88464f6d3ab1?q=80&w=800&auto=format&fit=crop", 
  }
];

export default function MenuSection() {
  const navigate = useNavigate();

  return (
    <Box id="menu" className="bg-[#412111] text-white py-24 md:py-32">
      <Container size="xl">
        <Group justify="space-between" align="center" mb={50}>
          <motion.div
            initial={{ opacity: 0, x: -30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6 }}
          >
            {/* Increased Text Size */}
            <Text className="text-5xl md:text-7xl font-extrabold leading-tight tracking-wide">
              Our <span style={{ color: '#F08E35' }}>BEST</span> Delivered
            </Text>
            <Text className="text-5xl md:text-7xl font-extrabold leading-tight tracking-wide">
              Categories
            </Text>
          </motion.div>
          
          <motion.div
            initial={{ opacity: 0, x: 30 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true, amount: 0.3 }}
            transition={{ duration: 0.6, delay: 0.2 }}
          >
            <Button 
              variant="outline" 
              radius="xl"
              size="lg"
              onClick={() => navigate('/login')}
              className="border-white text-white hover:bg-white/10 font-semibold tracking-wider px-8 transition-all"
            >
              EXPLORE FULL MENU
            </Button>
          </motion.div>
        </Group>

        <Grid gutter="xl">
          {CATEGORIES.map((item, index) => (
            <Grid.Col key={item.id} span={{ base: 12, md: 4 }}>
              <motion.div
                initial={{ opacity: 0, y: 40 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true, amount: 0.2 }}
                transition={{ duration: 0.5, delay: index * 0.15 }}
                className="group cursor-pointer"
                onClick={() => navigate('/login')}
              >
                <div className="relative rounded-3xl overflow-hidden mb-5 border border-[#5A321D] shadow-2xl aspect-[4/3]">
                  <Badge 
                    className="absolute bottom-4 left-4 bg-[#E88B23] text-white font-bold text-sm uppercase tracking-wider z-20 px-4 py-2"
                    radius="sm"
                  >
                    BEST SELLER
                  </Badge>
                  <Image 
                    src={item.image}
                    alt={item.name}
                    className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                  />
                </div>

                <Flex justify="space-between" align="flex-start" mt="md" px={2}>
                  <div>
                    <Text fw={800} size="xl" className="tracking-widest text-white uppercase font-display">
                      {item.name}
                    </Text>
                    <Text size="md" className="text-[#A78A7F] mt-1">
                      {item.description}
                    </Text>
                  </div>
                  <Text fw={900} size="2xl" style={{ color: '#E88B23' }}>
                    ₱{item.price}
                  </Text>
                </Flex>
              </motion.div>
            </Grid.Col>
          ))}
        </Grid>
      </Container>
    </Box>
  );
}