import { Card, Stack, Text, Title } from "@mantine/core";

function GuestHome() {
  return (
    <Card shadow="sm" radius="md" p="xl" withBorder>
      <Stack gap="xs">
        <Title order={2}>Welcome to Click n Chick</Title>
        <Text c="dimmed">
          Placeholder. Pending the Customer menu, cart and checkout modules.
        </Text>
      </Stack>
    </Card>
  );
}

export default GuestHome;