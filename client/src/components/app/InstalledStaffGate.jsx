import { useContext, useState } from "react";
import { IconDeviceDesktop } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { ROLES } from "../../lib/roles";
import { isInstalledApp } from "../../lib/installedApp";
import Button from "../ui/Button";

const INSTALLED = isInstalledApp();

/**
 * The installed app is for customers only (NFR-05). A Store Agent or Store
 * Manager who signs in there is shown this instead of the staff pages, and
 * signs out from it; staff use the website in a browser. In a browser tab this
 * renders its children untouched.
 *
 * Display only, like every route guard: the API's role checks are what protect
 * staff data.
 */
function InstalledStaffGate({ children }) {
  const { user, logOut } = useContext(AuthContext);
  const [signingOut, setSigningOut] = useState(false);

  if (!INSTALLED || !user || user.role === ROLES.CUSTOMER) return children;

  const signOut = async () => {
    setSigningOut(true);

    try {
      await logOut();
    } finally {
      setSigningOut(false);
    }
  };

  return (
    <main
      data-testid="installed-staff-gate"
      className="grid min-h-dvh place-items-center bg-[#fff7ed] px-6 text-center font-display"
    >
      <div className="flex max-w-[360px] flex-col items-center gap-4">
        <span className="grid h-14 w-14 place-items-center rounded-full bg-[#fff1e6] text-[#f58c2f]" aria-hidden="true">
          <IconDeviceDesktop size={28} stroke={1.8} />
        </span>

        <h1 className="m-0 text-[20px] font-bold text-ink">Staff pages are not in the app</h1>

        <p className="m-0 text-[14px] leading-relaxed text-[#6f6b68]">
          The Click n Chick app is for customers. Store Agent and Store Manager accounts use the website in a browser.
        </p>

        <Button onClick={signOut} loading={signingOut} loadingLabel="Signing out…" fullWidth>
          Sign out
        </Button>
      </div>
    </main>
  );
}

export default InstalledStaffGate;
