import { useContext, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Drawer } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { AnimatePresence } from "framer-motion";
import { IconIdBadge2, IconRefresh } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import ConfirmActionModal from "../../components/manager/ConfirmActionModal";
import PasswordConfirmModal from "../../components/manager/PasswordConfirmModal";
import ClaimCard from "../../components/staff/ClaimCard";
import ClaimReviewPanel from "../../components/staff/ClaimReviewPanel";
import FilterBar from "../../components/staff/FilterBar";
import LivePill from "../../components/staff/LivePill";
import ReadOnlyNotice from "../../components/staff/ReadOnlyNotice";
import RejectClaimModal from "../../components/staff/RejectClaimModal";
import StaffLayout from "../../components/staff/StaffLayout";
import Button from "../../components/ui/Button";
import { useDiscountQueue } from "../../hooks/useDiscountQueue";
import { CLAIM_TABS, CLAIM_TYPE, claimLabel, claimantName, matchesClaim } from "../../lib/agent";
import { canOperate } from "../../lib/roles";

const EMPTY = {
  pending: {
    title: "No IDs waiting for review.",
    detail: "A new application appears here the moment a customer submits one.",
  },
  approved: { title: "No approved applications yet.", detail: "Approved Senior Citizen and PWD IDs are listed here." },
  rejected: { title: "No rejected applications.", detail: "Rejected IDs are listed here with the reason given." },
};

function SkeletonList() {
  return (
    <div className="flex flex-col gap-2.5" aria-hidden="true">
      {[0, 1, 2].map((key) => (
        <div key={key} className="h-[74px] animate-pulse rounded-[12px] border border-[#f0e9df] bg-white" />
      ))}
    </div>
  );
}

function decisionCopy({ kind, claim, reason }, percentage) {
  const name = claimantName(claim);
  const type = CLAIM_TYPE[claim.discount_type]?.short ?? "discount";

  if (kind === "approve") {
    return {
      title: `Approve ${name}'s ${type} discount?`,
      points: [
        `They get the VAT exemption and ${percentage ?? 20}% discount on one marked item per order, once a day, from now on.`,
        "Make sure the ID photo is clear, valid, and matches the customer.",
      ],
      confirmLabel: "Approve",
    };
  }

  return {
    title: `Reject ${name}'s application?`,
    points: [`They are told: "${reason}"`, "They can apply again with a new photo."],
    confirmLabel: "Reject",
  };
}

function undoCopy(claim) {
  const name = claimantName(claim);
  const approved = claim.discount_status === "approved";

  return approved
    ? `The application goes back to Pending for a fresh review, and ${name} is told. Their discount cannot be used until it is approved again; orders already placed keep it. Enter your password to confirm.`
    : `The application goes back to Pending for a fresh review, and ${name} is told. Enter your password to confirm.`;
}

/**
 * Dicsount Requests: Senior Citizen and PWD IDs waiting for a Store Agent.
 *
 * A decision settles the customer's eligibility, not one order - the discount
 * is then theirs at checkout. Pending applications are served oldest first.
 * New applications and other agents' decisions arrive over admin.discounts.
 * The Store Manager gets the same queue, read-only.
 */
