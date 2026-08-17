import { createContext, useEffect, useState } from 'react'
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

    // Deliberately NOT read from localStorage. The user object carries real
    // PII - email, phone number, legal name - and storing it put all of that
    // at rest in the browser for no benefit beyond skipping one request. It
    // also made the role client-editable, which let anyone render the admin
    // shell by hand. It is now fetched from /api/user, which is the only
    // authority on who this token belongs to.
    const [user, setUser] = useState(null);

    // True while the boot-time /api/user call is in flight. Route guards must
    // wait for it: without this a signed-in user is bounced to /login on every
    // refresh, because `user` is briefly null before the fetch resolves.
    const [isBootstrapping, setIsBootstrapping] = useState(
        Boolean(JSON.parse(localStorage.getItem('token')) || null)
    );

    const url = import.meta.env.VITE_API_URL

    // Purge the key written by older builds so existing installs stop carrying
    // that PII around after this deploy.
    useEffect(() => {
        localStorage.removeItem('user');
    }, []);

    useEffect(() => {
        if (!token) {
            setUser(null);
            setIsBootstrapping(false);
            return undefined;
        }

        // Guards against a late response from a token that has since changed.
        let cancelled = false;

        (async () => {
            try {
                const response = await fetch(url + '/api/user', {
                    headers: {
                        Accept: 'application/json',
                        Authorization: `Bearer ${token}`,
                        ...deviceHeader(),
                    },
                });

                if (!response.ok) throw new Error('This session is no longer valid.');

                const data = await response.json();

                if (!cancelled) setUser(data);
            } catch {
                // Expired, revoked, or signed out from another device. Drop it
                // rather than leaving a dead token in storage.
                if (!cancelled) {
                    setUser(null);
                    setToken(null);
                    clearStoredSession();
                }
            } finally {
                if (!cancelled) setIsBootstrapping(false);
            }
        })();

        return () => { cancelled = true; };
    }, [token, url]);

    const adoptSession = (data) => {
        setToken(data.token);
        setUser(data.user);

        localStorage.setItem('token', JSON.stringify(data.token));

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

        if (data.token != undefined) {
            adoptSession(data);

            return data;
        }

        throw new Error(data.message || 'Login failed. Check credentials.');
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
        setUser:setUser,
        isBootstrapping:isBootstrapping
    }
    return (
        <AuthContext.Provider value={context}>
            {children}
        </AuthContext.Provider>
    )
    }