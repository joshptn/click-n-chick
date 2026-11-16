import { useState } from "react";
import { Redirect, router } from "expo-router";

import { ApiError } from "../../api/client";
import { authApi } from "../../api/auth";
import { CodeScreen } from "../../components/auth/CodeScreen";
import { useCooldown } from "../../hooks/useCooldown";
import { authFlow } from "../../lib/authFlow";
import { adoptSession, errorMessage } from "../../lib/signIn";

/**
 * Two-step sign-in, for an account with it turned on (after the password, or
 * after a password reset). Uses the six-box code design.
 */
export default function TwoFactor() {
  const [flow] = useState(() => authFlow.get());
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const cooldown = useCooldown(flow.resendIn ?? 60);

  if (!flow.challengeToken) return <Redirect href="/sign-in" />;

  const token = flow.challengeToken;
  const email = flow.channel === "email";

  const startAgain = (message: string) => {
    authFlow.clear();
    authFlow.set({ notice: message });
    router.replace("/sign-in");
  };

  const submit = async () => {
    setError(null);
    setInfo(null);
    setBusy(true);

    try {
      await adoptSession(await authApi.twoFactor(token, code));
    } catch (caught) {
      if (caught instanceof ApiError && caught.payload.reason === "challenge_expired") {
        startAgain("That sign-in attempt expired. Please sign in again.");
        return;
      }

      setError(errorMessage(caught, "That code is not correct."));
      setCode("");
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    setError(null);

    try {
      const result = await authApi.twoFactorResend(token);
      cooldown.restart(result.resend_available_in ?? 60);
      setInfo(result.message ?? "A new code is on its way.");
    } catch (caught) {
      if (caught instanceof ApiError && caught.payload.reason === "challenge_expired") {
        startAgain("That sign-in attempt expired. Please sign in again.");
        return;
      }

      const wait = caught instanceof ApiError ? Number(caught.payload.resend_available_in ?? 0) : 0;
      if (wait > 0) cooldown.restart(wait);
      setError(errorMessage(caught, "We could not send a new code. Please try again."));
    }
  };

  return (
    <CodeScreen
      title={email ? "Check your email" : "Check your phone"}
      sentTo={flow.masked ?? (email ? "your email address" : "your phone")}
      code={code}
      onCodeChange={setCode}
      onSubmit={submit}
      submitLabel="Verify Code"
      busy={busy}
      onBack={() => (router.canGoBack() ? router.back() : router.replace("/sign-in"))}
      cooldown={cooldown.seconds}
      onResend={resend}
      resendLabel={email ? "Resend email" : "Resend code"}
      error={error}
      info={info}
    />
  );
}
