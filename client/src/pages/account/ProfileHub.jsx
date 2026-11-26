import { useContext, useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Switch } from "@mantine/core";
import {
  IconBell,
  IconBellOff,
  IconChevronRight,
  IconDevices,
  IconLock,
  IconLogout,
  IconStarFilled,
  IconUserEdit,
} from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import DiscountCard from "../../components/account/DiscountCard";
import ScreenTitle from "../../components/phone/ScreenTitle";
import SignOutModal from "../../components/app/SignOutModal";
import TabScreen from "../../components/phone/TabScreen";
import toast from "../../components/app/Toast";
import { usePushSetting } from "../../hooks/usePushSetting";
import { applyForDiscount, fetchProfile } from "../../lib/profile";
import { PUSH_STATUS } from "../../lib/push";

/** The same cache entry as Account Settings (Profile.jsx), so the two never disagree. */
const QUERY_KEY = ["profile"];

/**
 * Profile, the phone's Profile tab (the app's design, 2026-11-26): who is
 * signed in and their points, discount eligibility, and the way into each
 * account page. Account Settings (Profile.jsx, at /account/profile/edit) holds
 * the details, photo and addresses; Security holds the password, two-step
 * sign-in and devices. The web has no BES Wallet: points are spent at
 * checkout, so the points pill is not a link.
 */
function ProfileHub() {
  const queryClient = useQueryClient();
  const { user: signedInUser } = useContext(AuthContext);
  const [confirmingSignOut, setConfirmingSignOut] = useState(false);
  const [justSubmittedDiscount, setJustSubmittedDiscount] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => fetchProfile({ signal }),
  });

  const discountMutation = useMutation({
    mutationFn: applyForDiscount,
    onSuccess: (payload) => {
      queryClient.setQueryData(QUERY_KEY, (prev) => ({ ...prev, discount: { ...prev?.discount, ...payload } }));
      setJustSubmittedDiscount(true);
    },
    onError: (err) => {
      toast.error(err?.message ?? "We could not submit your application.");
      if (err?.payload?.code) refetch();
    },
  });

  const user = data?.user ?? signedInUser;
  const name = [user?.first_name, user?.last_name].filter(Boolean).join(" ") || "My account";
  const points = Number(data?.loyalty_points ?? 0);

  return (
    <TabScreen className="min-h-dvh bg-[#fffaf6]">
      <ScreenTitle title="Profile" />

      <main className="flex flex-col gap-1.5 px-4 pt-2">
        <div className="flex items-center gap-3.5 pb-3">
          <Avatar user={user} />

          <div className="min-w-0 flex-1">
            <p className="m-0 truncate font-display text-[16px] font-semibold text-[#111827]">{name}</p>
            {user?.email && <p className="m-0 truncate font-display text-[12.5px] text-[#9ca3af]">{user.email}</p>}
          </div>

          <span
            title="Loyalty points, spent at checkout"
            data-testid="points-pill"
            className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-solid border-[#e5e7eb] bg-white px-3 py-1.5"
          >
            <IconStarFilled size={15} className="text-[#f5b301]" aria-hidden="true" />
            <span className="font-display text-[13.5px] font-semibold text-[#111827]">{points.toLocaleString("en-PH")}</span>
            <span className="sr-only">loyalty points</span>
          </span>
        </div>

        {isLoading && <div aria-hidden="true" className="h-[92px] animate-pulse rounded-[16px] bg-[#f0e9df]" />}

        {isError && !data && (
          <button type="button" onClick={() => refetch()} className="rounded-[14px] bg-[#fff1f1] p-3.5 text-left">
            <span className="font-display text-[13px] text-[#e5322d]">Your profile could not be loaded. Tap to try again.</span>
          </button>
        )}

        <DiscountCard
          discount={data?.discount}
          isSubmitting={discountMutation.isPending}
          justSubmitted={justSubmittedDiscount}
          onDismissSubmitted={() => setJustSubmittedDiscount(false)}
          onApply={(input) => discountMutation.mutate(input)}
        />

        <SectionLabel>Account settings</SectionLabel>
        <MenuRow
          to="/account/profile/edit"
          icon={IconUserEdit}
          title="Edit Profile"
          subtitle="Name, phone number, photo and addresses"
          testId="menu-edit-profile"
        />
        <PushRow />

        <SectionLabel>Account security</SectionLabel>
        <MenuRow to="/account/security" icon={IconLock} title="Password" subtitle="Change password, enable 2FA" testId="menu-security" />
        <MenuRow
          to="/account/security#devices"
          icon={IconDevices}
          title="Manage Devices"
          subtitle="Sign out unknown devices"
          testId="menu-devices"
        />

        <button
          type="button"
          onClick={() => setConfirmingSignOut(true)}
          data-testid="sign-out"
          className="mt-[26px] flex min-h-[50px] items-center justify-center gap-2.5 rounded-[16px] border border-solid border-[#fbd5d4] bg-white text-[#e5322d] active:opacity-70"
        >
          <IconLogout size={19} stroke={2} aria-hidden="true" />
          <span className="font-display text-[15px] font-medium">Sign Out</span>
        </button>
      </main>

      <SignOutModal opened={confirmingSignOut} onClose={() => setConfirmingSignOut(false)} />
    </TabScreen>
  );
}

