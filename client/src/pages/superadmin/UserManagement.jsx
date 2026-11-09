import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Pagination, Skeleton } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { useQueryClient } from "@tanstack/react-query";
import { IconBan, IconCirclePlus, IconLockOpen, IconPencil, IconRefresh, IconTrash, IconUsers } from "@tabler/icons-react";

import toast from "../../components/app/Toast";
import ConfirmActionModal from "../../components/manager/ConfirmActionModal";
import PasswordConfirmModal from "../../components/manager/PasswordConfirmModal";
import UserDetailDrawer, { RolePill, StatusPill } from "../../components/manager/UserDetailDrawer";
import UserFormModal from "../../components/manager/UserFormModal";
import FilterBar, { FilterMeta } from "../../components/staff/FilterBar";
import FilterSelect from "../../components/staff/FilterSelect";
import StaffLayout from "../../components/staff/StaffLayout";
import StatusSummary from "../../components/staff/StatusSummary";
import Button from "../../components/ui/Button";
import { useStepUp } from "../../hooks/useStepUp";
import { useUser, useUsers } from "../../hooks/useUsers";
import { initialsOf } from "../../lib/agent";
import { formatRecordDate } from "../../lib/records";
import { ROLES } from "../../lib/roles";
import {
  ROLE_LABELS,
  STATUS_FILTERS,
  USER_DEFAULTS,
  USERS_KEY,
  changeUserRole,
  createUser,
  deleteUser,
  discountLabel,
  reactivateUser,
  readUserFilters,
  renameUser,
  suspendUser,
  userKey,
  userQueryParams,
} from "../../lib/users";

const PAGE_TITLE = "User Management · Click n Chick";

const ROLE_TABS = [
  { key: "all", label: "ALL", field: "total" },
  { key: ROLES.SUPER_ADMIN, label: "Store Manager", field: "store_managers" },
  { key: ROLES.ADMIN, label: "Store Agent", field: "store_agents" },
  { key: ROLES.CUSTOMER, label: "Customer", field: "customers" },
];

const SUMMARY = [
  { key: "total", label: "Total Users", tone: "slate", field: "total" },
  { key: "active", label: "Active", tone: "green", field: "active" },
  { key: "suspended", label: "Suspended", tone: "orange", field: "suspended" },
  { key: "agents", label: "Store Agents", tone: "blue", field: "store_agents" },
  { key: "discounts", label: "Approved SC/PWD", tone: "amber", field: "discount_eligible" },
];

const FILTER_KEYS = ["q", "role", "status", "discount"];

const GRID =
  "grid grid-cols-[minmax(170px,1.5fr)_minmax(180px,1.5fr)_minmax(112px,0.8fr)_minmax(104px,0.8fr)_minmax(84px,0.6fr)_minmax(68px,0.5fr)_minmax(92px,0.7fr)_96px] items-center gap-x-3";

function Avatar({ user }) {
  return (
    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 font-display text-[12px] font-bold text-white">
      {initialsOf(user.name ?? user.email)}
    </span>
  );
}

function DiscountPill({ discount }) {
  if (!discount) return <span className="font-display text-[12px] text-[#c4bdb4]">—</span>;

  return (
    <span className="inline-flex rounded-full bg-[#f3ecff] px-2.5 py-[3px] font-display text-[10.5px] font-semibold text-[#7c3aed]">
      {discountLabel(discount.type)}
    </span>
  );
}

function IconAction({ label, onClick, tone = "text-[#a39f9b] hover:text-ink", testId, children }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      data-testid={testId}
      onClick={(event) => {
        event.stopPropagation();
        onClick();
      }}
      className={`grid h-7 w-7 place-items-center rounded-[7px] bg-transparent transition-colors hover:bg-[#f4f1ec] ${tone}`}
    >
      {children}
    </button>
  );
}

function RowActions({ user, actions }) {
  const name = user.name ?? user.email;

  return (
    <span className="flex items-center gap-0.5">
      <IconAction label={`Open ${name}`} onClick={() => actions.open(user)} testId="user-open">
        <IconPencil size={15} stroke={1.9} />
      </IconAction>
      {user.can.suspend && (
        <IconAction label={`Suspend ${name}`} onClick={() => actions.suspend(user)} tone="text-[#a39f9b] hover:text-[#c98500]" testId="user-suspend">
          <IconBan size={15} stroke={1.9} />
        </IconAction>
      )}
      {user.can.reactivate && (
        <IconAction label={`Reactivate ${name}`} onClick={() => actions.reactivate(user)} tone="text-[#a39f9b] hover:text-[#2f9e44]" testId="user-reactivate">
          <IconLockOpen size={15} stroke={1.9} />
        </IconAction>
      )}
      {user.can.delete && (
        <IconAction label={`Delete ${name}`} onClick={() => actions.remove(user)} tone="text-[#a39f9b] hover:text-[#e5322d]" testId="user-delete">
          <IconTrash size={15} stroke={1.9} />
        </IconAction>
      )}
    </span>
  );
}

