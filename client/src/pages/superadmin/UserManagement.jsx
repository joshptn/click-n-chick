import React from "react";
import { Card, Stack, Text, Title } from "@mantine/core";

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
