import { createContext, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { RECAPTCHA_ACTIONS, withRecaptcha } from '../lib/recaptcha';
import { disconnectEcho } from '../lib/echo';
import { deviceHeader } from '../lib/deviceId';
import { clearStoredSession } from '../lib/session';
import { queryClient } from '../lib/queryClient';

const AuthContext = createContext();

/**
 * Forget everything the previous account fetched.
 *
 * The query cache outlives a sign-out, and keys like ["profile"] or
 * ["cart", "account", ...] name no account - so the next person to sign in on
 * this browser was shown the last one's profile, cart and orders until each
 * refetch landed. clear() also cancels what is still in flight, so a late
 * answer cannot write the old account's data back in. Done before the state
 * change that re-renders the app, so no screen ever draws from it.
 */
function forgetPreviousAccount() {
    queryClient.clear();
}


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

    // The token a sign-in, sign-up or challenge has just handed over together
    // with its user. That response is as authoritative as /api/user, so the
    // boot check below is skipped for it instead of asking again.
    const adoptedToken = useRef(null);

    // True from a sign-out in this tab until the next sign-in. The route guard
    // reads it so the page signed out of is not remembered as the place to
    // return to: the next person to sign in on this browser would otherwise be
    // taken to the previous account's page. Set in the same update as the
    // token, so the guard never renders one without the other.
    const [signedOut, setSignedOut] = useState(false);

    // Who is signed in right now, for adoptSession, which is stable and so
    // cannot read the state directly.
    const current = useRef({ token, userId: null });
    current.current = { token, userId: user?.id ?? null };

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

        if (adoptedToken.current === token) {
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

    const adoptSession = useCallback((data) => {
        // A different account signing in over one that never signed out (a
        // reset link or a code opened in a signed-in tab). A guest signing in
        // has nothing to forget, and keeps the menu it already loaded.
        const previous = current.current;

        if (previous.token && previous.token !== data.token && previous.userId !== data.user?.id) {
            forgetPreviousAccount();
        }

        adoptedToken.current = data.token;
        setToken(data.token);
        setUser(data.user);
        setSignedOut(false);

        localStorage.setItem('token', JSON.stringify(data.token));

        // Which known_device this session belongs to. Stored so a
        // session.revoked broadcast aimed at this device can be recognised -
        // the event goes to the whole account, and only the named device acts.
        if (data.device_id !== undefined && data.device_id !== null) {
            localStorage.setItem('device_session_id', JSON.stringify(data.device_id));
        }

        return data;
    }, []);

    const loginUser = useCallback(async (e) => {
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
    }, [adoptSession]);

    const logoutUser = useCallback(async ({ revokeOnServer = true } = {}) => {
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

        // Storage first, so a request still in flight sees the session gone
        // (api.js discards its answer), then the cache, then the re-render.
        clearStoredSession();
        forgetPreviousAccount();

        setUser(null);
        setToken(null);
        setSignedOut(true);
    }, [token, url]);

    const context = useMemo(() => ({
        loginUser,
        adoptSession,
        logOut: logoutUser,
        user,
        token,
        setUser,
        isBootstrapping,
        signedOut,
    }), [loginUser, adoptSession, logoutUser, user, token, isBootstrapping, signedOut]);
    return (
        <AuthContext.Provider value={context}>
            {children}
        </AuthContext.Provider>
    )
    }