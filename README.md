# Chick-N-Click

![BEStie](https://github.com/joshptn/BEStie/blob/main/client/public/Screenshot%202025-11-15%20235940.png?raw=true)  

A web-based online ordering and restaurant management platform for **BES House of Chicken**. The system supports customers and store personnel through online ordering, account management, menu and stock management, order fulfilment, payments, delivery, notifications, and restaurant operations.

## 🚀 Key Features

- **Customer Account & Authentication**
  - Customer registration and phone verification
  - Login and account management
  - Two-factor authentication and account security features

- **Menu & Catalog**
  - Browse food items and categories
  - Manage menu items, categories, prices, and availability
  - Manage physical food stock

- **Cart & Ordering**
  - Add food items to the cart
  - Review order summaries
  - Place immediate orders
  - Support advance/scheduled orders

- **Order Queue & Fulfilment**
  - Process orders through the fulfilment queue
  - Monitor active orders and queue progression
  - Real-time order status and availability updates

- **Payment & Delivery**
  - QR Ph and supported e-wallet payments
  - Payment confirmation and refunds
  - Delivery and service-area handling

- **Discount & Customer Services**
  - Senior Citizen and PWD discount eligibility
  - Discount document submission and verification
  - Customer feedback and related communications

- **Store & Management Operations**
  - Store Manager and Store Agent capabilities
  - Menu, pricing, stock, and operational management
  - Administrative and reporting functions

## 🛠️ Technology Stack

### Server
- Laravel
- PHP
- PostgreSQL
- Laravel Sanctum

### Client
- React
- Vite

### External Services
- Semaphore — SMS/OTP delivery
- Gmail SMTP — Email services
- PayMongo — Payment processing
- OpenStreetMap / Nominatim — Mapping and location services
- Cloudinary — File and image storage
- Laravel Reverb — Real-time communication
- Google reCAPTCHA — Human/bot verification

---

## Installation Guide

### 1. Clone the Repository

```bash
git clone <REPOSITORY-URL>
cd BEStie
```

Replace `<REPOSITORY-URL>` with the actual repository URL.

## 2. Server Setup (Laravel)

```bash
cd server

# Install PHP dependencies
composer install

# Create environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure server/.env with the required database
# and external service credentials.

# Run database migrations
php artisan migrate

# Start the Laravel server
php artisan serve
```

## 3. Client Setup (React)

Open another terminal:

```bash
cd client

# Install JavaScript dependencies
npm install

# Start the React development server
npm run dev
```

## 4. Access the Application

After starting both the Laravel server and React development server, open the local URL displayed by Vite in your browser.

The Laravel API should also be running at the address provided by `php artisan serve`.

---

## ⚙️ Environment Configuration

Configure the required environment variables in:

```text
server/.env
client/.env
```

Do not commit `.env` files or API credentials to the repository.

External services such as Semaphore, Gmail SMTP, PayMongo, Cloudinary, mapping services, and Google reCAPTCHA require their corresponding credentials before their related features can be used.

## 📝 Notes

- Run the Laravel server and React development server separately during local development.
- Make sure PostgreSQL is running and the database configured in `server/.env` is available.
- Some features depend on external services and may require valid credentials.
- Production deployment configuration may differ from the local development setup.
