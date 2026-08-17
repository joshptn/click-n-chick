import React, { createContext, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom';
import { RECAPTCHA_ACTIONS, withRecaptcha } from '../lib/recaptcha';
import { disconnectEcho } from '../lib/echo';
import { deviceHeader } from '../lib/deviceId';
import { clearStoredSession } from '../lib/session';

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

    const adoptSession = (data) => {
        setToken(data.token);
        setUser(data.user);

        localStorage.setItem('token', JSON.stringify(data.token));
        localStorage.setItem('user', JSON.stringify(data.user));

        // Which known_device this session belongs to. Stored so a
        // session.revoked broadcast aimed at this device can be recognised -
        // the event goes to the whole account, and only the named device acts.
        if (data.device_id !== undefined && data.device_id !== null) {
            localStorage.setItem('device_session_id', JSON.stringify(data.device_id));
        }

        return data;
    };

    const loginUser = async (e) => {
        e.preventDefault();
        const url = import.meta.env.VITE_API_URL;

        try {
        const body = await withRecaptcha({
            login: e.target.login.value,
            password: e.target.password.value,
        }, RECAPTCHA_ACTIONS.LOGIN);

        const response = await fetch(url + '/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...deviceHeader() },
            body: JSON.stringify(body),
            credentials: 'include',
        });

        const data = await response.json();

        if (!response.ok) {
            const error = new Error(data.message || 'Login failed. Check credentials.');
            error.status = response.status;
            error.payload = data;
            throw error;
        }

        if (data.two_factor_required) {
            return data;
        }

        if(data.token != undefined){
            adoptSession(data);

            return data;
        }else{
            throw new Error(data.message || 'Login failed. Check credentials.');
        }

        } catch (err) {
        throw err; 
        }
    };
    
    const logoutUser = async ({ revokeOnServer = true } = {}) => {
        // Revoke the token server-side rather than only forgetting it locally.
        // Without this the session stays valid forever and shows up on the
        // devices screen as a live session for a device that has signed out.
        //
        // Best-effort and awaited before clearing: if the network is down the
        // user must still get out of the app locally. POST /api/logout ends
        // THIS session only - other devices are unaffected.
        if (revokeOnServer && token) {
            try {
                await fetch(url + '/api/logout', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        Authorization: `Bearer ${token}`,
                        ...deviceHeader(),
                    },
                });
            } catch {
                // Offline or server unreachable - fall through and clear locally.
            }
        }

        // Before clearing the token: the socket authorized with it and would
        // otherwise keep the previous user's subscriptions alive.
        disconnectEcho();

        setUser(null);
        setToken(null);
        clearStoredSession();
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