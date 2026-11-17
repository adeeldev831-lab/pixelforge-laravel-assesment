import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
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
}
