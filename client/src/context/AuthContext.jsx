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

    /**
     * Start a session from a {user, token} payload.
     *
     * Login is not the only endpoint that issues one - OTP verification returns
     * the same shape so a freshly verified account is signed in without a second
     * round trip to /login.
     */
    const adoptSession = (data) => {
        setToken(data.token);
        setUser(data.user);

        localStorage.setItem('token', JSON.stringify(data.token));
        localStorage.setItem('user', JSON.stringify(data.user));

        return data;
    };

    const loginUser = async (e) => {
        e.preventDefault();
        const url = import.meta.env.VITE_API_URL;

        try {
        const response = await fetch(url + '/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
            login: e.target.login.value,
            password: e.target.password.value,
            }),
            credentials: 'include',
        });

        const data = await response.json();

        if (!response.ok) {
            // throw error so Login component can catch it. The status and body ride
            // along so the caller can tell an unverified account (403) apart from
            // bad credentials (401) and route to the OTP screen.
            const error = new Error(data.message || 'Login failed. Check credentials.');
            error.status = response.status;
            error.payload = data;
            throw error;
        }

        if(data.token != undefined){
            adoptSession(data);

            return data;
        }else{
            throw new Error(data.message || 'Login failed. Check credentials.');
        }

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
        adoptSession:adoptSession,
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