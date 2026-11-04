import { useState } from "react";
import { Link } from "react-router-dom";
import { Indicator, Menu } from "@mantine/core";
import { IconBell } from "@tabler/icons-react";

import { useNotifications } from "../../context/useNotifications";
import { useRealtime } from "../../context/useRealtime";
import { notificationTarget } from "../../lib/notifications";

/**
 * The bell and its inbox.
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
  const { isConnected, isConfigured, isReady } = useRealtime();
  const { items, unreadCount, markAllRead, isMarking } = useNotifications();
  const [open, setOpen] = useState(false);

  const label = `Notifications${unreadCount ? `, ${unreadCount} new` : ""}`;

  return (
    <Menu opened={open} onChange={setOpen} position={position} width={300} shadow="md" radius="md">
      <Menu.Target>
        {trigger === "row" ? (
          <button
            type="button"
            aria-label={label}
            data-testid={testId}
            data-unread={unreadCount}
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
            type="button"
            aria-label={label}
            data-testid={testId}
            data-unread={unreadCount}
            className="grid h-10 w-10 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#f7f4f0]"
          >
            <Indicator disabled={unreadCount === 0} size={7} color="#ff8b2b" offset={5} processing>
              <IconBell size={21} stroke={1.9} />
            </Indicator>
          </button>
        )}
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

        {items.length === 0 ? (
          <div className="px-3 py-6 text-center">
            <p className="m-0 font-display text-[13px] text-[#8d8884]">You&rsquo;re all caught up.</p>
          </div>
        ) : (
          <div data-testid="notification-list" className="max-h-[300px] overflow-y-auto">
            {items.map((item, index) => {
              const target = targetFor(item);

              const content = (
                <>
                  <p className="m-0 flex items-start gap-1.5 font-display text-[12.5px] font-semibold text-ink">
                    {!item.is_read && (
                      <span aria-hidden="true" className="mt-[5px] h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" />
                    )}
                    {item.title}
                  </p>
                  <p className="m-0 font-display text-[12px] leading-snug text-[#6f6b68]">{item.body}</p>
                </>
              );

              const shell = "block border-b border-[#f5f0e9] px-3 py-2.5 last:border-b-0";

              return target ? (
                <Link
                  key={item.id ?? index}
                  to={target}
                  onClick={() => setOpen(false)}
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
  );
}

export default NotificationMenu;
