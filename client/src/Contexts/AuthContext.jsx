import React, { createContext, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom';

const AuthContext = createContext();


export default AuthContext


export function AuthProvider({children}) {
    const [token, setToken] = useState(
        JSON.parse(localStorage.getItem('token')) || null
    );

    const [user, setUser] = useState(
        JSON.parse(localStorage.getItem('user')) || null
    );

    const nav = useNavigate()

    const url = import.meta.env.VITE_API_URL

    const loginUser = async (e) => {
        e.preventDefault();
        const url = import.meta.env.VITE_API_URL;

        try {
        const response = await fetch(url + '/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
            email: e.target.email.value,
            password: e.target.password.value,
            }),
            credentials: 'include',
        });

        const data = await response.json();

        if (!response.ok) {
            // throw error so Login component can catch it
            throw new Error(data.message || 'Login failed. Check credentials.');
        }
        
        if(data.token != undefined){
            setToken(data.token);
            setUser(data.user);

            localStorage.setItem('token', JSON.stringify(data.token));
            localStorage.setItem('user', JSON.stringify(data.user));

            return data;
        }else{
            throw new Error(data.message || 'Login failed. Check credentials.');
        }

         // return for optional chaining in Login component
        } catch (err) {
        throw err; // propagate to Login component
        }
    };
    
    const logoutUser = () => {
        setUser(null);
        setToken(null);
        localStorage.removeItem('token');
        localStorage.removeItem('user');
    }

    var context = {
        loginUser:loginUser,
        logOut:logoutUser,
        user:user,
        token:token,
        setUser:setUser
    }
    return (
        <AuthContext.Provider value={context}>
            {children}
        </AuthContext.Provider>
    )
    }