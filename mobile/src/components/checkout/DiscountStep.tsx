import { useState, type ReactNode } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { Image } from "expo-image";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { PROFILE_KEY, accountApi, type DiscountType, type PickedFile } from "../../api/account";
import type { QuoteDiscount } from "../../api/checkout";
import { formatPeso } from "../../lib/money";
import { PickError, pickImage } from "../../lib/pickImage";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { DISCOUNT_LABEL } from "../account/DiscountCard";
import { IconCheck, IconInfoCircle, IconPaperclip, IconX } from "../icons";
import { CheckoutButton } from "./CheckoutButton";
import { Notice } from "./parts";

export type DiscountChoice = "use" | "later";

const TYPES: DiscountType[] = ["senior", "pwd"];
const TYPE_TITLE: Record<DiscountType, string> = { senior: "Senior Citizen", pwd: "Person with Disability" };

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

type DiscountStepProps = {
  discount: QuoteDiscount | null;
  choice: DiscountChoice | null;
  onChoose: (choice: DiscountChoice) => void;
  /** An application was sent: the quote is asked again, and now says "pending". */
  onSubmitted: () => void;
};

/**
 * Step 2, the Senior / PWD discount (designs of 2026-11-19), for accounts
 * only (a guest cannot claim one, as on the web). The customer must answer
 * before continuing: Use Discount or Later. Without an approved discount the
 * step offers the application itself (type and ID photo, the account page's
 * upload); once it is approved, Use Discount appears here.
 */
export function DiscountStep({ discount, choice, onChoose, onSubmitted }: DiscountStepProps) {
  if (!discount) {
    return (
      <>
        <View style={styles.skeleton} />
        <View style={[styles.skeleton, styles.skeletonShort]} />
      </>
    );
  }

  const offerable = discount.eligible && !discount.used_today && discount.can_apply;
  const spent = discount.eligible && discount.used_today;
  const percent = Number(discount.percentage);

  return (
    <>
      {offerable && (
        <>
          <TypeRow title={discount.type_label ?? "Discount"} chip={discount.type ? DISCOUNT_LABEL[discount.type] : null} />
          <Notice tone="info">
            Using your discount consumes it for the day. You save {formatPeso(discount.available_amount)} ({percent}% off the meal items).
          </Notice>
          <View style={styles.buttons}>
            <CheckoutButton
              label="Later"
              variant="outline"
              icon={choice === "later" ? IconCheck : undefined}
              onPress={() => onChoose("later")}
              flex={1}
              testID="discount-later"
            />
            <CheckoutButton
              label="Use Discount"
              icon={choice === "use" ? IconCheck : undefined}
              onPress={() => onChoose("use")}
              flex={1.6}
              testID="discount-use"
            />
          </View>
          <Chosen choice={choice} amount={discount.available_amount} />
        </>
      )}

      {spent && (
        <>
          <Notice>You have used your discount today. It is one order per day and resets tomorrow; this order is at the normal price.</Notice>
          <LaterOnly choice={choice} onChoose={onChoose} />
        </>
      )}

      {!discount.eligible && discount.status === "pending" && (
        <>
          <Notice tone="info">
            A Store Agent is checking your ID, usually within a few minutes. If it is approved before you continue, Use Discount
            appears here.
          </Notice>
          <LaterOnly choice={choice} onChoose={onChoose} />
        </>
      )}

      {!discount.eligible && (discount.status === "none" || discount.status === "rejected") && (
        <ApplyForm rejected={discount.status === "rejected"} percent={percent} choice={choice} onChoose={onChoose} onSubmitted={onSubmitted} />
      )}

      <View style={styles.footnote}>
        <IconInfoCircle size={14} color={colors.orange} strokeWidth={2} style={styles.footnoteIcon} />
        <AppText family="display" size={12} color={colors.authMuted} style={styles.flex}>
          The discount applies to the meal items only, not to add-ons and the delivery fee.
        </AppText>
      </View>
    </>
  );
}

function TypeRow({ title, chip, children }: { title: string; chip?: string | null; children?: ReactNode }) {
  return (
    <View style={styles.typeBlock}>
      <AppText family="display" weight="semibold" size={11.5} color={colors.authMuted} style={styles.caps}>
        DISCOUNT TYPE
      </AppText>
      <View style={styles.typeRow}>
        <AppText family="display" weight="semibold" size={14.5} color={colors.orange} style={styles.flex} numberOfLines={1}>
          {title}
        </AppText>
        {chip ? (
          <View style={[styles.chip, styles.chipOn]}>
            <AppText family="display" weight="semibold" size={12.5} color={colors.white}>
              {chip}
            </AppText>
          </View>
        ) : null}
        {children}
      </View>
    </View>
  );
}

function Chosen({ choice, amount }: { choice: DiscountChoice | null; amount: number }) {
  if (!choice) return null;

  return (
    <AppText family="display" weight="medium" size={12.5} color={choice === "use" ? colors.success : colors.authMuted} style={styles.center}>
      {choice === "use" ? `Discount applied: −${formatPeso(amount)}` : "Not using your discount on this order"}
    </AppText>
  );
}

/** No discount can be used today: the only answer is to go on without it. */
function LaterOnly({ choice, onChoose }: { choice: DiscountChoice | null; onChoose: (choice: DiscountChoice) => void }) {
  return (
    <CheckoutButton
      label={choice === "later" ? "Continuing without discount" : "Later"}
      variant="outline"
      icon={choice === "later" ? IconCheck : undefined}
      onPress={() => onChoose("later")}
      testID="discount-later"
    />
  );
}

