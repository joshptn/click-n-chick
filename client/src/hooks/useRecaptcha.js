import { useCallback, useEffect, useRef, useState } from "react";

import { loadRecaptcha, recaptchaConfig } from "../lib/recaptcha";

/**
 * reCAPTCHA v2 Invisible on one form (decided 2026-11-22).
 *
 * The form calls `run(submit)` on its button. Most people get a token at once
 * and `submit(token)` runs. Anyone Google is unsure about sees its puzzle
 * first, and `submit` runs when they pass. Until then nothing on the form
 * changes, so the form must start its own "Signing in..." inside `submit`,
 * never before calling `run`.
 *
 * Google does not say when someone closes the puzzle, so nothing here waits on
 * it: if no token has come after a moment, `status` turns WAITING and the form
 * says to finish the check or tap again. Tapping again runs a fresh check.
 *
 * `submit(null)` (no token) when the check is switched off, or when the API
 * could not say whether it is on: the server decides then.
 */

export const SECURITY_CHECK = {
  IDLE: "idle",
  /** A tap is waiting for Google: the puzzle is open, or was closed. */
  WAITING: "waiting",
  /** Google's script could not be loaded or reached. */
  UNAVAILABLE: "unavailable",
};

/** How long a tap waits for a token before the form says why nothing happened. */
const NOTE_DELAY_MS = 2000;

/** The check is switched off for this deployment. */
const OFF = "off";

export default function useRecaptcha({ enabled = true } = {}) {
  const [slot, setSlot] = useState(null);
  const [status, setStatus] = useState(SECURITY_CHECK.IDLE);

  const prepareRef = useRef(null);
  const pendingRef = useRef(null);
  const timerRef = useRef(null);

  const clearNote = useCallback(() => {
    window.clearTimeout(timerRef.current);
    timerRef.current = null;
  }, []);

  const deliver = useCallback(
    (token) => {
      clearNote();
      setStatus(SECURITY_CHECK.IDLE);

      const submit = pendingRef.current;
      pendingRef.current = null;
      submit?.(token);
    },
    [clearNote]
  );

  const fail = useCallback(() => {
    clearNote();
    pendingRef.current = null;
    setStatus(SECURITY_CHECK.UNAVAILABLE);
  }, [clearNote]);

  useEffect(() => {
    if (!enabled || !slot) return undefined;

    let cancelled = false;
    let widget = null;
    let preparing = null;

    // A fresh element every time: Google refuses to set up a second widget in
    // an element it has used, and development mode mounts everything twice.
    const host = document.createElement("div");
    slot.appendChild(host);

    const prepare = () => {
      if (widget) return Promise.resolve(widget);
      if (preparing) return preparing;

      preparing = (async () => {
        const config = await recaptchaConfig();

        if (!config.enabled) {
          // Remembered only when the API answered; otherwise asked again.
          if (config.known) widget = OFF;

          return OFF;
        }

        const grecaptcha = await loadRecaptcha();

        if (cancelled) throw new Error("The form has closed.");

        const id = grecaptcha.render(host, {
          sitekey: config.siteKey,
          size: "invisible",
          badge: "bottomright",
          callback: deliver,
          // A token unused for two minutes; the next tap gets a new one anyway.
          "expired-callback": () => grecaptcha.reset(id),
          "error-callback": fail,
        });

        widget = { grecaptcha, id };

        return widget;
      })().finally(() => {
        preparing = null;
      });

      return preparing;
    };

    prepareRef.current = prepare;

    // Start early, so Google has the page in view before the tap. A failure
    // here is tried again on the tap.
    prepare().catch(() => {});

    return () => {
      cancelled = true;
      prepareRef.current = null;
      pendingRef.current = null;
      window.clearTimeout(timerRef.current);
      host.remove();
    };
  }, [enabled, slot, deliver, fail]);

  const run = useCallback(
    (submit) => {
      const prepare = prepareRef.current;

      if (!enabled || !prepare) {
        submit(null);
        return;
      }

      clearNote();
      setStatus(SECURITY_CHECK.IDLE);
      pendingRef.current = submit;

      timerRef.current = window.setTimeout(() => {
        if (pendingRef.current === submit) setStatus(SECURITY_CHECK.WAITING);
      }, NOTE_DELAY_MS);

      prepare()
        .then((widget) => {
          // A later tap took over; it runs its own check.
          if (pendingRef.current !== submit) return;

          if (widget === OFF) {
            deliver(null);
            return;
          }

          // Every token is single-use, so each tap starts from a clean widget.
          // This also reopens a puzzle that was closed.
          widget.grecaptcha.reset(widget.id);
          widget.grecaptcha.execute(widget.id);
        })
        .catch(() => {
          if (pendingRef.current === submit) fail();
        });
    },
    [enabled, clearNote, deliver, fail]
  );

  return { slotRef: setSlot, run, status };
}
