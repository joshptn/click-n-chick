import { Card, Stack, Text, Title } from "@mantine/core";

function AdminDashboard() {
  return (
    <Card shadow="sm" radius="md" p="xl" withBorder>
      <Stack gap="xs">
        <Title order={2}>Admin Dashboard</Title>
        <Text c="dimmed">
          Placeholder. Pending the order and menu management modules.
        </Text>
      </Stack>
    </Card>
  );
}

export default AdminDashboard;
