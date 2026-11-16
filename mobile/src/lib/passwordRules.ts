/**
 * The web app's password rules (client/src/lib/passwordRules.js), checked
 * before sending so a weak password is caught on the phone. The server's
 * StrongPassword rule stays the authority.
 */
export const PASSWORD_RULES = [
  { label: "at least 8 characters", test: (value: string) => value.length >= 8 },
  { label: "one uppercase letter", test: (value: string) => /[A-Z]/.test(value) },
  { label: "one number", test: (value: string) => /\d/.test(value) },
];

/** "Password needs one uppercase letter and one number.", or null when all are met. */
export function passwordProblem(value: string): string | null {
  // Nothing typed yet: ask for it, rather than list every rule.
  if (value === "") return "Enter a password.";

  const unmet = PASSWORD_RULES.filter((rule) => !rule.test(value)).map((rule) => rule.label);

  if (unmet.length === 0) return null;

  const list = unmet.length === 1 ? unmet[0] : `${unmet.slice(0, -1).join(", ")} and ${unmet[unmet.length - 1]}`;

  return `Password needs ${list}.`;
}
