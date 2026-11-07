import { useEffect, useState } from "react";
import { Loader } from "@mantine/core";

/**
 * Shown while a page's code downloads on a cold load. The spinner waits a
 * moment before appearing, so a page that arrives quickly never flashes one.
 */
function PageFallback() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => setVisible(true), 250);

    return () => window.clearTimeout(timer);
  }, []);

  return (
    <div data-page-loading className="grid min-h-dvh place-items-center bg-[#fdfaf6]">
      {visible && <Loader color="orange" size="lg" />}
    </div>
  );
}

export default PageFallback;
