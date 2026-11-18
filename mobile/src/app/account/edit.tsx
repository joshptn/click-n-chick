import { useMemo, useState } from "react";
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { useMutation } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { ApiError } from "../../api/client";
import { accountApi, type PickedFile } from "../../api/account";
import { AppText } from "../../components/AppText";
import { AuthButton } from "../../components/auth/AuthButton";
import { Avatar } from "../../components/account/Avatar";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { Checkbox } from "../../components/auth/Checkbox";
import { TextField } from "../../components/auth/TextField";
import { IconCamera, IconLock } from "../../components/icons";
import { useProfile } from "../../hooks/useProfile";
import { PickError, pickImage } from "../../lib/pickImage";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";

type Form = { first_name: string; last_name: string; phone_number: string };
type FieldErrors = Partial<Record<keyof Form, string>>;

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * Edit Profile (UC-PROF-001), the web's Personal Information card as a screen:
 * photo, first and last name, phone number, and the privacy consent the API
 * needs before it saves. The email signs the customer in, so it is shown but
 * not editable, as on the web.
 */
export default function EditProfile() {
  const { data, isLoading, adopt } = useProfile();
  const user = data?.user;

  const [form, setForm] = useState<Form>({ first_name: "", last_name: "", phone_number: "" });
  const [consent, setConsent] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [seeded, setSeeded] = useState(false);

  // Fill the form once the profile arrives (and not again, so a background
  // refresh never overwrites what is being typed). Set while rendering, as
  // React recommends for state derived from a prop that arrives later.
  if (data && !seeded) {
    setSeeded(true);
    setForm({
      first_name: data.user.first_name ?? "",
      last_name: data.user.last_name ?? "",
      phone_number: data.user.phone_number ?? "",
    });
    setConsent(Boolean(data.profile.privacy_consent));
  }

  const phoneLock = data?.profile.phone_locked_reason ?? null;

  const dirty = useMemo(() => {
    if (!user) return false;

    return (
      form.first_name !== (user.first_name ?? "") ||
      form.last_name !== (user.last_name ?? "") ||
      form.phone_number !== (user.phone_number ?? "") ||
      consent !== Boolean(data?.profile.privacy_consent)
    );
  }, [form, consent, user, data?.profile.privacy_consent]);

  const save = useMutation({
    mutationFn: () =>
      accountApi.updateProfile({
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        privacy_consent: consent,
        // Only when it changed: the API refuses a phone edit while the number
        // is how the account verifies, which must not block saving a name.
        ...(form.phone_number !== (user?.phone_number ?? "") ? { phone_number: form.phone_number.trim() } : {}),
      }),
    onSuccess: (payload) => {
      setErrors({});
      adopt(payload);
      setForm({
        first_name: payload.user.first_name ?? "",
        last_name: payload.user.last_name ?? "",
        phone_number: payload.user.phone_number ?? "",
      });
      toast.success("Profile saved", payload.message ?? "Your details have been updated.");
      router.back();
    },
    onError: (error) => {
      const fieldErrors = error instanceof ApiError ? (error.payload.errors as Record<string, string[]> | undefined) : undefined;

      if (fieldErrors) {
        setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([key, list]) => [key, list[0]])) as FieldErrors);
        return;
      }

      toast.error("Not saved", messageOf(error, "We could not save your profile."));
    },
  });

  const photo = useMutation({
    mutationFn: (file: PickedFile) => accountApi.uploadAvatar(file),
    onSuccess: (payload) => {
      adopt(payload);
      toast.success("Photo updated");
    },
    onError: (error) => toast.error("Photo not saved", messageOf(error, "We could not upload that picture.")),
  });

  const removePhoto = useMutation({
    mutationFn: () => accountApi.removeAvatar(),
    onSuccess: (payload) => {
      adopt(payload);
      toast.success("Photo removed");
    },
    onError: (error) => toast.error("Something went wrong", messageOf(error, "We could not remove your photo.")),
  });

  const changePhoto = async () => {
    try {
      const file = await pickImage("Profile photo", { accept: ["image/jpeg", "image/png", "image/webp"], square: true });
      if (file) photo.mutate(file);
    } catch (error) {
      toast.error("Photo not used", messageOf(error instanceof PickError ? error : null, "That photo could not be used."));
    }
  };

  const set = (key: keyof Form) => (value: string) => {
    setForm((previous) => ({ ...previous, [key]: value }));
    setErrors((previous) => ({ ...previous, [key]: undefined }));
  };

  const name = [user?.first_name, user?.last_name].filter(Boolean).join(" ");
  const photoBusy = photo.isPending || removePhoto.isPending;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Edit Profile" />

      {isLoading || !data ? (
        <View style={styles.loading}>
          <ActivityIndicator color={colors.orange} />
        </View>
      ) : (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <View style={styles.hero}>
              <View>
                <Avatar uri={user?.avatar} firstName={user?.first_name} lastName={user?.last_name} size={112} />
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Change profile photo"
                  disabled={photoBusy}
                  onPress={changePhoto}
                  style={styles.camera}
                  hitSlop={6}
                  testID="change-photo"
                >
                  {photoBusy ? <ActivityIndicator size="small" color={colors.white} /> : <IconCamera size={17} color={colors.white} strokeWidth={2} />}
                </Pressable>
              </View>
              <AppText family="display" weight="semibold" size={22} color={colors.authInk} style={styles.center}>
                {name || "Your account"}
              </AppText>
              {user?.email ? (
                <AppText family="display" size={12.5} color={colors.authHint} style={styles.center}>
                  {user.email}
                </AppText>
              ) : null}
              {user?.avatar ? (
                <Pressable accessibilityRole="button" disabled={photoBusy} onPress={() => removePhoto.mutate()} hitSlop={6}>
                  <AppText family="display" weight="medium" size={12.5} color={colors.brand600}>
                    Remove photo
                  </AppText>
                </Pressable>
              ) : null}
            </View>

            <View style={styles.row}>
              <View style={styles.flex}>
                <TextField
                  label="First Name"
                  value={form.first_name}
                  onChangeText={set("first_name")}
                  error={errors.first_name}
                  autoCapitalize="words"
                  autoComplete="given-name"
                  maxLength={255}
                  testID="first-name"
                />
              </View>
              <View style={styles.flex}>
                <TextField
                  label="Last Name"
                  value={form.last_name}
                  onChangeText={set("last_name")}
                  error={errors.last_name}
                  autoCapitalize="words"
                  autoComplete="family-name"
                  maxLength={255}
                  testID="last-name"
                />
              </View>
            </View>

            <View style={styles.field}>
              <TextField label="Email Address" value={user?.email ?? ""} editable={false} style={styles.locked} />
              <Hint text="Your email signs you in, so it can't be changed here." />
            </View>

            <View style={styles.field}>
              <TextField
                label="Phone Number"
                value={form.phone_number}
                onChangeText={set("phone_number")}
                error={errors.phone_number}
                editable={!phoneLock}
                keyboardType="phone-pad"
                autoComplete="tel"
                maxLength={20}
                placeholder="09XXXXXXXXX"
                style={phoneLock ? styles.locked : undefined}
                testID="phone-number"
              />
              {phoneLock ? <Hint text={phoneLock} /> : null}
            </View>

            <Checkbox checked={consent} onChange={setConsent} testID="privacy-consent">
              <AppText family="display" size={12.5} color={colors.authMuted}>
                I agree to allow BES Click-n-Chick to save my delivery address and order information.
              </AppText>
            </Checkbox>
          </ScrollView>

          <View style={styles.footer}>
            <AuthButton
              label="Save Changes"
              loading={save.isPending}
              disabled={!consent || !dirty}
              onPress={() => save.mutate()}
              testID="save-profile"
            />
            <AppText family="display" size={11.5} color={colors.authHint} style={styles.center}>
              {!consent ? "Tick the consent box to save your profile." : !dirty ? "No changes to save." : "Ready to save."}
            </AppText>
          </View>
        </KeyboardAvoidingView>
      )}
    </SafeAreaView>
  );
}

function Hint({ text }: { text: string }) {
  return (
    <View style={styles.hint}>
      <IconLock size={12} color={colors.authHint} strokeWidth={2} />
      <AppText family="display" size={11.5} color={colors.authHint} style={styles.flex}>
        {text}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  loading: { flex: 1, alignItems: "center", justifyContent: "center" },
  content: { paddingHorizontal: 20, paddingTop: 8, paddingBottom: 24, gap: 18 },
  hero: { alignItems: "center", gap: 4, paddingBottom: 6 },
  camera: {
    position: "absolute",
    right: 2,
    bottom: 4,
    width: 34,
    height: 34,
    borderRadius: 17,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
    borderWidth: 2.5,
    borderColor: colors.white,
  },
  center: { textAlign: "center" },
  row: { flexDirection: "row", gap: 12 },
  field: { gap: 6 },
  locked: { color: colors.authHint },
  hint: { flexDirection: "row", gap: 5, alignItems: "flex-start" },
  footer: { paddingHorizontal: 20, paddingTop: 10, paddingBottom: 12, gap: 6, borderTopWidth: 1, borderTopColor: "#f1ebe3" },
});
