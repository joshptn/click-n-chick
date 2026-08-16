import { Card, Stack, Text, Title } from "@mantine/core";

import AppHeader from "../../components/app/AppHeader";
import { useRealtime } from "../../context/useRealtime";

/**
 * Staff landing page.
 *
 * Renders the global AppHeader so staff get the same app chrome as customers -
 * and, for GATE-3, so the realtime indicator and the notification bell exist on
 * a staff page at all. Without the header mounted here the admin.orders
 * firehose would be subscribed but have nowhere to surface.
 */
function AdminDashboard() {
  const { lastOrderEvent } = useRealtime();

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8">
        <Card shadow="sm" radius="md" p="xl" withBorder>
          <Stack gap="xs">
            <Title order={2}>Admin Dashboard</Title>
            <Text c="dimmed">Placeholder. Pending the order and menu management modules.</Text>

            {lastOrderEvent && (
              <Text
                data-testid="last-order-event"
                size="sm"
                className="font-display text-brand-600"
              >
                Live: order #{lastOrderEvent.order?.id} &rarr; {lastOrderEvent.order?.status}
              </Text>
            )}
          </Stack>
        </Card>
      </main>
    </div>
  );
}

export default AdminDashboard;
