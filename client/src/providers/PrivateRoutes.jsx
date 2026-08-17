import { useContext } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { Center, Loader } from "@mantine/core";
import AuthContext from "../context/AuthContext";

/**
 * Client-side route gating.
 *
 * This is UX, not security: it decides what to render, never what is allowed.
 * Every protected route is enforced server-side by auth:sanctum and the role
 * middleware, and `user` now comes from /api/user rather than localStorage, so
 * the role here is the server's answer rather than an editable local string.
 */
function PrivateRoutes({
  allowedRoles,
  redirectTo = "/login",
  unauthorizedTo = "/unauthorized",
  children,
}) {
  const { user, token, isBootstrapping } = useContext(AuthContext);
  const location = useLocation();

  // A token exists but we have not yet heard back who it belongs to. Deciding
  // now would bounce a legitimately signed-in user to /login on every refresh.
  if (token && isBootstrapping) {
    return (
      <Center style={{ height: "100vh" }}>
        <Loader color="orange" size="lg" />
      </Center>
    );
  }

  if (!token || !user) {
    return <Navigate to={redirectTo} state={{ from: location }} replace />;
  }

  if (allowedRoles?.length && !allowedRoles.includes(user.role)) {
    return <Navigate to={unauthorizedTo} replace />;
  }

  return children ?? <Outlet />;
}

export default PrivateRoutes;
