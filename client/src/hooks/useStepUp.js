import { useCallback, useState } from "react";

const REQUIRED = "PASSWORD_CONFIRMATION_REQUIRED";
const RETRYABLE = ["PASSWORD_CONFIRMATION_INVALID", "PASSWORD_CONFIRMATION_THROTTLED"];

/** Thrown when the Manager closes the password prompt; not an error to display. */
export class StepUpCancelled extends Error {}

/**
 * Runs a sensitive change through the server's step-up: tried without a
 * password first (a recent confirmation may still stand), and only if the
 * server asks does the prompt open and the same change go again with one.
 */
export function useStepUp() {
  const [prompt, setPrompt] = useState(null);
  const [confirming, setConfirming] = useState(false);

  const run = useCallback(async (action, copy) => {
    try {
      return await action();
    } catch (error) {
      if (error?.payload?.error_code !== REQUIRED) throw error;

      return new Promise((resolve, reject) => setPrompt({ action, copy, resolve, reject, error: null }));
    }
  }, []);

  const onConfirm = async (password) => {
    setConfirming(true);

    try {
      prompt.resolve(await prompt.action(password));
      setPrompt(null);
    } catch (error) {
      if (RETRYABLE.includes(error?.payload?.error_code)) {
        setPrompt((current) => ({ ...current, error: error.message }));
      } else {
        prompt.reject(error);
        setPrompt(null);
      }
    } finally {
      setConfirming(false);
    }
  };

  const onClose = () => {
    prompt?.reject(new StepUpCancelled());
    setPrompt(null);
  };

  return {
    run,
    modalProps: {
      opened: Boolean(prompt),
      onClose,
      onConfirm,
      loading: confirming,
      error: prompt?.error ?? null,
      title: prompt?.copy.title ?? "Confirm your password",
      description: prompt?.copy.description,
    },
  };
}

export default useStepUp;
