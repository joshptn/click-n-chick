import { useContext, useState } from "react";

import AuthContext from "../context/AuthContext";
import toast from "../components/app/Toast";
import { PUSH_STATUS, isPushOn, pushStatus, turnPushOff, turnPushOn } from "../lib/push";

/**
 * "Notifications on this device" for the signed-in account (PRD §9): the bell
 * panel's "⋯" row and the phone Profile's switch. `enable` must run straight
 * from a tap: Safari only shows the permission prompt for one.
 */
export function usePushSetting() {
  const { user } = useContext(AuthContext);
  const [status, setStatus] = useState(pushStatus);
  const [on, setOn] = useState(() => isPushOn(user?.id));
  const [busy, setBusy] = useState(false);

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

  return { signedIn: Boolean(user?.id), status, on, busy, enable, disable };
}

export default usePushSetting;
