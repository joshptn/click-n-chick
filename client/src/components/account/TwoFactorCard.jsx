import { useContext, useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader, Modal } from "@mantine/core";
import {
  IconAlertTriangle,
  IconArrowLeft,
  IconCheck,
  IconDeviceMobileMessage,
  IconMail,
  IconShieldCheck,
  IconShieldLock,
} from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import Button from "../ui/Button";
import toast from "../app/Toast";
import { CHANNELS, fetchVerificationChannels } from "../../lib/verificationChannels";
import { confirmTwoFactor, disableTwoFactor, requestTwoFactorCode } from "../../lib/twoFactor";

/**
 * Enable / manage two-factor authentication (UC-PROF-006, BR-31).
 *
 * The account's real 2FA state lives on the server and arrives on the user
 * object; nothing here keeps its own copy of "is it on". Every mutation hands
 * back a fresh user, which is written straight back into AuthContext, so this
 * card and the rest of the app can never disagree about whether 2FA is live.
 *
 * Enrolment is two steps on purpose - requesting a code changes nothing, and
 * only redeeming it turns 2FA on. An abandoned enrolment therefore leaves the
 * account exactly as it was.
 */

const CHANNEL_META = {
  [CHANNELS.EMAIL]: {
    label: "Email",
    icon: IconMail,
    describe: "We email you a 6-digit code each time you sign in.",
    noun: "email address",
  },
  [CHANNELS.SMS]: {
    label: "Text message",
    icon: IconDeviceMobileMessage,
    describe: "We text you a 6-digit code each time you sign in.",
    noun: "phone",
  },
};

/**
 * Counts down from `seconds`, restarting whenever `restartKey` changes.
 *
 * The key is load-bearing. Every send comes back with the same 60-second
 * cooldown, so keying the effect on the duration alone means a resend never
 * restarts the timer - 60 === 60, the effect does not re-run, and the button
 * re-enables early against a server that is still refusing.
 */
