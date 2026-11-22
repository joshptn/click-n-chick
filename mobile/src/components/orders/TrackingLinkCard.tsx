import { useEffect, useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { isEmail } from "../../api/checkout";
import type { TrackingLink } from "../../api/orders";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { Sheet } from "../account/Sheet";
import { TextField } from "../auth/TextField";
import { CheckoutButton } from "../checkout/CheckoutButton";
import { IconCircleCheck, IconMailForward } from "../icons";
import { GREEN } from "./OrderProgress";

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/** A clock that ticks each second while the card is on screen. */
function useNow() {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  return now;
}

type Props = {
  link: TrackingLink;
  /** Resolves when the link was sent; rejects with the API's message. */
  onResend: () => Promise<unknown>;
  /** Saves the new address; sends nothing by itself. */
  onChangeEmail: (email: string) => Promise<unknown>;
};

/**
 * "Did you get your tracking link?" on a guest's tracking screen, below "Your
 * order", while the order is open (FR-02.13b, decided 2026-11-22; the web's
 * TrackingLinkCard). The address the link went to, with Edit, and Resend
 * link. The API holds the limits (one send per 30 seconds, counted from the
 * automatic email too; 10 per order); this only shows them.
 */
export function TrackingLinkCard({ link, onResend, onChangeEmail }: Props) {
  const [sent, setSent] = useState(false);
  const [resending, setResending] = useState(false);
  const [editing, setEditing] = useState(false);
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const now = useNow();
  // Never above the API's own figure, though the clock may be a second behind.
  const wait = Math.min(link.resend_in ?? 0, Math.max(0, Math.ceil(((link.resend_until ?? 0) - now) / 1000)));
  const used = link.resends_left <= 0;

  const resend = async () => {
    if (resending || wait > 0 || used) return;

    setResending(true);

    try {
      await onResend();
      setSent(true);
    } catch {
      // The screen shows the API's message as a toast.
    } finally {
      setResending(false);
    }
  };

  const startEditing = () => {
    setEmail(link.email ?? "");
    setError(null);
    setEditing(true);
  };

  const trimmed = email.trim();
  const changed = trimmed !== "" && trimmed !== link.email;
  const emailError = trimmed !== "" && !isEmail(trimmed) ? "That email address does not look right." : undefined;

  const save = async () => {
    if (!changed || emailError || saving) return;

    setSaving(true);
    setError(null);

    try {
      await onChangeEmail(trimmed);
      setEditing(false);
      setSent(false);
    } catch (failure) {
      setError(messageOf(failure, "That email address could not be saved. Please try again."));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <View style={styles.card} testID="tracking-link-card">
        <View style={styles.row}>
          <View style={[styles.icon, sent && styles.iconSent]}>
            {sent ? (
              <IconCircleCheck size={19} color={GREEN} strokeWidth={2} />
            ) : (
              <IconMailForward size={19} color={colors.orange} strokeWidth={2} />
            )}
          </View>
          <View style={styles.flex}>
            <AppText family="display" weight="semibold" size={13.5} color={colors.authInk} accessibilityLiveRegion="polite">
              {sent
                ? "Your tracking link has been sent. Please check your email."
                : "Did you get your tracking link? If not, tap the button to send it again."}
            </AppText>
            <View style={styles.sentTo}>
              <AppText family="display" size={12.5} color={colors.ink500} style={styles.shrink}>
                Sent to{" "}
                <AppText family="display" weight="semibold" size={12.5} color={colors.authInk}>
                  {link.email}
                </AppText>
              </AppText>
              <Pressable accessibilityRole="button" accessibilityLabel="Edit email address" onPress={startEditing} hitSlop={8} testID="edit-link-email">
                <AppText family="display" weight="semibold" size={12.5} color={colors.orange}>
                  Edit
                </AppText>
              </Pressable>
            </View>
          </View>
        </View>

        {sent && (
          <AppText family="display" size={12.5} color={colors.ink500}>
            Still didn&apos;t get it? Check your spam folder and make sure your email address is correct, then tap Resend link.
          </AppText>
        )}

        {used ? (
          <View style={styles.limit}>
            <AppText family="display" size={12.5} color={colors.ink500}>
              You&apos;ve reached the resend limit for this order. You can still follow it here in the app.
            </AppText>
          </View>
        ) : (
          <CheckoutButton
            label={wait > 0 ? `Resend link (${wait}s)` : "Resend link"}
            variant="outline"
            icon={IconMailForward}
            onPress={() => void resend()}
            disabled={wait > 0}
            loading={resending}
            testID="resend-link"
          />
        )}
      </View>

      <Sheet
        visible={editing}
        title="Change email address"
        subtitle="Where your tracking link is sent. Tap Resend link after saving."
        onClose={() => setEditing(false)}
        busy={saving}
        testID="link-email-sheet"
      >
        <TextField
          label="Email address"
          value={email}
          onChangeText={(text) => setEmail(text.slice(0, 255))}
          keyboardType="email-address"
          autoCapitalize="none"
          autoComplete="email"
          editable={!saving}
          error={emailError}
          testID="link-email-input"
        />

        {error ? (
          <View style={styles.error}>
            <AppText family="display" size={12.5} color={colors.danger} accessibilityLiveRegion="polite">
              {error}
            </AppText>
          </View>
        ) : null}

        <View style={styles.actions}>
          <CheckoutButton label="Keep as is" variant="outline" onPress={() => setEditing(false)} disabled={saving} flex={1} />
          <CheckoutButton
            label="Save"
            onPress={() => void save()}
            disabled={!changed || Boolean(emailError)}
            loading={saving}
            flex={1.4}
            testID="save-link-email"
          />
        </View>
      </Sheet>
    </>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: 16,
    backgroundColor: colors.white,
    padding: 16,
    gap: 12,
    elevation: 2,
    shadowColor: "#412111",
    shadowOpacity: 0.08,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
  },
  row: { flexDirection: "row", alignItems: "flex-start", gap: 12 },
  icon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orangeTint,
  },
  iconSent: { backgroundColor: "#e3f6ea" },
  flex: { flex: 1, gap: 4 },
  shrink: { flexShrink: 1 },
  sentTo: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", columnGap: 8, rowGap: 2 },
  limit: { padding: 12, borderRadius: 12, backgroundColor: "#faf7f3" },
  error: { padding: 12, borderRadius: 12, backgroundColor: colors.dangerSurface },
  actions: { flexDirection: "row", gap: 10 },
});

export default TrackingLinkCard;
