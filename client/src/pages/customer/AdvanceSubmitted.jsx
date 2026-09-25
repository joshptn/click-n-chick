import { Link, useLocation, useParams } from "react-router-dom";
import { IconChecks, IconClockHour4, IconCreditCard, IconSend } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import { CART_MODE } from "../../lib/cartModes";
import { formatCollection } from "../../lib/advance";
import { formatPeso } from "../../lib/menu";

const TIMELINE = [
  {
    icon: IconSend,
    title: "Request sent",
    body: "The store has your order and the date you want it.",
    done: true,
  },
  {
    icon: IconClockHour4,
    title: "Waiting for the store",
    body: "A Store Agent checks whether the kitchen can make this on your date, then accepts or declines.",
    done: false,
  },
  {
    icon: IconCreditCard,
    title: "Pay to confirm",
    body: "Once it is accepted you have 24 hours to pay. Nothing is charged before then.",
    done: false,
  },
];

function AdvanceSubmitted() {
  const { orderId } = useParams();
  const { state } = useLocation();

  const order = state?.order ?? null;
  const collectAt = formatCollection(order?.scheduled_for);

  return (
    <div className="min-h-dvh bg-vlat-display text-ink">
      <AppHeader cartMode={CART_MODE.ADVANCE} />

      <main className="mx-auto w-full max-w-[640px] px-4 py-8 sm:px-6">
        <div className="overflow-hidden rounded-[18px] border border-[#f0e9df] bg-white">
          <div className="border-b border-[#f0e9df] bg-gradient-to-r from-brand-600 to-brand-500 px-6 py-7 text-center">
            <span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-white/20">
              <IconChecks size={24} stroke={2.2} aria-hidden="true" className="text-white" />
            </span>

            <h1 className="m-0 mt-3 font-display text-[21px] font-extrabold leading-tight text-white">
              Your request is with the store
            </h1>

            <p className="m-0 mt-1.5 font-display text-[13px] text-white/85">
              Request #{orderId}
              {collectAt ? ` · for ${collectAt}` : ""}
            </p>
          </div>

          <ol className="m-0 list-none p-6">
            {TIMELINE.map((entry, index) => (
              <li key={entry.title} className="flex gap-3.5 pb-5 last:pb-0">
                <span className="flex flex-col items-center">
                  <span
                    className={`grid h-9 w-9 shrink-0 place-items-center rounded-full ${
                      entry.done ? "bg-[#e9f8ee] text-[#2f9e44]" : "bg-[#f4f1ec] text-[#a39f9b]"
                    }`}
                  >
                    <entry.icon size={17} stroke={2.2} aria-hidden="true" />
                  </span>

                  {index < TIMELINE.length - 1 && (
                    <span aria-hidden="true" className="mt-1 w-px flex-1 bg-[#f0e9df]" />
                  )}
                </span>

                <span className="min-w-0 flex-1 pt-1.5">
                  <span className="block font-display text-[14px] font-bold text-ink">
                    {entry.title}
                  </span>
                  <span className="mt-0.5 block font-display text-[12.5px] leading-snug text-[#8d8884]">
                    {entry.body}
                  </span>
                </span>
              </li>
            ))}
          </ol>

          {order && (
            <div className="border-t border-[#f0e9df] px-6 py-4">
              <div className="flex items-center justify-between gap-3">
                <span className="font-display text-[13px] text-[#6f6b68]">
                  {order.item_count} item{order.item_count === 1 ? "" : "s"}
                </span>

                <span className="font-display text-[17px] font-extrabold leading-none text-brand-600">
                  {formatPeso(order.total_amount)}
                </span>
              </div>

              <p className="m-0 mt-1 text-right font-display text-[11px] text-[#a39f9b]">
                Payable once accepted
              </p>
            </div>
          )}

          <div className="flex flex-col gap-2.5 border-t border-[#f0e9df] px-6 py-5 sm:flex-row">
            <Link
              to={`/orders/${orderId}`}
              className="inline-flex h-[46px] flex-1 items-center justify-center rounded-[10px] bg-brand-500 font-display text-[14px] font-semibold text-white no-underline transition-colors hover:bg-brand-600"
            >
              Track this request
            </Link>

            <Link
              to="/advance-order"
              className="inline-flex h-[46px] flex-1 items-center justify-center rounded-[10px] border border-[#ece7e0] bg-white font-display text-[14px] font-semibold text-[#6f6b68] no-underline transition-colors hover:bg-[#faf7f3]"
            >
              Schedule another
            </Link>
          </div>
        </div>
      </main>
    </div>
  );
}

export default AdvanceSubmitted;
