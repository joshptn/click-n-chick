import { useContext, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Indicator, Menu, Modal } from "@mantine/core";
import {
  IconBell,
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
import { useCart } from "../../context/useCart";
import { useRealtime } from "../../context/useRealtime";

/**
 * The application header for every signed-in page.
 *
 * Deliberately separate from the landing page's SiteHeader: that one is a
 * marketing bar that scrolls between anchors on a single page, this one is
 * app chrome with search, store status and an account menu. Merging them would
 * mean one component with two disjoint sets of props.
 *
 * `search` is optional and controlled by the page - Home owns the query so it
 * can filter the grid, while other pages simply omit the box.
 */
function AppHeader({
  search,
  onSearchChange,
  searchPlaceholder = "What do you want to eat today...",
  isOpen = true,
  onOpenCart,
}) {
  const nav = useNavigate();
  const { user, logOut } = useContext(AuthContext);
  const { item_count: itemCount } = useCart();
  const { notifications, unreadCount, isConnected, isConfigured, isReady, markAllRead } = useRealtime();

  const [notificationsOpen, setNotificationsOpen] = useState(false);

  const showSearch = typeof onSearchChange === "function";

  const displayName = user?.first_name
    ? `${user.first_name} ${user.last_name?.charAt(0) ?? ""}.`.trim()
    : "Guest";

  const initials = `${user?.first_name?.charAt(0) ?? ""}${user?.last_name?.charAt(0) ?? ""}`.toUpperCase() || "G";

  // Straight off AuthContext, which every avatar mutation writes back to - so
  // a new picture is on the header the moment the upload resolves, without
  // this component fetching anything of its own.
  const avatar = user?.avatar ?? null;

  // A picture that cannot load falls back to the initials rather than leaving
  // a broken image in the header. Reset per URL: each upload is a new
  // Cloudinary address, so a failure on the old one must not stick.
  const [avatarBroken, setAvatarBroken] = useState(false);

  useEffect(() => {
    setAvatarBroken(false);
  }, [avatar]);

  const showAvatar = Boolean(avatar) && !avatarBroken;

  const [confirmingSignOut, setConfirmingSignOut] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  const handleSignOut = async () => {
    // logOut revokes the token server-side before clearing local state, so
    // this awaits rather than navigating straight away.
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
            className={`hidden rounded-full px-3.5 py-1.5 font-display text-[12px] font-bold sm:inline-flex ${isOpen ? "bg-[#e9f8ee] text-[#2f9e44]" : "bg-[#f4f1ec] text-[#8d8884]"}`}
          >
            {isOpen ? "Open" : "Closed"}
          </span>

          {/* Cart trigger. Doubles as the modal opener below xl, where the
              docked panel is not rendered. */}
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
                  {/* Live, not decorative: this is the Reverb connection. */}
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
                  {notifications.map((item, index) => (
                    <div
                      key={item.id ?? index}
                      className="border-b border-[#f5f0e9] px-3 py-2.5 last:border-b-0"
                    >
                      <p className="m-0 font-display text-[12.5px] font-semibold text-ink">{item.title}</p>
                      <p className="m-0 font-display text-[12px] leading-snug text-[#6f6b68]">{item.body}</p>
                    </div>
                  ))}

                  <button
                    type="button"
                    onClick={markAllRead}
                    className="w-full bg-transparent px-3 py-2.5 text-center font-display text-[12px] font-bold text-brand-600 hover:underline"
                  >
                    Clear
                  </button>
                </div>
              )}
            </Menu.Dropdown>
          </Menu>

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
              <Menu.Item leftSection={<IconReceipt size={16} stroke={1.9} />} onClick={() => nav("/home")}>
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
