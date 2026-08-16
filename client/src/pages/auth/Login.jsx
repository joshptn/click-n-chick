import { useContext, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconLock, IconUser } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import toast from "../../components/app/Toast";
import { CHANNELS, routeForChannel } from "../../lib/verificationChannels";

const ROLE_DESTINATIONS = {
  [ROLES.SUPER_ADMIN]: "/superadmin",
  [ROLES.ADMIN]: "/admin",
  [ROLES.CUSTOMER]: "/home",
};

function Login() {
  const { loginUser } = useContext(AuthContext);
  const nav = useNavigate();

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      const data = await loginUser(e);

      if (data?.two_factor_required) {
        nav("/two-factor", {
          state: {
            challengeToken: data.challenge_token,
            channel: data.two_factor_channel,
            identifier: data.identifier,
          },
        });
        return;
      }

      nav(ROLE_DESTINATIONS[data?.user?.role] ?? "/home");
    } catch (err) {
      if (err.status === 403 && err.payload?.status === "pending_verification") {
        const channel = err.payload.verification_channel ?? CHANNELS.SMS;
        const typed = e.target.login.value.trim();
        const typedIsEmail = typed.includes("@");

        if ((channel === CHANNELS.EMAIL) === typedIsEmail) {
          nav(routeForChannel(channel), {
            state: {
              identifier: typed,
              maskedIdentifier: err.payload.identifier ?? err.payload.phone_number,
            },
          });
          return;
        }

        toast.info(
          channel === CHANNELS.EMAIL
            ? "Finish verifying your email address to activate your account."
            : "Finish verifying your phone number to activate your account.",
          "Almost there"
        );
        nav("/register");
        return;
      }

      setError(err.message);
      setTimeout(() => setError(""), 4000);
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
