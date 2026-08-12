import { showNotification } from "@mantine/notifications";

export const toast = {
  success(message, title = "Success") {
    showNotification({ title, message, color: "green" });
  },
  error(message, title = "Something went wrong") {
    showNotification({ title, message, color: "red" });
  },
  info(message, title) {
    showNotification({ title, message, color: "blue" });
  },
};

export default toast;
