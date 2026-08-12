import { forwardRef } from 'react';
import { Loader } from '@mantine/core';

const VARIANTS = {
  primary:
    'bg-brand-500 text-white shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] hover:bg-brand-600 active:translate-y-px',
  secondary:
    'bg-cocoa-700 text-white shadow-[0_6px_16px_-4px_rgba(65,33,17,0.35)] hover:bg-cocoa-900 active:translate-y-px',
  outline:
    'border border-brand-500 bg-transparent text-brand-600 hover:bg-brand-50 active:translate-y-px',
  ghost: 'bg-transparent text-brand-600 hover:bg-brand-50',
};

const SIZES = {
  sm: 'h-[38px] px-4 text-[13px] rounded-[9px]',
  md: 'h-[46px] px-5 text-[14px] rounded-[10px]',
  lg: 'h-[52px] px-7 text-[15px] rounded-[12px]',
};

const Button = forwardRef(function Button(
  {
    variant = 'primary',
    size = 'md',
    fullWidth = false,
    loading = false,
    loadingLabel,
    disabled = false,
    type = 'button',
    className = '',
    children,
    ...rest
  },
  ref
) {
  const isDisabled = disabled || loading;

  return (
    <button
      ref={ref}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      className={`inline-flex items-center justify-center gap-2 font-display font-semibold transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-55 disabled:shadow-none ${VARIANTS[variant]} ${SIZES[size]} ${fullWidth ? 'w-full' : ''} ${className}`}
      {...rest}
    >
      {loading && <Loader size={16} color="currentColor" />}
      {loading ? loadingLabel ?? children : children}
    </button>
  );
});

export default Button;
