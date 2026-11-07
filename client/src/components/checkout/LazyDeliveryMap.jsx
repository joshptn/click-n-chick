import { Suspense, lazy } from "react";
import { Loader } from "@mantine/core";

import { loadDeliveryMap } from "../../lib/deliveryMap";
import { loadOrReload } from "../../lib/newVersion";

const DeliveryMap = lazy(loadOrReload(loadDeliveryMap));

/**
 * DeliveryMap, with its code loaded on first use. The placeholder takes the
 * map's own size and frame, so nothing moves when the map arrives.
 */
function LazyDeliveryMap(props) {
  const height = props.height ?? 260;

  return (
    <Suspense
      fallback={
        <div
          aria-label="Loading the map"
          className="grid place-items-center rounded-[12px] border border-[#ece7e0] bg-[#f4f1ec]"
          style={{ height }}
        >
          <Loader color="orange" size="sm" />
        </div>
      }
    >
      <DeliveryMap {...props} />
    </Suspense>
  );
}

export default LazyDeliveryMap;
