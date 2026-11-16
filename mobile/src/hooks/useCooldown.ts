import { useEffect, useState } from "react";

/** A countdown in seconds for "Resend code"; `restart` starts it again. */
export function useCooldown(initialSeconds = 0) {
  const [seconds, setSeconds] = useState(Math.max(0, Math.round(initialSeconds)));

  useEffect(() => {
    if (seconds <= 0) return undefined;

    const timer = setTimeout(() => setSeconds((value) => Math.max(0, value - 1)), 1000);

    return () => clearTimeout(timer);
  }, [seconds]);

  return { seconds, restart: (next: number) => setSeconds(Math.max(0, Math.round(next))) };
}

export default useCooldown;