function Avatar({ user }) {
  const [broken, setBroken] = useState(false);
  const initials = `${user?.first_name?.charAt(0) ?? ""}${user?.last_name?.charAt(0) ?? ""}`.toUpperCase() || "?";

  if (user?.avatar && !broken) {
    return (
      <img
        src={user.avatar}
        alt=""
        onError={() => setBroken(true)}
        className="h-[58px] w-[58px] shrink-0 rounded-full border border-solid border-[#f0e9df] object-cover"
      />
    );
  }

  return (
    <span className="grid h-[58px] w-[58px] shrink-0 place-items-center rounded-full bg-brand-500 font-display text-[19px] font-bold text-white">
      {initials}
    </span>
  );
}

function SectionLabel({ children }) {
  return (
    <h2 className="m-0 mb-0.5 ml-1.5 mt-[22px] font-display text-[12.5px] font-semibold uppercase tracking-[0.6px] text-[#9ca3af]">
      {children}
    </h2>
  );
}

/** A row of the lists: a soft orange icon, a title, a hint and a chevron (the app's MenuRow). */
function MenuRow({ to, icon, title, subtitle, testId }) {
  const Glyph = icon;

  return (
    <Link
      to={to}
      data-testid={testId}
      className="flex items-center gap-3.5 rounded-[14px] px-1.5 py-3 no-underline active:bg-[#fff1e0]"
    >
      <span aria-hidden="true" className="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-full bg-[#fff1e0] text-[#ff8904]">
        <Glyph size={20} stroke={1.9} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block font-display text-[15px] font-medium text-[#111827]">{title}</span>
        <span className="block truncate font-display text-[11.5px] text-[#9ca3af]">{subtitle}</span>
      </span>
      <IconChevronRight size={20} stroke={2} aria-hidden="true" className="shrink-0 text-[#6b7280]" />
    </Link>
  );
}

const PUSH_HINT = {
  [PUSH_STATUS.UNCONFIGURED]: "Not available on this website",
  [PUSH_STATUS.UNSUPPORTED]: "This browser cannot receive them",
  [PUSH_STATUS.NEEDS_INSTALL]: "On iPhone: Share, then Add to Home Screen first",
  [PUSH_STATUS.DENIED]: "Blocked in your browser settings",
};

/** "Notifications" on this device: the app's switch row, over the same setting as the bell's "⋯". */
function PushRow() {
  const { status, on, busy, enable, disable } = usePushSetting();
  const available = status === PUSH_STATUS.AVAILABLE;
  const Glyph = on ? IconBell : IconBellOff;
  const hint = available ? (on ? "On for this device" : "Off for this device") : PUSH_HINT[status];

  return (
    <div className="flex items-center gap-3.5 rounded-[14px] px-1.5 py-2.5" data-testid="menu-notifications">
      <span aria-hidden="true" className="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-full bg-[#fff1e0] text-[#ff8904]">
        <Glyph size={20} stroke={1.9} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block font-display text-[15px] font-medium text-[#111827]">Notifications</span>
        <span
          className={`block truncate font-display text-[11.5px] ${status === PUSH_STATUS.DENIED ? "text-[#e5322d]" : "text-[#9ca3af]"}`}
        >
          {hint}
        </span>
      </span>
      <Switch
        checked={available && on}
        disabled={!available || busy}
        onChange={(event) => (event.currentTarget.checked ? enable() : disable())}
        color="#ff8904"
        size="md"
        aria-label="Notifications on this device"
        data-testid="push-switch"
      />
    </div>
  );
}

export default ProfileHub;
