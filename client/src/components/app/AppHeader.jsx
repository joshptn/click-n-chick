import { useContext, useEffect, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { Indicator, Menu, Modal } from "@mantine/core";
import {
  IconBell,
  IconCalendarPlus,
  IconLogout,
  IconReceipt,
  IconSearch,
  IconShieldLock,
  IconShoppingBag,
  IconUser,
  IconX,
} from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import Button from "../ui/Button";
import LogoIcon from "../../assets/logo-icon.png";
import { CART_MODE } from "../../lib/cartModes";
import { ROLES } from "../../lib/roles";
import { formatStoreTime, useStoreStatus } from "../../lib/store";
import { useCart } from "../../context/useCart";
import { useNotifications } from "../../context/useNotifications";
import { useRealtime } from "../../context/useRealtime";
import { notificationTarget } from "../../lib/notifications";

function AppHeader({
  search,
  onSearchChange,
  searchPlaceholder = "What do you want to eat today...",
  onOpenCart,
  cartMode = CART_MODE.IMMEDIATE,
}) {
  const nav = useNavigate();
  const { pathname } = useLocation();
  const { user, token, logOut } = useContext(AuthContext);
  const signedIn = Boolean(token);
  const { item_count: itemCount } = useCart(cartMode);
  const { isOpen, status: storeStatus } = useStoreStatus();
  const { isConnected, isConfigured, isReady } = useRealtime();
  const {
    items: notifications,
    unreadCount,
    markAllRead,
    isMarking,
  } = useNotifications();

  const [notificationsOpen, setNotificationsOpen] = useState(false);

  const showSearch = typeof onSearchChange === "function";

  const canOrderInAdvance =
    user?.role === ROLES.CUSTOMER && !pathname.startsWith("/advance-order");

  const displayName = user?.first_name
    ? `${user.first_name} ${user.last_name?.charAt(0) ?? ""}.`.trim()
    : "Guest";

  const initials = `${user?.first_name?.charAt(0) ?? ""}${user?.last_name?.charAt(0) ?? ""}`.toUpperCase() || "G";

  const avatar = user?.avatar ?? null;

  const [avatarBroken, setAvatarBroken] = useState(false);

  useEffect(() => {
    setAvatarBroken(false);
  }, [avatar]);

  const showAvatar = Boolean(avatar) && !avatarBroken;

  const [confirmingSignOut, setConfirmingSignOut] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  const handleSignOut = async () => {
    setSigningOut(true);

    try {
      await logOut();
    } finally {
      setSigningOut(false);
      setConfirmingSignOut(false);
      nav("/login", { replace: true });
    }
  };

  return (
    <header className="sticky top-0 z-40 border-b border-[#f0e9df] bg-white/95 backdrop-blur-md">
      <div className="mx-auto flex h-[68px] w-full max-w-[1440px] items-center gap-3 px-4 sm:gap-5 sm:px-6 lg:px-8">

        <Link
          to="/home"
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

          {signedIn && (
          <Menu
            opened={notificationsOpen}
            onChange={setNotificationsOpen}
            position="bottom-end"
            width={280}
            shadow="md"
            radius="md"
          >
            <Menu.Target>
              <button
                type="button"
                aria-label={`Notifications${unreadCount ? `, ${unreadCount} new` : ""}`}
                data-testid="notification-bell"
                data-unread={unreadCount}
                className="grid h-10 w-10 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#f7f4f0]"
              >
                <Indicator disabled={unreadCount === 0} size={7} color="#ff8b2b" offset={5} processing>
                  <IconBell size={21} stroke={1.9} />
                </Indicator>
              </button>
            </Menu.Target>

            <Menu.Dropdown>
              <Menu.Label>
                <span className="flex items-center justify-between gap-3">
                  Notifications
                  {isConfigured && (
                    <span
                      data-testid="realtime-status"
                      data-connected={isConnected}
                      data-ready={isReady}
                      title={isConnected ? "Live updates on" : "Reconnecting..."}
                      className={`inline-block h-1.5 w-1.5 rounded-full ${isConnected ? "bg-[#2f9e44]" : "bg-[#d9d3cb]"}`}
                    />
                  )}
                </span>
              </Menu.Label>

              {notifications.length === 0 ? (
                <div className="px-3 py-6 text-center">
                  <p className="m-0 font-display text-[13px] text-[#8d8884]">You&rsquo;re all caught up.</p>
                </div>
              ) : (
                <div data-testid="notification-list" className="max-h-[260px] overflow-y-auto">
                  {notifications.map((item, index) => {
                    const target = notificationTarget(item);

                    const content = (
                      <>
                        <p className="m-0 flex items-start gap-1.5 font-display text-[12.5px] font-semibold text-ink">
                          {!item.is_read && (
                            <span
                              aria-hidden="true"
                              className="mt-[5px] h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"
                            />
                          )}
                          {item.title}
                        </p>
                        <p className="m-0 font-display text-[12px] leading-snug text-[#6f6b68]">
                          {item.body}
                        </p>
                      </>
                    );

                    const shell = "block border-b border-[#f5f0e9] px-3 py-2.5 last:border-b-0";

                    return target ? (
                      <Link
                        key={item.id ?? index}
                        to={target}
                        onClick={() => setNotificationsOpen(false)}
                        className={`${shell} no-underline transition-colors hover:bg-[#faf7f3]`}
                      >
                        {content}
                      </Link>
                    ) : (
                      <div key={item.id ?? index} className={shell}>
                        {content}
                      </div>
                    );
                  })}

                  {unreadCount > 0 && (
                    <button
                      type="button"
                      onClick={markAllRead}
                      disabled={isMarking}
                      className="w-full bg-transparent px-3 py-2.5 text-center font-display text-[12px] font-bold text-brand-600 hover:underline disabled:opacity-50"
                    >
                      {isMarking ? "Marking…" : "Mark all as read"}
                    </button>
                  )}
                </div>
              )}
            </Menu.Dropdown>
          </Menu>
          )}

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
          <Menu position="bottom-end" width={210} shadow="md" radius="md">
            <Menu.Target>
              <button
                type="button"
                className="flex items-center gap-2 rounded-full bg-transparent py-1 pl-1 pr-1 transition-colors hover:bg-[#f7f4f0] sm:pr-3"
              >
                {showAvatar ? (
                  <img
                    src={avatar}
                    alt=""
                    draggable={false}
                    onError={() => setAvatarBroken(true)}
                    className="h-9 w-9 shrink-0 select-none rounded-full border border-[#f0e9df] object-cover"
                  />
                ) : (
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 font-display text-[12.5px] font-bold text-white">
                    {initials}
                  </span>
                )}
                <span className="hidden font-display text-[13.5px] font-semibold text-ink sm:inline">
                  {displayName}
                </span>
              </button>
            </Menu.Target>

            <Menu.Dropdown>
              <Menu.Item leftSection={<IconUser size={16} stroke={1.9} />} onClick={() => nav("/account/profile")}>
                My profile
              </Menu.Item>
              <Menu.Item leftSection={<IconReceipt size={16} stroke={1.9} />} onClick={() => nav("/orders")}>
                My orders
              </Menu.Item>
              <Menu.Item
                leftSection={<IconShieldLock size={16} stroke={1.9} />}
                onClick={() => nav("/account/security")}
              >
                Your Security
              </Menu.Item>
              <Menu.Divider />
              <Menu.Item
                color="red"
                leftSection={<IconLogout size={16} stroke={1.9} />}
                onClick={() => setConfirmingSignOut(true)}
              >
                Sign out
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
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

      <Modal
        opened={confirmingSignOut}
        onClose={() => setConfirmingSignOut(false)}
        title="Are you sure you want to log out?"
        centered
        radius="md"
      >
        <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          You will need to sign in again on this device. Your other devices stay signed in.
        </p>

        <div className="mt-5 flex justify-end gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setConfirmingSignOut(false)}
            disabled={signingOut}
          >
            No
          </Button>
          <Button
            variant="secondary"
            size="sm"
            loading={signingOut}
            loadingLabel="Logging out&hellip;"
            onClick={handleSignOut}
          >
            Yes, log out
          </Button>
        </div>
      </Modal>
    </header>
  );
}

export default AppHeader;
