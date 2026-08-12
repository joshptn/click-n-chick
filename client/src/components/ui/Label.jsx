function Label({ htmlFor, required = false, className = '', children, ...rest }) {
  return (
    <label htmlFor={htmlFor} className={`block font-display text-[14px] font-medium leading-none text-[#3a3a3a] ${className}`} {...rest}>
      {children}
      {required && <span className="ml-1 text-[#e5322d]" aria-hidden="true">*</span>}
    </label>
  );
}

export default Label;
