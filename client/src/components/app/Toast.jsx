import React from "react";
import { notifications } from "@mantine/notifications";
import { motion } from "framer-motion";
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconCircleCheck,
  IconInfoCircle,
} from "@tabler/icons-react";


import "@mantine/notifications/styles.css";

const TOAST_FONT = "'Fredoka', ui-rounded, system-ui, sans-serif";

const VARIANTS = {
  success: {
    Icon: IconCircleCheck,
    accent: "#2f9e44",
    surface: "#f2fbf5",
    border: "#c8ebd4",
    shadow: "rgba(47, 158, 68, 0.18)",
  },
  error: {
    Icon: IconAlertCircle,
    accent: "#e5322d",
    surface: "#fff1f1",
    border: "#ffd7d5",
    shadow: "rgba(229, 50, 45, 0.18)",
  },
  warning: {
    Icon: IconAlertTriangle,
    accent: "#b8820a",
    surface: "#fff9e8",
    border: "#ffe6a8",
    shadow: "rgba(184, 130, 10, 0.2)",
  },
  info: {
    Icon: IconInfoCircle,
    accent: "#f27d1d",
    surface: "#fff6ec",
    border: "#ffd9b8",
    shadow: "rgba(242, 125, 29, 0.2)",
  },
};

const DEFAULT_AUTO_CLOSE = 4000;
const MotionSpan = motion.span;

function renderIcon(variant) {
  const Glyph = variant.Icon;

  return (
    <MotionSpan
      initial={{ scale: 0.4, opacity: 0 }}
      animate={{ scale: 1, opacity: 1 }}
      transition={{ type: "spring", stiffness: 460, damping: 20, delay: 0.04 }}
      style={{ display: "flex", color: variant.accent }}
    >
      <Glyph size={21} stroke={2.2} />
    </MotionSpan>
  );
}

function renderBody(message, variant, duration) {
  const showProgress = typeof duration === "number" && duration > 0;

  return (
    <>
      <span>{message}</span>

      {showProgress && (
        <MotionSpan
          aria-hidden="true"
          initial={{ scaleX: 1 }}
          animate={{ scaleX: 0 }}
          transition={{ duration: duration / 1000, ease: "linear" }}
          style={{
            position: "absolute",
            insetInline: 0,
            bottom: 0,
            height: 3,
            transformOrigin: "left center",
            backgroundColor: variant.accent,
            opacity: 0.25,
          }}
        />
      )}
    </>
  );
}

function buildStyles(variant) {
  return {
    root: {
      "--notification-color": "transparent",
      "--notification-radius": "14px",

      alignItems: "center",
      backgroundColor: variant.surface,
      border: `1px solid ${variant.border}`,
      boxShadow: `0 12px 32px -10px ${variant.shadow}, 0 2px 6px -2px ${variant.shadow}`,
      fontFamily: TOAST_FONT,
      maxWidth: "100%",
      padding: "14px 16px",
      paddingInlineStart: 18,
    },
    icon: {
      backgroundColor: "transparent",
      borderRadius: 0,
      color: variant.accent,
      height: 22,
      marginInlineEnd: 12,
      width: 22,
    },
    body: {
      paddingInlineEnd: 4,
    },
    title: {
      color: variant.accent,
      fontFamily: TOAST_FONT,
      fontSize: 14.5,
      fontWeight: 600,
      letterSpacing: "-0.1px",
      lineHeight: 1.35,
      marginBottom: 2,
    },
    description: {
      color: variant.accent,
      fontFamily: TOAST_FONT,
      fontSize: 14,
      fontWeight: 400,
      lineHeight: 1.45,
    },
    closeButton: {
      color: variant.accent,
      height: 24,
      minHeight: 24,
      minWidth: 24,
      opacity: 0.4,
      width: 24,
    },
  };
}

function show(variantName, message, titleOrOptions, maybeOptions) {
  const variant = VARIANTS[variantName];

  const isOptionsObject =
    titleOrOptions !== null &&
    typeof titleOrOptions === "object" &&
    !React.isValidElement(titleOrOptions);

  const { title, ...rest } = isOptionsObject
    ? titleOrOptions
    : { title: titleOrOptions, ...(maybeOptions || {}) };

  const autoClose = rest.autoClose === undefined ? DEFAULT_AUTO_CLOSE : rest.autoClose;

  return notifications.show({
    withBorder: false,
    ...rest,
    autoClose,
    title,
    message: renderBody(message, variant, autoClose),
    icon: renderIcon(variant),
    styles: buildStyles(variant),
  });
}

export const toast = {
  success(message, title = "Success", options) {
    return show("success", message, title, options);
  },
  error(message, title = "Something went wrong", options) {
    return show("error", message, title, options);
  },
  warning(message, title = "Heads up", options) {
    return show("warning", message, title, options);
  },
  info(message, title, options) {
    return show("info", message, title, options);
  },
};

export default toast;
