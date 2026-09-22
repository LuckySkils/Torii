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
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    flash: { success: string | null; error: string | null };
    driver: 'rules' | 'push';
    notifications: { enabled: boolean; topic: string | null };
    [key: string]: unknown;
}
