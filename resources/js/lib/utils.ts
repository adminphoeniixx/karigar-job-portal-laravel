import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

type Amount = string | number | null | undefined;

/** "24700.00" → "24,700": Indian grouping, paise only when there are any. */
export function rupees(v: Amount): string {
    return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 });
}

/** "₹12,000–20,000 / monthly", or null when no wage was given. */
export function wageText(min: Amount, max: Amount, type?: string | null): string | null {
    if (!min && !max) {
        return null;
    }

    const range = [min, max].filter(Boolean).map(rupees).join('–');

    return `₹${range}${type ? ' / ' + type : ''}`;
}
