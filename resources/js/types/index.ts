import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    isAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

/** Money fields are exact decimal dollar strings, e.g. "0.467343750". */
export interface Generation {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    error_code: string | null;
    error_message: string | null;
    estimated_min: string;
    estimated_max: string;
    charged: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    created_at: string;
}

export interface CostEstimate {
    min: string;
    max: string;
    /** Whether the user's available credits cover the maximum. */
    affordable: boolean;
}

export interface Credits {
    balance: string;
    reserved: string;
    available: string;
}

/** One line of the admin report. Money fields are exact decimal dollar strings. */
export interface ReportRow {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
}

export interface DailyReportRow extends ReportRow {
    date: string;
    /** What Pixelforge's usage endpoint reports for the day, or null if it couldn't be fetched. */
    pixelforge_cost: string | null;
    matches_pixelforge: boolean | null;
}

export interface UserReportRow extends ReportRow {
    date: string;
    user: string;
}
