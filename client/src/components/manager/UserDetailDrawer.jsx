import { useEffect, useState } from "react";
import { Drawer, Skeleton } from "@mantine/core";
import {
  IconCalendarEvent,
  IconCircleCheck,
  IconCircleX,
  IconClock,
  IconDevices,
  IconLock,
  IconMail,
  IconPhone,
  IconReceipt,
  IconRefresh,
} from "@tabler/icons-react";

import { Line, Section } from "../staff/DetailParts";
import Button from "../ui/Button";
import Input from "../ui/Input";
import { initialsOf } from "../../lib/agent";
import { formatRecordDate, formatRecordStamp } from "../../lib/records";
import { ROLE_LABELS, ROLE_OPTIONS, ROLE_PILL, STATUS_META, discountLabel } from "../../lib/users";

export function RolePill({ role }) {
  return (
    <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-[3px] font-display text-[10.5px] font-semibold ring-1 ring-inset ${ROLE_PILL[role]}`}>
      {ROLE_LABELS[role] ?? role}
    </span>
  );
}

export function StatusPill({ status }) {
  const meta = STATUS_META[status];

  return (
    <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-[3px] font-display text-[10.5px] font-semibold ring-1 ring-inset ${meta.pill}`}>
      {meta.label}
    </span>
  );
}

function Verified({ label, verified }) {
  const Icon = verified ? IconCircleCheck : IconCircleX;

  return (
    <Line icon={Icon}>
      <span className={verified ? "text-[#2f9e44]" : "text-[#8d8884]"}>
        {label} {verified ? "verified" : "not verified"}
      </span>
    </Line>
  );
}

function discountText(user) {
  if (user.discount) return `${discountLabel(user.discount.type)} discount approved`;
  if (user.latest_claim?.status === "pending") return `${discountLabel(user.latest_claim.type)} application waiting for a Store Agent`;
  if (user.latest_claim?.status === "rejected") return `Last ${discountLabel(user.latest_claim.type)} application was rejected`;

  return "No discount on this account";
}

function NameEditor({ user, onSave }) {
  const [editing, setEditing] = useState(false);
  const [first, setFirst] = useState(user.first_name ?? "");
  const [last, setLast] = useState(user.last_name ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    setEditing(false);
    setFirst(user.first_name ?? "");
    setLast(user.last_name ?? "");
    setError(null);
  }, [user.id, user.first_name, user.last_name]);

  if (!editing) {
    return (
      <button type="button" onClick={() => setEditing(true)} data-testid="edit-name" className="bg-transparent font-semibold text-brand-600 hover:text-brand-800">
        <span className="text-[12px]">Correct name</span>
      </button>
    );
  }

  const save = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await onSave({ first_name: first.trim(), last_name: last.trim() });
      setEditing(false);
    } catch (failure) {
      setError(failure.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={save} className="flex flex-col gap-2">
      <Input value={first} onChange={(event) => setFirst(event.target.value)} placeholder="First name" aria-label="First name" data-testid="name-first" />
      <Input value={last} onChange={(event) => setLast(event.target.value)} placeholder="Last name" aria-label="Last name" data-testid="name-last" />
      {error && <p className="m-0 font-display text-[12px] text-[#e5322d]">{error}</p>}
      <div className="flex justify-end gap-2">
        <Button size="sm" variant="ghost" onClick={() => setEditing(false)} disabled={busy}>
          Cancel
        </Button>
        <Button size="sm" type="submit" loading={busy} disabled={!first.trim() || !last.trim()} data-testid="name-save">
          Save
        </Button>
      </div>
    </form>
  );
}

function RoleEditor({ user, onChange }) {
  const [role, setRole] = useState(user.role);

  useEffect(() => setRole(user.role), [user.id, user.role]);

  return (
    <div className="flex items-center gap-2">
      <select
        value={role}
        onChange={(event) => setRole(event.target.value)}
        aria-label="Role"
        data-testid="detail-role"
        className="h-[38px] min-w-0 flex-1 rounded-[9px] border border-[#e8e4de] bg-white px-2.5 font-display text-[13px] text-ink outline-none focus:border-brand-500"
      >
        {ROLE_OPTIONS.map((option) => (
          <option key={option.key} value={option.key}>
            {option.label}
          </option>
        ))}
      </select>
      <Button size="sm" variant="outline" disabled={role === user.role} onClick={() => onChange(user, role)} data-testid="detail-role-save">
        Change
      </Button>
    </div>
  );
}

const DANGER_BUTTON =
  "h-[38px] rounded-[9px] border border-[#ffd7d5] bg-white font-display font-semibold text-[#e5322d] transition-colors hover:bg-[#fff1f1]";

