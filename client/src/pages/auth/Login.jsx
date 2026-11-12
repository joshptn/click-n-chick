import { useContext, useEffect, useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { IconAlertCircle, IconInfoCircle, IconLock, IconUser } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import { CHANNELS, routeForChannel } from "../../lib/verificationChannels";
import { pages, preloadWhenIdle } from "../../lib/pages";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

/** Why the visitor was brought here, when a session ended elsewhere (session.js). */
const REASONS = {
  session_ended: "Your session has ended. Please sign in again.",
  signed_out_remotely: "This device was signed out from another device. Sign in again to continue.",
};

function Login() {
  const { loginUser } = useContext(AuthContext);
  const nav = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const notice = REASONS[searchParams.get("reason")] ?? null;
  // The page a guard sent the visitor here from; they go back to it after signing in.
  const from = location.state?.from?.pathname ?? null;

  // Most sign-ins land on the menu; fetch its code while the form is filled in.
  useEffect(() => preloadWhenIdle([pages.Home]), []);

  const handleLogin = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const data = await loginUser(e);

      if (data?.two_factor_required) {
        nav("/two-factor", {
          state: {
            challengeToken: data.challenge_token,
            channel: data.two_factor_channel,
            identifier: data.identifier,
            // "two_factor" or "recaptcha_step_up" - the screen words itself
            // differently for each.
            reason: data.reason,
            from,
          },
        });
        return;
      }

      nav(from ?? ROLE_DESTINATIONS[data?.user?.role] ?? "/home", { replace: true });
    } catch (err) {
      if (err.status === 403 && err.payload?.status === "pending_verification") {
        // The password was right but the identifier typed is not the one this
        // account verifies with; the server says which one to use.
        if (err.payload.sign_in_with_channel) {
          setError(err.message);
          return;
        }

        // An account the store created, signing in for the first time: the
        // code has just been sent to the identifier they typed.
        const channel = err.payload.verification_channel ?? CHANNELS.EMAIL;

        nav(routeForChannel(channel), {
          state: {
            identifier: e.target.login.value.trim(),
            maskedIdentifier: err.payload.identifier,
            resendAvailableIn: err.payload.resend_available_in,
            from,
          },
        });
        return;
      }

      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      heading="Sign In"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Don&rsquo;t have an account?{" "}
          <Link to="/register" className="font-bold text-brand-600 no-underline hover:underline">
            Sign Up
          </Link>
        </p>
      }
    >
      {notice && !error && (
        <div
          role="status"
          className="mb-5 flex items-start gap-2.5 rounded-[12px] border border-[#ffe6a8] bg-[#fff9e8] px-4 py-3"
        >
          <IconInfoCircle size={19} stroke={2.2} className="mt-px shrink-0 text-[#b7791f]" />
          <p className="font-display text-[13px] leading-snug text-[#7a5620]">{notice}</p>
        </div>
      )}

      {error && (
        <div
          role="alert"
          className="mb-5 flex items-start gap-2.5 rounded-[12px] border border-[#ffd7d5] bg-[#fff1f1] px-4 py-3"
        >
          <IconAlertCircle size={19} stroke={2.2} className="mt-px shrink-0 text-[#e5322d]" />

          <div className="min-w-0">
            <p className="font-display text-[13.5px] font-semibold text-[#e5322d]">Login failed</p>
            <p className="font-display text-[13px] leading-snug text-[#e5322d]">{error}</p>
          </div>
        </div>
      )}

      <form onSubmit={handleLogin} className="flex flex-col gap-4">
        <Field label="Enter your phone number or email address">
          {(id) => (
            <Input
              id={id}
              type="text"
              name="login"
              icon={IconUser}
              placeholder="Phone number or email address"
              autoComplete="username"
              required
              disabled={loading}
            />
          )}
        </Field>

        <Field label="Enter your Password">
          {(id) => (
            <Input
              id={id}
              type="password"
              name="password"
              icon={IconLock}
              placeholder="Password"
              autoComplete="current-password"
              required
              disabled={loading}
            />
          )}
        </Field>

        <Link
          to="/forgot-password"
          className="-mt-1 self-end font-display text-[12.5px] font-bold text-brand-600 no-underline hover:underline"
        >
          Forgot Password?
        </Link>

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Signing In..." className="mt-2">
          Sign In
        </Button>
      </form>
    </AuthLayout>
  );
}

export default Login;
