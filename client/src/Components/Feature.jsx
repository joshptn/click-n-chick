import React, { useState } from 'react'

import deal1 from "../assets/deal1.png";


import AppButton from "../Components/AppButton";
import { Modal } from '@mantine/core';
import hoc from "../assets/hoc_image.svg";
import { ArrowLeft } from "lucide-react";

function Feature() {
  const [opened, setOpened] = useState(false)


  return (
    <>
    <section className="relative w-full p-5 overflow-hidden bg-white">
          
          <div className="relative z-10 mt-8 md:mt-0">
            <img
              src={deal1}
              alt="Chicken Bucket"
              className="w-full h-[400px]"
            />
          </div>
        
      </section>

    <section className="px-4 py-16 mx-auto ">
      {/* Title */}
      <h2 className="mb-6 text-lg font-bold ">FEATURED</h2>

      <div className="flex flex-row items-center justify-center gap-6 w-full">
        {/* Left Card */}
        <div className="p-8 bg-white border border-gray-200 shadow-md rounded-2xl flex flex-row w-[70%]">
          
            <img
              src={hoc}
              alt="Restaurant"
              className="rounded-xl "
            />
          
          <div className='p-4 flex flex-col justify-between'>
            <div>
              <h3 className="mb-2 text-lg font-bold">
                BES House of Chicken in Apalit: Built on Love, Strengthened by
                Faith, Inspired by Hope
              </h3>
              <p className="mb-4 mt-2 text-sm text-gray-600">
                BES House of Chicken is more than just a place for crispy fried
                chicken—it’s a story of love from a grandfather, strengthened by
                faith, and inspired by hope, now serving as a beloved spot in
                Apalit.
              </p>
            </div>
            <AppButton useCase="order" size="md" onClick={()=>setOpened(true)} className='w-[200px]'>
              Learn More
            </AppButton>
          </div>
        </div>

        {/* Right Card */}
        <div className="bg-white shadow-md rounded-2xl border border-gray-200 p-6 w-[30%]">
          <img
            src={deal1}
            alt="Meal"
            className=" w-full h-45 mb-4 rounded-xl"
          />
          <h3 className="mb-2 text-lg font-bold">
            Why Everyone Loves the Famous C1 Meal at BES House of Chicken?
          </h3>
          <p className="text-sm text-gray-600">
            The C1 is BES’s signature combo: crispy fried chicken paired with
            rice and savory gravy. It’s affordable, filling, and the top choice
            for first-time visitors.
          </p>
        </div>
      </div>
    </section>
    <Modal
      opened={opened}
      onClose={() => setOpened(false)}
      size="100%"
      centered
      overlayProps={{
        opacity: 0.55,
        blur: 3,
      }}
    >
      <div className="flex flex-row w-full p-6 relative items-start gap-10">

        {/* LEFT SIDE – IMAGE + ORANGE BG */}
        <div className="w-1/2 relative flex justify-center items-center">

          {/* ORANGE BACKGROUND */}
          <div className="absolute left-10 top-5 w-[340px] h-[600px] rounded-2xl z-10">
            <img
            src={hoc}
            alt="BES House of Chicken"
            className="rounded-xl  h-[600px] object-cover shadow-lg"
          />
          </div>

          
          <div className="w-[340px] h-[680px] bg-orange-400 rounded-2xl -z-10"></div>
        </div>

        {/* RIGHT SIDE – TEXT */}
        <div className="w-1/2 pr-6">
          <h1 className="hoc_font text-amber-900 text-4xl font-bold leading-tight mb-6">
            BES House of Chicken in Apalit: Built on Love, Strengthened by Faith, Inspired by Hope
          </h1>

          <p className="tracking-wide text-xl font-light leading-relaxed">
            It all began with a grandfather’s simple love for his grandchildren. They loved fried chicken, 
            and from that joy came the idea of creating a place that not only serves food but also brings 
            back the warmth of family memories.
            <br /><br />
            In 2018, this idea officially turned into a business, giving birth to BES House of Chicken. 
            The name “BES” itself carries a warm and personal touch—just like the bond between a grandfather 
            and his grandchildren.
            <br /><br />
            Over time, BES House of Chicken grew with the help of social media, community events, and the 
            support of loyal customers. Slowly, it became more than just a restaurant—it became a part of 
            Apalit’s culture, especially within the MCGI community.
          </p>

          {/* BACK BUTTON */}
          <button
            onClick={() => setOpened(false)}
            className="absolute bottom-6 right-6 w-16 h-16 bg-yellow-400 rounded-full shadow-md 
                      flex items-center justify-center hover:bg-yellow-300 transition"
          >
            <ArrowLeft size={32} color="white" strokeWidth={3} />
          </button>
        </div>

      </div>
    </Modal>
      </>
  )
}

export default Feature