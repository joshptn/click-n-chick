import { useContext, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader, Modal, Tooltip } from "@mantine/core";
import {
  IconAlertTriangle,
  IconDeviceDesktop,
  IconDeviceMobile,
  IconDeviceTablet,
  IconRefresh,
  IconShieldCheck,
  IconShieldLock,
  IconShieldOff,
} from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import Button from "../../components/ui/Button";
import toast from "../../components/app/Toast";
import { fetchDevices, revokeDevice, setDeviceTrust } from "../../lib/devices";

/**
 * Known devices, trust, and remote sign-out (FR-01.11 / FR-01.13 / UC-AUTH-013).
 *
 * The user thinks in devices; each row's "Sign out" revokes the Sanctum
 * token(s) that device holds, server-side. Nothing here decides ownership or
 * permission - the list only ever contains the caller's own devices, and the
 * server re-checks trust on every write. The disabled states below exist so a
 * button that is guaranteed to fail is not offered, not as the security check.
 */

function deviceIcon(platform) {
  switch (platform) {
    case "iOS":
    case "Android":
      return IconDeviceMobile;
    case "iPadOS":
      return IconDeviceTablet;
    default:
      return IconDeviceDesktop;
  }
}

/** "3 minutes ago" / "on 12 Aug 2026" - relative while it is still meaningful. */
function lastSeen(iso) {
  if (!iso) return "Never";

  const then = new Date(iso);
  const minutes = Math.round((Date.now() - then.getTime()) / 60000);

  if (minutes < 1) return "Just now";
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? "" : "s"} ago`;

  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} hour${hours === 1 ? "" : "s"} ago`;

  const days = Math.round(hours / 24);
  if (days < 7) return `${days} day${days === 1 ? "" : "s"} ago`;

  return then.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
}

/**
 * Wraps a disabled button so the reason is still discoverable.
 *
 * A disabled control with no explanation is the worst of both worlds - the
 * user cannot act and cannot find out why.
 */
function MaybeTooltip({ label, children }) {
  if (!label) return children;

  return (
    <Tooltip label={label} withArrow position="top" multiline w={220}>
      {/* span: Mantine needs a wrapper that still fires events when the
          button inside is disabled. */}
      <span className="inline-flex">{children}</span>
    </Tooltip>
  );
}

