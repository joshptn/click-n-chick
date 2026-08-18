import { useContext, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Modal } from "@mantine/core";
import { IconAlertCircle, IconLock, IconShieldExclamation } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import Button from "../ui/Button";
import PasswordChecklist from "../auth/PasswordChecklist";
import toast from "../app/Toast";
import { CHANNELS, fetchVerificationChannels } from "../../lib/verificationChannels";
import { changePassword, requestPasswordChangeCode } from "../../lib/passwordChange";
import { unmetPasswordRules } from "../../lib/passwordRules";

/**
 * Change the account password (BR-33).
 *
 * The visual design shows three fields and a button. BR-33 needs one more
 * thing than that: the account must re-prove itself over a verified channel
 * first. So the form collects the passwords, then a code is sent and confirmed
 * in a dialog - the extra step sits after the button rather than in front of
 * it, which keeps the card looking like the design and still satisfies the
 * rule.
 *
 * A successful change revokes every session on the account. That is correct -
 * a password change should end sessions opened with the old one - so this
 * signs the user out and sends them to the login screen instead of pretending
 * the current session survived.
 */

const EMPTY = { current: "", next: "", confirm: "" };

function PasswordField({ id, label, value, onChange, autoComplete, disabled, describedBy }) {
  return (
    <div>
      <label htmlFor={id} className="block font-display text-[12.5px] font-semibold text-ink">
        {label}
      </label>
      <input
        id={id}
        type="password"
        value={value}
        onChange={onChange}
        autoComplete={autoComplete}
        disabled={disabled}
        aria-describedby={describedBy}
        className="mt-1.5 h-[46px] w-full rounded-[10px] border border-[#ece7e0] bg-[#faf8f5] px-3.5 font-display text-[14px] text-ink outline-none transition-colors focus:border-brand-500 focus:bg-white disabled:opacity-60"
      />
    </div>
  );
}

