import { Card, Stack, Text, Title } from "@mantine/core";

import StaffLayout from "../../components/staff/StaffLayout";
import { useRealtime } from "../../context/useRealtime";

/**
 * The Store Manager's landing page - still a placeholder.
 *
 * Framed by the staff portal so the Manager's sidebar (Menu Catalog and the
 * shared staff pages) is reachable from where sign-in lands them.
 */
function SuperAdminDashboard() {
  const { lastOrderEvent } = useRealtime();

  return (
    <StaffLayout>
      <main className="w-full px-4 py-6 sm:px-6 lg:px-7">
        <Card shadow="sm" radius="md" p="xl" withBorder>
          <Stack gap="xs">
            <Title order={2}>Store Manager Dashboard</Title>
            <Text c="dimmed">Placeholder. Pending the dashboard and reporting modules.</Text>

            {lastOrderEvent && (
              <Text data-testid="last-order-event" size="sm" className="font-display text-brand-600">
                Live: order #{lastOrderEvent.order?.id} &rarr; {lastOrderEvent.order?.status}
              </Text>
            )}
          </Stack>
        </Card>
      </main>
    </StaffLayout>
  );
}

export default SuperAdminDashboard;