function ApplyForm({
  rejected,
  percent,
  choice,
  onChoose,
  onSubmitted,
}: {
  rejected: boolean;
  percent: number;
  choice: DiscountChoice | null;
  onChoose: (choice: DiscountChoice) => void;
  onSubmitted: () => void;
}) {
  const queryClient = useQueryClient();
  const [type, setType] = useState<DiscountType | null>(null);
  const [file, setFile] = useState<PickedFile | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apply = useMutation({
    mutationFn: ({ kind, picked }: { kind: DiscountType; picked: PickedFile }) => accountApi.applyForDiscount(kind, picked),
    onSuccess: () => {
      toast.success("Submitted for review", "A Store Agent is checking your ID.");
      void queryClient.invalidateQueries({ queryKey: PROFILE_KEY });
      onSubmitted();
    },
    onError: (failure) => setError(messageOf(failure, "We could not submit your application.")),
  });

  const attach = async () => {
    setError(null);

    try {
      const picked = await pickImage(`${type ? DISCOUNT_LABEL[type] : "Senior / PWD"} ID`, { accept: ["image/jpeg", "image/png"] });
      if (picked) setFile(picked);
    } catch (failure) {
      setError(messageOf(failure instanceof PickError ? failure : null, "That photo could not be used."));
    }
  };

  // Both the type and the photo are required; the API checks the file again.
  const ready = type !== null && file !== null;

  return (
    <>
      {rejected && <Notice>Your last application was not approved. You can apply again with a clearer photo of your ID.</Notice>}

      <TypeRow title={type ? TYPE_TITLE[type] : "Choose one"}>
        {TYPES.map((item) => {
          const selected = item === type;

          return (
            <Pressable
              key={item}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              disabled={apply.isPending}
              onPress={() => setType(item)}
              style={[styles.chip, selected && styles.chipOn]}
              testID={`discount-type-${item}`}
            >
              <AppText family="display" weight="semibold" size={12.5} color={selected ? colors.white : colors.authLabel}>
                {DISCOUNT_LABEL[item]}
              </AppText>
            </Pressable>
          );
        })}
      </TypeRow>

      <View style={styles.attachBlock}>
        <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
          Attached Senior / PWD ID Photo
        </AppText>

        {file ? (
          <View style={styles.picked}>
            <Image source={{ uri: file.uri }} style={styles.thumb} contentFit="cover" />
            <View style={styles.flex}>
              <AppText family="display" weight="medium" size={13} color={colors.authInk} numberOfLines={1}>
                {file.name}
              </AppText>
              <AppText family="display" size={12} color={colors.success}>
                Ready to submit
              </AppText>
            </View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Remove this photo"
              disabled={apply.isPending}
              onPress={() => setFile(null)}
              hitSlop={8}
              style={styles.remove}
            >
              <IconX size={16} color={colors.authMuted} />
            </Pressable>
          </View>
        ) : (
          <Pressable accessibilityRole="button" onPress={() => void attach()} style={styles.attach} testID="discount-attach">
            <IconPaperclip size={17} color={colors.authHint} strokeWidth={2} />
            <AppText family="display" size={12.5} color={colors.authHint} style={styles.flex}>
              Attach a photo of your ID (.jpg or .png, up to 5 MB)
            </AppText>
            <AppText family="display" weight="semibold" size={12} color={colors.orange}>
              ATTACH PHOTO
            </AppText>
          </Pressable>
        )}

        {error ? (
          <AppText family="display" size={12} color={colors.danger} accessibilityLiveRegion="polite">
            {error}
          </AppText>
        ) : null}
      </View>

      <View style={styles.footnote}>
        <View style={styles.bullet} />
        <AppText family="display" size={12} color={colors.authMuted} style={styles.flex}>
          A Store Agent verifies your ID, usually within a few minutes. The {percent}% discount can be used once it is approved,
          once per day.
        </AppText>
      </View>

      <View style={styles.buttons}>
        <CheckoutButton
          label="Later"
          variant="outline"
          icon={choice === "later" ? IconCheck : undefined}
          disabled={apply.isPending}
          onPress={() => onChoose("later")}
          flex={1}
          testID="discount-later"
        />
        <CheckoutButton
          label="Submit Photo"
          disabled={!ready}
          loading={apply.isPending}
          onPress={() => type && file && apply.mutate({ kind: type, picked: file })}
          flex={1.6}
          testID="discount-submit"
        />
      </View>
      <Chosen choice={choice} amount={0} />
    </>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  center: { textAlign: "center" },
  caps: { letterSpacing: 0.6 },
  skeleton: { height: 64, borderRadius: 12, backgroundColor: "#f4f1ec" },
  skeletonShort: { height: 48 },
  typeBlock: { gap: 6 },
  typeRow: { flexDirection: "row", alignItems: "center", gap: 8 },
  chip: {
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: "#f4f1ec",
  },
  chipOn: { backgroundColor: colors.orange, borderColor: colors.orange },
  buttons: { flexDirection: "row", gap: 10 },
  attachBlock: { gap: 7 },
  attach: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderWidth: 1.5,
    borderStyle: "dashed",
    borderColor: "#e4ded6",
    backgroundColor: "#faf8f5",
  },
  picked: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    padding: 10,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#c8ebd4",
    backgroundColor: colors.successSurface,
  },
  thumb: { width: 46, height: 46, borderRadius: 8, backgroundColor: colors.white },
  remove: { width: 30, height: 30, borderRadius: 15, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  footnote: { flexDirection: "row", alignItems: "flex-start", gap: 6 },
  footnoteIcon: { marginTop: 2 },
  bullet: { width: 5, height: 5, borderRadius: 3, backgroundColor: colors.orange, marginTop: 7 },
});
