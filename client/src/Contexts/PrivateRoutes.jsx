import { useContext } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import AuthContext from "../Contexts/AuthContext";

function PrivateRoutes({ allowedRoles }) {
  const { user } = useContext(AuthContext);
  const location = useLocation();

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }else{
     return allowedRoles.includes(user.role) ? (
    <Outlet />
    ) : (
      <Navigate to="/unauthorized" replace />
    );
  }

 
}

export default PrivateRoutes;
