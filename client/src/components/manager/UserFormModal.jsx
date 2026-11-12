import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";

import Button from "../ui/Button";
import Field from "../ui/Field";
import Input from "../ui/Input";
import { StepUpCancelled } from "../../hooks/useStepUp";
import { PASSWORD_RULES, unmetPasswordRules } from "../../lib/passwordRules";
import { ROLES } from "../../lib/roles";
import { ROLE_OPTIONS } from "../../lib/users";

const EMPTY = {
  first_name: "",
  last_name: "",
  identifier: "",
  new_password: "",
  new_password_confirmation: "",
  role: ROLES.CUSTOMER,
};

const SELECT =
  "h-[46px] w-full rounded-[10px] border border-[#e8e4de] bg-white px-3 font-display text-[14px] text-ink outline-none focus:border-brand-500";

function UserFormModal({ opened, onClose, onSubmit }) {
  const [values, setValues] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (opened) {
      setValues(EMPTY);
      setErrors({});
      setFormError(null);
    }
  }, [opened]);

  const bind = (key) => ({
    value: values[key],
    invalid: Boolean(errors[key]),
    onChange: (event) => setValues((current) => ({ ...current, [key]: event.target.value })),
  });

  const validate = () => {
    const found = {};

    ["first_name", "last_name", "identifier"].forEach((key) => {
      if (!values[key].trim()) found[key] = "Required.";
    });

    if (unmetPasswordRules(values.new_password).length > 0) found.new_password = "Does not meet the rules below.";
    if (values.new_password !== values.new_password_confirmation) found.new_password_confirmation = "The passwords do not match.";

    return found;
  };

  const submit = async (event) => {
    event.preventDefault();

    const found = validate();

    setErrors(found);
    setFormError(null);

    if (Object.keys(found).length > 0) return;

    setBusy(true);

    try {
      await onSubmit(values);
      onClose();
    } catch (failure) {
      if (failure instanceof StepUpCancelled) return;

      const fields = failure.payload?.errors;

      if (fields) setErrors(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])));
      else setFormError(failure.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={busy ? () => {} : onClose}
      centered
      radius={16}
      size={500}
      title={<span className="font-display text-[17px] font-bold text-ink">Add new user</span>}
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-3" data-testid="user-form">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="First name" required error={errors.first_name}>
            <Input {...bind("first_name")} autoComplete="off" data-testid="user-first-name" />
          </Field>
          <Field label="Last name" required error={errors.last_name}>
            <Input {...bind("last_name")} autoComplete="off" data-testid="user-last-name" />
          </Field>
        </div>

        <Field label="Phone number or email address" required error={errors.identifier}>
          <Input {...bind("identifier")} placeholder="0917 123 4567 or name@example.com" autoComplete="off" data-testid="user-identifier" />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Password" required error={errors.new_password}>
            <Input type="password" {...bind("new_password")} autoComplete="new-password" data-testid="user-password" />
          </Field>
          <Field label="Confirm password" required error={errors.new_password_confirmation}>
            <Input type="password" {...bind("new_password_confirmation")} autoComplete="new-password" data-testid="user-password-confirm" />
          </Field>
        </div>
        <p className="m-0 font-display text-[11px] text-[#8d8884]">{PASSWORD_RULES.map((rule) => rule.label).join(" · ")}</p>

        <Field label="Role" error={errors.role}>
          <select className={SELECT} value={values.role} onChange={bind("role").onChange} data-testid="user-role">
            {ROLE_OPTIONS.map((option) => (
              <option key={option.key} value={option.key}>
                {option.label}
              </option>
            ))}
          </select>
        </Field>

        <p className="m-0 rounded-[10px] bg-[#faf6f0] px-3 py-2 font-display text-[11.5px] leading-relaxed text-[#6f6b68]">
          They confirm a one-time code sent to that phone number or email address the first time they sign in. Discounts are not set here: a
          Store Agent approves them from the customer&rsquo;s ID.
        </p>

        {formError && (
          <p className="m-0 rounded-[10px] bg-[#fdecec] px-3 py-2 font-display text-[12px] text-[#c4302b]" role="alert">
            {formError}
          </p>
        )}

        <div className="mt-1 grid grid-cols-2 gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button type="submit" loading={busy} data-testid="user-save">
            Save user
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export default UserFormModal;
