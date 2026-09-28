import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={item.url === page.url.split('?')[0]}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {item.badge ? (
                            <SidebarMenuBadge className="rounded-full bg-amber-500/15 p-0 text-amber-700 dark:text-amber-300">
                                {item.badgeUrl ? (
                                    <Link
                                        href={item.badgeUrl}
                                        className="pointer-events-auto flex h-full min-w-5 items-center justify-center rounded-full px-1 hover:bg-amber-500/25"
                                        aria-label={`${item.badge} need review`}
                                        title="Show the ones needing review"
                                    >
                                        {item.badge}
                                    </Link>
                                ) : (
                                    <span className="px-1" aria-label={`${item.badge} need review`}>
                                        {item.badge}
                                    </span>
                                )}
                            </SidebarMenuBadge>
                        ) : null}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
