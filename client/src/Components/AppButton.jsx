import React from "react";

const useCaseVariants = {
  signin: "bg-orange-400 text-white hover:bg-amber-800",
  signup: "bg-orange-400 text-white hover:bg-amber-800",
  order: "bg-orange-400 text-white hover:bg-orange-500 p-10",
  checkout: "bg-yellow-500 text-white hover:bg-yellow-600",
  remove: "bg-red-500 text-white hover:bg-red-600",
  confirm: "bg-orange-500 text-white hover:bg-orange-600",
  link: "bg-yellow-400 text-white hover:bg-yellow-500",
  verify: "bg-yellow-400 text-white hover:bg-yellow-500",
  menu: "bg-orange-400 text-white hover:bg-orange-600",
  outline: "bg-orange-400 text-white hover:bg-amber-800",

  tabActive: "bg-orange-500 text-white",
  tabInactive: "bg-gray-100 text-orange-800",

  foodTabActive: "bg-amber-300 text-white",
  foodTabInactive: "bg-gray-100 text-slate-900",
};

const sizes = {
  sm: "px-3 py-1.5",
  md: "px-5 py-2",
  lg: "px-6 py-3",
  xlg: "px-10 py-10",
};

const roundness = {
  none: "rounded-none",
  sm: "rounded-sm",
  md: "rounded-md",
  lg: "rounded-lg",
  xl: "rounded-xl",
  full: "rounded-full",
};

const textSizes = {
  lg: "text-lg",
  md: "text-base",
  sm: "text-sm",
};

export default function AppButton({
  children,
  useCase = "order",
  size = "md",
  icon,
  iconPosition = "right",
  fullWidth = false,
  className = "",
  roundedType = "full",
  bgColor,
  hoverColor,
  textSize = "md",
  bold = true,
  iconOnly = false, // New prop
  ...props
}) {
  const customColors =
    bgColor || hoverColor
      ? `${bgColor || ""} ${hoverColor || ""} text-white`
      : useCaseVariants[useCase] || useCaseVariants.order;

  const baseClasses = `
    flex items-center justify-center gap-2 transition-all duration-200
    ${customColors}
    ${sizes[size] || sizes.md}
    ${fullWidth ? "w-full" : ""}
    ${roundness[roundedType] || roundness.full}
    ${className}
    ${iconOnly ? "p-2 w-auto h-auto" : ""}
  `;

  const textClasses = `
    ${textSizes[textSize] || textSizes.md}
    ${bold ? "font-bold" : "font-normal"}
  `;

  return (
    <button className={baseClasses} {...props}>
      {icon && iconPosition === "left" && React.createElement(icon, { size: iconOnly ? 24 : 18 })}
      {!iconOnly && <span className={textClasses}>{children}</span>}
      {icon && iconPosition === "right" && React.createElement(icon, { size: iconOnly ? 24 : 18 })}
    </button>
  );
}
