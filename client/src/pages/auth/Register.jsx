import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconAlertCircle, IconCheck, IconLock, IconMail, IconPhone, IconUser } from "@tabler/icons-react";

import AuthLayout from "../../components/auth/AuthLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import toast from "../../components/app/Toast";
import { CHANNELS, routeForChannel } from "../../lib/verificationChannels";

const CHANNEL_OPTIONS = [
  { value: CHANNELS.SMS, label: "Text message", hint: "To your mobile number", Icon: IconPhone },
  { value: CHANNELS.EMAIL, label: "Email", hint: "To your email address", Icon: IconMail },
];

// Mirrors App\Rules\StrongPassword on the server. The server is the authority;
// this exists so the requirements are visible before the form is submitted.
const PASSWORD_RULES = [
  { label: "At least 8 characters", test: (value) => value.length >= 8 },
  { label: "One uppercase letter", test: (value) => /[A-Z]/.test(value) },
  { label: "One number", test: (value) => /\d/.test(value) },
];

function Register() {
  const nav = useNavigate();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  // PRD §6.2: phone and email are both first-class; whichever is chosen here is
  // the one that blocks registration until verified.
  const [channel, setChannel] = useState(CHANNELS.SMS);
  // Observed, not controlled - the form stays uncontrolled and submit still
  // reads from e.target. This only drives the live requirement checklist.
  const [password, setPassword] = useState("");

  const RegisterUser = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    const phone = e.target.phone_number.value.trim();

    if (!/^\d{10}$/.test(phone)) {
      setError("Please enter a valid 10-digit phone number (digits only).");
      setLoading(false);
      setTimeout(() => setError(""), 4000);
      return;
    }

    const unmet = PASSWORD_RULES.filter((rule) => !rule.test(e.target.password.value));

    if (unmet.length > 0) {
      setError(`Password must have: ${unmet.map((rule) => rule.label.toLowerCase()).join(", ")}.`);
      setLoading(false);
      setTimeout(() => setError(""), 4000);
      return;
    }

    const url = import.meta.env.VITE_API_URL;

    try {
      const body = {
        first_name: e.target.first_name.value,
        last_name: e.target.last_name.value,
        email: e.target.email.value,
        password: e.target.password.value,
        password_confirmation: e.target.password_confirmation.value,
        phone_number: `+63${phone}`,
        verification_channel: channel,
      };

      const response = await fetch(`${url}/api/register`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(body),
        credentials: "include",
      });

      const data = await response.json();
      if (!response.ok) throw new Error(data.message || "Registration failed");

      // Blocking flow: the account exists but holds no token until the chosen
      // channel is verified, so there is no auto-login here and no way past
      // this screen.
      nav(routeForChannel(data.verification_channel ?? channel), {
        replace: true,
        state: {
          identifier: channel === CHANNELS.EMAIL ? body.email : body.phone_number,
          maskedIdentifier: data.identifier,
          resendAvailableIn: data.resend_available_in,
        },
      });
    } catch (err) {
      console.error("Registration error:", err);
      setError(err.message);
    } finally {
      setLoading(false);
      setTimeout(() => setError(""), 4000);
    }
  };

  return (
    <AuthLayout
      heading="Sign Up"
      footer={
        <p className="text-center font-display text-[13px] text-[#6f6b68]">
          Already have an Account?{" "}
          <Link to="/login" className="font-bold text-brand-600 no-underline hover:underline">
            Sign In
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
            <p className="font-display text-[13.5px] font-semibold text-[#e5322d]">Registration failed</p>
            <p className="font-display text-[13px] leading-snug text-[#e5322d]">{error}</p>
          </div>
        </div>
      )}

      <form onSubmit={RegisterUser} className="flex flex-col gap-4">
        <Field label="Enter your email address" required>
          {(id) => (
            <Input id={id} type="email" name="email" icon={IconMail} placeholder="Email address" autoComplete="email" required />
          )}
        </Field>

        <Field label="Enter your contact number" required>
          {(id) => (
            <Input
              id={id}
              type="text"
              name="phone_number"
              icon={IconPhone}
              prefix="+63"
              placeholder="Contact number"
              inputMode="numeric"
              autoComplete="tel-national"
              maxLength={10}
              pattern="\d{10}"
              onInput={(e) => {
                e.target.value = e.target.value.replace(/\D/g, "");
              }}
              required
            />
          )}
        </Field>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="First Name" required>
            {(id) => (
              <Input id={id} type="text" name="first_name" icon={IconUser} placeholder="John" autoComplete="given-name" required />
            )}
          </Field>

          <Field label="Last Name" required>
            {(id) => (
              <Input id={id} type="text" name="last_name" icon={IconUser} placeholder="Doe" autoComplete="family-name" required />
            )}
          </Field>
        </div>

        <Field label="Enter your Password" required>
          {(id) => (
            <Input
              id={id}
              type="password"
              name="password"
              icon={IconLock}
              placeholder="Password"
              autoComplete="new-password"
              aria-describedby="password-requirements"
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          )}
        </Field>

        <ul id="password-requirements" className="-mt-1.5 grid grid-cols-1 gap-1.5">
          {PASSWORD_RULES.map((rule) => {
            const met = rule.test(password);

            return (
              <li key={rule.label} className={`flex items-center gap-1.5 font-display text-[12px] transition-colors ${met ? "text-brand-600" : "text-[#8d8884]"}`}>
                {met ? (
                  <IconCheck size={13} stroke={3} aria-hidden="true" className="shrink-0" />
                ) : (
                  <span aria-hidden="true" className="mx-1 h-[5px] w-[5px] shrink-0 rounded-full bg-[#d9d3cb]" />
                )}
                {rule.label}
              </li>
            );
          })}
        </ul>

        <Field label="Confirm your Password" required>
          {(id) => (
            <Input
              id={id}
              type="password"
              name="password_confirmation"
              icon={IconLock}
              placeholder="Confirm Password"
              autoComplete="new-password"
              required
            />
          )}
        </Field>

        <fieldset className="mt-1 border-0 p-0">
          <legend className="mb-2 font-display text-[13px] font-semibold text-[#33302c]">
            How should we send your verification code?
          </legend>

          <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            {CHANNEL_OPTIONS.map((option) => {
              const { value, label, hint } = option;
              const selected = channel === value;

              return (
                <label
                  key={value}
                  className={`flex cursor-pointer items-center gap-3 rounded-[12px] border-2 px-3.5 py-3 transition-colors ${selected ? "border-brand-500 bg-[#fff6ee]" : "border-[#e6ded4] bg-white hover:border-[#d9d3cb]"}`}
                >
                  <input
                    type="radio"
                    name="verification_channel"
                    value={value}
                    checked={selected}
                    onChange={() => setChannel(value)}
                    className="peer sr-only"
                  />

                  <option.Icon size={19} stroke={2} aria-hidden="true" className={selected ? "shrink-0 text-brand-600" : "shrink-0 text-[#a39f9b]"} />

                  <span className="min-w-0">
                    <span className="block font-display text-[13.5px] font-semibold text-[#33302c]">{label}</span>
                    <span className="block font-display text-[12px] text-[#8d8884]">{hint}</span>
                  </span>
                </label>
              );
            })}
          </div>
        </fieldset>

        <div className="mt-1 flex items-start gap-3">
          {/* The native input stays visible (appearance-none, not sr-only) so the
              browser can focus it when `required` blocks submission. */}
          <span className="relative mt-px flex shrink-0">
            <input
              id="terms"
              type="checkbox"
              name="terms"
              required
              className="peer h-5 w-5 cursor-pointer appearance-none rounded-full border-2 border-[#d9d3cb] bg-white transition-colors duration-150 checked:border-brand-500 checked:bg-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
            />

            <IconCheck
              size={12}
              stroke={3.5}
              aria-hidden="true"
              className="pointer-events-none absolute inset-0 m-auto text-white opacity-0 transition-opacity duration-150 peer-checked:opacity-100"
            />
          </span>

          <p className="font-display text-[13px] leading-snug text-[#6f6b68]">
            <label htmlFor="terms" className="cursor-pointer">I agree to the</label>{" "}
            <button
              type="button"
              onClick={() => toast.info("Our Terms of Service page is on the way.", "Coming soon")}
              className="bg-transparent font-semibold text-brand-600 hover:underline"
            >
              Terms of Service
            </button>{" "}
            <label htmlFor="terms" className="cursor-pointer">and</label>{" "}
            <button
              type="button"
              onClick={() => toast.info("Our Privacy Policy page is on the way.", "Coming soon")}
              className="bg-transparent font-semibold text-brand-600 hover:underline"
            >
              Privacy Policy
            </button>
          </p>
        </div>

        <Button type="submit" fullWidth size="lg" loading={loading} loadingLabel="Signing Up..." className="mt-1">
          Sign Up
        </Button>
      </form>
    </AuthLayout>
  );
}

export default Register;
