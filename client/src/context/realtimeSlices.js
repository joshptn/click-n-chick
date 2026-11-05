import { createContext } from "react";

/** Live notifications received on the user's channel (see RealtimeProvider). */
export const LiveNotificationsContext = createContext(null);

/** The last broadcast on the staff order firehose, or null. */
export const LastOrderEventContext = createContext(null);
