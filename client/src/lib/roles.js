export const ROLES = {
  SUPER_ADMIN: "super_admin",
  ADMIN: "admin",
  CUSTOMER: "customer",
};

export const canOperate = (user) => user?.role === ROLES.ADMIN;

export default ROLES;
