import React from "react";
import { Card, Stack, Text, Title } from "@mantine/core";

/**
 * Super admin user management.
 *
 * Placeholder created during the folder restructure. There is no user
 * management API yet - the backend exposes no admin user endpoints - so this
 * page is deliberately NOT routed in App.jsx. Wire it up alongside the
 * admin user management work.
 */
function UserManagement() {
  return (
    <Card shadow="sm" radius="md" p="xl" withBorder>
      <Stack gap="xs">
        <Title order={2}>User Management</Title>
        <Text c="dimmed">
          Placeholder. Pending admin user management endpoints.
        </Text>
      </Stack>
    </Card>
  );
}

export default UserManagement;
