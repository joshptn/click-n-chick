import { useContext, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconShieldLock } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { deviceHeader } from "../../lib/deviceId";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import { CHANNELS } from "../../lib/verificationChannels";
import toast from "../../components/app/Toast";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

/** The code was sent as the challenge was issued, so the first resend waits the full cooldown. */
const RESEND_COOLDOWN_SECONDS = 60;

function TwoFactorChallenge() {
  const nav = useNavigate();
  const location = useLocation();
  const { adoptSession } = useContext(AuthContext);

  const { challengeToken, channel, identifier, from } = location.state ?? {};

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [cooldown, setCooldown] = useState(RESEND_COOLDOWN_SECONDS);
  const codeRef = useRef(null);

  useEffect(() => {
    if (!challengeToken) {
      nav("/login", { replace: true });
    }
  }, [challengeToken, nav]);

  useEffect(() => {
    if (cooldown <= 0) return undefined;

    const timer = window.setInterval(() => {
      setCooldown((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [cooldown]);

  const url = import.meta.env.VITE_API_URL;

  const expired = () => {
    toast.warning("This sign-in attempt has expired. Please sign in again.", "Start again");
    nav("/login", { replace: true });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const response = await fetch(`${url}/api/2fa/challenge`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...deviceHeader() },
        body: JSON.stringify({ challenge_token: challengeToken, code: e.target.code.value.trim() }),
        credentials: "include",
      });

      const data = await response.json();

      if (!response.ok) {
        if (data.reason === "challenge_expired") {
          expired();
          return;
        }

        throw new Error(data.message || "That code is not correct.");
      }

      adoptSession(data);
      nav(from ?? ROLE_DESTINATIONS[data?.user?.role] ?? "/home", { replace: true });
    } catch (err) {
      setError(err.message);
      if (codeRef.current) codeRef.current.value = "";
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async () => {
    setError("");

    try {
      const response = await fetch(`${url}/api/2fa/resend`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...deviceHeader() },
        body: JSON.stringify({ challenge_token: challengeToken }),
        credentials: "include",
      });

      const data = await response.json();

      if (data.reason === "challenge_expired") {
        expired();
        return;
      }

      setCooldown(data.resend_available_in ?? RESEND_COOLDOWN_SECONDS);

      if (!response.ok) {
        toast.warning(data.message || "Please wait before requesting another code.", "Hold on");
        return;
      }

      toast.info(data.message, "Code sent");
    } catch {
      toast.error("We could not send a new code. Please try again.");
    }
  };

  const sentTo = channel === CHANNELS.EMAIL ? "email address" : "phone";

  return (
    <AuthLayout
      eyebrow="Two-step verification for"
      heading="Enter your code"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Not you?{" "}
          <Link to="/login" className="font-bold text-brand-600 no-underline hover:underline">
            Back to sign in
          </Link>
        </p>
      }
    >
      {error && (
        <div
          role="alert"
          className="mb-5 flex items-start gap-2.5 rounded-[12px] border border-[#ffd7d5] bg-[#fff1f1] px-4 py-3"
        >
          <IconAlertCircle size={19} stroke={2.2} className="mt-px shrink-0 text-[#e5322d]" />

          <div className="min-w-0">
            <p className="font-display text-[13.5px] font-semibold text-[#e5322d]">Verification failed</p>
            <p className="font-display text-[13px] leading-snug text-[#e5322d]">{error}</p>
          </div>
        </div>
      )}

      <p className="mb-5 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
        We sent a 6-digit code to your {sentTo}
        {identifier ? (
          <>
            {" "}
            <span className="font-semibold text-[#33302c]">{identifier}</span>
          </>
        ) : null}
        . Enter it to finish signing in.
      </p>

      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="Verification code" required>
          {(id) => (
            <Input
              ref={codeRef}
              id={id}
              type="text"
              name="code"
              icon={IconShieldLock}
              placeholder="123456"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              pattern="\d{6}"
              autoFocus
              onInput={(e) => {
                e.target.value = e.target.value.replace(/\D/g, "");
              }}
              required
              disabled={loading}
            />
          )}
        </Field>

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Verifying..." className="mt-1">
          Continue
        </Button>

        <button
          type="button"
          onClick={handleResend}
          disabled={cooldown > 0 || loading}
          className="mx-auto bg-transparent font-display text-[12.5px] font-bold text-brand-600 transition-opacity hover:underline disabled:cursor-not-allowed disabled:text-[#a39f9b] disabled:no-underline"
        >
          {cooldown > 0 ? `Resend code in ${cooldown}s` : "Resend code"}
        </button>
      </form>
    </AuthLayout>
  );
}

export default TwoFactorChallenge;