function DiscountRequests() {
  const { user } = useContext(AuthContext);
  const canAct = canOperate(user);
  const wide = useMediaQuery("(min-width: 64em)", true);
  const [searchParams, setSearchParams] = useSearchParams();
  const [search, setSearch] = useState("");
  const [rejecting, setRejecting] = useState(null);
  // Decided 2026-11-23: approve and reject ask yes or no first; undo asks for
  // the password every time.
  const [confirming, setConfirming] = useState(null);
  const [undoing, setUndoing] = useState(null);
  const [undoError, setUndoError] = useState(null);
  // The wide layout picks a claim on its own; on a phone that must not pop
  // the review drawer open, so the drawer follows the agent's own taps only.
  const [tapped, setTapped] = useState(false);

  const status = CLAIM_TABS.some((tab) => tab.key === searchParams.get("status")) ? searchParams.get("status") : "pending";
  const selectedId = searchParams.get("claim");

  const queue = useDiscountQueue(status);
  const searching = search.trim() !== "";

  const visible = useMemo(
    () => (searching ? queue.claims.filter((claim) => matchesClaim(claim, search)) : queue.claims),
    [queue.claims, search, searching]
  );

  const listed = selectedId ? queue.claims.find((claim) => String(claim.id) === selectedId) : null;
  // A claim this agent just decided has left the Pending list; the panel keeps
  // showing its outcome until they move on.
  const selected = listed ?? (selectedId ? queue.decided[selectedId] ?? null : null);

  const nextClaim =
    canAct && status === "pending" ? queue.claims.find((claim) => claim.id !== selected?.id && !queue.decided[claim.id]) : null;

  const select = (claim, { auto = false } = {}) => {
    setTapped(Boolean(claim) && !auto);
    setSearchParams(
      (params) => {
        if (claim) params.set("claim", String(claim.id));
        else params.delete("claim");

        return params;
      },
      { replace: true }
    );
  };

  const changeTab = (key) => {
    setSearchParams(
      (params) => {
        if (key === "pending") params.delete("status");
        else params.set("status", key);
        params.delete("claim");

        return params;
      },
      { replace: true }
    );
  };

  // On a wide screen the panel is always there, so it opens on the
  // longest-waiting application rather than an empty card - including when the
  // one on screen was just settled by another agent and left the list.
  const first = queue.claims[0];
  const missing = !selected && !queue.isLoading;

  useEffect(() => {
    if (wide && missing && first) select(first, { auto: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wide, missing, first?.id]);

  const pendingCount = queue.counts?.pending ?? 0;

  useEffect(() => {
    document.title =
      pendingCount > 0 ? `(${pendingCount}) Discount Requests · Click n Chick` : "Discount Requests · Click n Chick";
  }, [pendingCount]);

  useEffect(() => () => {
    document.title = "Chick N' Click";
  }, []);

  // The hook has already said why a decision failed, so the yes/no prompt
  // just closes either way.
  const decide = async () => {
    const { kind, claim, reason } = confirming;

    try {
      if (kind === "approve") await queue.approve(claim);
      else await queue.reject(claim, reason);
    } catch {
      // Toasted by the hook.
    }

    if (kind === "reject") setRejecting(null);
  };

  const askUndo = (claim) => {
    setUndoError(null);
    setUndoing(claim);
  };

  const confirmUndo = async (password) => {
    setUndoError(null);

    try {
      await queue.undo(undoing, password);
      setUndoing(null);
    } catch (error) {
      if (["PASSWORD_CONFIRMATION_INVALID", "PASSWORD_CONFIRMATION_THROTTLED"].includes(error?.payload?.error_code)) {
        setUndoError(error.message);
      } else {
        setUndoing(null);
      }
    }
  };

  const busy = Boolean(selected && queue.pendingId === selected.id);

  const panel = (
    <ClaimReviewPanel
      claim={selected}
      percentage={queue.percentage}
      busy={busy}
      approving={queue.isApproving}
      canAct={canAct}
      onApprove={(claim) => setConfirming({ kind: "approve", claim })}
      onReject={setRejecting}
      onUndo={askUndo}
      nextClaim={nextClaim}
      onNext={select}
    />
  );

  const empty = EMPTY[status];

  return (
    <StaffLayout>
      <div className="flex flex-1">
        <main className="flex min-w-0 flex-1 flex-col">
          <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2.5">
                  <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Discount Request</h1>
                  <LivePill />
                </div>
                <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                  Review Senior Citizen and PWD discount IDs submitted by customers
                </p>
              </div>
            </div>
          </header>

          <div className="flex flex-1 flex-col gap-2.5 px-4 py-4 sm:px-6 lg:px-7">
            {!canAct && <ReadOnlyNotice>IDs are reviewed by the Store Agent. You are viewing applications read-only.</ReadOnlyNotice>}

            <FilterBar
              search={{
                value: search,
                onChange: setSearch,
                placeholder: "Search applications…",
                title: "Search by application number, name, email or phone",
                label: "Search applications",
              }}
              tabs={{
                items: CLAIM_TABS.map((tab) => ({ ...tab, count: queue.counts?.[tab.key] })),
                value: status,
                onChange: changeTab,
                label: "Application status",
                testIdPrefix: "claim-tab",
              }}
            />

            {queue.isError ? (
              <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
                <p className="m-0 font-display text-[15px] font-semibold text-ink">The applications could not be loaded.</p>
                <p className="m-0 font-display text-[13px] text-[#8d8884]">{queue.error?.message}</p>
                <Button size="sm" variant="outline" onClick={() => queue.refetch()}>
                  <IconRefresh size={16} stroke={2} />
                  Try again
                </Button>
              </div>
            ) : queue.isLoading ? (
              <SkeletonList />
            ) : visible.length === 0 ? (
              <div
                data-testid="claims-empty"
                className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center"
              >
                <IconIdBadge2 size={28} stroke={1.6} className="text-[#cfc7bd]" />
                <p className="m-0 font-display text-[14px] font-semibold text-ink">
                  {searching ? "No application matches that search." : empty.title}
                </p>
                {!searching && <p className="m-0 font-display text-[12px] text-[#8d8884]">{empty.detail}</p>}
              </div>
            ) : (
              <>
                <AnimatePresence mode="popLayout" initial={false}>
                  {visible.map((claim) => (
                    <ClaimCard
                      key={claim.id}
                      claim={claim}
                      selected={String(claim.id) === selectedId}
                      onSelect={select}
                    />
                  ))}
                </AnimatePresence>

                {queue.hasMore && (
                  <Button
                    size="sm"
                    variant="ghost"
                    className="self-center"
                    loading={queue.isLoadingMore}
                    onClick={() => queue.loadMore()}
                  >
                    Load more
                  </Button>
                )}
              </>
            )}
          </div>
        </main>

        {wide && (
          <aside
            aria-label="Application review"
            className="sticky top-0 h-dvh w-[336px] shrink-0 overflow-y-auto border-l border-[#f0e9df] bg-white"
          >
            {panel}
          </aside>
        )}
      </div>

      {!wide && (
        <Drawer
          opened={Boolean(selectedId) && tapped}
          onClose={() => select(null)}
          position="right"
          size="min(100%, 380px)"
          padding={0}
          radius={0}
          aria-label="Application review"
        >
          {panel}
        </Drawer>
      )}

      <RejectClaimModal
        claim={rejecting}
        opened={Boolean(rejecting)}
        onClose={() => setRejecting(null)}
        onConfirm={(reason) => setConfirming({ kind: "reject", claim: rejecting, reason })}
        loading={queue.isRejecting}
      />

      <ConfirmActionModal
        opened={Boolean(confirming)}
        onClose={() => setConfirming(null)}
        onConfirm={decide}
        zIndex={400}
        danger={confirming?.kind === "reject"}
        {...(confirming ? decisionCopy(confirming, queue.percentage) : {})}
      />

      <PasswordConfirmModal
        opened={Boolean(undoing)}
        onClose={() => setUndoing(null)}
        onConfirm={confirmUndo}
        loading={queue.isUndoing}
        error={undoError}
        zIndex={400}
        title={undoing ? `Undo the decision on #${claimLabel(undoing)}?` : "Undo the decision?"}
        description={undoing ? undoCopy(undoing) : null}
        confirmLabel="Undo decision"
      />
    </StaffLayout>
  );
}

export default DiscountRequests;
