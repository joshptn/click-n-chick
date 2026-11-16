import type { Channel, SessionPayload } from "../api/auth";

type AuthFlow = {
  /** The email or phone number typed, as the API wants it back. */
  identifier?: string;
  /** Where the code went. */
  channel?: Channel;
  /** The masked form the API returned, for display. */
  masked?: string;
  /** Seconds before another code may be asked for. */
  resendIn?: number;

  challengeToken?: string;

  /** The reset code typed on the code screen, sent with the new password. */
  resetCode?: string;
  /** Set when the API refused the reset code, shown back on the code screen. */
  resetCodeError?: string;

  /** A session held until the customer continues from the success screen. */
  pending?: SessionPayload;

  /** Why the customer was sent back to the start, shown there once. */
  notice?: string;
};

let flow: AuthFlow = {};

export const authFlow = {
  get: (): AuthFlow => flow,
  set: (next: AuthFlow) => {
    flow = { ...flow, ...next };
  },
  clear: () => {
    flow = {};
  },
  /** Read and remove a one-time notice. */
  takeNotice: (): string | undefined => {
    const notice = flow.notice;
    flow = { ...flow, notice: undefined };
    return notice;
  },
};
