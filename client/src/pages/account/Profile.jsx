import { useContext, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader, Tooltip } from "@mantine/core";
import {
  IconAlertTriangle,
  IconArrowLeft,
  IconCamera,
  IconLock,
  IconTrophy,
  IconUsers,
} from "@tabler/icons-react";

import AddressManager from "../../components/account/AddressManager";
import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import Button from "../../components/ui/Button";
import DiscountCard from "../../components/account/DiscountCard";
import PasswordChangeCard from "../../components/account/PasswordChangeCard";
import toast from "../../components/app/Toast";
import {
  applyForDiscount,
  createAddress,
  deleteAddress,
  fetchProfile,
  setDefaultAddress,
  updateAddress,
  updateProfile,
  uploadAvatar,
} from "../../lib/profile";

/**
 * Account Settings (UC-PROF-001 / UC-PROF-002).
 *
 * One query owns the whole page. Every mutation returns the same payload shape
 * and is written straight back into that cache, so the screen never shows a
 * value the server has already moved on from - which matters here more than
 * usual, because saving a phone number can clear its verification and
 * approving a discount changes what the card is allowed to offer.
 *
 * The header search box is deliberately absent: `search`/`onSearchChange` are
 * simply not passed, and AppHeader renders no box without them. Searching the
 * menu from the settings screen would navigate the user away from unsaved work.
 */

const QUERY_KEY = ["profile"];

/** Initials for the avatar fallback. */
function initialsFor(user) {
  const value = `${user?.first_name?.charAt(0) ?? ""}${user?.last_name?.charAt(0) ?? ""}`.toUpperCase();

  return value || "?";
}

function SectionCard({ icon, title, subtitle, children }) {
  // Local alias: the lint config ignores unused *variables* named in caps but
  // not parameters, so a destructured `icon: Icon` reads as unused. Same
  // workaround as Toast.jsx.
  const Glyph = icon;

  return (
    <section className="rounded-2xl border border-[#f0e9df] bg-white p-5 sm:p-6">
      <div className="flex items-start gap-3">
        <span
          aria-hidden="true"
          className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#fff6ec] text-brand-600"
        >
          <Glyph size={19} stroke={1.9} />
        </span>

        <div className="min-w-0">
          <h2 className="m-0 font-display text-[15.5px] font-bold text-brand-600">{title}</h2>
          <p className="m-0 mt-0.5 font-display text-[12.5px] leading-snug text-[#8d8884]">{subtitle}</p>
        </div>
      </div>

      {children}
    </section>
  );
}

