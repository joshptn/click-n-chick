/**
 * What the Senior/PWD discount would take off an order, worked out the way the
 * server does (App\Services\Orders\StatutoryDiscount): the one unit of the
 * highest-priced dish marked for the discount, VAT taken out first, then the
 * rate on what is left, to the centavo. Only for showing; checkout's quote is
 * what is charged.
 */
const VAT_RATE = 12;

const round = (value) => Math.round(value * 100) / 100;

/** The line the discount goes on: the highest-priced marked dish that can be ordered. */
export function discountLine(lines) {
  return lines.reduce((best, line) => {
    if (!line.is_discountable || !line.is_orderable) return best;

    return best === null || Number(line.base_price) > Number(best.base_price) ? line : best;
  }, null);
}

/** VAT exemption plus the rate on one unit at `price`. */
export function discountSaving(price, percentage) {
  const amount = Number(price);

  if (!(amount > 0)) return 0;

  const exclusive = round(amount / (1 + VAT_RATE / 100));
  const vatExempt = round(amount - exclusive);

  return round(vatExempt + round(exclusive * (Number(percentage) / 100)));
}
