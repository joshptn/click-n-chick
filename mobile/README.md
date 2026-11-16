# BES Click n Chick — Mobile

The official Android ordering application for **BES House of Chicken**, built with **React Native, Expo, and TypeScript**.

The app serves customers and guests, sharing the existing Laravel backend with the web platform.

## Tech Stack

- **Framework:** React Native + Expo
- **API:** Laravel + Sanctum
- **Push Notifications:** Firebase Cloud Messaging (FCM)
- **Real-time Updates:** Laravel Reverb
- **Maps:** Leaflet + OpenStreetMap

## Features

- Menu browsing and cart management
- Pickup and delivery checkout
- Order tracking and notifications
- Advance orders
- Customer profile and saved addresses
- Discounts and loyalty points

*Features are being implemented in phases.*

## Getting Started

Install dependencies:

```bash
npm install
```

Start the development server:

```bash
npx expo start
```

Run an Android development build:

```bash
npx expo run:android
```

## Environment

Create a `.env` file:

```env
EXPO_PUBLIC_API_URL=https://your-api-domain.com
```

Firebase configuration is required for native push notifications. Keep service account private keys on the Laravel backend, never in the mobile app.

## Development

The mobile app follows the phased implementation plan. Checkout and payment integration depend on the completion of the web payment backend.

**Platform:** Android only

**Project:** BES Click n Chick — BES House of Chicken