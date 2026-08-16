export const PASSWORD_RULES = [
  { label: "At least 8 characters", test: (value) => value.length >= 8 },
  { label: "One uppercase letter", test: (value) => /[A-Z]/.test(value) },
  { label: "One number", test: (value) => /\d/.test(value) },
];

export function unmetPasswordRules(value) {
  return PASSWORD_RULES.filter((rule) => !rule.test(value ?? ""));
}

export default PASSWORD_RULES;
