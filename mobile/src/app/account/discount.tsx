import { useState } from "react";
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Image } from "expo-image";
import { useMutation } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { ApiError } from "../../api/client";
import { accountApi, type DiscountType, type PickedFile } from "../../api/account";
import { AppText } from "../../components/AppText";
import { AuthButton } from "../../components/auth/AuthButton";
import { DISCOUNT_LABEL, percentText } from "../../components/account/DiscountCard";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { IconCircleCheck, IconInfoCircle, IconUpload, IconX } from "../../components/icons";
import { useProfile } from "../../hooks/useProfile";
import { PickError, pickImage } from "../../lib/pickImage";
import { colors } from "../../theme/colors";

const TYPES: DiscountType[] = ["senior", "pwd"];

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * Apply for the Senior Citizen or PWD discount (the web's
 * DiscountUploadModal as a screen): choose the type, photograph the ID or
 * pick it from the gallery, and send it for a Store Agent to review. The API
 * accepts .jpg or .png up to 5 MB and stays the authority.
 */
export default function DiscountApplication() {
  const { data, isLoading, adopt, refetch } = useProfile();
  const [type, setType] = useState<DiscountType>("senior");
  const [file, setFile] = useState<PickedFile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  const discount = data?.discount ?? null;
  const percent = percentText(discount?.percentage);
  const label = DISCOUNT_LABEL[type];

  const apply = useMutation({
    mutationFn: (picked: PickedFile) => accountApi.applyForDiscount(type, picked),
    onSuccess: (payload) => {
      if (data?.discount) adopt({ discount: { ...data.discount, ...payload } });
      setSubmitted(true);
    },
    onError: (failure) => {
      setError(messageOf(failure, "We could not submit your application."));
      // A claim already exists: refresh so the Profile card shows it.
      if (failure instanceof ApiError && failure.payload.code) void refetch();
    },
  });

  const choose = async () => {
    setError(null);

    try {
      const picked = await pickImage(`${label} ID`, { accept: ["image/jpeg", "image/png"] });
      if (picked) setFile(picked);
    } catch (failure) {
      setError(messageOf(failure instanceof PickError ? failure : null, "That photo could not be used."));
    }
  };

  if (submitted) {
    return (
      <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
        <View style={styles.done}>
          <View style={styles.doneIcon}>
            <IconCircleCheck size={46} color={colors.success} strokeWidth={1.8} />
          </View>
          <AppText family="display" weight="semibold" size={20} color={colors.authInk} style={styles.center}>
            Submitted for Review
          </AppText>
          <AppText family="display" size={13.5} color={colors.authMuted} style={styles.center}>
            Your ID has been sent to a Store Agent. We&apos;ll notify you once your {percent}% {label} Discount is approved.
          </AppText>
          <View style={styles.doneButton}>
            <AuthButton label="Done" onPress={() => router.back()} testID="discount-done" />
          </View>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Discount" />

      {isLoading ? (
        <View style={styles.center1}>
          <ActivityIndicator color={colors.orange} />
        </View>
      ) : !discount?.can_apply ? (
        <View style={styles.center1}>
          <AppText family="display" size={14} color={colors.authMuted} style={styles.center}>
            {discount?.claim?.discount_status === "approved"
              ? "Your discount is already approved."
              : "Your application is already with a Store Agent. We'll notify you once it's reviewed."}
          </AppText>
        </View>
      ) : (
        <>
          <ScrollView contentContainerStyle={styles.content}>
            <AppText family="display" size={13.5} color={colors.authMuted}>
              Upload your valid ID to unlock the discount.
            </AppText>

            <View style={styles.types} accessibilityRole="radiogroup">
              {TYPES.map((item) => {
                const selected = item === type;

                return (
                  <Pressable
                    key={item}
                    accessibilityRole="radio"
                    accessibilityState={{ selected }}
                    disabled={apply.isPending}
                    onPress={() => setType(item)}
                    style={[styles.type, selected && styles.typeSelected]}
                    testID={`discount-type-${item}`}
                  >
                    <AppText family="display" weight="semibold" size={14} color={selected ? colors.white : colors.authLabel}>
                      {DISCOUNT_LABEL[item]}
                    </AppText>
                  </Pressable>
                );
              })}
            </View>

            <View style={styles.banner}>
              <AppText family="display" weight="semibold" size={15} color={colors.white}>
                {percent}% {label} Discount
              </AppText>
              <AppText family="display" size={12} color="rgba(255,255,255,0.88)">
                Applicable once per day. After agent approval.
              </AppText>
            </View>

            <View style={styles.uploadHead}>
              <AppText family="display" weight="semibold" size={11.5} color={colors.authMuted} style={styles.caps}>
                {`Upload ${label} ID`.toUpperCase()}
              </AppText>
              <View style={styles.required}>
                <AppText family="display" weight="semibold" size={10.5} color={colors.brand600}>
                  Required
                </AppText>
              </View>
            </View>

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
              <Pressable accessibilityRole="button" onPress={choose} style={styles.drop} testID="discount-pick">
                <View style={styles.dropIcon}>
                  <IconUpload size={20} color={colors.authMuted} strokeWidth={1.9} />
                </View>
                <AppText family="display" weight="semibold" size={14} color={colors.authInk}>
                  Take a photo or choose one
                </AppText>
                <AppText family="display" size={11.5} color={colors.authHint}>
                  .jpg or .png, up to 5 MB
                </AppText>
              </Pressable>
            )}

            {error ? (
              <AppText family="display" size={12.5} color={colors.danger} accessibilityLiveRegion="polite">
                {error}
              </AppText>
            ) : null}

            <View style={styles.notice}>
              <View style={styles.noticeHead}>
                <IconInfoCircle size={15} color="#1c7ed6" strokeWidth={2.2} />
                <AppText family="display" weight="semibold" size={11.5} color="#1c7ed6" style={styles.caps}>
                  IMPORTANT NOTICE
                </AppText>
              </View>
              {[
                "Your ID will be reviewed by a Store Agent. This may take a short while.",
                `Once approved, the VAT exemption and ${percent}% discount can be used once per day only, on one item tagged SC/PWD discount.`,
                "You will be notified once your verification is complete.",
              ].map((line) => (
                <View key={line} style={styles.noticeLine}>
                  <View style={styles.dot} />
                  <AppText family="display" size={12.5} color="#41618a" style={styles.flex}>
                    {line}
                  </AppText>
                </View>
              ))}
            </View>
          </ScrollView>

          <View style={styles.footer}>
            <AuthButton
              label="Submit for Review"
              loading={apply.isPending}
              disabled={!file}
              onPress={() => file && apply.mutate(file)}
              testID="discount-submit"
            />
          </View>
        </>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  center: { textAlign: "center" },
  center1: { flex: 1, alignItems: "center", justifyContent: "center", paddingHorizontal: 32 },
  caps: { letterSpacing: 0.6 },
  content: { paddingHorizontal: 20, paddingTop: 4, paddingBottom: 24, gap: 14 },
  types: { flexDirection: "row", gap: 10 },
  type: {
    flex: 1,
    alignItems: "center",
    paddingVertical: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
  },
  typeSelected: { backgroundColor: colors.orange, borderColor: colors.orange },
  banner: { gap: 2, padding: 16, borderRadius: 16, backgroundColor: colors.brand500 },
  uploadHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  required: {
    paddingHorizontal: 10,
    paddingVertical: 2,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: "#ffd9b8",
    backgroundColor: colors.brand50,
  },
  drop: {
    alignItems: "center",
    gap: 4,
    paddingVertical: 26,
    borderRadius: 16,
    borderWidth: 2,
    borderStyle: "dashed",
    borderColor: "#e4ded6",
    backgroundColor: colors.white,
  },
  dropIcon: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f4f1ec",
    marginBottom: 6,
  },
  picked: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    padding: 12,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#c8ebd4",
    backgroundColor: colors.successSurface,
  },
  thumb: { width: 54, height: 54, borderRadius: 10, backgroundColor: colors.white },
  remove: { width: 30, height: 30, borderRadius: 15, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  notice: { gap: 8, padding: 14, borderRadius: 16, borderWidth: 1, borderColor: "#d6e6fb", backgroundColor: "#f4f9ff" },
  noticeHead: { flexDirection: "row", alignItems: "center", gap: 6 },
  noticeLine: { flexDirection: "row", gap: 8 },
  dot: { width: 4, height: 4, borderRadius: 2, backgroundColor: "#1c7ed6", marginTop: 8 },
  footer: { paddingHorizontal: 20, paddingTop: 10, paddingBottom: 12, borderTopWidth: 1, borderTopColor: "#f1ebe3" },
  done: { flex: 1, alignItems: "center", justifyContent: "center", gap: 10, paddingHorizontal: 32 },
  doneIcon: {
    width: 88,
    height: 88,
    borderRadius: 44,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#e9f8ee",
    marginBottom: 6,
  },
  doneButton: { alignSelf: "stretch", marginTop: 14 },
});
