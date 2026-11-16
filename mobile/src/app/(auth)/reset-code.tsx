import { useCallback, useState } from "react";
import { Redirect, router, useFocusEffect } from "expo-router";

import { ApiError } from "../../api/client";
import { authApi } from "../../api/auth";
import { CodeScreen } from "../../components/auth/CodeScreen";
import { useCooldown } from "../../hooks/useCooldown";
import { authFlow } from "../../lib/authFlow";
import { errorMessage } from "../../lib/signIn";

/**
 * The reset code (design "Check your email"). The API checks it together with
 * the new password, on the next screen; if it was wrong, that screen sends the
 * customer back here with the reason.
 */
export default function ResetCode() {
  const [flow] = useState(() => authFlow.get());
  const [code, setCode] = useState(flow.resetCode ?? "");
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const cooldown = useCooldown(flow.resendIn ?? 60);

  // Coming back from the password screen because the API refused the code.
  useFocusEffect(
    useCallback(() => {
      const refused = authFlow.get().resetCodeError;

      if (refused) {
        setError(refused);
        setCode("");
        authFlow.set({ resetCodeError: undefined, resetCode: undefined });
      }
    }, [])
  );

  if (!flow.identifier) return <Redirect href="/forgot-password" />;

  const identifier = flow.identifier;
  const email = flow.channel === "email";

  const next = () => {
    setError(null);
    authFlow.set({ resetCode: code });
    router.push("/reset-password");
  };

  const resend = async () => {
    setError(null);

    try {
      const result = await authApi.forgotPassword(identifier);
      cooldown.restart(result.resend_available_in ?? 60);
      setInfo("A new code is on its way.");
    } catch (caught) {
      const wait = caught instanceof ApiError ? Number(caught.payload.resend_available_in ?? 0) : 0;
      if (wait > 0) cooldown.restart(wait);
      setError(errorMessage(caught, "We could not send a new code. Please try again."));
    }
  };

  return (
    <CodeScreen
      title={email ? "Check your email" : "Check your phone"}
      sentTo={flow.masked ?? identifier}
      code={code}
      onCodeChange={setCode}
      onSubmit={next}
      onBack={() => (router.canGoBack() ? router.back() : router.replace("/forgot-password"))}
      cooldown={cooldown.seconds}
      onResend={resend}
      resendLabel={email ? "Resend email" : "Resend code"}
      error={error}
      info={info}
    />
  );
}