/** One account: who, its standing, and what the server says may be done to it. */
function UserDetailDrawer({ opened, onClose, user, isLoading, isError, error, onRetry, onRename, onChangeRole, onSuspend, onReactivate, onDelete }) {
  return (
    <Drawer
      opened={opened}
      onClose={onClose}
      position="right"
      size="min(100%, 400px)"
      padding={0}
      radius={0}
      title={<span className="font-display text-[15px] font-bold text-ink">Account details</span>}
      styles={{ header: { padding: "14px 20px", borderBottom: "1px solid #f3ede4" }, body: { padding: 0 } }}
    >
      {isLoading ? (
        <div className="flex flex-col gap-4 px-5 py-5" aria-hidden="true">
          <Skeleton height={72} radius={12} />
          <Skeleton height={100} />
          <Skeleton height={100} />
        </div>
      ) : isError || !user ? (
        <div className="flex flex-col items-center gap-3 px-6 py-12 text-center" data-testid="user-detail-error">
          <p className="m-0 font-display text-[13px] text-[#8d8884]">{error?.message ?? "This account could not be loaded."}</p>
          {error?.status !== 404 && (
            <Button size="sm" variant="outline" onClick={onRetry}>
              <IconRefresh size={16} stroke={2} />
              Try again
            </Button>
          )}
        </div>
      ) : (
        <div data-testid="user-detail" data-user={user.id}>
          <div className="px-5 py-4">
            <div className="flex items-center gap-3">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-brand-500 font-display text-[15px] font-bold text-white">
                {initialsOf(user.name ?? user.email ?? user.phone_masked ?? "")}
              </span>
              <div className="min-w-0">
                <p className="m-0 truncate font-display text-[15px] font-bold text-ink">{user.name ?? "No name"}</p>
                <p className="m-0 font-display text-[11px] text-[#a39f9b]">ID #{user.id}</p>
              </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              <RolePill role={user.role} />
              <StatusPill status={user.status} />
            </div>
            {user.can.edit && (
              <div className="mt-3">
                <NameEditor user={user} onSave={(values) => onRename(user, values)} />
              </div>
            )}
          </div>

          <Section title="Contact">
            <div className="flex flex-col gap-2">
              {user.email && <Line icon={IconMail}>{user.email}</Line>}
              {user.phone_masked && <Line icon={IconPhone}>{user.phone_masked}</Line>}
            </div>
          </Section>

          <Section title="Security">
            <div className="flex flex-col gap-2">
              <Verified label="Email" verified={user.verification.email_verified} />
              <Verified label="Phone" verified={user.verification.phone_verified} />
              <Line icon={IconLock}>Two-factor {user.two_factor_enabled ? "on" : "off"}</Line>
              <Line icon={IconDevices}>
                {user.active_sessions} active {user.active_sessions === 1 ? "session" : "sessions"}
              </Line>
            </div>
          </Section>

          <Section title="Activity">
            <div className="flex flex-col gap-2">
              <Line icon={IconCalendarEvent}>Joined {formatRecordDate(user.joined_at)}</Line>
              {user.last_seen_at && <Line icon={IconClock}>Last seen {formatRecordStamp(user.last_seen_at)}</Line>}
              <Line icon={IconReceipt}>
                {user.orders_count} {user.orders_count === 1 ? "order" : "orders"}
              </Line>
            </div>
          </Section>

          <Section title="Discount">
            <p className="m-0 font-display text-[13px] text-[#5f5b58]" data-testid="detail-discount">
              {discountText(user)}
            </p>
          </Section>

          {user.is_self ? (
            <Section title="Your account">
              <p className="m-0 font-display text-[12px] leading-relaxed text-[#8d8884]">
                You cannot change your own role or status. Another Store Manager has to do that.
              </p>
            </Section>
          ) : (
            <>
              {user.can.change_role && (
                <Section title="Role">
                  <RoleEditor user={user} onChange={onChangeRole} />
                </Section>
              )}

              <Section title="Account access">
                <div className="flex flex-col gap-2 text-[13px]">
                  {user.can.suspend && (
                    <button type="button" onClick={() => onSuspend(user)} data-testid="detail-suspend" className={DANGER_BUTTON}>
                      Suspend account
                    </button>
                  )}
                  {user.can.reactivate && (
                    <Button size="sm" variant="outline" onClick={() => onReactivate(user)} data-testid="detail-reactivate">
                      Reactivate account
                    </Button>
                  )}
                  {user.can.delete && (
                    <button type="button" onClick={() => onDelete(user)} data-testid="detail-delete" className={DANGER_BUTTON}>
                      Delete account
                    </button>
                  )}
                </div>
              </Section>
            </>
          )}
        </div>
      )}
    </Drawer>
  );
}

export default UserDetailDrawer;
