import {
  IconBell,
  IconChefHat,
  IconCircleCheck,
  IconCircleX,
  IconPhoto,
  IconReceipt,
  IconRosetteDiscountCheck,
  IconScooter,
  IconShieldLock,
  IconShoppingBag,
  IconSpeakerphone,
} from "@tabler/icons-react";

import { notificationKind } from "./notifications";

/** The coloured icon of each kind of notice, as in the app's NotificationCard. */
export const LOOKS = {
  placed: { Icon: IconReceipt, color: "#e2551c", tint: "#fde8de" },
  confirmed: { Icon: IconCircleCheck, color: "#1fa64a", tint: "#dcf5e4" },
  preparing: { Icon: IconChefHat, color: "#b7791f", tint: "#fdf1cf" },
  onTheWay: { Icon: IconScooter, color: "#2563eb", tint: "#dde8fd" },
  ready: { Icon: IconShoppingBag, color: "#7c3aed", tint: "#ece4fd" },
  cancelled: { Icon: IconCircleX, color: "#e5322d", tint: "#fff1f1" },
  order: { Icon: IconBell, color: "#ff8904", tint: "#fff1e6" },
  security: { Icon: IconShieldLock, color: "#2563eb", tint: "#dde8fd" },
  updates: { Icon: IconSpeakerphone, color: "#ff8904", tint: "#fff1e6" },
  // Staff only: an application to review, a poster about to come down.
  review: { Icon: IconRosetteDiscountCheck, color: "#e2551c", tint: "#fde8de" },
  poster: { Icon: IconPhoto, color: "#7c3aed", tint: "#ece4fd" },
};

function matchLook(text) {
  const words = String(text ?? "").toLowerCase();

  if (/cancel|declin|reject/.test(words)) return LOOKS.cancelled;
  if (/placed|request|new order/.test(words)) return LOOKS.placed;
  if (/on the way|out for delivery|rider/.test(words)) return LOOKS.onTheWay;
  if (/ready/.test(words)) return LOOKS.ready;
  if (/prepar|cooking|kitchen/.test(words)) return LOOKS.preparing;
  if (/delivered|completed|received|confirm|accept/.test(words)) return LOOKS.confirmed;

  return null;
}

/**
 * The picture for a notice. An order notice is drawn from its words, since a
 * notification records its message, not the status it announced.
 */
export function lookFor(notification) {
  const type = notification?.notification_type;

  if (type === "discount_review") return LOOKS.review;
  if (type === "poster_expiry") return LOOKS.poster;

  const kind = notificationKind(notification);

  if (kind === "security") return LOOKS.security;
  if (kind === "updates") return LOOKS.updates;

  // The title names the status; the message is only a fallback ("Order
  // placed" says "confirming it" in its message).
  return matchLook(notification?.title) ?? matchLook(notification?.body) ?? LOOKS.order;
}
