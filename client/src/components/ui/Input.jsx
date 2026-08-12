import { forwardRef, useId, useState } from 'react';
import { IconEye, IconEyeOff } from '@tabler/icons-react';

const Input = forwardRef(function Input(
  { id, type = 'text', icon: Icon, prefix, invalid = false, className = '', ...rest },
  ref
) {
  const generatedId = useId();
  const inputId = id ?? generatedId;

  const isPassword = type === 'password';
  const [revealed, setRevealed] = useState(false);
  const resolvedType = isPassword && revealed ? 'text' : type;

  const borderClass = invalid
    ? 'border-[#e5322d] focus-within:border-[#e5322d]'
    : 'border-[#e8e4de] focus-within:border-brand-500';

  return (
    <div
      className={`flex h-[46px] w-full items-center gap-2.5 rounded-[10px] border bg-white px-3.5 transition-colors duration-150 focus-within:shadow-[0_0_0_3px_rgba(255,139,43,0.12)] ${borderClass} ${className}`}
    >
      {Icon && (
        <Icon size={18} stroke={1.8} className="shrink-0 text-[#b8b2aa]" aria-hidden="true" />
      )}

      {prefix && (
        <span className="shrink-0 select-none font-display text-[14px] text-[#8d867e]">{prefix}</span>
      )}

      <input
        ref={ref}
        id={inputId}
        type={resolvedType}
        className="h-full min-w-0 flex-1 bg-transparent font-display text-[14px] text-[#33302c] outline-none placeholder:text-[#b8b2aa] disabled:cursor-not-allowed disabled:opacity-60"
        {...rest}
      />

      {isPassword && (
        <button
          type="button"
          onClick={() => setRevealed((prev) => !prev)}
          tabIndex={-1}
          aria-label={revealed ? 'Hide password' : 'Show password'}
          className="shrink-0 text-[#b8b2aa] transition-colors duration-150 hover:text-brand-600"
        >
          {revealed ? <IconEyeOff size={18} stroke={1.8} /> : <IconEye size={18} stroke={1.8} />}
        </button>
      )}
    </div>
  );
});

export default Input;
