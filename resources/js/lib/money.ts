/**
 * Formats an exact decimal dollar string for display: "0.467343750" => "$0.46734375".
 * It only trims trailing zeros (keeping at least two decimals), so nothing is rounded.
 */
export function formatUsd(amount: string): string {
    const sign = amount.startsWith('-') ? '-' : '';
    const [dollars, fraction = ''] = amount.replace('-', '').split('.');

    return `${sign}$${dollars}.${fraction.replace(/0+$/, '').padEnd(2, '0')}`;
}
