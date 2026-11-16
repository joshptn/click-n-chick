/**
 * "₱1,234.00", as the web's formatPeso. Formatted by hand rather than through
 * Intl, so every Android version shows the same thing.
 */
export function formatPeso(amount: number | string | null | undefined): string {
  const value = Number(amount ?? 0);
  const [whole, cents] = (Number.isFinite(value) ? Math.abs(value) : 0).toFixed(2).split(".");
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");

  return `${value < 0 ? "-" : ""}₱${grouped}.${cents}`;
}
