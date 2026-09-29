import { NavMain } from '@/components/nav-main';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, Folder, LayoutGrid, Tv } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { pendingLinkSuggestions } = usePage<SharedData>().props;

    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', url: '/', icon: LayoutGrid },
        { title: 'Shows', url: '/shows', icon: Folder, badge: pendingLinkSuggestions, badgeUrl: '/shows?review=1' },
        { title: 'Anime', url: '/anime', icon: Tv },
        { title: 'Schedule', url: '/schedule', icon: CalendarDays },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <p className="mt-auto px-2 py-2 text-xs text-sidebar-foreground/60 italic group-data-[collapsible=icon]:hidden">
                    A torii marks no wall, only a threshold — what passes through arrives changed.
                </p>
            </SidebarFooter>
        </Sidebar>
    );
}
