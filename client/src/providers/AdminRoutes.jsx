import { useContext } from "react";
import { Navigate, Outlet } from "react-router-dom";
import { Center, Loader } from "@mantine/core";
import AuthContext from "../context/AuthContext";
import { ROLES } from "../lib/roles";

const STAFF_ROLES = [ROLES.ADMIN, ROLES.SUPER_ADMIN];

/**
 * Staff-area gate.
 *
 * This used to run its own /api/user fetch, because the role in localStorage
 * could not be trusted and something had to ask the server. AuthContext now
 * does that for every signed-in page, so this is just the role check against
 * the answer it already has - one request instead of two, and no second copy
 * of the session-expiry handling to keep in sync.
 *
 * Still only a rendering decision. The staff endpoints are enforced by the
 * role middleware regardless of what this component does.
 */
function AdminRoutes() {
  const { user, token, isBootstrapping } = useContext(AuthContext);

  if (token && isBootstrapping) {
    return (
      <Center style={{ height: "100vh" }}>
        <Loader color="orange" size="lg" />
      </Center>
    );
  }

  return STAFF_ROLES.includes(user?.role) ? <Outlet /> : <Navigate to="/unauthorized" replace />;
}

export default AdminRoutes;
