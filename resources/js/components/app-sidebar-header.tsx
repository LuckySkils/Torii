import { Breadcrumbs } from '@/components/breadcrumbs';
import { ThemeToggle } from '@/components/theme-toggle';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import AppLogoIcon from './app-logo-icon';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const pageTitle = breadcrumbs.at(-1)?.title;

    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-3 pt-[env(safe-area-inset-top)] transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-4 md:px-4">
            <SidebarTrigger className="-ml-1" />

            <div className="flex min-w-0 flex-1 items-center gap-2 md:hidden">
                <div className="flex shrink-0 items-center gap-1.5">
                    <AppLogoIcon className="size-5 text-[#E0492F]" />
                    <span className="text-sm font-semibold">Torii</span>
                </div>
                {pageTitle && (
                    <>
                        <span className="text-muted-foreground">/</span>
                        <span className="min-w-0 flex-1 truncate text-sm text-muted-foreground">{pageTitle}</span>
                    </>
                )}
            </div>

            <div className="hidden min-w-0 flex-1 items-center gap-2 md:flex">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <ThemeToggle />
        </header>
    );
}
