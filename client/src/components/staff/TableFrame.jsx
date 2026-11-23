import { useRef } from "react";

const STICKY_TOP = "top-[54px] lg:top-0";

/**
 * A staff table whose column row stays at the top of the screen while its rows
 * scroll under it (decided 2026-11-24, every staff table).
 *
 * The column row lives outside the rows' sideways scroller: a sticky row inside
 * an `overflow-x` box sticks to that box, never to the page. So the rows scroll
 * sideways on their own on a narrow window, and the column row is moved to
 * match. Below `lg` the staff top bar (54px) is pinned too, so the row stops
 * under it. `sticky={false}` keeps it in place, for a table inside a dialog,
 * where the dialog's own header is pinned.
 *
 * `head` may carry more than the column row: a bar that should travel with it
 * (the ticked-items bar) goes above it, inside the same pinned block.
 */
function TableFrame({ minWidth, head, children, sticky = true, testId }) {
  const headRef = useRef(null);
  const bodyRef = useRef(null);

  const follow = (event) => {
    if (headRef.current) headRef.current.scrollLeft = event.currentTarget.scrollLeft;
  };

  // A sideways swipe over the pinned row scrolls the rows it labels.
  const passSideways = (event) => {
    if (bodyRef.current && event.deltaX) bodyRef.current.scrollLeft += event.deltaX;
  };

  return (
    <div className="rounded-[16px] bg-white ring-1 ring-[#f0e9df]" data-testid={testId}>
      <div
        ref={headRef}
        onWheel={passSideways}
        data-testid="table-head"
        className={`${sticky ? `sticky ${STICKY_TOP} z-20` : ""} overflow-hidden rounded-t-[16px] bg-white`}
      >
        <div className={minWidth}>{head}</div>
      </div>
      <div ref={bodyRef} onScroll={follow} className="overflow-x-auto rounded-b-[16px]">
        <div className={minWidth}>{children}</div>
      </div>
    </div>
  );
}

export default TableFrame;