function DeviceRow({ device, canActOnOthers, onSignOut, onToggleTrust, pendingAction }) {
  const Icon = deviceIcon(device.platform);

  // Acting on a device other than this one requires this one to be trusted.
  // Acting on yourself never does.
  const blocked = !device.is_current && !canActOnOthers;
  const blockedReason = blocked
    ? "Only a trusted device can manage your other devices. Trust this device first."
    : null;

  const signOutDisabled = !device.is_active || blocked || pendingAction !== null;
  const trustDisabled = blocked || pendingAction !== null;

  return (
    <li className="flex flex-wrap items-center gap-4 border-b border-[#f0e9df] px-5 py-4 last:border-b-0">
      <span
        aria-hidden="true"
        className={`grid h-11 w-11 shrink-0 place-items-center rounded-full ${
          device.is_active ? "bg-brand-50 text-brand-600" : "bg-[#f4f1ec] text-[#a39f9b]"
        }`}
      >
        <Icon size={21} stroke={1.9} />
      </span>

      <div className="min-w-[180px] flex-1">
        <p className="m-0 flex flex-wrap items-center gap-2 font-display text-[14px] font-semibold text-ink">
          {device.name ?? "Unknown device"}

          {device.is_current && (
            <span className="rounded-full bg-[#e9f8ee] px-2.5 py-0.5 font-display text-[11px] font-bold text-[#2f9e44]">
              This device
            </span>
          )}

          {device.is_trusted && (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#f0f7ff] px-2.5 py-0.5 font-display text-[11px] font-bold text-[#1c7ed6]">
              <IconShieldCheck size={12} stroke={2.4} aria-hidden="true" />
              Trusted
            </span>
          )}
        </p>

        <p className="m-0 font-display text-[12.5px] text-[#8d8884]">
          {device.last_ip_address ?? "Unknown IP"} &middot; Last active {lastSeen(device.last_seen_at)}
        </p>
      </div>

      <span
        className={`rounded-full px-3 py-1 font-display text-[11.5px] font-bold ${
          device.is_active ? "bg-[#f0f7ff] text-[#1c7ed6]" : "bg-[#f4f1ec] text-[#8d8884]"
        }`}
      >
        {device.is_active
          ? `${device.active_session_count} active session${device.active_session_count === 1 ? "" : "s"}`
          : "Signed out"}
      </span>

      <div className="flex items-center gap-2">
        <MaybeTooltip label={blockedReason}>
          <Button
            variant="outline"
            size="sm"
            disabled={trustDisabled}
            loading={pendingAction === "trust"}
            onClick={() => onToggleTrust(device)}
          >
            {device.is_trusted ? (
              <>
                <IconShieldOff size={15} stroke={1.9} aria-hidden="true" />
                Untrust
              </>
            ) : (
              <>
                <IconShieldCheck size={15} stroke={1.9} aria-hidden="true" />
                Trust
              </>
            )}
          </Button>
        </MaybeTooltip>

        <MaybeTooltip label={blockedReason}>
          <Button
            variant={device.is_current ? "outline" : "secondary"}
            size="sm"
            // Nothing to revoke on a device with no live session. Note that a
            // TRUSTED device is still signed out normally - trust governs who
            // may act, not what may be acted upon.
            disabled={signOutDisabled}
            loading={pendingAction === "revoke"}
            onClick={() => onSignOut(device)}
          >
            Sign out
          </Button>
        </MaybeTooltip>
      </div>
    </li>
  );
}

