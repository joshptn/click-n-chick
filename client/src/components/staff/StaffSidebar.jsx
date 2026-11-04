import { useContext } from "react";
import { NavLink } from "react-router-dom";
import { Tooltip } from "@mantine/core";
import { useQuery } from "@tanstack/react-query";
import {
  IconArchive,
  IconCalendarTime,
  IconClipboardList,
  IconHourglassHigh,
  IconIdBadge2,
  IconToolsKitchen2,
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

  const pages = [
    { label: "All Orders", icon: IconClipboardList, to: "/admin", end: true },
    { label: "Pending Verification", icon: IconIdBadge2, to: "/admin/verification", count: discounts.data?.total ?? 0 },
  ];

  // Built next, in sidebar order. Shown now so the navigation does not shift
  // under the agent as each page lands, with the counts already live.
  const upcoming = [
    { label: "Advance Order Request", icon: IconCalendarTime, count: advance.data?.summary?.awaiting_decision ?? 0 },
    { label: "Pending Order", icon: IconHourglassHigh },
    { label: "Product Availability", icon: IconToolsKitchen2 },
    { label: "Order Records", icon: IconArchive },
  ];

  const roleLabel = user?.role === ROLES.SUPER_ADMIN ? "Store Manager" : "Store Agent";

  return (
    <div className="flex h-full flex-col bg-white">
      <div className="flex items-center gap-2 px-4 pb-5 pt-5">
        <img src={LogoIcon} alt="" draggable={false} className="h-8 w-8 select-none object-contain" />
        <div className="leading-tight">
          <p className="m-0 font-logo text-[16px] font-bold tracking-[-0.3px] text-brand-700">
            Click <span className="text-ink">n</span> Chick
          </p>
          <p className="m-0 font-display text-[10.5px] text-[#8d8884]">{roleLabel} Portal</p>
        </div>
      </div>

      <nav aria-label="Staff" className="flex flex-col gap-0.5 px-2.5">
        {pages.map((entry) => {
          const Icon = entry.icon;

          return (
            <NavLink
              key={entry.to}
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
        })}

        {upcoming.map((entry) => {
          const Icon = entry.icon;

          return (
            <Tooltip key={entry.label} label="Coming next" position="right" withArrow openDelay={300}>
              <span role="link" aria-disabled="true" className={`${rowClass} cursor-default text-[#a39f9b]`}>
                <Icon size={17} stroke={1.9} className="shrink-0" />
                <span className="flex-1 leading-snug">{entry.label}</span>
                <Badge count={entry.count} />
              </span>
            </Tooltip>
          );
        })}
      </nav>

      <div className="mt-auto flex flex-col gap-1.5 border-t border-[#f3ede4] px-2.5 pb-3 pt-3">
        <StoreToggles />

        <div className="mt-1 flex flex-col gap-0.5 border-t border-[#f3ede4] pt-2">
          <NotificationMenu trigger="row" position="right-end" targetFor={staffNotificationTarget} />
          <AccountMenu trigger="row" position="right-end" showOrders={false} subtitle={roleLabel} />
        </div>
      </div>
    </div>
  );
}

export default StaffSidebar;
