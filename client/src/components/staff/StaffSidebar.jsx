import { useContext, useEffect } from "react";
import { NavLink } from "react-router-dom";
import { Tooltip } from "@mantine/core";
import { useQuery } from "@tanstack/react-query";
import {
  IconArchive,
  IconBuildingStore,
  IconCalendarTime,
  IconChartLine,
  IconClipboardList,
  IconHourglassHigh,
  IconIdBadge2,
  IconLayoutDashboard,
  IconToolsKitchen2,
  IconUsers,
} from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AccountMenu from "../app/AccountMenu";
import NotificationMenu from "../app/NotificationMenu";
import StoreToggles from "./StoreToggles";
import LogoIcon from "../../assets/logo-icon.png";
import { ROLES } from "../../lib/roles";
import {
  ADVANCE_REQUESTS_KEY,
  DISCOUNT_CLAIMS_KEY,
  fetchAdvanceRequests,
  fetchPendingDiscountClaims,
  staffNotificationTarget,
} from "../../lib/agent";
import { pages, preloadWhenIdle } from "../../lib/pages";

// The pages each role's sidebar links to. Staff move between them all day, so
// their code is fetched once the first staff page has settled.
const SIDEBAR_PAGES = {
  manager: [pages.SuperAdminDashboard, pages.MenuCatalog, pages.AdminDashboard, pages.AdvanceOrders, pages.DiscountRequests, pages.PendingOrders, pages.DailySalesReport, pages.OrderRecords, pages.UserManagement],
  agent: [pages.AdminDashboard, pages.AdvanceOrders, pages.DiscountRequests, pages.PendingOrders, pages.ProductAvailability, pages.OrderRecords],
};

function Badge({ count, inverted = false }) {
  if (!count) return null;

  return (
    <span
      className={`grid h-[18px] min-w-[18px] place-items-center rounded-full px-1.5 font-display text-[10px] font-bold ${
        inverted ? "bg-white text-brand-600" : "bg-brand-500 text-white"
      }`}
    >
      {count > 99 ? "99+" : count}
    </span>
  );
}

const rowClass =
  "flex w-full items-center gap-2.5 rounded-[9px] px-3 py-2 font-display text-[12.5px] font-medium no-underline transition-colors";

function NavRow({ entry, onNavigate }) {
  const Icon = entry.icon;

  if (!entry.to) {
    return (
      <Tooltip label="Coming next" position="right" withArrow openDelay={300}>
        <span role="link" aria-disabled="true" className={`${rowClass} cursor-default text-[#a39f9b]`}>
          <Icon size={17} stroke={1.9} className="shrink-0" />
          <span className="flex-1 leading-snug">{entry.label}</span>
          <Badge count={entry.count} />
        </span>
      </Tooltip>
    );
  }

  return (
    <NavLink
      to={entry.to}
      end={entry.end}
      onClick={onNavigate}
      className={({ isActive }) =>
        `${rowClass} ${
          isActive
            ? "bg-brand-500 font-semibold text-white shadow-[0_6px_16px_-6px_rgba(255,139,43,0.6)]"
            : "text-[#6f6b68] hover:bg-[#faf6f0] hover:text-ink"
        }`
      }
    >
      {({ isActive }) => (
        <>
          <Icon size={17} stroke={1.9} className="shrink-0" />
          <span className="flex-1 leading-snug">{entry.label}</span>
          <Badge count={entry.count} inverted={isActive} />
        </>
      )}
    </NavLink>
  );
}

