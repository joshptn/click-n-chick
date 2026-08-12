import React, { createContext, useContext, useEffect, useState, useRef } from "react";
import AuthContext from "./AuthContext";
import { FoodContext } from "./FoodProvider";


export const AddOnContext = createContext();

export const AddOnProvider = ({ children }) => {
    const { foods} = useContext(FoodContext);
    const [sides, setSides] = useState([]);
    const [drinks, setDrinks] = useState([]);
    const { token } = useContext(AuthContext);
    const url = import.meta.env.VITE_API_URL;
   
   
      
    const fetchSides = async () => {
           try {
               
               const res = await fetch(`${url}/api/sides`, {
                   credentials: "include",
               });
   
               if (!res.ok) throw new Error("Failed to fetch sides");
               const data = await res.json();
               
               setSides(data || []);
               
           } catch (err) {
               console.error(err);
           } 
       };
   
      
    const fetchDrinks = async () => {
           try {
               
               const res = await fetch(`${url}/api/drinks`, {
                   credentials: "include",
               });
   
               if (!res.ok) throw new Error("Failed to fetch drinks");
               const data = await res.json();
              
               setDrinks(data || []);
               
           } catch (err) {
               console.error(err);
           } 
       };
   
      
       useEffect(() => {
           fetchDrinks();
           fetchSides();
       }, [foods]);
    
    const context ={   
        sides:sides,
        drinks:drinks
    }

    return (
        <AddOnContext.Provider value={context}>
            {children}
        </AddOnContext.Provider>
    );
};
