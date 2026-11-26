/**
 * The Orders, Notification and Profile tabs' top bar on a phone (the app's):
 * the title centred on the whole bar, anything else on the right, the same
 * height on every tab so moving between them does not jump.
 */
function ScreenTitle({ title, right = null }) {
  return (
    <div className="relative flex min-h-[58px] items-center justify-end px-4 py-2.5">
      <h1 className="pointer-events-none absolute inset-x-0 m-0 text-center font-display text-[19px] font-semibold text-[#111827]">
        {title}
      </h1>
      {right && <div className="relative">{right}</div>}
    </div>
  );
}

export default ScreenTitle;
