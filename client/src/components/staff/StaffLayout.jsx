import { Drawer } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";
import { IconMenu2 } from "@tabler/icons-react";

import NotificationMenu from "../app/NotificationMenu";
import StaffSidebar from "./StaffSidebar";
import LogoIcon from "../../assets/logo-icon.png";
import { staffNotificationTarget } from "../../lib/agent";

/**
 * The staff portal's frame: a fixed sidebar on desktop, a drawer behind a top
 * bar below `lg`, where a 232px column would leave the board no room.
 */
function StaffLayout({ children }) {
  const [opened, { open, close }] = useDisclosure(false);

  return (
    <div className="flex min-h-dvh bg-[#faf6f0] font-display text-ink">
      <aside className="sticky top-0 hidden h-dvh w-[212px] shrink-0 border-r border-[#f0e9df] lg:block">
        <StaffSidebar />
      </aside>

      <Drawer
        opened={opened}
        onClose={close}
        size={240}
        padding={0}
        withCloseButton={false}
        aria-label="Staff navigation"
      >
        <div className="h-dvh">
          <StaffSidebar onNavigate={close} />
        </div>
      </Drawer>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="sticky top-0 z-30 flex h-[54px] items-center gap-3 border-b border-[#f0e9df] bg-white/95 px-4 backdrop-blur-md lg:hidden">
          <button
            type="button"
            onClick={open}
            aria-label="Open navigation"
            className="grid h-10 w-10 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#f7f4f0]"
          >
            <IconMenu2 size={22} stroke={1.9} />
          </button>
          <img src={LogoIcon} alt="" draggable={false} className="h-8 w-8 select-none object-contain" />
          <span className="font-logo text-[17px] font-bold tracking-[-0.3px] text-brand-700">
            Click <span className="text-ink">n</span> Chick
          </span>
          <span className="ml-auto">
            <NotificationMenu targetFor={staffNotificationTarget} testId="notification-bell-compact" />
          </span>
        </div>

        {children}
      </div>
    </div>
  );
}

export default StaffLayout;
