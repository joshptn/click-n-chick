import ReasonModal from "./ReasonModal";
import { CLAIM_REJECTION_REASONS, claimLabel, claimantName } from "../../lib/agent";

/**
 * Reject a discount application.
 *
 * The reason is required: it is the customer's notification, and the only way
 * they know what to fix before applying again with a new photo.
 */
function RejectClaimModal({ claim, opened, onClose, onConfirm, loading }) {
  if (!claim) return null;

  return (
    <ReasonModal
      opened={opened}
      onClose={onClose}
      onConfirm={onConfirm}
      loading={loading}
      resetKey={claim.id}
      title={`Reject application #${claimLabel(claim)}?`}
      description={`${claimantName(claim)} is told why, and can apply again with a new photo.`}
      presets={CLAIM_REJECTION_REASONS}
      hint="Required. Say what to fix so the next photo can be approved."
      maxLength={500}
      confirmLabel="Reject application"
      busyLabel="Rejecting…"
      testId="claim-reject"
    />
  );
}

export default RejectClaimModal;
