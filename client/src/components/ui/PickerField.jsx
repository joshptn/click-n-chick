/**
 * A field that opens a picker instead of taking typing: the chosen value or a
 * placeholder, with an icon, styled like the other inputs.
 */
function PickerField({ id, icon, value, placeholder, onOpen, invalid = false, disabled = false, ariaLabel }) {
  const Icon = icon;

  return (
    <button
      id={id}
      type="button"
      onClick={onOpen}
      disabled={disabled}
      aria-label={ariaLabel}
      aria-haspopup="dialog"
      className={`relative flex h-[46px] w-full items-center rounded-[10px] border bg-white pl-10 pr-3 text-left transition-colors hover:border-brand-300 focus-visible:border-brand-400 focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-[#faf7f3] ${
        invalid ? "border-[#e5322d]" : "border-[#ece7e0]"
      }`}
    >
      {Icon && (
        <Icon
          size={16}
          stroke={2}
          aria-hidden="true"
          className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#a39f9b]"
        />
      )}
      {/* On the span: Mantine resets type on <button> itself. */}
      <span className={`truncate font-display text-[13.5px] ${value ? "text-ink" : "text-[#a39f9b]"}`}>{value || placeholder}</span>
    </button>
  );
}

export default PickerField;
