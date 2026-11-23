import { useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { mobileDigits, mobileForField, toLocalMobile } from "../../api/checkout";
import type { ContactChanges, Order } from "../../api/orders";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { Sheet } from "../account/Sheet";
import { TextField } from "../auth/TextField";
import { CheckoutButton } from "../checkout/CheckoutButton";
import { IconUserEdit } from "../icons";

function messageOf(error: unknown) {
  return error instanceof Error && error.message ? error.message : "Your contact details could not be changed. Please try again.";
}

/**
 * Change the order's contact name and mobile number (BR-21b, decided
 * 2026-11-17; the web's EditOrderModal): the only details that can change
 * after paying, until the order closes. This order's copy only, not the
 * profile. Shown only while the API says the contact is editable.
 */
export function ContactEditor({ order, onSave }: { order: Order; onSave: (changes: ContactChanges) => Promise<unknown> }) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const currentName = order.contact.name ?? "";
  const currentPhone = mobileForField(order.contact.phone);

  const start = () => {
    setName(currentName);
    setPhone(currentPhone);
    setError(null);
    setOpen(true);
  };

  const changes: ContactChanges = {};
  if (name.trim() !== currentName) changes.contact_name = name.trim();
  if (phone !== currentPhone) changes.contact_phone = phone;

  const changed = Object.keys(changes).length > 0;
  // Grey until something changed and both are valid, as checkout's buttons.
  const valid = name.trim() !== "" && toLocalMobile(phone) !== "";
  const phoneError = phone.trim() !== "" && !toLocalMobile(phone) ? "Enter an 11-digit mobile number starting 09." : undefined;

  const save = async () => {
    if (!changed || !valid || saving) return;

    setSaving(true);
    setError(null);

    try {
      await onSave(changes);
      setOpen(false);
    } catch (failure) {
      setError(messageOf(failure));
    } finally {
      setSaving(false);
    }
  };

  const delivery = order.order_type === "delivery";

  return (
    <>
      <View style={styles.card} testID="change-contact-card">
        <AppText family="display" size={12.5} color={colors.ink500}>
          Someone else {delivery ? "receiving" : "collecting"} it, or a new number? You can change the contact name and mobile number
          until your order is completed.
        </AppText>
        <Pressable
          accessibilityRole="button"
          onPress={start}
          style={({ pressed }) => [styles.button, pressed && styles.pressed]}
          testID="change-contact"
        >
          <IconUserEdit size={16} color={colors.orange} strokeWidth={2.1} />
          <AppText family="display" weight="semibold" size={13} color={colors.orange}>
            Change contact
          </AppText>
        </Pressable>
      </View>

      <Sheet
        visible={open}
        title="Change contact details"
        subtitle="Who the store should call about this order. This changes this order only, not your profile."
        onClose={() => setOpen(false)}
        busy={saving}
        testID="change-contact-sheet"
      >
        <TextField
          label={delivery ? "Recipient's name" : "Name of who will collect it"}
          value={name}
          onChangeText={(text) => setName(text.slice(0, 255))}
          autoComplete="name"
          editable={!saving}
          testID="contact-name-input"
        />
        <TextField
          label="Mobile number"
          value={phone}
          onChangeText={(text) => setPhone(mobileDigits(text))}
          placeholder="09123456789"
          keyboardType="number-pad"
          maxLength={11}
          autoComplete="tel"
          editable={!saving}
          error={phoneError}
          testID="contact-phone-input"
        />

        {error ? (
          <View style={styles.error}>
            <AppText family="display" size={12.5} color={colors.danger} accessibilityLiveRegion="polite">
              {error}
            </AppText>
          </View>
        ) : null}

        <View style={styles.actions}>
          <CheckoutButton label="Keep as is" variant="outline" onPress={() => setOpen(false)} disabled={saving} flex={1} />
          <CheckoutButton
            label="Save changes"
            onPress={() => void save()}
            disabled={!changed || !valid}
            loading={saving}
            flex={1.4}
            testID="save-contact"
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
    gap: 10,
    elevation: 2,
    shadowColor: "#412111",
    shadowOpacity: 0.08,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
  },
  button: {
    alignSelf: "flex-start",
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 10,
    borderWidth: 1.5,
    borderColor: colors.orange,
    backgroundColor: colors.white,
  },
  pressed: { opacity: 0.8 },
  error: { padding: 12, borderRadius: 12, backgroundColor: colors.dangerSurface },
  actions: { flexDirection: "row", gap: 10 },
});

export default ContactEditor;
