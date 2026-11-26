import { useContext } from "react";
import { useMediaQuery } from "@mantine/hooks";

import AuthContext from "../context/AuthContext";
import { PHONE_QUERY } from "../lib/phone";
import { ROLES } from "../lib/roles";

/**
 * Whether this page should take the app's phone design (lib/phone.js): a
 * phone-sized screen, for a customer or a guest. Staff keep their portal.
 * Read straight away, not after a first render, so a phone never flashes
 * the website first.
 */
export function usePhoneLayout() {
  const phone = useMediaQuery(PHONE_QUERY, false, { getInitialValueInEffect: false });
  const { user } = useContext(AuthContext);
  const staff = user?.role === ROLES.ADMIN || user?.role === ROLES.SUPER_ADMIN;

  return Boolean(phone) && !staff;
}

export default usePhoneLayout;
