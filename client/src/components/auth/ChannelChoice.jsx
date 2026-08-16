import { useEffect, useState } from "react";
import { IconInfoCircle, IconMail, IconPhone } from "@tabler/icons-react";

import { CHANNELS } from "../../lib/verificationChannels";

const ICONS = {
  [CHANNELS.SMS]: IconPhone,
  [CHANNELS.EMAIL]: IconMail,
};

const HINTS = {
  [CHANNELS.SMS]: "To your mobile number",
  [CHANNELS.EMAIL]: "To your email address",
};

/**
 * Phone/email picker shared by registration, 2FA enrolment and the
 * password-change confirmation.
 *
 * Availability comes from GET /api/verification/channels, never from a
 * constant here - when the SMS provider is sorted out the backend flag flips
 * and the phone option enables itself with no change to this file.
 */
function ChannelChoice({ value, onChange, legend = "How should we send your code?", disabled = false }) {
  const [channels, setChannels] = useState([]);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const url = import.meta.env.VITE_API_URL;

    const load = async () => {
      try {
        const response = await fetch(`${url}/api/verification/channels`, {
          headers: { Accept: "application/json" },
        });
        const data = await response.json();

        if (cancelled) return;

        const list = data.channels ?? [];
        setChannels(list);

        // Never leave the form pointing at something that cannot deliver.
        const current = list.find((c) => c.channel === value);
        if (current && !current.available) {
          const fallback = list.find((c) => c.available);
          if (fallback) onChange(fallback.channel);
        }
      } catch {
        // Backend unreachable: fall back to email only, which is the channel
        // that never depends on an external provider being provisioned.
        if (!cancelled) {
          setChannels([{ channel: CHANNELS.EMAIL, label: "Email", available: true, reason: null }]);
          onChange(CHANNELS.EMAIL);
        }
      } finally {
        if (!cancelled) setLoaded(true);
      }
    };

    load();

    return () => {
      cancelled = true;
    };
    // Runs once: availability is a property of the deployment, not of the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!loaded) {
    return <p className="font-display text-[13px] text-[#8d8884]">Checking available options...</p>;
  }

  const unavailable = channels.filter((c) => !c.available && c.reason);

  return (
    <fieldset className="border-0 p-0" disabled={disabled}>
      <legend className="mb-2 font-display text-[13px] font-semibold text-[#33302c]">{legend}</legend>

      <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
        {channels.map((option) => {
          const Icon = ICONS[option.channel] ?? IconMail;
          const selected = value === option.channel;
          const blocked = !option.available;

          return (
            <label
              key={option.channel}
              title={option.reason ?? undefined}
              className={`flex items-center gap-3 rounded-[12px] border-2 px-3.5 py-3 transition-colors ${blocked ? "cursor-not-allowed border-[#ece7e0] bg-[#f7f4f0] opacity-70" : selected ? "cursor-pointer border-brand-500 bg-[#fff6ee]" : "cursor-pointer border-[#e6ded4] bg-white hover:border-[#d9d3cb]"}`}
            >
              <input
                type="radio"
                name="channel_choice"
                value={option.channel}
                checked={selected}
                disabled={blocked}
                onChange={() => onChange(option.channel)}
                className="sr-only"
              />

              <Icon size={19} stroke={2} aria-hidden="true" className={selected && !blocked ? "shrink-0 text-brand-600" : "shrink-0 text-[#a39f9b]"} />

              <span className="min-w-0">
                <span className="block font-display text-[13.5px] font-semibold text-[#33302c]">{option.label}</span>
                <span className="block font-display text-[12px] text-[#8d8884]">
                  {blocked ? "Unavailable" : HINTS[option.channel]}
                </span>
              </span>
            </label>
          );
        })}
      </div>

      {unavailable.map((option) => (
        <p key={option.channel} className="mt-2 flex items-start gap-1.5 font-display text-[12px] leading-snug text-[#8d8884]">
          <IconInfoCircle size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
          {option.reason}
        </p>
      ))}
    </fieldset>
  );
}

export default ChannelChoice;
