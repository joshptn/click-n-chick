import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconBellOff } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import ScreenTitle from "../../components/phone/ScreenTitle";
import TabScreen from "../../components/phone/TabScreen";
import { useNotifications } from "../../context/useNotifications";
import { usePhoneLayout } from "../../hooks/usePhoneLayout";
import { lookFor } from "../../lib/notificationLooks";
import { NOTIFICATION_TABS, inTab, notificationAgo, notificationTarget } from "../../lib/notifications";
import { formatDay } from "../../lib/orders";

const EMPTY = {
  all: {
    title: "No Notifications Yet",
    body: "You're all caught up! When you have new orders or updates, they'll appear here.",
  },
  updates: {
    title: "No updates yet",
    body: "News from the store, like price changes and delivery rates, will show here.",
  },
  security: {
    title: "No security alerts",
    body: "We'll tell you here when your account signs in on a new device.",
  },
};

/**
 * Notification, the phone's Notif tab (the app's design, 2026-11-26): All,
 * Updates and Security, grouped by day; opening a notice marks it read and
 * goes where it points. An unread notice is light blue with an orange dot, as
 * in the bell's panel. On a wider screen it is the same list under the
 * website's header (the bell's panel is the usual way in there).
 */
function Notifications() {
  const phone = usePhoneLayout();
  const navigate = useNavigate();
  const { items, unreadCount, isLoading, isError, refetch, markAllRead, markRead, isMarking } = useNotifications();
  const [chip, setChip] = useState("all");

  // "8 min ago" moves on by itself.
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60 * 1000);

    return () => clearInterval(timer);
  }, []);

  const shown = useMemo(() => items.filter((item) => inTab(item, chip)), [items, chip]);

  const groups = useMemo(() => {
    const result = [];

    shown.forEach((item) => {
      const day = formatDay(item.created_at);
      const last = result[result.length - 1];

      if (last && last.day === day) last.items.push(item);
      else result.push({ day, items: [item] });
    });

    return result;
  }, [shown]);

  const open = (item) => {
    if (!item.is_read) markRead(item.id);

    const target = notificationTarget(item);

    if (target) navigate(target);
  };

  const empty = !isLoading && !isError && shown.length === 0;

  const top = (
    <div className="sticky z-30 bg-[#fffaf6]" style={{ top: phone ? 0 : 68 }}>
      <ScreenTitle
        title="Notification"
        right={
          <button
            type="button"
            onClick={markAllRead}
            disabled={unreadCount === 0 || isMarking}
            data-testid="mark-all-read"
            className="bg-transparent py-1 text-[#111827] disabled:opacity-40"
          >
            <span className="font-display text-[12px] font-medium">{isMarking ? "Marking…" : "Mark all as read"}</span>
          </button>
        }
      />

      <div role="tablist" aria-label="Notification types" className="flex gap-2 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {NOTIFICATION_TABS.map((entry) => {
          const selected = entry.key === chip;

          return (
            <button
              key={entry.key}
              type="button"
              role="tab"
              aria-selected={selected}
              onClick={() => setChip(entry.key)}
              data-testid={`chip-${entry.key}`}
              className={`inline-flex h-[30px] shrink-0 items-center rounded-[15px] border border-solid px-4 ${
                selected ? "border-[#ff8904] bg-[#ff8904] text-white" : "border-[#e5e7eb] bg-white text-[#4b5563]"
              }`}
            >
              <span className="font-display text-[12.5px] font-medium">{entry.label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );

  const list = (
    <div className={`flex flex-col gap-3 px-4 pt-1.5 ${empty ? "min-h-[60dvh] justify-center" : ""}`}>
      {isLoading &&
        [0, 1, 2].map((row) => <div key={row} aria-hidden="true" className="h-24 animate-pulse rounded-[16px] bg-[#f0e9df]" />)}

      {isError && !isLoading && (
        <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
          <p className="m-0 font-display text-[15px] font-semibold text-[#e5322d]">Your notifications could not be loaded</p>
          <button
            type="button"
            onClick={() => refetch()}
            className="rounded-[10px] bg-[#ff8904] px-5 py-2 text-white"
          >
            <span className="font-display text-[13px] font-semibold">Try again</span>
          </button>
        </div>
      )}

      {empty && (
        <div className="flex flex-col items-center gap-2 px-6 pb-16 text-center">
          <span aria-hidden="true" className="mb-3.5 grid h-[120px] w-[120px] place-items-center rounded-full bg-[#fff1e0] text-[#ff8904]">
            <IconBellOff size={44} stroke={1.6} />
          </span>
          <p className="m-0 font-display text-[18px] font-semibold text-[#111827]">{EMPTY[chip].title}</p>
          <p className="m-0 max-w-[320px] font-display text-[13.5px] text-[#6f6b68]">{EMPTY[chip].body}</p>
          <Link
            to="/home"
            className="mt-3.5 rounded-full bg-[#ff8904] px-8 py-3 font-display text-[14px] font-semibold text-[#111827] no-underline shadow-[0_4px_8px_rgba(255,137,4,0.35)]"
          >
            Explore Menu
          </Link>
        </div>
      )}

      {groups.map((group) => (
        <section key={group.day} aria-label={group.day} className="flex flex-col gap-3">
          <h2 className="m-0 mt-1 font-display text-[11px] font-semibold uppercase tracking-[1px] text-[#a39f9b]">{group.day}</h2>

          {group.items.map((item) => (
            <NotificationCard key={item.id} item={item} now={now} onOpen={open} />
          ))}
        </section>
      ))}
    </div>
  );

  if (phone) {
    return (
      <TabScreen className="min-h-dvh bg-[#fffaf6]">
        {top}
        {list}
      </TabScreen>
    );
  }

  return (
    <div className="min-h-dvh bg-[#fffaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[680px] pb-10">
        {top}
        {list}
      </main>
    </div>
  );
}

/** One notice: the coloured icon, the title and message, how long ago, an orange dot until read. */
function NotificationCard({ item, now, onOpen }) {
  const { Icon, color, tint } = lookFor(item);
  const unread = !item.is_read;

  return (
    <button
      type="button"
      onClick={() => onOpen(item)}
      data-testid={`notification-${item.id}`}
      data-unread={unread}
      className={`flex w-full items-start gap-3 rounded-[16px] p-3.5 text-left shadow-[0_2px_8px_rgba(65,33,17,0.08)] transition-opacity active:opacity-85 ${
        unread ? "bg-[#eef4ff]" : "bg-white"
      }`}
    >
      <span aria-hidden="true" className="grid h-11 w-11 shrink-0 place-items-center rounded-full" style={{ backgroundColor: tint, color }}>
        <Icon size={22} stroke={2} />
      </span>

      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="font-display text-[14.5px] font-semibold text-[#111827]">
          {unread && <span className="sr-only">Unread: </span>}
          {item.title}
        </span>
        {item.body && <span className="font-display text-[12.5px] leading-snug text-[#6f6b68]">{item.body}</span>}
        <span className={`font-display text-[11.5px] font-medium ${unread ? "text-[#2f6fd6]" : "text-[#4b5563]"}`}>
          {notificationAgo(item.created_at, now)}
        </span>
      </span>

      {unread && <span aria-hidden="true" className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[#ff8904]" />}
    </button>
  );
}

export default Notifications;
