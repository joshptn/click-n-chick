import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { IconCircleCheck, IconMailForward } from "@tabler/icons-react";

import Button from "../ui/Button";
import Input from "../ui/Input";
import toast from "../app/Toast";
import { changeGuestEmail, resendGuestLink, withLinkDeadline } from "../../lib/orders";

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** A clock that ticks each second while the card is on screen. */
function useNow() {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(timer);
  }, []);

  return now;
}

/**
 * "Did you get your tracking link?" on a guest's tracking page, below the
 * order summary, while the order is open (FR-02.13b, decided 2026-11-22).
 *
 * Shows the address the link went to, with Edit to correct it, and Resend
 * link. The server holds the limits (one send per 30 seconds, counted from the
 * automatic email too; 10 per order); this only shows them.
 */
function TrackingLinkCard({ token, link, queryKey }) {
  const queryClient = useQueryClient();
  const [sent, setSent] = useState(false);
  const [editing, setEditing] = useState(false);
  const [email, setEmail] = useState(link.email ?? "");
  const [emailError, setEmailError] = useState(null);

  const now = useNow();
  // Never above the server's own figure, though the clock may be a second behind.
  const wait = Math.min(link.resend_in ?? 0, Math.max(0, Math.ceil(((link.resend_until ?? 0) - now) / 1000)));
  const used = link.resends_left <= 0;

  // Every answer carries the card's fresh details.
  const keep = (next) => {
    if (next) queryClient.setQueryData(queryKey, (prev) => (prev ? { ...prev, link: withLinkDeadline(next) } : prev));
  };

  const resending = useMutation({
    mutationFn: () => resendGuestLink(token),
    onSuccess: (payload) => {
      keep(payload?.link);
      setSent(true);
    },
    onError: (err) => {
      keep(err?.payload?.link);
      toast.error(err?.message ?? "We couldn't send it just now. Please try again in a moment.", "Link not sent");
    },
  });

  const saving = useMutation({
    mutationFn: (value) => changeGuestEmail(token, value),
    onSuccess: (_payload, value) => {
      queryClient.setQueryData(queryKey, (prev) => (prev?.link ? { ...prev, link: { ...prev.link, email: value } } : prev));
      setEditing(false);
      setSent(false);
      toast.success("Click Resend link to send it there.", "Email updated");
    },
    onError: (err) => setEmailError(err?.message ?? "That email address could not be saved."),
  });

  const save = (event) => {
    event.preventDefault();

    const value = email.trim();

    if (!EMAIL_PATTERN.test(value)) {
      setEmailError("That email address does not look right. Please check it.");

      return;
    }

    setEmailError(null);
    saving.mutate(value);
  };

  return (
    <section className="rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4 sm:px-6">
      <div className="flex items-start gap-3">
        <span
          className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${
            sent ? "bg-[#e9f8ee] text-[#2f9e44]" : "bg-[#fff4e8] text-brand-600"
          }`}
        >
          {sent ? (
            <IconCircleCheck size={19} stroke={2} aria-hidden="true" />
          ) : (
            <IconMailForward size={19} stroke={2} aria-hidden="true" />
          )}
        </span>

        <div className="min-w-0 flex-1">
          <p className="m-0 font-display text-[13.5px] font-semibold leading-snug text-ink" aria-live="polite">
            {sent
              ? "Your tracking link has been sent. Please check your email."
              : "Did you get your tracking link? If not, click the button to send it again."}
          </p>

          {!editing && (
            <p className="m-0 mt-1 break-all font-display text-[12.5px] text-[#6f6b68]">
              Sent to <span className="font-semibold text-ink">{link.email}</span>
              {" · "}
              <button
                type="button"
                onClick={() => {
                  setEmail(link.email ?? "");
                  setEmailError(null);
                  setEditing(true);
                }}
                className="font-semibold text-brand-600 underline-offset-2 hover:underline"
              >
                Edit
              </button>
            </p>
          )}
        </div>
      </div>

      {editing && (
        <form onSubmit={save} className="mt-3" noValidate>
          <label htmlFor="tracking-link-email" className="mb-1.5 block font-display text-[12.5px] font-semibold text-ink">
            Email address
          </label>
          <Input
            id="tracking-link-email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            invalid={Boolean(emailError)}
            aria-describedby={emailError ? "tracking-link-email-error" : undefined}
            autoFocus
          />
          {emailError && (
            <p id="tracking-link-email-error" className="m-0 mt-1.5 font-display text-[12px] text-[#e5322d]">
              {emailError}
            </p>
          )}

          <div className="mt-3 flex justify-end gap-2">
            <Button variant="ghost" size="sm" onClick={() => setEditing(false)} disabled={saving.isPending}>
              Cancel
            </Button>
            <Button
              type="submit"
              size="sm"
              loading={saving.isPending}
              loadingLabel="Saving&hellip;"
              disabled={email.trim() === "" || email.trim() === link.email}
            >
              Save
            </Button>
          </div>
        </form>
      )}

      {!editing && (
        <>
          {sent && (
            <p className="m-0 mt-3 font-display text-[12.5px] leading-snug text-[#6f6b68]">
              Still didn&apos;t get it? Check your spam folder and make sure your email address is correct, then click
              Resend link.
            </p>
          )}

          {used ? (
            <p className="m-0 mt-3 rounded-[10px] bg-[#faf7f3] px-3 py-2.5 font-display text-[12.5px] leading-snug text-[#6f6b68]">
              You&apos;ve reached the resend limit for this order. Keep this page open, or bookmark it, to come back to
              your order.
            </p>
          ) : (
            <Button
              variant="outline"
              size="md"
              fullWidth
              className="mt-3"
              loading={resending.isPending}
              loadingLabel="Sending&hellip;"
              disabled={wait > 0}
              onClick={() => resending.mutate()}
            >
              <IconMailForward size={16} stroke={2.2} aria-hidden="true" />
              {wait > 0 ? `Resend link (${wait}s)` : "Resend link"}
            </Button>
          )}
        </>
      )}
    </section>
  );
}

export default TrackingLinkCard;
