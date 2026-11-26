import { useContext, useEffect, useId, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Indicator, Popover } from "@mantine/core";
import { IconBell, IconBellOff, IconChecks, IconDots } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { useNotifications } from "../../context/useNotifications";
import { useRealtime } from "../../context/useRealtime";
import { lookFor } from "../../lib/notificationLooks";
import {
  NOTIFICATION_TABS,
  inTab,
  notificationAgo,
  notificationKind,
  notificationTarget,
  notificationWhen,
} from "../../lib/notifications";
import { hasPushSettings } from "../../lib/push";
import { ROLES } from "../../lib/roles";
import PushToggle from "./PushToggle";

/**
 * The bell and its inbox (redesigned 2026-11-26): a wide panel with All,
 * Updates and Security tabs like the app's Notification tab, "Mark all as
 * read", and a "⋯" menu holding "notifications on this device".
 *
 * `trigger="icon"` is the round header button; `trigger="row"` is the full-width
 * sidebar entry the staff portal uses. Both carry the test ids the realtime e2e
 * suite waits on.
 */
function NotificationMenu({
  trigger = "icon",
  position = "bottom-end",
  targetFor = notificationTarget,
  testId = "notification-bell",
}) {
  const { user } = useContext(AuthContext);
  const { isConnected, isConfigured, isReady } = useRealtime();
  const { items, unreadCount, isLoading, markAllRead, markRead, isMarking } = useNotifications();
  const [open, setOpen] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [tab, setTab] = useState("all");
  const [now, setNow] = useState(() => Date.now());
  const panelId = useId();

  const staff = Boolean(user?.role) && user.role !== ROLES.CUSTOMER;
  const label = `Notifications${unreadCount ? `, ${unreadCount} new` : ""}`;

  // "8 min ago" moves on by itself while the panel is open.
  useEffect(() => {
    if (!open) return undefined;

    const timer = setInterval(() => setNow(Date.now()), 60 * 1000);

    return () => clearInterval(timer);
  }, [open]);

  const toggle = (next) => {
    if (next) setNow(Date.now());
    else setMoreOpen(false);

    setOpen(next);
  };

  const close = () => toggle(false);

  const unreadIn = useMemo(() => {
    const counts = { all: 0, updates: 0, security: 0 };

    items.forEach((item) => {
      if (item?.is_read) return;

      counts.all += 1;

      const kind = notificationKind(item);
      if (kind in counts) counts[kind] += 1;
    });

    return counts;
  }, [items]);

  const shown = useMemo(() => items.filter((item) => inTab(item, tab)), [items, tab]);

  const triggerProps = {
    type: "button",
    "aria-label": label,
    "data-testid": testId,
    "data-unread": unreadCount,
    onClick: () => toggle(!open),
    // Escape inside the panel is Mantine's; this covers focus left on the bell.
    onKeyDown: (event) => {
      if (event.key === "Escape" && open) close();
    },
  };

  return (
    <Popover
      opened={open}
      onChange={toggle}
      position={position}
      width={380}
      offset={8}
      radius={18}
      trapFocus
      returnFocus
    >
      <Popover.Target>
        {trigger === "row" ? (
          <button
            {...triggerProps}
            className="flex w-full items-center gap-2.5 rounded-[9px] bg-transparent px-3 py-2 text-left font-display font-medium text-[#6f6b68] transition-colors hover:bg-[#faf6f0] hover:text-ink"
          >
            <IconBell size={17} stroke={1.9} className="shrink-0" />
            <span className="flex-1 text-[12.5px]">Notifications</span>
            {unreadCount > 0 && (
              <span className="grid h-[18px] min-w-[18px] place-items-center rounded-full bg-brand-500 px-1.5 text-[10px] font-bold text-white">
                {unreadCount > 99 ? "99+" : unreadCount}
              </span>
            )}
          </button>
        ) : (
          <button
            {...triggerProps}
            className="grid h-10 w-10 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#f7f4f0]"
          >
            <Indicator disabled={unreadCount === 0} size={7} color="#ff8b2b" offset={5} processing>
              <IconBell size={21} stroke={1.9} />
            </Indicator>
          </button>
        )}
      </Popover.Target>

      <Popover.Dropdown
        p={0}
        aria-label="Notifications"
        style={{
          maxWidth: "calc(100vw - 16px)",
          border: "1px solid #f0e9df",
          boxShadow: "0 24px 60px -18px rgba(65, 33, 17, 0.28), 0 2px 8px rgba(65, 33, 17, 0.06)",
        }}
      >
        <div className="flex flex-col gap-3 px-4 pb-2.5 pt-4">
          <div className="flex items-center gap-2">
            <h2 className="m-0 flex min-w-0 items-center gap-2 font-display text-[17px] font-semibold tracking-[-0.2px] text-ink">
              Notifications
              {isConfigured && (
                <span
                  data-testid="realtime-status"
                  data-connected={isConnected}
                  data-ready={isReady}
                  title={isConnected ? "Live updates on" : "Reconnecting..."}
                  className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${isConnected ? "bg-[#2f9e44]" : "bg-[#d9d3cb]"}`}
                />
              )}
            </h2>

            <div className="ml-auto flex shrink-0 items-center gap-0.5">
              <button
                type="button"
                onClick={markAllRead}
                disabled={unreadCount === 0 || isMarking}
                data-testid="mark-all-read"
                className="inline-flex items-center gap-1 rounded-full bg-transparent px-2 py-1 text-brand-600 transition-colors hover:bg-brand-50 disabled:cursor-default disabled:bg-transparent disabled:text-[#c9c3bc]"
              >
                <IconChecks size={14} stroke={2} aria-hidden="true" />
                <span className="font-display text-[12px] font-semibold">
                  {isMarking ? "Marking…" : "Mark all as read"}
                </span>
              </button>

              {hasPushSettings() && (
                <Popover
                  opened={moreOpen}
                  onChange={setMoreOpen}
                  position="bottom-end"
                  width={280}
                  offset={6}
                  radius={14}
                  // Inside the panel, so a click in here is not a click outside it.
                  withinPortal={false}
                  zIndex={10}
                >
                  <Popover.Target>
                    <button
                      type="button"
                      aria-label="More notification options"
                      data-testid="notification-more"
                      onClick={() => setMoreOpen((value) => !value)}
                      className={`grid h-7 w-7 place-items-center rounded-full text-[#6f6b68] transition-colors hover:bg-[#f4efe8] hover:text-ink ${moreOpen ? "bg-[#f4efe8] text-ink" : "bg-transparent"}`}
                    >
                      <IconDots size={16} stroke={2} />
                    </button>
                  </Popover.Target>

                  <Popover.Dropdown
                    p={6}
                    aria-label="Notification options"
                    style={{
                      maxWidth: "calc(100vw - 32px)",
                      border: "1px solid #f0e9df",
                      boxShadow: "0 16px 40px -14px rgba(65, 33, 17, 0.3)",
                    }}
                  >
                    {/* Mounted when opened, so it reads the permission fresh each time. */}
                    <PushToggle />
                  </Popover.Dropdown>
                </Popover>
              )}
            </div>
          </div>

          <div role="tablist" aria-label="Notification types" className="inline-flex max-w-full gap-0.5 self-start rounded-[12px] bg-[#f6f2ec] p-[3px]">
            {NOTIFICATION_TABS.map((entry) => {
              const selected = entry.key === tab;
              const count = unreadIn[entry.key] ?? 0;

              return (
                <button
                  key={entry.key}
                  type="button"
                  role="tab"
                  id={`${panelId}-${entry.key}`}
                  aria-selected={selected}
                  aria-controls={`${panelId}-list`}
                  // Where focus lands on opening, rather than on "Mark all as read".
                  data-autofocus={selected || undefined}
                  data-testid={`notification-tab-${entry.key}`}
                  onClick={() => setTab(entry.key)}
                  className={`flex items-center gap-1.5 rounded-[9px] px-2.5 py-1 transition-colors ${selected ? "bg-white shadow-[0_1px_3px_rgba(65,33,17,0.12)]" : "bg-transparent hover:bg-white/60"}`}
                >
                  <span className={`font-display text-[12px] font-medium ${selected ? "text-brand-600" : "text-[#6f6b68]"}`}>
                    {entry.label}
                  </span>
                  <span
                    aria-label={`${count} unread`}
                    className={`grid h-4 min-w-4 place-items-center rounded-[5px] px-1 font-display text-[10px] font-bold ${selected ? "bg-brand-100 text-brand-700" : "bg-[#e9e3db] text-[#6f6b68]"}`}
                  >
                    {count > 99 ? "99+" : count}
                  </span>
                </button>
              );
            })}
          </div>
        </div>

        <div
          id={`${panelId}-list`}
          role="tabpanel"
          aria-labelledby={`${panelId}-${tab}`}
          data-testid="notification-list"
          // One height whatever the count (the reference leaves white space under
          // a short list), and the scrollbar's room kept so rows never shift.
          className="h-[min(420px,60vh)] overflow-y-auto px-2 pb-2 [scrollbar-gutter:stable]"
        >
          {isLoading && items.length === 0 ? (
            <Skeleton />
          ) : shown.length === 0 ? (
            <Empty tab={tab} staff={staff} />
          ) : (
            <ul className="m-0 flex list-none flex-col gap-1 p-0">
              {shown.map((item, index) => (
                <li key={item.id ?? index}>
                  <NotificationRow
                    item={item}
                    target={targetFor(item)}
                    now={now}
                    onOpen={() => {
                      if (!item.is_read) markRead(item.id);
                    }}
                    onNavigate={close}
                  />
                </li>
              ))}
            </ul>
          )}
        </div>
      </Popover.Dropdown>
    </Popover>
  );
}

/**
 * Picture, title, message and when; on the right how long ago and an orange
 * dot until read. An unread notice also sits on a light blue background, the
 * usual inbox cue (2026-11-26). Opening it marks it read. A notice with
 * nowhere to go is still a button while unread, so it can be marked read on
 * its own.
 */
function NotificationRow({ item, target, now, onOpen, onNavigate }) {
  const { Icon, color, tint } = lookFor(item);
  const unread = !item.is_read;

  const content = (
    <>
      <span
        aria-hidden="true"
        className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-full"
        style={{ backgroundColor: tint, color }}
      >
        <Icon size={17} stroke={1.9} />
      </span>

      <span className="flex min-w-0 flex-1 flex-col gap-px">
        <span className={`font-display text-[13px] leading-snug text-ink ${unread ? "font-semibold" : "font-medium"}`}>
          {unread && <span className="sr-only">Unread: </span>}
          {item.title}
        </span>
        {item.body && (
          <span className="line-clamp-2 font-display text-[12px] leading-snug text-[#6f6b68]">{item.body}</span>
        )}
        <span className={`mt-0.5 font-display text-[11px] ${unread ? "text-[#4a7bd0]" : "text-[#a39f9b]"}`}>
          {notificationWhen(item.created_at)}
        </span>
      </span>

      <span className="flex shrink-0 flex-col items-end gap-1.5 pt-1">
        <span
          aria-hidden="true"
          className={`h-2 w-2 rounded-full ${unread ? "bg-brand-500" : "bg-transparent"}`}
        />
        <span className={`whitespace-nowrap font-display text-[11px] ${unread ? "font-semibold text-[#2f6fd6]" : "text-[#8d8884]"}`}>
          {notificationAgo(item.created_at, now)}
        </span>
      </span>
    </>
  );

  const shell = `flex w-full items-start gap-2.5 rounded-[12px] px-2.5 py-2.5 text-left ${unread ? "bg-[#eef4ff]" : "bg-transparent"}`;
  const interactive = `${shell} cursor-pointer no-underline transition-colors ${unread ? "hover:bg-[#e3edff] focus-visible:bg-[#e3edff]" : "hover:bg-[#faf6f0] focus-visible:bg-[#faf6f0]"}`;

  if (target) {
    return (
      <Link
        to={target}
        data-unread={unread}
        onClick={() => {
          onOpen();
          onNavigate();
        }}
        className={interactive}
      >
        {content}
      </Link>
    );
  }

  if (unread) {
    return (
      <button type="button" data-unread={unread} onClick={onOpen} className={interactive}>
        {content}
      </button>
    );
  }

  return <div className={shell}>{content}</div>;
}

// --- Loading and empty ---------------------------------------------------------

function Skeleton() {
  return (
    <div aria-hidden="true" className="flex flex-col gap-0.5">
      {[0, 1, 2].map((row) => (
        <div key={row} className="flex items-start gap-2.5 px-2.5 py-2.5">
          <span className="h-[34px] w-[34px] shrink-0 animate-pulse rounded-full bg-[#f2ece4]" />
          <span className="flex flex-1 flex-col gap-2 pt-1">
            <span className="h-3 w-3/5 animate-pulse rounded bg-[#f2ece4]" />
            <span className="h-3 w-4/5 animate-pulse rounded bg-[#f5f0e9]" />
          </span>
        </div>
      ))}
    </div>
  );
}

const EMPTY = {
  all: {
    title: "No notifications yet",
    customer: "You're all caught up! When you have new orders or updates, they'll appear here.",
    staff: "You're all caught up! New orders and requests will appear here.",
  },
  updates: {
    title: "No updates yet",
    customer: "News from the store, like price changes and delivery rates, will show here.",
    staff: "Senior/PWD applications to review and poster reminders will show here.",
  },
  security: {
    title: "No security alerts",
    customer: "We'll tell you here when your account signs in on a new device.",
    staff: "We'll tell you here when your account signs in on a new device.",
  },
};

function Empty({ tab, staff }) {
  const copy = EMPTY[tab] ?? EMPTY.all;

  return (
    <div className="flex h-full flex-col items-center justify-center px-6 pb-6 text-center">
      <span aria-hidden="true" className="mb-2.5 grid h-14 w-14 place-items-center rounded-full bg-[#fff1e0] text-brand-600">
        <IconBellOff size={24} stroke={1.7} />
      </span>
      <p className="m-0 font-display text-[14px] font-semibold text-ink">{copy.title}</p>
      <p className="m-0 mt-1 max-w-[270px] font-display text-[12px] leading-snug text-[#8d8884]">
        {staff ? copy.staff : copy.customer}
      </p>
    </div>
  );
}

export default NotificationMenu;
