# Chick-N-Click

![BEStie](https://github.com/joshptn/click-n-chick/blob/main/client/public/Landing.png?raw=true)  

A web-based online ordering and restaurant management platform for **BES House of Chicken**. The system supports customers and store personnel through online ordering, account management, menu and stock management, order fulfilment, payments, delivery, notifications, and restaurant operations.

## Key Features

- **Automated Discount Calculation:** Employs built-in logic to accurately calculate Senior Citizen and PWD discounts, including VAT exemptions. The system automatically applies the legal percentage reductions to the bill, ensuring compliance with Philippine law and reducing manual arithmetic errors.

- **Centralized Navigation and Catalog Provisioning:** Authorized managers are provided with persistent sidebar navigation for quick access to business management modules. Food and category management allow authorized managers to add, edit, or delete items while keeping the customer-facing menu updated.

- **Interactive Menu and Item Customizations:** Products are organized into menu categories, allowing customers to view food details, select available customizations and add-ons, and add orders to the cart with toast notifications.

- **Advance Ordering:** Allows customers to place orders ahead of time, helping reduce waiting time and peak-hour congestion while allowing staff to receive and prepare orders in advance.

- **Comprehensive Cart and Order Management:** Allows users to edit quantities, remove items, clear the cart, confirm orders, track order status through notifications, and receive real-time updates. Order cancellation is limited to pending orders.

- **Geo-Mapped Logistics:** Features an interactive zone-based fee calculator that automatically determines delivery charges based on geographic location, reducing the need for manual distance estimation.

- **Real-Time Service Synchronization:** Bridges the gap between store capacity and customer expectations through live menu and service availability controls.

- **Systematic Order Queuing:** Establishes a first-come, first-served (FCFS) digital queue for handling high-volume traffic. During concurrent order surges, the system sequences transactions based on their submission time.

## Technology Stack

### Server
- Laravel
- PHP
- PostgreSQL
- Laravel Reverb 

### Client
- React
- Vite

### External Services
- Semaphore — SMS Provider
- PayMongo — Payment System
- OpenStreetMap — Mapping and Location Services
- Google reCAPTCHA — Human/Bot Verification

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

