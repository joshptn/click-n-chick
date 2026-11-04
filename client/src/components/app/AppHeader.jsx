import { useContext } from "react";
import { Link, useLocation } from "react-router-dom";
import { IconCalendarPlus, IconSearch, IconShoppingBag, IconX } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AccountMenu from "./AccountMenu";
import NotificationMenu from "./NotificationMenu";
import LogoIcon from "../../assets/logo-icon.png";
import { CART_MODE } from "../../lib/cartModes";
import { ROLES } from "../../lib/roles";
import { formatStoreTime, useStoreStatus } from "../../lib/store";
import { useCart } from "../../context/useCart";

function AppHeader({
  search,
  onSearchChange,
  searchPlaceholder = "What do you want to eat today...",
  onOpenCart,
  cartMode = CART_MODE.IMMEDIATE,
}) {
  const { pathname } = useLocation();
  const { user, token } = useContext(AuthContext);
  const signedIn = Boolean(token);
  const { item_count: itemCount } = useCart(cartMode);
  const { isOpen, status: storeStatus } = useStoreStatus();

  const showSearch = typeof onSearchChange === "function";

  const canOrderInAdvance =
    user?.role === ROLES.CUSTOMER && !pathname.startsWith("/advance-order");

  return (
    <header className="sticky top-0 z-40 border-b border-[#f0e9df] bg-white/95 backdrop-blur-md">
      <div className="mx-auto flex h-[68px] w-full max-w-[1440px] items-center gap-3 px-4 sm:gap-5 sm:px-6 lg:px-8">

        <Link
          to="/"
          className="flex shrink-0 items-center gap-2 no-underline transition-opacity hover:opacity-80"
          aria-label="Click n Chick — home"
        >
          <img src={LogoIcon} alt="" draggable={false} className="h-9 w-9 select-none object-contain" />
          <span className="hidden font-logo text-[19px] font-bold leading-none tracking-[-0.4px] text-brand-700 sm:inline">
            Click <span className="text-ink">n</span> Chick
          </span>
        </Link>

        {showSearch && (
          <div className="relative mx-auto w-full max-w-[420px]">
            <IconSearch
              size={17}
              stroke={2.2}
              aria-hidden="true"
              className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#a39f9b]"
            />

            <input
              type="search"
              value={search ?? ""}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder={searchPlaceholder}
              aria-label="Search the menu"
              className="h-[42px] w-full rounded-full border border-[#ece7e0] bg-[#faf7f3] pl-10 pr-9 font-display text-[13.5px] text-ink outline-none transition-colors placeholder:text-[#a39f9b] focus:border-brand-300 focus:bg-white"
            />

            {search ? (
              <button
                type="button"
                onClick={() => onSearchChange("")}
                aria-label="Clear search"
                className="absolute right-3 top-1/2 -translate-y-1/2 bg-transparent text-[#a39f9b] transition-colors hover:text-ink"
              >
                <IconX size={15} stroke={2.4} />
              </button>
            ) : null}
          </div>
        )}

        <div className={`flex shrink-0 items-center gap-2 sm:gap-3 ${showSearch ? "" : "ml-auto"}`}>

          <span
            data-testid="store-status"
            data-open={isOpen}
            title={
              storeStatus
                ? `Online ordering ${formatStoreTime(storeStatus.opens_at)} - ${formatStoreTime(storeStatus.closes_at)}`
                : undefined
            }
            className={`hidden rounded-full px-3.5 py-1.5 font-display text-[12px] font-bold sm:inline-flex ${isOpen ? "bg-[#e9f8ee] text-[#2f9e44]" : "bg-[#f4f1ec] text-[#8d8884]"}`}
          >
            {isOpen ? "Open" : "Closed"}
          </span>

          <button
            type="button"
            onClick={onOpenCart}
            aria-label={`My orders, ${itemCount} item${itemCount === 1 ? "" : "s"}`}
            className="relative grid h-10 w-10 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#f7f4f0] xl:hidden"
          >
            <IconShoppingBag size={21} stroke={1.9} />
            {itemCount > 0 && (
              <span className="absolute -right-0.5 -top-0.5 grid h-[19px] min-w-[19px] place-items-center rounded-full bg-brand-500 px-1 font-display text-[10.5px] font-bold text-white">
                {itemCount > 99 ? "99+" : itemCount}
              </span>
            )}
          </button>

          {signedIn && <NotificationMenu />}

          {canOrderInAdvance && (
            <>
              <Link
                to="/advance-order"
                data-testid="advance-order-entry"
                className="hidden h-[38px] shrink-0 items-center rounded-full border border-brand-500 bg-transparent px-4 font-display text-[13px] font-semibold text-brand-600 no-underline transition-colors hover:bg-brand-50 sm:inline-flex"
              >
                Advance Order
              </Link>

              <Link
                to="/advance-order"
                aria-label="Advance order"
                className="grid h-10 w-10 place-items-center rounded-full bg-transparent text-brand-600 no-underline transition-colors hover:bg-brand-50 sm:hidden"
              >
                <IconCalendarPlus size={21} stroke={1.9} />
              </Link>
            </>
          )}

          {signedIn ? (
            <AccountMenu />
          ) : (
            <Link
              to="/login"
              className="inline-flex h-[38px] shrink-0 items-center rounded-full bg-brand-500 px-4 font-display text-[13px] font-semibold text-white no-underline transition-colors hover:bg-brand-600"
            >
              Sign in
            </Link>
          )}
        </div>
      </div>

    </header>
  );
}

export default AppHeader;
