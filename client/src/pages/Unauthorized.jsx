import { Link } from "react-router-dom";
import { Button, Card, Center, Stack, Text, Title } from "@mantine/core";
import { IconLock } from "@tabler/icons-react";

function Unauthorized() {
  return (
    <Center mih="100vh" bg="gray.0" p="md">
      <Card shadow="md" radius="lg" p="xl" withBorder w={400}>
        <Stack align="center" gap="md">
          <IconLock size={64} color="orange" />
          <Title order={2} c="orange">
            Access Denied
          </Title>
          <Text c="dimmed" ta="center">
            Your account does not have permission to view this page.
          </Text>
          <Button component={Link} to="/" color="orange" size="md" radius="md">
            Back to Home
          </Button>
        </Stack>
      </Card>
    </Center>
  );
}

export default Unauthorized;
