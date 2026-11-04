import { useContext, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Menu, Modal } from "@mantine/core";
import { IconChevronUp, IconLogout, IconReceipt, IconShieldLock, IconUser } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import Button from "../ui/Button";

/**
 * The signed-in user's menu and its sign-out confirmation.
 *
 * `trigger="pill"` is the header's avatar and name; `trigger="row"` is the staff
 * sidebar's account row, which also names the role.
 */
function AccountMenu({ trigger = "pill", position = "bottom-end", showOrders = true, subtitle }) {
  const nav = useNavigate();
  const { user, logOut } = useContext(AuthContext);

  const displayName = user?.first_name
    ? `${user.first_name} ${user.last_name?.charAt(0) ?? ""}.`.trim()
    : "Guest";

  const initials =
    `${user?.first_name?.charAt(0) ?? ""}${user?.last_name?.charAt(0) ?? ""}`.toUpperCase() || "G";

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

  const face = showAvatar ? (
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
  );

  return (
    <>
      <Menu position={position} width={210} shadow="md" radius="md">
        <Menu.Target>
          {trigger === "row" ? (
            <button
              type="button"
              aria-label="Account menu"
              className="flex w-full items-center gap-2.5 rounded-[10px] bg-transparent px-2 py-1.5 text-left transition-colors hover:bg-[#faf6f0]"
            >
              {face}
              <span className="min-w-0 flex-1">
                <span className="block truncate font-display text-[12.5px] font-semibold text-ink">
                  {displayName}
                </span>
                {subtitle && (
                  <span className="block truncate font-display text-[10.5px] text-[#a39f9b]">{subtitle}</span>
                )}
              </span>
              <IconChevronUp size={14} stroke={2} className="shrink-0 text-[#a39f9b]" />
            </button>
          ) : (
            <button
              type="button"
              className="flex items-center gap-2 rounded-full bg-transparent py-1 pl-1 pr-1 transition-colors hover:bg-[#f7f4f0] sm:pr-3"
            >
              {face}
              <span className="hidden font-display text-[13.5px] font-semibold text-ink sm:inline">{displayName}</span>
            </button>
          )}
        </Menu.Target>

        <Menu.Dropdown>
          <Menu.Item leftSection={<IconUser size={16} stroke={1.9} />} onClick={() => nav("/account/profile")}>
            My profile
          </Menu.Item>
          {showOrders && (
            <Menu.Item leftSection={<IconReceipt size={16} stroke={1.9} />} onClick={() => nav("/orders")}>
              My orders
            </Menu.Item>
          )}
          <Menu.Item leftSection={<IconShieldLock size={16} stroke={1.9} />} onClick={() => nav("/account/security")}>
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
          <Button variant="ghost" size="sm" onClick={() => setConfirmingSignOut(false)} disabled={signingOut}>
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
    </>
  );
}

export default AccountMenu;
