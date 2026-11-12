import { useEffect, useState } from "react";
import { Modal, Pagination } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { IconArchive, IconArrowLeft, IconRefresh } from "@tabler/icons-react";

import FilterBar from "./FilterBar";
import RecordDetailPanel from "./RecordDetailPanel";
import { RecordCards, RecordTable } from "./RecordList";
import Button from "../ui/Button";
import { useOrderRecord, useOrderRecords, useRecordPrefetch } from "../../hooks/useOrderRecords";
import { ADVANCE_HISTORY_OUTCOMES } from "../../lib/agent";
import { RECORDS_PER_PAGE } from "../../lib/records";

/**
 * Finished advance orders: fulfilled, declined and cancelled.
 *
 * The board holds only requests that can still be acted on, so a collected
 * order leaves it from Ready and lands here. Read through Order Records
 * (kind=advance), which already pages, searches and counts each outcome, and
 * whose list key sits under "orders": a pickup on the board reaches this list
 * through the admin.orders broadcast with nothing subscribed here.
 */
function AdvanceHistoryModal({ opened, onClose }) {
  const wide = useMediaQuery("(min-width: 48em)", true);

  const [outcome, setOutcome] = useState("completed");
  const [searchText, setSearchText] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState(null);

  // Sent once typing pauses, not on every key. A new search or outcome starts
  // again from its first page.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchText.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchText]);

  const pickOutcome = (key) => {
    setOutcome(key);
    setPage(1);
  };

  const list = useOrderRecords(
    { kind: "advance", outcome, search, page, per_page: RECORDS_PER_PAGE, sort: "newest" },
    { enabled: opened }
  );
  const detail = useOrderRecord(opened ? selectedId : null);
  const intent = useRecordPrefetch();

  const current = ADVANCE_HISTORY_OUTCOMES.find((item) => item.key === outcome) ?? ADVANCE_HISTORY_OUTCOMES[0];
  const meta = list.meta;
  const preview = list.records.find((record) => String(record.id) === selectedId) ?? null;

  const close = () => {
    setSelectedId(null);
    onClose();
  };

  const tabs = ADVANCE_HISTORY_OUTCOMES.map((item) => ({
    key: item.key,
    label: item.label,
    count: list.summary ? list.summary[item.key] ?? 0 : null,
  }));

  return (
    <Modal
      opened={opened}
      onClose={close}
      size="min(920px, 100%)"
      fullScreen={!wide}
      radius="lg"
      centered
      title={<span className="font-display text-[18px] font-bold text-brand-600">{current.title}</span>}
      data-testid="advance-history"
    >
      {selectedId ? (
        <div className="flex flex-col gap-3">
          <button
            type="button"
            onClick={() => setSelectedId(null)}
            className="inline-flex w-fit items-center gap-1.5 bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800"
          >
            <IconArrowLeft size={16} stroke={2} />
            <span className="text-[12.5px]">Back to the list</span>
          </button>

          <div className="rounded-[16px] ring-1 ring-[#f0e9df]">
            <RecordDetailPanel
              preview={preview}
              record={detail.record}
              isLoading={detail.isLoading}
              isError={detail.isError}
              error={detail.error}
              onRetry={() => detail.refetch()}
            />
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <FilterBar
            testId="advance-history-filters"
            search={{
              value: searchText,
              onChange: setSearchText,
              placeholder: "Search…",
              title: "Search by order number, customer, phone or item",
              label: "Search finished advance orders",
            }}
            tabs={{
              items: tabs,
              value: outcome,
              onChange: pickOutcome,
              label: "How the order ended",
              testIdPrefix: "advance-history",
            }}
          />

          {list.isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-10 text-center ring-1 ring-[#f0e9df]">
              <p className="m-0 font-display text-[14px] font-semibold text-ink">These orders could not be loaded.</p>
              <p className="m-0 font-display text-[12.5px] text-[#8d8884]">{list.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => list.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : !list.isLoading && list.records.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
              <IconArchive size={26} stroke={1.6} className="text-[#c9c2b8]" aria-hidden="true" />
              <p className="m-0 font-display text-[13px] text-[#8d8884]">
                {search ? "No finished advance order matches that search." : current.empty}
              </p>
            </div>
          ) : (
            <div className={`transition-opacity ${list.isPlaceholder ? "opacity-60" : ""}`} aria-busy={list.isFetching}>
              {wide ? (
                <RecordTable
                  records={list.records}
                  loading={list.isLoading}
                  selectedId={selectedId}
                  onSelect={(record) => setSelectedId(String(record.id))}
                  intent={intent}
                  showPayment={outcome === "completed"}
                />
              ) : (
                <RecordCards
                  records={list.records}
                  loading={list.isLoading}
                  selectedId={selectedId}
                  onSelect={(record) => setSelectedId(String(record.id))}
                  intent={intent}
                  showPayment={outcome === "completed"}
                />
              )}
            </div>
          )}

          {meta && meta.total > 0 && (
            <div className="flex flex-col items-center justify-between gap-2.5 sm:flex-row">
              <p className="m-0 font-display text-[11.5px] text-[#8d8884]">
                Showing {meta.from}–{meta.to} of {meta.total}
              </p>
              {meta.last_page > 1 && (
                <Pagination
                  total={meta.last_page}
                  value={Math.min(page, meta.last_page)}
                  onChange={setPage}
                  size="sm"
                  radius="xl"
                  color="orange"
                  siblings={wide ? 1 : 0}
                  boundaries={1}
                  getControlProps={(control) => ({ "aria-label": `${control} page` })}
                />
              )}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

export default AdvanceHistoryModal;
