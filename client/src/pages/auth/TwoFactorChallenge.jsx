import { useContext, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconShieldLock } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import { CHANNELS } from "../../lib/verificationChannels";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

/**
 * Second factor at login.
 *
 * The channel is whatever was fixed when 2FA was enabled - there is no choice
 * here on purpose. The challenge token stands in for the password having
 * already been accepted; it lives in router state only, so a refresh sends the
 * user back to sign in rather than leaving a reusable credential lying around.
 */
function TwoFactorChallenge() {
  const nav = useNavigate();
  const location = useLocation();
  const { adoptSession } = useContext(AuthContext);

  const { challengeToken, channel, identifier } = location.state ?? {};

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const codeRef = useRef(null);

  useEffect(() => {
    if (!challengeToken) {
      nav("/login", { replace: true });
    }
  }, [challengeToken, nav]);

  const url = import.meta.env.VITE_API_URL;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const response = await fetch(`${url}/api/2fa/challenge`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ challenge_token: challengeToken, code: e.target.code.value.trim() }),
        credentials: "include",
      });

      const data = await response.json();

      if (!response.ok) {
        // An expired or spent challenge cannot be retried on this screen.
        if (data.reason === "challenge_expired") {
          nav("/login", { replace: true });
          return;
        }

        throw new Error(data.message || "That code is not correct.");
      }

      adoptSession(data);
      nav(ROLE_DESTINATIONS[data?.user?.role] ?? "/home", { replace: true });
    } catch (err) {
      setError(err.message);
      if (codeRef.current) codeRef.current.value = "";
    } finally {
      setLoading(false);
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
      </form>
    </AuthLayout>
  );
}

export default TwoFactorChallenge;
