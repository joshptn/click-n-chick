import { useContext, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconLock, IconShieldLock } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { deviceHeader } from "../../lib/deviceId";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import PasswordChecklist from "../../components/auth/PasswordChecklist";
import { ROLES } from "../../lib/roles";
import { unmetPasswordRules } from "../../lib/passwordRules";
import { RECAPTCHA_ACTIONS, withRecaptcha } from "../../lib/recaptcha";
import toast from "../../components/app/Toast";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

function ResetPassword() {
  const nav = useNavigate();
  const location = useLocation();
  const { adoptSession } = useContext(AuthContext);

  const { identifier, notice, resendAvailableIn } = location.state ?? {};

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [password, setPassword] = useState("");
  const [cooldown, setCooldown] = useState(resendAvailableIn ?? 0);
  const codeRef = useRef(null);

  useEffect(() => {
    if (!identifier) {
      nav("/forgot-password", { replace: true });
    }
  }, [identifier, nav]);

  useEffect(() => {
    if (cooldown <= 0) return undefined;

    const timer = window.setInterval(() => {
      setCooldown((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [cooldown]);

  const url = import.meta.env.VITE_API_URL;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    const newPassword = e.target.password.value;
    const confirmation = e.target.password_confirmation.value;

    const unmet = unmetPasswordRules(newPassword);

    if (unmet.length > 0) {
      setError(`Password must have: ${unmet.map((rule) => rule.label.toLowerCase()).join(", ")}.`);
      return;
    }

    if (newPassword !== confirmation) {
      setError("The two passwords do not match.");
      return;
    }

    setLoading(true);

    try {
      const response = await fetch(`${url}/api/password/reset`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...deviceHeader() },
        body: JSON.stringify(
          await withRecaptcha(
            {
              identifier,
              code: e.target.code.value.trim(),
              password: newPassword,
              password_confirmation: confirmation,
            },
            RECAPTCHA_ACTIONS.PASSWORD_RESET
          )
        ),
        credentials: "include",
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || "That code is not correct.");
      }

      if (data.two_factor_required) {
        toast.success("Your password has been reset.", "Almost done");
        nav("/two-factor", {
          replace: true,
          state: {
            challengeToken: data.challenge_token,
            channel: data.two_factor_channel,
            identifier: data.identifier,
          },
        });
        return;
      }

      adoptSession(data);
      toast.success("Your password has been reset.", "Welcome back");
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
      const response = await fetch(`${url}/api/password/forgot`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...deviceHeader() },
        body: JSON.stringify(await withRecaptcha({ identifier }, RECAPTCHA_ACTIONS.PASSWORD_FORGOT)),
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
      eyebrow="Account recovery for"
      heading="Choose a new password"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Wrong account?{" "}
          <Link to="/forgot-password" className="font-bold text-brand-600 no-underline hover:underline">
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
            <p className="font-display text-[13.5px] font-semibold text-[#e5322d]">Reset failed</p>
            <p className="font-display text-[13px] leading-snug text-[#e5322d]">{error}</p>
          </div>
        </div>
      )}

      <p className="mb-5 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
        {notice ?? "If that account is registered, we have sent it a 6-digit code."} Enter the code below along
        with the password you would like to use from now on.
      </p>

      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="Reset code" required>
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

        <Field label="New password" required>
          {(id) => (
            <Input
              id={id}
              type="password"
              name="password"
              icon={IconLock}
              placeholder="New password"
              autoComplete="new-password"
              aria-describedby="reset-password-requirements"
              onChange={(e) => setPassword(e.target.value)}
              required
              disabled={loading}
            />
          )}
        </Field>

        <PasswordChecklist id="reset-password-requirements" password={password} />

        <Field label="Confirm new password" required>
          {(id) => (
            <Input
              id={id}
              type="password"
              name="password_confirmation"
              icon={IconLock}
              placeholder="Confirm new password"
              autoComplete="new-password"
              required
              disabled={loading}
            />
          )}
        </Field>

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Resetting..." className="mt-1">
          Reset password
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

export default ResetPassword;