function StaffSidebar({ onNavigate }) {
  const { user } = useContext(AuthContext);

  const advance = useQuery({
    queryKey: ADVANCE_REQUESTS_KEY,
    queryFn: ({ signal }) => fetchAdvanceRequests({ signal }),
    staleTime: 30 * 1000,
    refetchInterval: 60 * 1000,
  });

  const discounts = useQuery({
    queryKey: DISCOUNT_CLAIMS_KEY,
    queryFn: ({ signal }) => fetchPendingDiscountClaims({ signal }),
    staleTime: 30 * 1000,
    refetchInterval: 60 * 1000,
  });

  const manager = user?.role === ROLES.SUPER_ADMIN;

  useEffect(() => preloadWhenIdle(SIDEBAR_PAGES[manager ? "manager" : "agent"]), [manager]);

  const advanceEntry = {
    label: "Advance Orders",
    icon: IconCalendarTime,
    to: "/admin/advance-orders",
    count: advance.data?.summary?.awaiting_decision ?? 0,
  };
  const discountEntry = {
    label: "Discount Requests",
    icon: IconIdBadge2,
    to: "/admin/discount-requests",
    count: discounts.data?.total ?? 0,
  };

  const allOrders = { label: "All Orders", icon: IconClipboardList, to: "/admin", end: true };
  const orderRecords = { label: "Order Records", icon: IconArchive, to: "/admin/records" };
  const pendingOrders = { label: "Pending Orders", icon: IconHourglassHigh, to: "/admin/pending-orders" };

  const groups = manager
    ? [
        {
          label: "Overview",
          entries: [
            { label: "Dashboard", icon: IconLayoutDashboard, to: "/superadmin", end: true },
            { label: "Daily Sales Report", icon: IconChartLine, to: "/superadmin/reports/daily" },
          ],
        },
        { label: "Operations", entries: [allOrders, advanceEntry, discountEntry, pendingOrders, orderRecords] },
        {
          label: "Administration",
          entries: [
            { label: "Menu Management", icon: IconToolsKitchen2, to: "/superadmin/menu" },
            { label: "User Management", icon: IconUsers, to: "/superadmin/users" },
            { label: "Store Settings", icon: IconBuildingStore, to: "/superadmin/settings" },
          ],
        },
      ]
    : [
        { label: "Operations", entries: [allOrders, advanceEntry, discountEntry, pendingOrders, orderRecords] },
        { label: "Menu", entries: [{ label: "Product Availability", icon: IconToolsKitchen2, to: "/admin/products" }] },
      ];

  const roleLabel = manager ? "Store Manager" : "Store Agent";

  return (
    <div className="flex h-full flex-col bg-white">
      <div className="flex items-center gap-2 px-4 pb-4 pt-5">
        <img src={LogoIcon} alt="" draggable={false} className="h-8 w-8 select-none object-contain" />
        <div className="leading-tight">
          <p className="m-0 font-logo text-[16px] font-bold tracking-[-0.3px] text-brand-700">
            Click <span className="text-ink">n</span> Chick
          </p>
          <p className="m-0 font-display text-[10.5px] text-[#8d8884]">{roleLabel} Portal</p>
        </div>
      </div>

      {/* Scrolls by itself so a short laptop screen never pushes the account row off. */}
      <nav aria-label="Staff" className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-2.5 pb-3">
        {groups.map((group) => (
          <div key={group.label} role="group" aria-label={group.label} className="flex flex-col gap-0.5">
            <p aria-hidden="true" className="m-0 px-3 pb-1 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]">
              {group.label}
            </p>

            {group.entries.map((entry) => (
              <NavRow key={entry.label} entry={entry} onNavigate={onNavigate} />
            ))}
          </div>
        ))}
      </nav>

      <div className="flex shrink-0 flex-col gap-1 border-t border-[#f3ede4] px-2.5 pb-2 pt-2">
        <StoreToggles />

        <div className="mt-1 flex flex-col gap-0.5 border-t border-[#f3ede4] pt-1">
          <NotificationMenu trigger="row" position="right-end" targetFor={staffNotificationTarget} />
          <AccountMenu trigger="row" position="right-end" showOrders={false} subtitle={roleLabel} />
        </div>
      </div>
    </div>
  );
}

export default StaffSidebar;
