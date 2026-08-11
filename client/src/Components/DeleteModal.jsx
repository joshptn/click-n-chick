import React from "react";
import { Modal, Button, Text, Stack, Center } from "@mantine/core";
import { AlertTriangle } from "lucide-react";

function DeleteModal({ opened, onClose, onConfirm, itemName="" }) {
  return (
    <Modal
      opened={opened}
      onClose={onClose}
      withCloseButton={false}
      centered
      radius="lg"
      size="sm"
      overlayProps={{ opacity: 0.5, blur: 2 }}
    >
      <Stack align="center" spacing="md" py="lg">
        <Center
          style={{
            backgroundColor: "#FFF5F5",
            borderRadius: "50%",
            width: 80,
            height: 80,
          }}
        >
          <AlertTriangle size={40} color="#E03131" />
        </Center>

        <Text fw={700} fz="xl">
          Are you sure?
        </Text>

        <Text c="dimmed" ta="center" fz="sm" mx="lg">
          Do you really want to delete this <Text  ta="center" fz="md" mx="lg">{itemName}?</Text> This process cannot be
          undone.
        </Text>

        <div className="flex gap-3 mt-3">
          <Button
            variant="filled"
            color="gray"
            style={{
              backgroundColor: "#E9ECEF",
              color: "#212529",
              flex: 1,
              fontWeight: 600,
            }}
            onClick={onClose}
          >
            CANCEL
          </Button>

          <Button
            color="red"
            style={{ flex: 1, fontWeight: 600 }}
            onClick={onConfirm}
          >
            DELETE
          </Button>
        </div>
      </Stack>
    </Modal>
  );
}

export default DeleteModal;
