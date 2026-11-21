import { createPortal } from "react-dom";
import { IconAlertCircle, IconShieldCheck } from "@tabler/icons-react";

import { SECURITY_CHECK } from "../../hooks/useRecaptcha";

/**
 * Where a form's invisible reCAPTCHA lives, and what the form says when a tap
 * has not produced a token (decided 2026-11-22).
 *
 * The widget's element goes at the end of the page: Google's badge is fixed to
 * the corner anyway, and kept out of the form it cannot add a gap to a flex
 * layout. The note renders only when there is something to say.
 *
 * `action` is the button's label, for "tap Sign In again".
 */
function SecurityCheck({ captcha, action, className = "mt-3" }) {
  const { slotRef, status } = captcha;

  return (
    <>
      {createPortal(<div ref={slotRef} />, document.body)}

      {status === SECURITY_CHECK.WAITING && (
        <p
          role="status"
          className={`flex items-start gap-1.5 font-display text-[12px] leading-snug text-[#6f6b68] ${className}`}
        >
          <IconShieldCheck size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0 text-brand-600" />
          <span>Complete the security check to continue. If you closed it, tap {action} again.</span>
        </p>
      )}

      {status === SECURITY_CHECK.UNAVAILABLE && (
        <p
          role="alert"
          className={`flex items-start gap-1.5 font-display text-[12px] leading-snug text-[#e5322d] ${className}`}
        >
          <IconAlertCircle size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
          <span>
            We couldn&rsquo;t load the security check. Check your connection or turn off any blocker for this site,
            then tap {action} again.
          </span>
        </p>
      )}
    </>
  );
}

export default SecurityCheck;
