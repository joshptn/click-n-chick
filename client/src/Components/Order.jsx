import React, { useState } from "react";
import { Button } from "@mantine/core";
import OrdersModal from "./OrdersModal";
import { ShoppingBag   } from "lucide-react";
import AppButton from "./AppButton";

function Order() {
    const [ordersOpen, setOrdersOpen] = useState(false);
    
  return (
    <div className="">
      
      <AppButton
            useCase="checkout"
            size="lg"
            roundedType="full"
            onClick={() => setOrdersOpen(true)}
            iconOnly
            icon={ShoppingBag}
        />

      

      <OrdersModal
        opened={ordersOpen}
        onClose={() => setOrdersOpen(false)}
        
      />
    </div>
  )
}

export default Order