import { useQuery } from "@tanstack/react-query";

import { DEVICES_KEY, accountApi, type Device } from "../../api/account";
import { formatWhen } from "../../lib/time";
import { IconDeviceDesktop, IconDeviceMobile, IconDeviceTablet } from "../icons";

/** The account's devices (GET /api/user/devices), shared by the list and a device's page. */
export function useDevices() {
  return useQuery({
    queryKey: DEVICES_KEY,
    queryFn: ({ signal }) => accountApi.devices(signal),
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });
}

/** A phone, tablet or computer, by the platform the API read from the device. */
export function DeviceIcon({ platform, size, color }: { platform: string | null; size: number; color: string }) {
  switch (platform) {
    case "Android":
    case "iOS":
      return <IconDeviceMobile size={size} color={color} strokeWidth={1.5} />;
    case "iPadOS":
      return <IconDeviceTablet size={size} color={color} strokeWidth={1.5} />;
    default:
      return <IconDeviceDesktop size={size} color={color} strokeWidth={1.5} />;
  }
}

export function deviceName(device: Device): string {
  return device.name ?? "Unknown device";
}

/** "Apalit, Philippines • Yesterday at 12:23 PM". */
export function deviceLine(device: Device): string {
  const place = device.location ?? "Unknown location";
  const when = formatWhen(device.last_seen_at);

  return when ? `${place} • ${when}` : place;
}

/** Acting on another device needs this phone to be trusted; acting on itself never does. */
export function blockedReason(device: Device, currentTrusted: boolean): string | null {
  return !device.is_current && !currentTrusted
    ? "Only a trusted device can manage your other devices. Trust this phone first."
    : null;
}
