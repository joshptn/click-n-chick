import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconInfoCircle, IconUser } from "@tabler/icons-react";

import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { CHANNELS, fetchVerificationChannels } from "../../lib/verificationChannels";

/**
 * Step 1 of password recovery: name the account.
 *
 * One free-text field rather than a phone/email toggle - someone who has lost
 * access to their account should not first have to remember which identifier
 * they signed up with. The backend infers the channel from the shape of what
 * arrives.
 *
 * The response is deliberately the same whether or not the identifier is
 * registered, so this screen must not branch on it. It always moves the user
 * forward to the code step; anything else would rebuild the account-existence
 * oracle the API is careful not to be.
 */
function ForgotPassword() {
  const nav = useNavigate();

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  // Backend-driven, never a constant here: the note appears only while SMS
  // genuinely cannot deliver, and disappears on its own once it can.
  const [smsNote, setSmsNote] = useState(null);

  useEffect(() => {
    let cancelled = false;

    fetchVerificationChannels().then((channels) => {
      if (cancelled) return;

      const sms = channels.find((c) => c.channel === CHANNELS.SMS);
      setSmsNote(sms && !sms.available ? sms.reason : null);
    });

    return () => {
      cancelled = true;
    };
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    const identifier = e.target.identifier.value.trim();
    const url = import.meta.env.VITE_API_URL;

    try {
      const response = await fetch(`${url}/api/password/forgot`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ identifier }),
        credentials: "include",
      });

      const data = await response.json();

      // 429 is the only failure worth surfacing - it is about this caller's
      // request rate, not about whether the account exists.
      if (!response.ok) {
        throw new Error(data.message || "Too many attempts. Please try again shortly.");
      }

      nav("/reset-password", {
        state: {
          identifier,
          notice: data.message,
          resendAvailableIn: data.resend_available_in,
        },
      });
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      eyebrow="Account recovery for"
      heading="Forgot your password?"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Remembered it?{" "}
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
            <p className="font-display text-[13.5px] font-semibold text-[#e5322d]">Request failed</p>
            <p className="font-display text-[13px] leading-snug text-[#e5322d]">{error}</p>
          </div>
        </div>
      )}

      <p className="mb-5 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
        Enter the phone number or email address on your account and we&rsquo;ll send you a 6-digit code to reset
        your password.
      </p>

      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="Phone number or email address" required>
          {(id) => (
            <Input
              id={id}
              type="text"
              name="identifier"
              icon={IconUser}
              placeholder="Phone number or email address"
              autoComplete="username"
              aria-describedby={smsNote ? "recovery-channel-note" : undefined}
              autoFocus
              required
              disabled={loading}
            />
          )}
        </Field>

        {smsNote && (
          <p
            id="recovery-channel-note"
            className="-mt-2 flex items-start gap-1.5 font-display text-[12px] leading-snug text-[#8d8884]"
          >
            <IconInfoCircle size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
            {smsNote} Use the email address on your account to recover it.
          </p>
        )}

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Sending..." className="mt-1">
          Send reset code
        </Button>
      </form>
    </AuthLayout>
  );
}

export default ForgotPassword;
