import React from "react";
import { Card, Stack, Text, Title } from "@mantine/core";

function LandingPage() {
  return (
    <Card shadow="sm" radius="md" p="xl" withBorder>
      <Stack gap="xs">
        <Title order={2}>Landing Page</Title>
        <Text c="dimmed">
          Placeholder for landing page.
        </Text>
      </Stack>
    </Card>
  );
}

export default LandingPage;