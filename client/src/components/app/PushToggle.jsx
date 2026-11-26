import { useContext, useState } from "react";
import { IconBellOff, IconBellRinging, IconDeviceMobile } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { PUSH_STATUS, isPushOn, pushStatus, turnPushOff, turnPushOn } from "../../lib/push";
import toast from "./Toast";

/**
 * The "notifications on this device" row in the bell's "⋯" menu (PRD §9).
 * Every signed-in account, customers and staff (staff since 2026-11-26);
 * nothing shows when this build has no Firebase keys or the browser cannot
 * receive push.
 *
 * Plain elements with the type on inner spans: Mantine's button reset would
 * otherwise override the Tailwind sizes.
 */
function PushToggle() {
  const { user } = useContext(AuthContext);
  const [status, setStatus] = useState(pushStatus);
  const [on, setOn] = useState(() => isPushOn(user?.id));
  const [busy, setBusy] = useState(false);

  if (!user?.id) return null;
  if (status === PUSH_STATUS.UNCONFIGURED || status === PUSH_STATUS.UNSUPPORTED) return null;

  if (status === PUSH_STATUS.NEEDS_INSTALL) {
    return (
      <Row testId="push-needs-install" icon={<IconDeviceMobile size={17} stroke={1.9} />}>
        To get notifications on this iPhone, tap Share, then <b>Add to Home Screen</b>, and open Click n Chick from there.
      </Row>
    );
  }

  if (status === PUSH_STATUS.DENIED) {
    return (
      <Row testId="push-denied" icon={<IconBellOff size={17} stroke={1.9} />}>
        Notifications are blocked for this site. Allow them in your browser settings to get them here.
      </Row>
    );
  }

  const enable = async () => {
    setBusy(true);

    try {
      const result = await turnPushOn(user.id);

      if (result === "on") {
        setOn(true);
        toast.success("You will get notifications on this device.", "Notifications on");
      } else if (result === "denied") {
        setStatus(PUSH_STATUS.DENIED);
      }
    } catch (error) {
      toast.error(error?.message || "Notifications could not be turned on. Please try again.");
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    setBusy(true);

    try {
      await turnPushOff(user.id);
      setOn(false);
      toast.info("Notifications are off for this device.", "Notifications off");
    } catch (error) {
      toast.error(error?.message || "Notifications could not be turned off. Please try again.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Row
      testId="push-toggle"
      on={on}
      icon={on ? <IconBellRinging size={17} stroke={1.9} /> : <IconBellOff size={17} stroke={1.9} />}
      action={
        <button
          type="button"
          onClick={on ? disable : enable}
          disabled={busy}
          data-testid={on ? "push-turn-off" : "push-turn-on"}
          className={
            on
              ? "shrink-0 cursor-pointer rounded-full border border-[#e8e1d8] bg-white px-3 py-1 text-[#6f6b68] disabled:opacity-50"
              : "shrink-0 cursor-pointer rounded-full border-0 bg-brand-500 px-3 py-1 text-white disabled:opacity-50"
          }
        >
          <span className="font-display text-[11.5px] font-bold">
            {busy ? "…" : on ? "Turn off" : "Turn on"}
          </span>
        </button>
      }
    >
      {on ? "Notifications are on for this device." : "Get notifications on this device, even when the app is closed."}
    </Row>
  );
}

function Row({ icon, action, children, testId, on = false }) {
  return (
    <div
      data-testid={testId}
      data-on={on}
      className="flex items-center gap-3 rounded-[12px] bg-[#fffaf4] px-3 py-3"
    >
      <span className={`shrink-0 ${on ? "text-[#2f9e44]" : "text-brand-600"}`} aria-hidden="true">
        {icon}
      </span>
      <p className="m-0 flex-1 font-display text-[12.5px] leading-snug text-[#6f6b68]">{children}</p>
      {action}
    </div>
  );
}

export default PushToggle;