function TextField({ id, label, required, value, onChange, disabled, hint, maxLength, type = "text", error }) {
  const counter = maxLength && !disabled ? `${value.length}/${maxLength}` : null;

  return (
    <div>
      <label htmlFor={id} className="block font-display text-[12.5px] font-semibold text-ink">
        {label} {required && <span className="text-[#e5322d]">*</span>}
      </label>

      <div className="relative mt-1.5">
        <input
          id={id}
          type={type}
          value={value}
          onChange={onChange}
          disabled={disabled}
          maxLength={maxLength}
          aria-describedby={hint ? `${id}-hint` : undefined}
          aria-invalid={error ? "true" : undefined}
          className={`h-[46px] w-full rounded-[10px] border bg-[#faf8f5] px-3.5 font-display text-[14px] text-ink outline-none transition-colors focus:bg-white disabled:cursor-not-allowed disabled:text-[#8d8884] ${
            error ? "border-[#e5322d]" : "border-[#ece7e0] focus:border-brand-500"
          } ${counter ? "pr-14" : ""}`}
        />

        {counter && (
          <span className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 font-display text-[11px] text-[#b8b2aa]">
            {counter}
          </span>
        )}
      </div>

      {error ? (
        <p className="m-0 mt-1.5 font-display text-[12px] text-[#e5322d]">{error}</p>
      ) : (
        hint && (
          <p id={`${id}-hint`} className="m-0 mt-1.5 flex items-start gap-1 font-display text-[12px] text-[#a39f9b]">
            {disabled && <IconLock size={12} stroke={2} aria-hidden="true" className="mt-0.5 shrink-0" />}
            {hint}
          </p>
        )
      )}
    </div>
  );
}

function Profile() {
  const nav = useNavigate();
  const queryClient = useQueryClient();
  const { user: authUser, setUser } = useContext(AuthContext);

  const [form, setForm] = useState({ first_name: "", last_name: "", phone_number: "" });
  const [consent, setConsent] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});
  const [justSubmittedDiscount, setJustSubmittedDiscount] = useState(false);
  const avatarInput = useRef(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => fetchProfile({ signal }),
  });

  const user = data?.user;

  // Seed the form once the server answers, and re-seed whenever the server's
  // copy changes underneath us (a save, an avatar upload, another tab).
  useEffect(() => {
    if (!user) return;

    setForm({
      first_name: user.first_name ?? "",
      last_name: user.last_name ?? "",
      phone_number: user.phone_number ?? "",
    });
    setConsent(Boolean(data?.profile?.privacy_consent));
  }, [user, data?.profile?.privacy_consent]);

  /** Write a mutation's payload back into the page cache and AuthContext. */
  const adopt = (payload) => {
    queryClient.setQueryData(QUERY_KEY, (prev) => ({ ...prev, ...payload }));

    // The header avatar and greeting read from AuthContext, not from here.
    if (payload?.user) setUser(payload.user);
  };

  const saveMutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: (payload) => {
      setFieldErrors({});
      adopt(payload);
      toast.success(payload?.message ?? "Your profile has been saved.");
    },
    onError: (err) => {
      const errors = err?.payload?.errors;

      // Field-level errors belong under their field, not in a toast that
      // disappears before the user has found what to fix.
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, list]) => [key, list[0]])));
        return;
      }

      toast.error(err?.message ?? "We could not save your profile.");
    },
  });

  const avatarMutation = useMutation({
    mutationFn: uploadAvatar,
    onSuccess: (payload) => {
      adopt(payload);
      toast.success("Profile picture updated.");
    },
    onError: (err) => toast.error(err?.message ?? "We could not upload that picture."),
  });

  const discountMutation = useMutation({
    mutationFn: applyForDiscount,
    onSuccess: (payload) => {
      queryClient.setQueryData(QUERY_KEY, (prev) => ({ ...prev, discount: { ...prev?.discount, ...payload } }));
      setJustSubmittedDiscount(true);
    },
    onError: (err) => {
      toast.error(err?.message ?? "We could not submit your application.");
      // The server refused because a claim already exists - refetch so the
      // card stops offering a button that cannot work.
      if (err?.payload?.code) refetch();
    },
  });

  const addressMutation = useMutation({
    mutationFn: ({ action, id, body }) => {
      if (action === "create") return createAddress(body);
      if (action === "update") return updateAddress(id, body);
      if (action === "delete") return deleteAddress(id);

      return setDefaultAddress(id);
    },
    onSuccess: (payload) => {
      queryClient.setQueryData(QUERY_KEY, (prev) => ({ ...prev, addresses: payload.addresses }));
      toast.success(payload?.message ?? "Addresses updated.");
    },
    onError: (err) => toast.error(err?.message ?? "We could not update your addresses."),
  });

  const phoneLockReason = data?.profile?.phone_locked_reason ?? null;

  const isDirty = useMemo(() => {
    if (!user) return false;

    return (
      form.first_name !== (user.first_name ?? "") ||
      form.last_name !== (user.last_name ?? "") ||
      form.phone_number !== (user.phone_number ?? "") ||
      consent !== Boolean(data?.profile?.privacy_consent)
    );
  }, [form, consent, user, data?.profile?.privacy_consent]);

  const set = (key) => (e) => {
    setForm((prev) => ({ ...prev, [key]: e.target.value }));
    setFieldErrors((prev) => ({ ...prev, [key]: undefined }));
  };

  const handleSave = () => {
    const body = {
      first_name: form.first_name,
      last_name: form.last_name,
      privacy_consent: consent,
    };

    // Only sent when it actually changed: the server refuses a phone edit while
    // the number is load-bearing, and re-sending an unchanged value would turn
    // "save my name" into that refusal.
    if (form.phone_number !== (user?.phone_number ?? "")) {
      body.phone_number = form.phone_number;
    }

    saveMutation.mutate(body);
  };

  const displayUser = user ?? authUser;

  if (isLoading) {
    return (
      <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
        <AppHeader />
        <div className="grid place-items-center gap-3 py-24">
          <Loader size="sm" color="#ff8b2b" />
          <p className="m-0 font-display text-[13px] text-[#8d8884]">Loading your profile&hellip;</p>
        </div>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
        <AppHeader />
        <div className="grid place-items-center gap-3 py-24 text-center">
          <IconAlertTriangle size={28} stroke={1.8} className="text-[#e5322d]" aria-hidden="true" />
          <p className="m-0 font-display text-[14px] text-ink">
            {error?.message ?? "We could not load your profile."}
          </p>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Try again
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      {/* No search props - see the note at the top of this file. */}
      <AppHeader />

      <main className="mx-auto w-full max-w-[1240px] px-4 py-6 sm:px-6 lg:px-8">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => nav(-1)}
              aria-label="Go back"
              className="grid h-9 w-9 place-items-center rounded-full bg-transparent text-brand-600 transition-colors hover:bg-[#fff0e0]"
            >
              <IconArrowLeft size={20} stroke={2} />
            </button>

            <h1 className="m-0 font-display text-[24px] font-bold tracking-[-0.4px] text-brand-600">
              Account Settings
            </h1>
          </div>

          {/* Display only. Earning and redeeming is a later module, so this
              shows the stored balance and offers no action. */}
          <div className="flex items-center gap-2.5 rounded-full border border-[#f0e9df] bg-white px-4 py-2">
            <IconTrophy size={19} stroke={1.9} aria-hidden="true" className="text-[#f0a020]" />
            <div className="leading-none">
              <p className="m-0 font-display text-[9.5px] font-bold uppercase tracking-[0.1em] text-[#a39f9b]">
                My points
              </p>
              <p className="m-0 mt-0.5 font-display text-[14px] font-extrabold text-ink">
                ★ {(data?.loyalty_points ?? 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>

        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
          <div className="grid min-w-0 gap-5">
            <SectionCard
              icon={IconUsers}
              title="Personal Information"
              subtitle="Make sure your details are accurate for smooth order processing"
            >
              <div className="mt-5 flex items-center gap-4">
                <div className="relative shrink-0">
                  {displayUser?.avatar ? (
                    <img
                      src={displayUser.avatar}
                      alt=""
                      className="h-[62px] w-[62px] rounded-full object-cover"
                    />
                  ) : (
                    <span className="grid h-[62px] w-[62px] place-items-center rounded-full bg-brand-500 font-display text-[21px] font-bold text-white">
                      {initialsFor(displayUser)}
                    </span>
                  )}

                  <input
                    ref={avatarInput}
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp"
                    className="hidden"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file) avatarMutation.mutate(file);
                      e.target.value = "";
                    }}
                  />

                  <button
                    type="button"
                    onClick={() => avatarInput.current?.click()}
                    disabled={avatarMutation.isPending}
                    aria-label="Change profile picture"
                    className="absolute -bottom-0.5 -right-0.5 grid h-[26px] w-[26px] place-items-center rounded-full border-2 border-white bg-brand-500 text-white transition-colors hover:bg-brand-600 disabled:opacity-60"
                  >
                    {avatarMutation.isPending ? (
                      <Loader size={12} color="white" />
                    ) : (
                      <IconCamera size={13} stroke={2.2} />
                    )}
                  </button>
                </div>

                <div className="min-w-0">
                  <p className="m-0 truncate font-display text-[16px] font-bold text-ink">
                    {`${displayUser?.first_name ?? ""} ${displayUser?.last_name ?? ""}`.trim() || "Your account"}
                  </p>
                  <p className="m-0 truncate font-display text-[12.5px] text-[#8d8884]">{displayUser?.email}</p>
                </div>
              </div>

              <div className="mt-5 grid gap-4">
                {/* Two fields rather than the prototype's single "Full Name".
                    The API stores first and last separately, and splitting one
                    box on whitespace mangles names like "dela Cruz" on the way
                    back in. */}
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField
                    id="first-name"
                    label="First Name"
                    required
                    value={form.first_name}
                    onChange={set("first_name")}
                    error={fieldErrors.first_name}
                  />
                  <TextField
                    id="last-name"
                    label="Last Name"
                    required
                    value={form.last_name}
                    onChange={set("last_name")}
                    error={fieldErrors.last_name}
                  />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField
                    id="email"
                    label="Email Address"
                    required
                    type="email"
                    value={displayUser?.email ?? ""}
                    onChange={() => {}}
                    disabled
                    hint="Your email signs you in, so changing it needs verification. Contact support."
                  />

                  <Tooltip
                    label={phoneLockReason}
                    disabled={!phoneLockReason}
                    withArrow
                    multiline
                    w={250}
                    position="top"
                  >
                    <div>
                      <TextField
                        id="phone"
                        label="Mobile Number"
                        required
                        value={form.phone_number}
                        onChange={set("phone_number")}
                        disabled={Boolean(phoneLockReason)}
                        maxLength={11}
                        hint={phoneLockReason}
                        error={fieldErrors.phone_number}
                      />
                    </div>
                  </Tooltip>
                </div>

                {/* Absent entirely for staff: the server omits `discount` from
                    their payload, so there is nothing to hide here. */}
                <DiscountCard
                  discount={data?.discount}
                  isSubmitting={discountMutation.isPending}
                  justSubmitted={justSubmittedDiscount}
                  onDismissSubmitted={() => setJustSubmittedDiscount(false)}
                  onApply={(input) => discountMutation.mutate(input)}
                />

                <label className="flex cursor-pointer items-start gap-2.5">
                  <input
                    type="checkbox"
                    checked={consent}
                    onChange={(e) => setConsent(e.target.checked)}
                    className="mt-0.5 h-[17px] w-[17px] shrink-0 cursor-pointer accent-brand-500"
                  />
                  <span className="font-display text-[12.5px] leading-snug text-[#6f6b68]">
                    I agree to allow BES Click-n-Chick to save my delivery address and order information.
                  </span>
                </label>
              </div>
            </SectionCard>

            <PasswordChangeCard />
          </div>

          <div className="grid gap-5">
            <AddressManager
              addresses={data?.addresses ?? []}
              isSaving={addressMutation.isPending}
              isBusy={addressMutation.isPending}
              onCreate={(body) => addressMutation.mutateAsync({ action: "create", body })}
              onUpdate={(id, body) => addressMutation.mutateAsync({ action: "update", id, body })}
              onDelete={(address) => addressMutation.mutate({ action: "delete", id: address.id })}
              onMakeDefault={(address) => addressMutation.mutate({ action: "default", id: address.id })}
            />

            <section className="rounded-2xl border border-[#f0e9df] bg-white p-5">
              <h2 className="m-0 font-display text-[15.5px] font-bold text-brand-600">Save Profile</h2>
              <p className="m-0 mt-1 font-display text-[12.5px] leading-relaxed text-[#8d8884]">
                Your saved details will be used automatically on your next order.
              </p>

              <Button
                fullWidth
                className="mt-4"
                disabled={!consent || !isDirty || saveMutation.isPending}
                loading={saveMutation.isPending}
                loadingLabel="Saving&hellip;"
                onClick={handleSave}
              >
                Save Changes
              </Button>

              {/* Says which of the two reasons applies, rather than leaving a
                  dead button with no explanation. */}
              <p className="m-0 mt-2 text-center font-display text-[11.5px] text-[#a39f9b]">
                {!consent
                  ? "Please check the consent box to save your profile"
                  : !isDirty
                    ? "No changes to save"
                    : "Ready to save"}
              </p>
            </section>
          </div>
        </div>
      </main>
    </div>
  );
}

export default Profile;
