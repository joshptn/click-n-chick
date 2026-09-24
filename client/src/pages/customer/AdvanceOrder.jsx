import { useContext } from "react";
import { Navigate } from "react-router-dom";

import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import { ROLES } from "../../lib/roles";

function AdvanceOrder() {
  const { user } = useContext(AuthContext);

  // FR-03.4 — advance ordering is restricted to registered customers. Staff can
  // reach the storefront, so the page refuses them rather than relying on the
  // header simply not offering the entrance.
  if (user && user.role !== ROLES.CUSTOMER) {
    return <Navigate to="/home" replace />;
  }

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1180px] px-4 py-8 sm:px-6 lg:px-8">
        <h1 className="m-0 font-display text-[22px] font-extrabold text-ink">Advance Order</h1>

        <p className="m-0 mt-2 max-w-[560px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          Book ahead for collection at the store. The advance menu and cart arrive in the next
          slice of work.
        </p>
      </main>
    </div>
  );
}

export default AdvanceOrder;
