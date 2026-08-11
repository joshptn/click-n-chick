import React, { useContext, useEffect } from "react";
import Header from "../Components/Header";
import { ArrowRight } from "lucide-react";
import AppButton from "../Components/AppButton";
import heroProduct from "../assets/hero_product.png";

import roastedChicken from "../assets/roasted_chicken.png";


import deal1 from "../assets/deal1.png"; // Sulit Deal
import deal2 from "../assets/deal2.png"; // 7th Anniv Sale
import deal3 from "../assets/deal3.png"; // Dine & Bite

import appSite from "../assets/app_site.png";
import locationSection from "../assets/location_section.svg";
import hocLogo from "../assets/hoc_logo.png";
import fries from "../assets/fries.png";
import { useNavigate } from "react-router-dom";
import AuthContext from "../Contexts/AuthContext";


function LandingPage() {

  const nav = useNavigate()
  const { token, user } = useContext(AuthContext);

  // useEffect(()=>{
  //   if(token && user){
  //     user.role == "user" ? nav('/home') : nav('admin')
  //   }
  // },[])

  return (
    <>
      <Header />

      
      <section className=" w-full bg-[#FFF9F2] overflow-hidden">
        <div className="flex flex-col items-center justify-end w-full gap-5 px-2 py-16 mx-auto md:px-12 lg:px-20 md:flex-row">
          {/* LEFT */}
          <div className="flex-1 text-center md:text-left">
            <h1 className="text-[100px] font-medium leading-tight text-amber-700 hoc_font">
              BASTA <span className="text-amber-400">BES</span> DA <br />
              BEST!
            </h1>
            <p className="max-w-md mx-auto mt-6 text-gray-700 md:mx-0">
              Bes House of Chicken in Apalit, Pampanga serves flavorful, juicy
              chicken at budget-friendly prices. Perfect for casual meals with
              friends or family — where chicken is always the star!
            </p>

            <div className="mt-8">
              <AppButton
                useCase="menu"
                size="lg"
                icon={ArrowRight}
                iconPosition="right"
                textSize={"lg"}
                bold={true}
                onClick={()=> nav('/home')}
              >
                VIEW MENU
              </AppButton>
            </div>
          </div>

          {/* RIGHT */}
          <div className="flex justify-center mt-10 abosolute">
           
            <img
              src={heroProduct}
              alt="Bes House of Chicken"
              className=" z-10 w-[600px] h-[700px] object-center drop-shadow-xl"
            />
          </div>
        </div>
      </section>

      {/* ABOUT US */}
      <section
        id="about"
        className="relative w-full bg-white py-16 px-6 md:px-12 lg:px-20 my-[50px]"
      >
        <div className="flex flex-col items-center max-w-6xl gap-8 mx-auto md:flex-row md:items-stretch">
          {/* LEFT IMAGE */}
          <div className="relative flex justify-center flex-1">
            <img
              src={roastedChicken}
              alt="Roasted Chicken"
              className="rounded-3xl shadow-md w-[700px] h-[400px] object-cover"
            />
          </div>

          {/* RIGHT CARD */}
          <div className="flex-1 relative -ml-[10%] -mt-[-5%]">
            <div className="bg-[#FFF9F2] rounded-r-3xl rounded-tl-3xl shadow-md p-8 md:p-10 w-[320px] h-[450px]">
              <h2 className="mb-4 text-4xl font-medium text-orange-600 uppercase hoc_font">
                About Us
              </h2>
              <p className="text-sm leading-relaxed text-gray-700 md:text-base">
                BES House of Chicken is a 24-hour Filipino fast food chain
                renowned for its signature fried chicken cooked with healthy
                herbs and spices. Established in 2018, it was born from a
                grandfather’s unconditional love for his grandchildren, whose
                all-time favorite is fried chicken—a love that continues to
                inspire the heart and soul of the brand.
              </p>
            </div>
          </div>
        </div>
      </section>

      <section className="flex flex-row items-center justify-center w-full">
        
          <img
                src={appSite}
                alt="Visit the app"
                className="rounded-2xl object-cover p-5 w-[60%] h-[650px]"
              />
        
      </section>

      {/* DEALS SECTION */}
      <section id="deals" className="relative w-full bg-white py-16 px-6 md:px-12 lg:px-20 my-[100px]">
        <div className="mx-auto max-w-7xl">
          {/* Title Row */}
          <div className="flex flex-col items-start justify-between mb-10 md:flex-row">
            <h2 className="max-w-lg text-4xl font-medium leading-snug text-orange-900 uppercase hoc_font">
              Bite Into Our Best <br /> Deals!
            </h2>
            <p className="max-w-xl mt-4 text-gray-700 md:mt-0">
              Enjoy our newest chicken combos and sulit meals made for every
              craving — delicious, affordable, and perfect for sharing!
            </p>
          </div>

          
          <div className="flex flex-row gap-5">
           
            <div className="col-span-1 md:col-span-2">
              <img
                src={deal1}
                alt="Sulit Deal"
                className="object-cover w-full shadow-md rounded-2xl"
              />
            </div>

            
            <div className="flex flex-col gap-6">
              <img
                src={deal2}
                alt="7th Anniv Sale"
                className="object-cover w-full shadow-md rounded-2xl"
              />
              <img
                src={deal3}
                alt="Dine & Bite"
                className="object-cover w-full shadow-md rounded-2xl"
              />
            </div>

          </div>
          
        </div>
      </section>

       <section id="find-us" className="flex flex-row items-center justify-center w-full mt-[250px]">
        
          <img
                src={locationSection}
                className=" object-fit h-[850px]"
              />
        
      </section>

       {/* TESTIMONIAL SECTION */}
      <section
        id="testimonial"
        className="relative w-full bg-white py-20 px-6 md:px-12 lg:px-20 mb-[250px]"
      >
        <div className="max-w-4xl mx-auto text-center">
          {/* Title */}
          <h2 className="text-6xl font-semibold tracking-wider text-orange-500 uppercase hoc_font">
            Testimonial
          </h2>
          <p className="mt-2 italic font-medium text-orange-600">
            - Ms. Sadsad and Family -
          </p>

          {/* Quote */}
          <p className="mt-8 text-lg leading-relaxed text-gray-800">
            “The BES tasting chicken at an affordable price! Its staffs are
            polite and accommodating. Not to mention also their gravy that's
            surely a blockbuster hit! It already became a tradition for us to
            celebrate our birthdays at BES House of Chicken. A really must try
            for everyone.”
          </p>

          {/* Orange speech bubble line */}
           <div className="flex justify-center mt-10">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 400 60"
                className="w-64 h-10"
                aria-hidden="true"
                role="img"
              >
                {/* Path: horizontal line with a downward spike (tail) */}
                <path
                  d="M10 30 H160 L200 52 L220 30 H390"
                  stroke="#F97316"
                  strokeWidth="6"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  fill="none"
                />
              </svg>
            </div>
        </div>
      </section>

      {/* FOOTER SECTION */}
      <section id="contact" className="w-full mt-20">
        {/* Top banner with fries and contact email */}
        <div className="relative bg-orange-400 flex flex-row md:flex-row items-center justify-between px-6  py-5 my-[50px]">
          {/* Fries image (left) */}
          <img
            src={fries}
            alt="French Fries"
            className="w-[400px] h-[400px] absolute"
          />

          {/* Text (right) */}
          <div className="w-full m-10 text-right">
            <h3 className="text-xl font-semibold leading-snug text-white md:text-2xl">
              We’d love to hear from you! Send your inquiries, feedback,
              <br className="hidden md:block" /> or suggestions to{" "}
              <span className="font-bold text-lime-200">
                beshochelpdesk@gmail.com
              </span>
            </h3>
          </div>
        </div>

        {/* Bottom info section */}
        <div className="bg-[#FFF9F2] px-6 md:px-16 py-14">
          <div className="grid grid-cols-1 gap-12 mx-auto max-w-7xl md:grid-cols-3">
            
            {/* Left: Logo and description */}
            <div className="flex flex-col items-start">
              <img
                src={hocLogo} // replace with your chicken logo asset
                alt="Click n' Chick"
                className="object-fit w-[500px] h-[200px]"
              />
              <p className="mb-6 text-sm leading-relaxed text-gray-700">
                Craving the comforts of home? Worry no more — we’re passionate
                about bringing that warm, home-cooked goodness straight to your
                table. Experience the taste of home, only here at BES House of
                Chicken.
              </p>

              {/* Social Icons */}
              <div className="flex space-x-4">
                <a href="https://facebook.com" target="_blank" rel="noreferrer">
                  <i className="text-2xl text-gray-700 fab fa-facebook hover:text-orange-500"></i>
                </a>
                <a href="https://instagram.com" target="_blank" rel="noreferrer">
                  <i className="text-2xl text-gray-700 fab fa-instagram hover:text-orange-500"></i>
                </a>
              </div>
            </div>

            {/* Middle: Branch info */}
            <div>
              <h4 className="mb-4 text-lg font-semibold">Apalit Branch</h4>
              <ul className="space-y-2 text-sm text-gray-700">
                <li>Sampaloc, Apalit, Pampanga</li>
                <li>+63 966 381 6965</li>
                <li>+63 931 010 1409</li>
                <li>beshoc.apalit@gmail.com</li>
                <li>Sunday to Friday, 6:00 am to 5:00 pm</li>
                <li>Saturday 6:00 am to 3:00 pm</li>
              </ul>
            </div>

            {/* Right: Teams */}
            <div>
              <h4 className="mb-4 text-lg font-semibold">Teams</h4>
              <ul className="space-y-2 text-sm text-gray-700">
                <li>Bacani, Aaron Job</li>
                <li>Constano, Numeriano Lubrino</li>
                <li>Dungca, Allain Francois D.</li>
                <li>Santos, Ceejay S.</li>
                <li>Tulalian, Aaron Matthew</li>
              </ul>
            </div>
          </div>
        </div>
      </section>


    </>
  );
}

export default LandingPage;
