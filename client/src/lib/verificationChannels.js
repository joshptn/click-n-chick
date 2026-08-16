// Mirrors App\Services\Verification\Channel on the server.
export const CHANNELS = {
  SMS: "sms",
  EMAIL: "email",
};

// One screen serves both channels; this is the only thing that differs.
export const CHANNEL_COPY = {
  [CHANNELS.SMS]: {
    route: "/verify-phone",
    heading: "Verify your phone",
    eyebrow: "One last step for",
    sentTo: "a 6-digit code to",
    // The request field the API expects the identifier under.
    field: "phone_number",
    autoComplete: "one-time-code",
  },
  [CHANNELS.EMAIL]: {
    route: "/verify-email",
    heading: "Verify your email",
    eyebrow: "One last step for",
    sentTo: "a 6-digit code to",
    field: "email",
    autoComplete: "one-time-code",
  },
};

export function routeForChannel(channel) {
  return CHANNEL_COPY[channel]?.route ?? CHANNEL_COPY[CHANNELS.SMS].route;
}

// Email only - the channel that never depends on an external provider being
// provisioned, so it is the safe assumption when the backend cannot be reached.
const OFFLINE_FALLBACK = [{ channel: CHANNELS.EMAIL, label: "Email", available: true, reason: null }];

export async function fetchVerificationChannels() {
  const url = import.meta.env.VITE_API_URL;

  try {
    const response = await fetch(`${url}/api/verification/channels`, {
      headers: { Accept: "application/json" },
    });
    const data = await response.json();

    return data.channels ?? OFFLINE_FALLBACK;
  } catch {
    return OFFLINE_FALLBACK;
  }
}

export default CHANNELS;
