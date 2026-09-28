import { LucideIcon } from 'lucide-react';

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
    /** Where clicking the badge goes (e.g. the filtered list it counts). */
    badgeUrl?: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    flash: { success: string | null; error: string | null };
    driver: 'rules' | 'push';
    notifications: { enabled: boolean; topic: string | null };
    /** Shows with anime-link suggestions waiting for review. */
    pendingLinkSuggestions: number;
    [key: string]: unknown;
}
