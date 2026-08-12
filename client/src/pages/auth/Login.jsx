import { useContext, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconLock, IconMail } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { ROLES } from "../../lib/roles";
import toast from "../../components/app/Toast";

// Where each role lands after signing in. Unknown/absent roles fall back to the
// customer home so a login never dead-ends on an unmatched route.
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
      nav(ROLE_DESTINATIONS[data?.user?.role] ?? "/home");
    } catch (err) {
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
        <Field label="Enter your username or email address">
          {(id) => (
            <Input
              id={id}
              type="text"
              name="email"
              icon={IconMail}
              placeholder="Username or email address"
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

        <button
          type="button"
          onClick={() =>
            toast.info("Password reset isn't available yet — please contact support.", "Coming soon")
          }
          className="-mt-1 self-end bg-transparent font-display text-[12.5px] font-bold text-brand-600 hover:underline"
        >
          Forgot Password?
        </button>

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Signing In..." className="mt-2">
          Sign In
        </Button>
      </form>
    </AuthLayout>
  );
}

export default Login;
