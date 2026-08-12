import React from "react";
import { Center, Loader as MantineLoader, Stack, Text } from "@mantine/core";

function Loader({ label = "Loading...", size = "lg" }) {
  return (
    <Center p="xl">
      <Stack align="center" gap="sm">
        <MantineLoader size={size} />
        {label ? <Text c="dimmed">{label}</Text> : null}
      </Stack>
    </Center>
  );
}

export default Loader;
