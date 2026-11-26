import TabBar from "./TabBar";
import { TAB_BAR_SPACE } from "../../lib/phone";

/** A tab's page on a phone: its content, room at the bottom, and the tab bar over it. */
function TabScreen({ children, className = "" }) {
  return (
    <div className={`font-display text-ink ${className}`} style={{ paddingBottom: TAB_BAR_SPACE }}>
      {children}
      <TabBar />
    </div>
  );
}

export default TabScreen;
