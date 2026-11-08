import { IconLock } from "@tabler/icons-react";

/** Says why a staff page has no controls for the role viewing it. */
function ReadOnlyNotice({ children }) {
  return (
    <p
      data-testid="read-only-notice"
      className="m-0 flex items-start gap-2 rounded-[10px] bg-white px-3.5 py-2.5 font-display text-[12px] text-[#6f6b68] ring-1 ring-inset ring-[#f0e9df]"
    >
      <IconLock size={15} stroke={2} className="mt-[1px] shrink-0" />
      <span>{children}</span>
    </p>
  );
}

export default ReadOnlyNotice;
