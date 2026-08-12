import { useId } from 'react';
import Label from './Label';

function Field({ label, required = false, error, hint, htmlFor, className = '', children }) {
  const generatedId = useId();
  const controlId = htmlFor ?? generatedId;

  return (
    <div className={`flex flex-col gap-2 ${className}`}>
      {label && (
        <Label htmlFor={controlId} required={required}>
          {label}
        </Label>
      )}

      {typeof children === 'function' ? children(controlId) : children}

      {error ? (
        <p className="font-display text-[12px] leading-snug text-[#e5322d]">{error}</p>
      ) : (
        hint && <p className="font-display text-[12px] leading-snug text-[#8d867e]">{hint}</p>
      )}
    </div>
  );
}

export default Field;
