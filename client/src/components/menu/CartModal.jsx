import React, { useState } from "react";
import { Modal, Button, Text } from "@mantine/core";
import Cart from "./Cart";

export default function CartModal() {
  const [opened, setOpened] = useState(false);

  return (
    <>
     
      <Button onClick={() => setOpened(true)} color="blue" radius="md">
        🛒 View Cart
      </Button>

    
      <Modal
        opened={opened}
        onClose={() => setOpened(false)}
        title={<Text fw={700}>Your Cart</Text>}
        size="lg"
        centered
        overlayProps={{
          backgroundOpacity: 0.55,
          blur: 3,
        }}
      >
       
        <Cart />
      </Modal>
    </>
  );
}
