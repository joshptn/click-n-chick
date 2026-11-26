import { useContext } from "react";
import { Link, useNavigate } from "react-router-dom";
import { IconSearch, IconX } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import LogoIcon from "../../assets/logo-icon.png";
import { PHONE_HEADER_HEIGHT } from "../../lib/phone";
import { formatStoreTime, useStoreStatus } from "../../lib/store";

/**
 * The app's header on a phone (mobile menu/AppHeader.tsx), on Home and Cart:
 * the logo, the menu search, Advance Order and whether the store is open.
 * Typing on Cart takes the search to Home, as in the app.
 *
 * The search is 16px: iPhones zoom into any smaller field on focus.
 */
function PhoneHeader({ search = "", onSearchChange, autoFocus = false }) {
  const navigate = useNavigate();
  const { token } = useContext(AuthContext);
  const { isOpen, status } = useStoreStatus();

  const change = onSearchChange ?? ((text) => navigate("/home", { state: { search: text } }));

  return (
    <header
      className="sticky top-0 z-40 flex items-center gap-2.5 border-b border-[#f0e9df] bg-white px-4"
      style={{ height: PHONE_HEADER_HEIGHT }}
    >
      <Link
        to="/"
        aria-label="Click n Chick home page"
        data-testid="header-logo"
        className="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-[10px] bg-[#ff8904]"
      >
        <img src={LogoIcon} alt="" draggable={false} className="h-[30px] w-[30px] select-none object-contain" />
      </Link>

      <div className="flex h-[38px] min-w-0 flex-1 items-center gap-2 rounded-[19px] bg-[#f3f4f6] px-3">
        <IconSearch size={16} stroke={2} aria-hidden="true" className="shrink-0 text-[#9ca3af]" />
        <input
          type="search"
          value={search}
          onChange={(event) => change(event.target.value)}
          // Only when a search typed on Cart carried over to Home.
          autoFocus={autoFocus}
          placeholder="Search food..."
          aria-label="Search the menu"
          enterKeyHint="search"
          data-testid="menu-search"
          className="min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 font-display text-[16px] text-[#111827] outline-none placeholder:text-[#9ca3af] [&::-webkit-search-cancel-button]:hidden"
        />
        {search ? (
          <button
            type="button"
            onClick={() => change("")}
            aria-label="Clear search"
            className="grid h-5 w-5 shrink-0 place-items-center bg-transparent text-[#9ca3af]"
          >
            <IconX size={15} stroke={2.2} />
          </button>
        ) : null}
      </div>

      {/* Advance orders need an account, so a guest signs in first. */}
      <Link
        to={token ? "/advance-order" : "/login"}
        aria-label={token ? "Advance Order" : "Advance Order. Sign in first"}
        data-testid="advance-order-entry"
        className="inline-flex h-[34px] shrink-0 items-center rounded-full border-[1.5px] border-solid border-[#ff8904] bg-white px-2.5 font-display text-[11.5px] font-semibold text-[#ff8904] no-underline"
      >
        Advance Order
      </Link>

      <span
        data-testid="store-status"
        data-open={isOpen}
        title={status ? `Online ordering ${formatStoreTime(status.opens_at)} - ${formatStoreTime(status.closes_at)}` : undefined}
        className={`inline-flex shrink-0 items-center gap-[5px] rounded-full border border-solid px-2.5 py-[5px] ${
          isOpen ? "border-[#b7ebc6] bg-[#ecfdf3] text-[#2f9e44]" : "border-[#e6ded4] bg-[#f4f1ec] text-[#6f6b68]"
        }`}
      >
        <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${isOpen ? "bg-[#2f9e44]" : "bg-[#a39f9b]"}`} />
        <span className="font-display text-[11.5px] font-semibold">{isOpen ? "Open" : "Closed"}</span>
      </span>
    </header>
  );
}

export default PhoneHeader;
