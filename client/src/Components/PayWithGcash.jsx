import React from 'react'


const url = import.meta.env.VITE_API_URL;

 async function PayWithGcash(data) {
   try{
      window.location.href = data.checkout_url; 
    } catch (err) {
      console.error("Checkout error:", err);
      
    }
}
  


export default PayWithGcash