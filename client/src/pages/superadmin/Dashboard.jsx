import React from "react";
import { Card, Stack, Text, Title } from "@mantine/core";

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