function UserName({ user }) {
  return (
    <span className="min-w-0">
      <span className="block truncate font-display text-[13px] font-semibold text-ink">
        {user.name ?? "No name"}
        {user.is_self && <span className="ml-1.5 text-[10.5px] font-medium text-brand-600">You</span>}
      </span>
      <span className="block font-display text-[10.5px] text-[#a39f9b]">ID #{user.id}</span>
    </span>
  );
}

function UserTable({ users, loading, actions }) {
  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className="min-w-[980px]">
        <div className={`${GRID} border-b border-[#f3ede4] px-4 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}>
          <span>User</span>
          <span>Email</span>
          <span>Phone</span>
          <span>Role</span>
          <span>Status</span>
          <span>Discount</span>
          <span>Joined</span>
          <span>Actions</span>
        </div>

        {loading
          ? [0, 1, 2, 3, 4, 5].map((key) => (
              <div key={key} className={`${GRID} border-b border-[#f6f1ea] px-4 py-3.5 last:border-b-0`}>
                <Skeleton height={32} />
                <Skeleton height={14} />
                <Skeleton height={14} />
                <Skeleton height={20} radius="xl" />
                <Skeleton height={20} radius="xl" />
                <Skeleton height={14} width={30} />
                <Skeleton height={14} />
                <Skeleton height={20} />
              </div>
            ))
          : users.map((user) => (
              <div
                key={user.id}
                onClick={() => actions.open(user)}
                data-testid="user-row"
                data-user={user.id}
                className={`${GRID} cursor-pointer border-b border-[#f6f1ea] px-4 py-2.5 transition-colors last:border-b-0 hover:bg-[#fffaf4]`}
              >
                <span className="flex min-w-0 items-center gap-2.5">
                  <Avatar user={user} />
                  <UserName user={user} />
                </span>
                <span className="truncate font-display text-[12.5px] text-[#5f5b58]" title={user.email}>
                  {user.email}
                </span>
                <span className="font-display text-[12.5px] tabular-nums text-[#5f5b58]">{user.phone_masked ?? "—"}</span>
                <span>
                  <RolePill role={user.role} />
                </span>
                <span>
                  <StatusPill status={user.status} />
                </span>
                <span>
                  <DiscountPill discount={user.discount} />
                </span>
                <span className="font-display text-[12px] text-[#8d8884]">{formatRecordDate(user.joined_at)}</span>
                <RowActions user={user} actions={actions} />
              </div>
            ))}
      </div>
    </div>
  );
}

