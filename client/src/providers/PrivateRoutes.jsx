import { useContext } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import AuthContext from "../context/AuthContext";

/**
 * Single auth + role gate for the whole app.
 *
 * Consolidates the previous PrivateRoutes and the proposed RoleGuard. All
 * role decisions come from the allowedRoles prop - there is no route-specific
 * role logic anywhere else.
 *
 *   as a layout route:  <Route element={<PrivateRoutes allowedRoles={[ROLES.ADMIN]} />}>
 *   as a wrapper:       <PrivateRoutes allowedRoles={[ROLES.ADMIN]}><Thing /></PrivateRoutes>
 *
 * Omitting allowedRoles gates on authentication only.
 *
 * This is a UX gate, not a security boundary: user.role is hydrated from
 * localStorage and is trivially spoofable. Every protected endpoint must still
 * enforce its own policy check server-side.
 */
function PrivateRoutes({
  allowedRoles,
  redirectTo = "/login",
  unauthorizedTo = "/unauthorized",
  children,
}) {
  const { user, token } = useContext(AuthContext);
  const location = useLocation();

  if (!token || !user) {
    return <Navigate to={redirectTo} state={{ from: location }} replace />;
  }

  if (allowedRoles?.length && !allowedRoles.includes(user.role)) {
    return <Navigate to={unauthorizedTo} replace />;
  }

  return children ?? <Outlet />;
}

export default PrivateRoutes;
