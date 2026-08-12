// Placeholder catalogue for the marketing page. The real menu comes from
// /api/foods once the menu module is rebuilt — keep the shape (name,
// description, price, image) aligned with the Food model so the swap is a
// straight substitution rather than a rewrite of MenuSection.
export const MENU_ITEMS = [
  {
    id: 1,
    name: 'Whole Roast',
    description: 'The Original Recipe',
    price: 350,
    image: 'https://images.unsplash.com/photo-1598514982205-f36b96d1e8d4?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 2,
    name: 'Chicken',
    description: 'Good for 4-6 persons',
    price: 550,
    image: 'https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 3,
    name: 'Buffalo Wings',
    description: 'Good for 4-5 persons',
    price: 550,
    image: 'https://images.unsplash.com/photo-1569691899455-88464f6d3ab1?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 4,
    name: 'Crispy Tenders',
    description: 'Hand-breaded, 8 pieces',
    price: 320,
    image: 'https://images.unsplash.com/photo-1562967914-608f82629710?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 5,
    name: 'Smoky BBQ Quarter',
    description: 'Charcoal-grilled, with rice',
    price: 180,
    image: 'https://images.unsplash.com/photo-1432139555190-58524dae6a55?q=80&w=800&auto=format&fit=crop',
  },
  {
    id: 6,
    name: 'Family Bucket',
    description: 'Good for 6-8 persons',
    price: 899,
    image: 'https://images.unsplash.com/photo-1513639776629-7b61b0ac49cb?q=80&w=800&auto=format&fit=crop',
  },
];

export const NAV_LINKS = [
  ['menu', 'Menu'],
  ['reviews', 'Reviews'],
  ['contact', 'Contact'],
];
