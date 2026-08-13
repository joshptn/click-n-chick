import { useContext, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconShieldLock } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import toast from "../../components/app/Toast";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

function VerifyPhone() {
  const nav = useNavigate();
  const location = useLocation();
  const { adoptSession } = useContext(AuthContext);

  // Carried from the register (or blocked-login) redirect. Without it there is
  // nothing to verify against, so the screen sends the user back.
  const { phoneNumber, maskedPhone, resendAvailableIn } = location.state ?? {};

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [cooldown, setCooldown] = useState(resendAvailableIn ?? 0);
  const codeRef = useRef(null);

  useEffect(() => {
    if (!phoneNumber) {
      nav("/register", { replace: true });
    }
  }, [phoneNumber, nav]);

  useEffect(() => {
    if (cooldown <= 0) return undefined;

    const timer = window.setInterval(() => {
      setCooldown((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [cooldown]);

  const url = import.meta.env.VITE_API_URL;

  const handleVerify = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const response = await fetch(`${url}/api/otp/verify`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ phone_number: phoneNumber, code: e.target.code.value.trim() }),
        credentials: "include",
      });

      const data = await response.json();

      if (!response.ok) throw new Error(data.message || "That code is not correct.");

      // This endpoint returns the same {user, token} shape as login, so the
      // session starts here - no separate /login round trip.
      adoptSession(data);
      toast.success("Your phone number is verified.", "Welcome to Click n Chick");
      nav(ROLE_DESTINATIONS[data?.user?.role] ?? "/home", { replace: true });
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
      const response = await fetch(`${url}/api/otp/resend`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ phone_number: phoneNumber }),
        credentials: "include",
      });

      const data = await response.json();

      setCooldown(data.resend_available_in ?? 60);

      if (!response.ok) {
        toast.warning(data.message || "Please wait before requesting another code.", "Hold on");
        return;
      }

      toast.info(data.message, "Code sent");
    } catch {
      toast.error("We could not send a new code. Please try again.");
    }
  };

  return (
    <AuthLayout
      eyebrow="One last step for"
      heading="Verify your phone"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Wrong number?{" "}
          <Link to="/register" className="font-bold text-brand-600 no-underline hover:underline">
            Start over
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
        We sent a 6-digit code to{" "}
        <span className="font-semibold text-[#33302c]">{maskedPhone ?? phoneNumber}</span>. Enter it below to
        finish creating your account.
      </p>

      <form onSubmit={handleVerify} className="flex flex-col gap-4">
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
          Verify
        </Button>

        <button
          type="button"
          onClick={handleResend}
          disabled={cooldown > 0}
          className="mx-auto bg-transparent font-display text-[12.5px] font-bold text-brand-600 transition-opacity hover:underline disabled:cursor-not-allowed disabled:text-[#a39f9b] disabled:no-underline"
        >
          {cooldown > 0 ? `Resend code in ${cooldown}s` : "Resend code"}
        </button>
      </form>
    </AuthLayout>
  );
}

export default VerifyPhone;