function UserCards({ users, loading, actions }) {
  if (loading) {
    return (
      <div className="flex flex-col gap-2.5">
        {[0, 1, 2, 3].map((key) => (
          <Skeleton key={key} height={112} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {users.map((user) => (
        <article key={user.id} onClick={() => actions.open(user)} data-testid="user-row" data-user={user.id} className="cursor-pointer rounded-[14px] bg-white p-3.5 ring-1 ring-[#f0e9df]">
          <div className="flex items-center gap-2.5">
            <Avatar user={user} />
            <UserName user={user} />
            <span className="ml-auto">
              <StatusPill status={user.status} />
            </span>
          </div>
          <p className="m-0 mt-2 truncate font-display text-[12.5px] text-[#5f5b58]">{user.email}</p>
          <div className="mt-2 flex items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-2">
            <span className="flex flex-wrap items-center gap-1.5">
              <RolePill role={user.role} />
              {user.discount && <DiscountPill discount={user.discount} />}
            </span>
            <RowActions user={user} actions={actions} />
          </div>
        </article>
      ))}
    </div>
  );
}

/**
 * User Management (UC-ADMIN-001, 002), for the Store Manager.
 *
 * What may be done to an account comes from the server's `can` on each row, and
 * every action is enforced there again. Filters and the open account live in
 * the URL. Sensitive changes go through the server's password step-up.
 */
function UserManagement() {
  const wide = useMediaQuery("(min-width: 64em)", true);
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = readUserFilters(searchParams);
  const selectedId = searchParams.get("user");

  const [searchText, setSearchText] = useState(filters.q);
  const [creating, setCreating] = useState(false);
  const [confirming, setConfirming] = useState(null);

  const list = useUsers(userQueryParams(filters));
  const detail = useUser(selectedId);
  const stepUp = useStepUp();

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  const write = (changes, { resetPage = true, push = false } = {}) => {
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current);

        Object.entries(changes).forEach(([key, value]) => {
          if (value === null || value === "" || value === USER_DEFAULTS[key]) next.delete(key);
          else next.set(key, value);
        });

        if (resetPage && !("page" in changes)) next.delete("page");

        return next;
      },
      { replace: !push }
    );
  };

  useEffect(() => {
    if (searchText.trim() === filters.q.trim()) return undefined;

    const timer = setTimeout(() => write({ q: searchText.trim() }), 300);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchText]);

  const lastPage = list.meta?.last_page ?? 1;
  const page = Number(filters.page);

  useEffect(() => {
    if (list.meta && !list.isPlaceholder && page > lastPage) write({ page: String(lastPage) }, { resetPage: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, lastPage, list.meta, list.isPlaceholder]);

  const open = (user) => write({ user: String(user.id) }, { resetPage: false });
  const close = () => write({ user: null }, { resetPage: false });

  const refresh = () => queryClient.invalidateQueries({ queryKey: USERS_KEY });

  /** Applies a response carrying the account, so the drawer updates at once. */
  const settled = (response) => {
    toast.success(response.message, "Saved");

    if (response.data) queryClient.setQueryData(userKey(response.data.id), { data: response.data });

    return refresh();
  };

  const suspend = (user) =>
    setConfirming({
      title: `Suspend ${user.name ?? user.email}?`,
      points: ["They are signed out everywhere and cannot sign in.", "Their orders, discount and details stay as they are.", "You can reactivate the account at any time."],
      confirmLabel: "Suspend account",
      danger: true,
      run: async () =>
        settled(
          await stepUp.run((password) => suspendUser(user.id, password), {
            title: "Confirm the suspension",
            description: `Suspending ${user.name ?? user.email} needs your password.`,
          })
        ),
    });

  const reactivate = (user) =>
    setConfirming({
      title: `Reactivate ${user.name ?? user.email}?`,
      points: ["They can sign in again with their existing password."],
      confirmLabel: "Reactivate account",
      run: async () =>
        settled(
          await stepUp.run((password) => reactivateUser(user.id, password), {
            title: "Confirm the reactivation",
            description: `Reactivating ${user.name ?? user.email} needs your password.`,
          })
        ),
    });

  const changeRole = (user, role) =>
    setConfirming({
      title: `Make ${user.name ?? user.email} a ${ROLE_LABELS[role]}?`,
      points: [`Their access changes from ${ROLE_LABELS[user.role]} to ${ROLE_LABELS[role]}.`, "They are signed out everywhere and sign in again with the new role."],
      confirmLabel: "Change role",
      run: async () =>
        settled(
          await stepUp.run((password) => changeUserRole(user.id, role, password), {
            title: "Confirm the role change",
            description: `Changing a role needs your password.`,
          })
        ),
    });

  const remove = (user) =>
    setConfirming({
      title: "Remove user",
      points: [
        `${user.name ?? user.email}'s account, contact details, saved addresses, discount record and ID photo are permanently erased.`,
        "Past orders and payments are kept for the store's records, shown as “Deleted customer”.",
        "This action cannot be undone. To block access for now, suspend the account instead.",
      ],
      confirmLabel: "Yes, remove user",
      danger: true,
      acknowledge: `I understand that ${user.name ?? user.email} will be permanently deleted.`,
      run: async () => {
        const response = await stepUp.run((password) => deleteUser(user.id, password), {
          title: "Confirm the deletion",
          description: `Permanently deleting ${user.name ?? user.email} needs your password.`,
        });

        toast.success(response.message, "Account deleted");

        if (selectedId === String(user.id)) close();

        queryClient.removeQueries({ queryKey: userKey(user.id) });
        queryClient.invalidateQueries({ queryKey: ["orders"] });

        return refresh();
      },
    });

  const create = async (values) =>
    settled(
      await stepUp.run((password) => createUser(values, password), {
        title: "Confirm the new account",
        description: `Creating an account needs your password.`,
      })
    );

  const rename = async (user, values) => settled(await renameUser(user.id, values));

  const onSummary = (key) => {
    const target = {
      total: { role: "all", status: "all", discount: "all" },
      active: { status: filters.status === "active" ? "all" : "active" },
      suspended: { status: filters.status === "suspended" ? "all" : "suspended" },
      agents: { role: filters.role === ROLES.ADMIN ? "all" : ROLES.ADMIN },
      discounts: { discount: filters.discount === "eligible" ? "all" : "eligible" },
    }[key];

    write(target);
  };

  const filtered = FILTER_KEYS.some((key) => filters[key] !== USER_DEFAULTS[key]);

  const clear = () => {
    setSearchText("");
    write({ q: "", role: "all", status: "all", discount: "all" });
  };

  const summaryCards = SUMMARY.map((card) => ({ ...card, total: list.summary?.[card.field] ?? 0 }));
  const actions = { open, suspend, reactivate, remove };
  const meta = list.meta;

  return (
    <StaffLayout>
      <main className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="min-w-0">
              <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">User Management</h1>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">Accounts, roles and access for the store.</p>
            </div>

            <button
              type="button"
              onClick={() => setCreating(true)}
              data-testid="add-user"
              className="inline-flex h-[38px] w-full items-center justify-center gap-1.5 rounded-full bg-brand-500 px-4 font-display font-semibold text-white shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600 md:w-auto md:shrink-0"
            >
              <IconCirclePlus size={16} stroke={2} />
              <span className="text-[13px]">Add User</span>
            </button>
          </div>

          <div className="mt-4">
            <StatusSummary columns={summaryCards} loading={list.isLoading} onSelect={onSummary} />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          <FilterBar
            testId="user-filters"
            search={{
              value: searchText,
              onChange: setSearchText,
              placeholder: "Search by name or email…",
              title: "Search by name, email, ID or full phone number",
              label: "Search users",
            }}
            tabs={{
              items: ROLE_TABS.map((tab) => ({ key: tab.key, label: tab.label, count: list.summary?.[tab.field] })),
              value: filters.role,
              onChange: (role) => write({ role }),
              label: "Filter by role",
              testIdPrefix: "role-tab",
            }}
          >
            <FilterSelect label="Status" value={filters.status} onChange={(status) => write({ status })} options={STATUS_FILTERS} active={filters.status !== "all"} testId="filter-status" />
            <FilterMeta onClear={filtered ? clear : undefined} />
          </FilterBar>

          {list.isError && !meta ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]" data-testid="users-error">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The accounts could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{list.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => list.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : !list.isLoading && list.users.length === 0 ? (
            <div data-testid="users-empty" className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center">
              <IconUsers size={28} stroke={1.6} className="text-[#cfc7bd]" />
              <p className="m-0 font-display text-[14px] font-semibold text-ink">{filtered ? "No account matches these filters." : "No accounts yet."}</p>
              {filtered && (
                <Button size="sm" variant="ghost" onClick={clear}>
                  Clear filters
                </Button>
              )}
            </div>
          ) : (
            <div className={`transition-opacity ${list.isPlaceholder ? "opacity-60" : ""}`}>
              {wide ? <UserTable users={list.users} loading={list.isLoading} actions={actions} /> : <UserCards users={list.users} loading={list.isLoading} actions={actions} />}
            </div>
          )}

          {meta && meta.total > 0 && (
            <div className="flex flex-col items-center justify-between gap-2.5 sm:flex-row">
              <p className="m-0 font-display text-[11.5px] text-[#8d8884]" data-testid="page-range">
                Showing {meta.from}–{meta.to} of {meta.total}
              </p>
              {meta.last_page > 1 && (
                <Pagination
                  total={meta.last_page}
                  value={Math.min(page, meta.last_page)}
                  onChange={(next) => write({ page: String(next) }, { resetPage: false, push: true })}
                  size="sm"
                  radius="xl"
                  color="orange"
                  siblings={wide ? 1 : 0}
                  boundaries={1}
                />
              )}
            </div>
          )}
        </div>
      </main>

      <UserDetailDrawer
        opened={Boolean(selectedId)}
        onClose={close}
        user={detail.user}
        isLoading={detail.isLoading}
        isError={detail.isError}
        error={detail.error}
        onRetry={() => detail.refetch()}
        onRename={rename}
        onChangeRole={changeRole}
        onSuspend={suspend}
        onReactivate={reactivate}
        onDelete={remove}
      />

      <UserFormModal opened={creating} onClose={() => setCreating(false)} onSubmit={create} />

      <ConfirmActionModal opened={Boolean(confirming)} onClose={() => setConfirming(null)} onConfirm={() => confirming.run()} {...(confirming ?? {})} />

      <PasswordConfirmModal {...stepUp.modalProps} zIndex={500} />
    </StaffLayout>
  );
}

export default UserManagement;