function Devices() {
  const nav = useNavigate();
  const queryClient = useQueryClient();
  const { logOut } = useContext(AuthContext);

  const [confirming, setConfirming] = useState(null);

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ["devices"],
    queryFn: ({ signal }) => fetchDevices({ signal }),
  });

  const revokeMutation = useMutation({
    mutationFn: (device) => revokeDevice(device.id),
    onSuccess: (result, device) => {
      setConfirming(null);

      // Revoking the device you are holding destroys the token this tab is
      // using. Clear local state and leave, rather than sitting on a screen
      // whose every later request will 401.
      if (result?.current_device_revoked) {
        toast.success("You have been signed out on this device.");
        logOut({ revokeOnServer: false });
        nav("/login", { replace: true });
        return;
      }

      toast.success(`${device.name ?? "That device"} has been signed out.`);
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: (err) => {
      setConfirming(null);
      toast.error(err?.message ?? "Could not sign that device out.");
    },
  });

  const trustMutation = useMutation({
    mutationFn: (device) => setDeviceTrust(device.id, !device.is_trusted),
    onSuccess: (result) => {
      toast.success(result?.message ?? "Trust updated.");
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: (err) => {
      toast.error(err?.message ?? "Could not update trust for that device.");
    },
  });

  const devices = data?.devices ?? [];
  const canActOnOthers = Boolean(data?.current_device_trusted);

  /** Which action, if any, is in flight for a given row. */
  const pendingActionFor = (device) => {
    if (revokeMutation.isPending && revokeMutation.variables?.id === device.id) return "revoke";
    if (trustMutation.isPending && trustMutation.variables?.id === device.id) return "trust";
    return null;
  };

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[900px] px-4 py-8 sm:px-6 lg:px-8">
        <header className="mb-6">
          <h1 className="m-0 flex items-center gap-2.5 font-display text-[24px] font-bold tracking-[-0.4px] text-ink">
            <IconShieldLock size={24} stroke={1.9} className="text-brand-600" aria-hidden="true" />
            Your devices
          </h1>
          <p className="m-0 mt-1.5 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
            Every device that has signed in to your account. If you do not recognise one, sign it out &mdash;
            it will need your password to get back in.
          </p>
        </header>

        {!canActOnOthers && devices.length > 1 && (
          <div className="mb-5 flex items-start gap-3 rounded-xl border border-[#ffe3bf] bg-[#fff8ef] px-4 py-3.5">
            <IconShieldCheck size={19} stroke={1.9} className="mt-0.5 shrink-0 text-[#e8890c]" aria-hidden="true" />
            <p className="m-0 font-display text-[13px] leading-relaxed text-[#7a5620]">
              <strong className="font-semibold">This device is not trusted yet.</strong> Trust it to manage your
              other devices from here. You can always sign this device out on its own.
            </p>
          </div>
        )}

        <section className="overflow-hidden rounded-2xl border border-[#f0e9df] bg-white">
          <div className="flex items-center justify-between gap-3 border-b border-[#f0e9df] px-5 py-3">
            <h2 className="m-0 font-display text-[13px] font-bold uppercase tracking-[0.5px] text-[#8d8884]">
              {devices.length} device{devices.length === 1 ? "" : "s"}
            </h2>

            <button
              type="button"
              onClick={() => refetch()}
              disabled={isFetching}
              className="flex items-center gap-1.5 bg-transparent font-display text-[12.5px] font-semibold text-brand-600 transition-opacity hover:underline disabled:opacity-50"
            >
              <IconRefresh size={15} stroke={2} aria-hidden="true" />
              Refresh
            </button>
          </div>

          {isLoading ? (
            <div className="grid place-items-center gap-3 px-5 py-14">
              <Loader size="sm" color="#ff8b2b" />
              <p className="m-0 font-display text-[13px] text-[#8d8884]">Loading your devices&hellip;</p>
            </div>
          ) : isError ? (
            <div className="grid place-items-center gap-3 px-5 py-14 text-center">
              <IconAlertTriangle size={26} stroke={1.8} className="text-[#e5322d]" aria-hidden="true" />
              <p className="m-0 font-display text-[13.5px] text-ink">
                {error?.message ?? "We could not load your devices."}
              </p>
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                Try again
              </Button>
            </div>
          ) : devices.length === 0 ? (
            <p className="m-0 px-5 py-14 text-center font-display text-[13.5px] text-[#8d8884]">
              No devices recorded yet.
            </p>
          ) : (
            <ul className="m-0 list-none p-0">
              {devices.map((device) => (
                <DeviceRow
                  key={device.id}
                  device={device}
                  canActOnOthers={canActOnOthers}
                  onSignOut={setConfirming}
                  onToggleTrust={trustMutation.mutate}
                  pendingAction={pendingActionFor(device)}
                />
              ))}
            </ul>
          )}
        </section>
      </main>

      <Modal
        opened={confirming !== null}
        onClose={() => setConfirming(null)}
        title={confirming?.is_current ? "Sign out this device?" : "Sign out that device?"}
        centered
        radius="md"
      >
        <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          {confirming?.is_current ? (
            <>
              This is the device you are using now. You will be signed out immediately and returned to the
              login screen. Your other devices stay signed in.
            </>
          ) : (
            <>
              <strong className="font-semibold text-ink">{confirming?.name}</strong> will be signed out
              straight away and will need your password to sign in again. Your other devices are unaffected.
            </>
          )}
        </p>

        <div className="mt-5 flex justify-end gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setConfirming(null)}
            disabled={revokeMutation.isPending}
          >
            No, cancel
          </Button>
          <Button
            variant="secondary"
            size="sm"
            loading={revokeMutation.isPending}
            loadingLabel="Signing out&hellip;"
            onClick={() => revokeMutation.mutate(confirming)}
          >
            Yes, sign out
          </Button>
        </div>
      </Modal>
    </div>
  );
}

export default Devices;
