import React, { useState } from 'react'
import FoodFormModal from './FoodFormModal'
import AppButton from './AppButton';
import { Plus } from 'lucide-react';


function AddFood() {
    const [opened, setOpened] = useState(false);
  return (
     <div className="p-6 h-full flex items-end justify-end m-5">
      <AppButton
        icon={Plus}
        iconOnly={true}
        onClick={() => setOpened(true)}
        size="xlg"
        roundedType="full"
        useCase="order" 
        className="text-white shadow-lg"
        />

      <FoodFormModal
        opened={opened}
        onClose={() => setOpened(false)}
        onSuccess={() => console.log("Food saved!")}
      />
    </div>
  )
}

export default AddFood