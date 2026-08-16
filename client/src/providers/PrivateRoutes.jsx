import { useContext } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import AuthContext from "../context/AuthContext";

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
