import { useContext, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Modal } from "@mantine/core";

import AuthContext from "../../context/AuthContext";
import Button from "../ui/Button";

/** "Sign out of your account?" for the account menu and the phone's Profile tab. */
function SignOutModal({ opened, onClose }) {
  const nav = useNavigate();
  const { logOut } = useContext(AuthContext);
  const [signingOut, setSigningOut] = useState(false);

  const handleSignOut = async () => {
    setSigningOut(true);

    try {
      await logOut();
    } finally {
      setSigningOut(false);
      onClose();
      nav("/login", { replace: true });
    }
  };

  return (
    <Modal opened={opened} onClose={onClose} title="Sign out of your account?" centered radius="md">
      <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
        You will need to sign in again on this device. Your other devices stay signed in.
      </p>

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={signingOut}>
          Cancel
        </Button>
        <Button variant="danger" size="sm" loading={signingOut} loadingLabel="Signing out&hellip;" onClick={handleSignOut}>
          Sign out
        </Button>
      </div>
    </Modal>
  );
}

export default SignOutModal;
