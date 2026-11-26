import { useContext } from "react";
import { Link, useLocation } from "react-router-dom";
import { IconBell, IconHomeFilled, IconLogin2, IconReceipt, IconShoppingCart, IconUser } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { useCartState } from "../../context/useCart";
import { useNotifications } from "../../context/useNotifications";
import { TAB_BAR_BOTTOM } from "../../lib/phone";

/**
 * The app's floating bottom bar on a phone (mobile TabBar.tsx): a signed-in
 * customer has Home, Cart, Orders, Notif and Profile; a guest has Home, Cart
 * and Sign in. The current tab is orange; Cart counts its items and Notif its
 * unread notices.
 */
function TabBar() {
  const { pathname } = useLocation();
  const { token } = useContext(AuthContext);
  const signedIn = Boolean(token);
  const { item_count: itemCount } = useCartState();
  const { unreadCount } = useNotifications();

  const items = [
    { to: "/home", label: "Home", Icon: IconHomeFilled },
    { to: "/cart", label: "Cart", Icon: IconShoppingCart, badge: itemCount, unit: "item" },
    ...(signedIn
      ? [
          { to: "/orders", label: "Orders", Icon: IconReceipt },
          { to: "/notifications", label: "Notif", Icon: IconBell, badge: unreadCount, unit: "unread" },
          { to: "/account/profile", label: "Profile", Icon: IconUser },
        ]
      : [{ to: "/login", label: "Sign in", Icon: IconLogin2 }]),
  ];

  return (
    <nav
      aria-label="Main"
      data-testid="tab-bar"
      className="fixed inset-x-5 z-40 flex h-[66px] items-center rounded-[33px] bg-white shadow-[0_6px_16px_rgba(0,0,0,0.12)]"
      style={{ bottom: TAB_BAR_BOTTOM }}
    >
      {items.map((item) => {
        const { to, label, badge, unit } = item;
        const Icon = item.Icon;
        const current = pathname === to;
        const count = badge > 0 ? badge : 0;
        const spoken = count ? `${label}, ${count} ${unit}${unit === "item" && count !== 1 ? "s" : ""}` : label;

        return (
          <Link
            key={to}
            to={to}
            aria-label={spoken}
            aria-current={current ? "page" : undefined}
            data-testid={`tab-${label.toLowerCase().replace(" ", "-")}`}
            className={`flex h-full flex-1 flex-col items-center justify-center gap-0.5 no-underline ${current ? "text-[#ff8904]" : "text-[#111827]"}`}
          >
            <span className="relative">
              <Icon size={23} stroke={1.8} aria-hidden="true" />
              {count > 0 && (
                <span className="absolute -right-[9px] -top-[5px] grid h-4 min-w-4 place-items-center rounded-full border-[1.5px] border-white bg-[#ff8904] px-[3px] font-display text-[9px] font-bold leading-none text-white">
                  {count > 99 ? "99+" : count}
                </span>
              )}
            </span>
            <span className={`font-display text-[11px] ${current ? "font-semibold" : "font-medium"}`}>{label}</span>
          </Link>
        );
      })}
    </nav>
  );
}

export default TabBar;