function PasswordChangeCard() {
  const nav = useNavigate();
  const { user, logOut } = useContext(AuthContext);

  const [form, setForm] = useState(EMPTY);
  const [error, setError] = useState(null);
  const [sending, setSending] = useState(false);

  const [confirming, setConfirming] = useState(null);
  const [code, setCode] = useState("");
  const [codeError, setCodeError] = useState(null);
  const [saving, setSaving] = useState(false);

  const { data: channels } = useQuery({
    queryKey: ["verification-channels"],
    queryFn: fetchVerificationChannels,
    staleTime: 5 * 60 * 1000,
  });

  const set = (key) => (e) => {
    setForm((prev) => ({ ...prev, [key]: e.target.value }));
    setError(null);
  };

  /**
   * Where the confirmation code can actually go.
   *
   * Only channels this account has verified AND the deployment can deliver
   * on. Offering SMS to someone who never verified their phone produces a code
   * that never arrives and a form that looks broken.
   */
  const usableChannel = () => {
    const deliverable = (channels ?? []).filter((entry) => entry.available).map((entry) => entry.channel);

    if (user?.email_verified_at && deliverable.includes(CHANNELS.EMAIL)) return CHANNELS.EMAIL;
    if (user?.phone_verified_at && deliverable.includes(CHANNELS.SMS)) return CHANNELS.SMS;

    return null;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (form.next !== form.confirm) {
      setError("The new passwords do not match.");
      return;
    }

    const unmet = unmetPasswordRules(form.next);

    if (unmet.length > 0) {
      setError(`Your new password still needs: ${unmet.map((r) => r.label.toLowerCase()).join(", ")}.`);
      return;
    }

    const channel = usableChannel();

    if (channel === null) {
      setError("We have no verified way to reach you. Verify your email address first.");
      return;
    }

    setSending(true);

    try {
      const data = await requestPasswordChangeCode(channel);

      setCode("");
      setCodeError(null);
      setConfirming({ channel, identifier: data?.identifier });
    } catch (err) {
      setError(err?.message ?? "We could not send a confirmation code.");
    } finally {
      setSending(false);
    }
  };

  const handleConfirm = async (e) => {
    e.preventDefault();
    setSaving(true);
    setCodeError(null);

    try {
      await changePassword({
        currentPassword: form.current,
        password: form.next,
        passwordConfirmation: form.confirm,
        code,
      });

      setConfirming(null);
      setForm(EMPTY);
      toast.success("Password updated. Please sign in again.");

      // Every token was just revoked server-side, this one included. Clear
      // local state without a second revoke call and leave.
      await logOut({ revokeOnServer: false });
      nav("/login", { replace: true });
    } catch (err) {
      const errors = err?.payload?.errors ?? {};

      // A wrong current password comes back as a field error, and it is the
      // form behind the dialog that needs correcting - not the code.
      if (errors.current_password || errors.password) {
        setConfirming(null);
        setError(errors.current_password?.[0] ?? errors.password?.[0]);
        return;
      }

      setCodeError(err?.message ?? "That code is not correct.");
      setCode("");
    } finally {
      setSaving(false);
    }
  };

  const filled = form.current && form.next && form.confirm;

  return (
    <>
      <section className="rounded-2xl border border-[#f0e9df] bg-white p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#fff6ec] text-brand-600"
            >
              <IconShieldExclamation size={19} stroke={1.9} />
            </span>

            <div>
              <h2 className="m-0 font-display text-[15.5px] font-bold text-brand-600">Account Security</h2>
              <p className="m-0 mt-0.5 font-display text-[12.5px] text-[#8d8884]">
                Update your password to keep your account safe
              </p>
            </div>
          </div>

          {/* Rendered from PASSWORD_RULES rather than hardcoded, so the hint
              can never drift from what the server actually enforces. */}
          <div className="flex items-start gap-2 rounded-[10px] border border-[#ffe6a8] bg-[#fff9e8] px-3 py-2">
            <IconAlertCircle size={15} stroke={2.1} aria-hidden="true" className="mt-px shrink-0 text-[#b8820a]" />
            <p className="m-0 font-display text-[12px] leading-snug text-[#7a5620]">
              Password must be at least 8 chars with an uppercase letter and a number.
            </p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="mt-5 grid gap-4">
          <PasswordField
            id="current-password"
            label="Current Password"
            value={form.current}
            onChange={set("current")}
            autoComplete="current-password"
            disabled={sending}
          />

          <div>
            <PasswordField
              id="new-password"
              label="New Password"
              value={form.next}
              onChange={set("next")}
              autoComplete="new-password"
              disabled={sending}
              describedBy="new-password-rules"
            />
            {form.next.length > 0 && (
              <div className="mt-2">
                <PasswordChecklist id="new-password-rules" password={form.next} />
              </div>
            )}
          </div>

          <PasswordField
            id="confirm-password"
            label="Confirm New Password"
            value={form.confirm}
            onChange={set("confirm")}
            autoComplete="new-password"
            disabled={sending}
          />

          {error && (
            <p role="alert" className="m-0 font-display text-[12.5px] text-[#e5322d]">
              {error}
            </p>
          )}

          <div>
            <Button type="submit" disabled={!filled || sending} loading={sending} loadingLabel="Sending code&hellip;">
              Change Password
            </Button>
            <p className="m-0 mt-2 font-display text-[12px] text-[#a39f9b]">
              We&rsquo;ll send a confirmation code before the change is applied.
            </p>
          </div>
        </form>
      </section>

      <Modal
        opened={confirming !== null}
        onClose={() => setConfirming(null)}
        title="Confirm it's you"
        centered
        radius="md"
      >
        <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          We sent a 6-digit code to your{" "}
          {confirming?.channel === CHANNELS.EMAIL ? "email address" : "phone"}
          {confirming?.identifier ? (
            <>
              {" "}
              <span className="font-semibold text-ink">{confirming.identifier}</span>
            </>
          ) : null}
          . Enter it to finish changing your password.
        </p>

        <form onSubmit={handleConfirm}>
          <label htmlFor="password-change-code" className="mt-4 block font-display text-[12.5px] font-semibold text-ink">
            Confirmation code
          </label>

          <input
            id="password-change-code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            autoFocus
            value={code}
            onChange={(e) => {
              setCode(e.target.value.replace(/\D/g, ""));
              setCodeError(null);
            }}
            placeholder="123456"
            aria-invalid={codeError ? "true" : undefined}
            className={`mt-1.5 h-[46px] w-full max-w-[200px] rounded-[10px] border bg-white px-3.5 font-display text-[16px] tracking-[3px] text-ink outline-none transition-colors focus:border-brand-400 ${
              codeError ? "border-[#e5322d]" : "border-[#ece7e0]"
            }`}
          />

          {codeError && (
            <p role="alert" className="m-0 mt-1.5 font-display text-[12.5px] text-[#e5322d]">
              {codeError}
            </p>
          )}

          <div className="mt-4 flex items-start gap-2 rounded-[10px] border border-[#ffe6a8] bg-[#fff9e8] px-3 py-2.5">
            <IconLock size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0 text-[#b8820a]" />
            <p className="m-0 font-display text-[12px] leading-snug text-[#7a5620]">
              Changing your password signs you out everywhere, including this device.
            </p>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={() => setConfirming(null)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={code.length < 6} loading={saving} loadingLabel="Updating&hellip;">
              Update password
            </Button>
          </div>
        </form>
      </Modal>
    </>
  );
}

export default PasswordChangeCard;