function useCountdown(seconds, restartKey) {
  const [remaining, setRemaining] = useState(seconds ?? 0);

  useEffect(() => {
    setRemaining(seconds ?? 0);

    if (!seconds) return undefined;

    const id = setInterval(() => {
      setRemaining((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);

    return () => clearInterval(id);
  }, [seconds, restartKey]);

  return remaining;
}

function ChannelOption({ channel, available, reason, selected, disabled, onSelect }) {
  const meta = CHANNEL_META[channel];

  if (!meta) return null;

  const Icon = meta.icon;
  const blocked = !available || disabled;

  return (
    <button
      type="button"
      onClick={() => onSelect(channel)}
      disabled={blocked}
      aria-pressed={selected}
      className={`flex w-full items-start gap-3 rounded-xl border p-3.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
        selected
          ? "border-brand-500 bg-brand-50"
          : "border-[#ece7e0] bg-white hover:border-brand-300"
      }`}
    >
      <span
        aria-hidden="true"
        className={`grid h-9 w-9 shrink-0 place-items-center rounded-full ${
          selected ? "bg-brand-500 text-white" : "bg-[#f4f1ec] text-[#8d8884]"
        }`}
      >
        <Icon size={18} stroke={1.9} />
      </span>

      <span className="min-w-0 flex-1">
        <span className="block font-display text-[13.5px] font-semibold text-ink">{meta.label}</span>
        <span className="block font-display text-[12.5px] leading-snug text-[#8d8884]">
          {/* The server is the authority on deliverability - if SMS has no
              provider configured it says so, and that reason is shown rather
              than a dead option with no explanation. */}
          {available ? meta.describe : reason ?? "Not available right now."}
        </span>
      </span>

      {selected && (
        <IconCheck size={17} stroke={2.4} className="mt-0.5 shrink-0 text-brand-600" aria-hidden="true" />
      )}
    </button>
  );
}

function TwoFactorCard() {
  const { user, setUser } = useContext(AuthContext);

  const isEnabled = Boolean(user?.two_factor_enabled);
  const activeChannel = user?.two_factor_channel ?? null;

  // "idle" -> choosing / at rest. "code" -> a code is outstanding.
  const [step, setStep] = useState("idle");
  const [selectedChannel, setSelectedChannel] = useState(null);
  const [pending, setPending] = useState(null);
  const [code, setCode] = useState("");
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const [disabling, setDisabling] = useState(false);
  const [password, setPassword] = useState("");
  const [passwordError, setPasswordError] = useState(null);
  const [disableBusy, setDisableBusy] = useState(false);

  const codeRef = useRef(null);
  const cooldown = useCountdown(pending?.resend_available_in, pending?.requestedAt);

  const { data: channels, isLoading: channelsLoading } = useQuery({
    queryKey: ["verification-channels"],
    queryFn: fetchVerificationChannels,
    staleTime: 5 * 60 * 1000,
  });

  const resetEnrolment = () => {
    setStep("idle");
    setPending(null);
    setCode("");
    setError(null);
  };

  const handleRequestCode = async (channel, { isResend = false } = {}) => {
    setBusy(true);
    setError(null);

    try {
      const data = await requestTwoFactorCode(channel);

      // requestedAt only exists to restart the resend countdown - see useCountdown.
      setPending({ ...data, channel, requestedAt: Date.now() });
      setStep("code");
      setCode("");

      if (isResend) toast.success("A new code is on its way.");

      // Autofocus only once the field is actually rendered.
      requestAnimationFrame(() => codeRef.current?.focus());
    } catch (err) {
      setError(err?.message ?? "We could not send a code. Try again.");
    } finally {
      setBusy(false);
    }
  };

  const handleConfirm = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const data = await confirmTwoFactor(code);

      // The response carries a fresh user, so the card flips to "on" from the
      // server's answer rather than from an assumption made here.
      if (data?.user) setUser(data.user);

      resetEnrolment();
      toast.success(data?.message ?? "Two-factor authentication is on.");
    } catch (err) {
      // A dead or spent code needs a new one, not another guess at this one.
      if (["expired", "too_many_attempts", "no_code"].includes(err?.payload?.reason)) {
        setError(`${err.message} Request a new code below.`);
        setPending((prev) => (prev ? { ...prev, resend_available_in: 0 } : prev));
      } else {
        setError(err?.message ?? "That code is not correct.");
      }

      setCode("");
      codeRef.current?.focus();
    } finally {
      setBusy(false);
    }
  };

  const closeDisablePrompt = () => {
    setDisabling(false);
    setPassword("");
    setPasswordError(null);
  };

  const handleDisable = async (e) => {
    e.preventDefault();
    setDisableBusy(true);
    setPasswordError(null);

    try {
      const data = await disableTwoFactor(password);

      if (data?.user) setUser(data.user);

      closeDisablePrompt();
      resetEnrolment();
      toast.success(data?.message ?? "Two-factor authentication is off.");
    } catch (err) {
      // A wrong password keeps the dialog open with the message inline -
      // sending it to a toast would close the form they need to correct.
      if (err?.payload?.code === "PASSWORD_REQUIRED") {
        setPasswordError(err.message);
        setPassword("");
        return;
      }

      closeDisablePrompt();
      toast.error(err?.message ?? "Could not turn two-factor authentication off.");
    } finally {
      setDisableBusy(false);
    }
  };

  const activeMeta = activeChannel ? CHANNEL_META[activeChannel] : null;
  const pendingMeta = pending?.channel ? CHANNEL_META[pending.channel] : null;

  return (
    <section className="mb-6 overflow-hidden rounded-2xl border border-[#f0e9df] bg-white">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[#f0e9df] px-5 py-4">
        <div className="flex min-w-0 items-start gap-3">
          <span
            aria-hidden="true"
            className={`grid h-11 w-11 shrink-0 place-items-center rounded-full ${
              isEnabled ? "bg-[#e9f8ee] text-[#2f9e44]" : "bg-[#f4f1ec] text-[#a39f9b]"
            }`}
          >
            <IconShieldLock size={21} stroke={1.9} />
          </span>

          <div className="min-w-0">
            <h2 className="m-0 flex flex-wrap items-center gap-2 font-display text-[15px] font-bold text-ink">
              Two-step verification
              {isEnabled && (
                <span className="inline-flex items-center gap-1 rounded-full bg-[#e9f8ee] px-2.5 py-0.5 font-display text-[11px] font-bold text-[#2f9e44]">
                  <IconShieldCheck size={12} stroke={2.4} aria-hidden="true" />
                  On
                </span>
              )}
            </h2>

            <p className="m-0 mt-1 font-display text-[12.5px] leading-relaxed text-[#8d8884]">
              {isEnabled
                ? `Codes go to your ${activeMeta?.noun ?? "chosen channel"} every time you sign in.`
                : "Add a second check at sign-in, so your password alone is not enough to get in."}
            </p>
          </div>
        </div>

        {isEnabled && (
          <Button variant="outline" size="sm" onClick={() => setDisabling(true)}>
            Turn off
          </Button>
        )}
      </div>

      <div className="px-5 py-4">
        {isEnabled ? (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
              Want your codes somewhere else? Pick a channel below and confirm the code we send.
            </p>
          </div>
        ) : null}

        {/* Choosing a channel. Shown both for first enrolment and for moving
            an existing enrolment to the other channel. */}
        {step === "idle" && (
          <>
            {channelsLoading ? (
              <div className="flex items-center gap-2.5 py-3">
                <Loader size="xs" color="#ff8b2b" />
                <span className="font-display text-[13px] text-[#8d8884]">Checking what we can send&hellip;</span>
              </div>
            ) : (
              <div className="mt-3 grid gap-2.5 sm:grid-cols-2">
                {(channels ?? []).map((entry) => (
                  <ChannelOption
                    key={entry.channel}
                    channel={entry.channel}
                    available={entry.available}
                    reason={entry.reason}
                    selected={selectedChannel === entry.channel}
                    disabled={busy}
                    onSelect={setSelectedChannel}
                  />
                ))}
              </div>
            )}

            {error && (
              <p role="alert" className="m-0 mt-3 font-display text-[12.5px] text-[#e5322d]">
                {error}
              </p>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button
                size="sm"
                disabled={!selectedChannel || busy}
                loading={busy}
                loadingLabel="Sending&hellip;"
                onClick={() => handleRequestCode(selectedChannel)}
              >
                Send me a code
              </Button>

              {isEnabled && selectedChannel === activeChannel && (
                <span className="font-display text-[12.5px] text-[#8d8884]">
                  This is already where your codes go.
                </span>
              )}
            </div>
          </>
        )}

        {/* Redeeming the code. Until this succeeds nothing has changed. */}
        {step === "code" && (
          <form onSubmit={handleConfirm} className="mt-1">
            <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
              We sent a 6-digit code to your {pendingMeta?.noun ?? "chosen channel"}
              {pending?.identifier ? (
                <>
                  {" "}
                  <span className="font-semibold text-ink">{pending.identifier}</span>
                </>
              ) : null}
              . It expires in {pending?.expires_in_minutes ?? 10} minutes.
            </p>

            <label
              htmlFor="two-factor-code"
              className="mt-4 block font-display text-[12.5px] font-semibold text-ink"
            >
              Verification code
            </label>

            <input
              ref={codeRef}
              id="two-factor-code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => {
                setCode(e.target.value.replace(/\D/g, ""));
                setError(null);
              }}
              placeholder="123456"
              aria-invalid={error ? "true" : undefined}
              aria-describedby={error ? "two-factor-code-error" : undefined}
              disabled={busy}
              className={`mt-1.5 h-[46px] w-full max-w-[200px] rounded-[10px] border bg-white px-3.5 font-display text-[16px] tracking-[3px] text-ink outline-none transition-colors focus:border-brand-400 ${
                error ? "border-[#e5322d]" : "border-[#ece7e0]"
              }`}
            />

            {error && (
              <p
                id="two-factor-code-error"
                role="alert"
                className="m-0 mt-1.5 font-display text-[12.5px] text-[#e5322d]"
              >
                {error}
              </p>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-2">
              <Button
                type="submit"
                size="sm"
                disabled={code.length < 6 || busy}
                loading={busy}
                loadingLabel="Confirming&hellip;"
              >
                Confirm and turn on
              </Button>

              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={busy || cooldown > 0}
                onClick={() => handleRequestCode(pending.channel, { isResend: true })}
              >
                {cooldown > 0 ? `Resend in ${cooldown}s` : "Resend code"}
              </Button>

              <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={resetEnrolment}>
                <IconArrowLeft size={15} stroke={1.9} aria-hidden="true" />
                Back
              </Button>
            </div>
          </form>
        )}
      </div>

      {/* Turning 2FA off is a security downgrade, so it re-checks the account
          password. Deliberately NOT a code from the 2FA channel: that would
          strand anyone who lost the phone they enrolled. */}
      <Modal
        opened={disabling}
        onClose={closeDisablePrompt}
        title="Turn off two-step verification?"
        centered
        radius="md"
      >
        <div className="flex items-start gap-2.5 rounded-xl border border-[#ffe3bf] bg-[#fff8ef] px-3.5 py-3">
          <IconAlertTriangle size={18} stroke={1.9} className="mt-0.5 shrink-0 text-[#e8890c]" aria-hidden="true" />
          <p className="m-0 font-display text-[12.5px] leading-relaxed text-[#7a5620]">
            Your password will be the only thing protecting your account. Anyone who learns it can sign in.
          </p>
        </div>

        <form onSubmit={handleDisable}>
          <label
            htmlFor="disable-2fa-password"
            className="mt-4 block font-display text-[12.5px] font-semibold text-ink"
          >
            Account password
          </label>

          <input
            id="disable-2fa-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value);
              setPasswordError(null);
            }}
            aria-invalid={passwordError ? "true" : undefined}
            aria-describedby={passwordError ? "disable-2fa-password-error" : undefined}
            className={`mt-1.5 h-[46px] w-full rounded-[10px] border bg-white px-3.5 font-display text-[14px] text-ink outline-none transition-colors focus:border-brand-400 ${
              passwordError ? "border-[#e5322d]" : "border-[#ece7e0]"
            }`}
          />

          {passwordError && (
            <p
              id="disable-2fa-password-error"
              role="alert"
              className="m-0 mt-1.5 font-display text-[12.5px] text-[#e5322d]"
            >
              {passwordError}
            </p>
          )}

          <div className="mt-5 flex justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={closeDisablePrompt} disabled={disableBusy}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="secondary"
              size="sm"
              disabled={password.length === 0}
              loading={disableBusy}
              loadingLabel="Turning off&hellip;"
            >
              Turn it off
            </Button>
          </div>
        </form>
      </Modal>
    </section>
  );
}

export default TwoFactorCard;
