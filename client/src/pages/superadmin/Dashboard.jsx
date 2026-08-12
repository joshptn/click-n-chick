import React from "react";
import { Card, Stack, Text, Title } from "@mantine/core";

/**
 * Super admin dashboard.
 *
 * Placeholder created during the folder restructure. The "super admin" role
 * does not exist in the backend yet - users.role currently only holds
 * 'admin' or 'user' - so this page is deliberately NOT routed in App.jsx.
 * Wire it up once the third role lands.
 */
function SuperAdminDashboard() {
  return (
    <Card shadow="sm" radius="md" p="xl" withBorder>
      <Stack gap="xs">
        <Title order={2}>Super Admin Dashboard</Title>
        <Text c="dimmed">
          Placeholder. Pending the super admin role in the backend.
        </Text>
      </Stack>
    </Card>
  );
}

export default SuperAdminDashboard;
