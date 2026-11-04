import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Drawer } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { AnimatePresence, LayoutGroup, motion } from "framer-motion";
import { IconIdBadge2, IconRefresh } from "@tabler/icons-react";

import ClaimCard from "../../components/staff/ClaimCard";
import ClaimReviewPanel from "../../components/staff/ClaimReviewPanel";
import LivePill from "../../components/staff/LivePill";
import RejectClaimModal from "../../components/staff/RejectClaimModal";
import StaffLayout from "../../components/staff/StaffLayout";
import StaffSearch from "../../components/staff/StaffSearch";
import Button from "../../components/ui/Button";
import { useDiscountQueue } from "../../hooks/useDiscountQueue";
import { CLAIM_TABS, matchesClaim } from "../../lib/agent";

const MotionSpan = motion.span;

const EMPTY = {
  pending: {
    title: "No IDs waiting for review.",
    detail: "A new application appears here the moment a customer submits one.",
  },
  approved: { title: "No approved applications yet.", detail: "Approved Senior Citizen and PWD IDs are listed here." },
  rejected: { title: "No rejected applications.", detail: "Rejected IDs are listed here with the reason given." },
};

function Tabs({ status, counts, onChange }) {
  return (
    <LayoutGroup id="claim-tabs">
      <div role="tablist" aria-label="Application status" className="inline-flex rounded-full bg-[#f4f1ec] p-1">
        {CLAIM_TABS.map((tab) => {
          const active = tab.key === status;
          const count = counts?.[tab.key];

          return (
            <button
              key={tab.key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => onChange(tab.key)}
              data-testid={`claim-tab-${tab.key}`}
              className={`relative rounded-full bg-transparent px-3.5 py-1.5 font-display font-semibold transition-colors ${
                active ? "text-white" : "text-[#6f6b68] hover:text-ink"
              }`}
            >
              {active && (
                <MotionSpan
                  layoutId="claim-tab-pill"
                  className="absolute inset-0 rounded-full bg-brand-500 shadow-[0_4px_12px_-4px_rgba(255,139,43,0.6)]"
                  transition={{ type: "spring", stiffness: 500, damping: 38 }}
                />
              )}
              <span className="relative text-[12px]">
                {tab.label}
                {count != null && tab.key === "pending" && count > 0 && ` (${count})`}
              </span>
            </button>
          );
        })}
      </div>
    </LayoutGroup>
  );
}

function SkeletonList() {
  return (
    <div className="flex flex-col gap-2.5" aria-hidden="true">
      {[0, 1, 2].map((key) => (
        <div key={key} className="h-[74px] animate-pulse rounded-[12px] border border-[#f0e9df] bg-white" />
      ))}
    </div>
  );
}

/**
 * Pending Verification: Senior Citizen and PWD IDs waiting for a Store Agent.
 *
 * A decision settles the customer's eligibility, not one order - the discount
 * is then theirs at checkout. Pending applications are served oldest first.
 * New applications and other agents' decisions arrive over admin.discounts.
 */
function Verification() {
  const wide = useMediaQuery("(min-width: 64em)", true);
  const [searchParams, setSearchParams] = useSearchParams();
  const [search, setSearch] = useState("");
  const [rejecting, setRejecting] = useState(null);
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
    status === "pending" ? queue.claims.find((claim) => claim.id !== selected?.id && !queue.decided[claim.id]) : null;

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
      pendingCount > 0 ? `(${pendingCount}) Pending verification · Click n Chick` : "Pending verification · Click n Chick";
  }, [pendingCount]);

  useEffect(() => () => {
    document.title = "Chick N' Click";
  }, []);

  const approve = async (claim) => {
    try {
      await queue.approve(claim);
    } catch {
      // The hook has already said why.
    }
  };

  const confirmReject = async (reason) => {
    try {
      await queue.reject(rejecting, reason);
      setRejecting(null);
    } catch {
      setRejecting(null);
    }
  };

  const busy = Boolean(selected && queue.pendingId === selected.id);

  const panel = (
    <ClaimReviewPanel
      claim={selected}
      percentage={queue.percentage}
      busy={busy}
      approving={queue.isApproving}
      onApprove={approve}
      onReject={setRejecting}
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
                  <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Pending Verification</h1>
                  <LivePill />
                </div>
                <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                  Review Senior Citizen and PWD discount IDs submitted by customers
                </p>
              </div>

              <StaffSearch
                value={search}
                onChange={setSearch}
                placeholder="Search applications…"
                title="Search by application number, name, email or phone"
                label="Search applications"
              />
            </div>

            <div className="mt-3.5">
              <Tabs status={status} counts={queue.counts} onChange={changeTab} />
            </div>
          </header>

          <div className="flex flex-1 flex-col gap-2.5 px-4 py-4 sm:px-6 lg:px-7">
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
        onConfirm={confirmReject}
        loading={queue.isRejecting}
      />
    </StaffLayout>
  );
}

export default Verification;
