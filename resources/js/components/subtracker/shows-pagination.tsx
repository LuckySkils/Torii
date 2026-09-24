import { Pagination, PaginationContent, PaginationItem, PaginationLink } from '@/components/ui/pagination';
import { cn } from '@/lib/utils';
import { type PaginationLinkItem } from '@/types/subtracker';

interface ShowsPaginationProps {
    links: PaginationLinkItem[];
    onNavigate: (url: string) => void;
}

/**
 * Laravel's paginator links: « Previous, the page numbers (with "..." gaps), Next ».
 * Every item sizes to its label (min 40px), so "« Previous"/"Next »" never
 * overlap their neighbours. Below `sm`, only the first, last, current and
 * adjacent pages are kept, so 10+ pages still fit on a phone.
 */
export function ShowsPagination({ links, onNavigate }: ShowsPaginationProps) {
    const numbered = links.slice(1, -1);
    const current = numbered.find((link) => link.active)?.page ?? 1;
    const last = numbered.reduce((max, link) => Math.max(max, link.page ?? 0), 1);

    function keepOnPhone(link: PaginationLinkItem, index: number): boolean {
        if (index === 0 || index === links.length - 1) {
            return true;
        }

        if (link.page === null) {
            return false;
        }

        return link.page === 1 || link.page === last || Math.abs(link.page - current) <= 1;
    }

    return (
        <Pagination>
            <PaginationContent className="gap-1 sm:gap-1.5">
                {links.map((link, index) => (
                    <PaginationItem key={index} className={cn(!keepOnPhone(link, index) && 'hidden sm:list-item')}>
                        {link.url === null ? (
                            <span
                                className="flex h-10 min-w-10 items-center justify-center px-2 text-sm whitespace-nowrap sm:px-3 text-muted-foreground opacity-50"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <PaginationLink
                                href={link.url}
                                isActive={link.active}
                                className="w-auto min-w-10 px-2 whitespace-nowrap sm:px-3"
                                onClick={(e) => {
                                    e.preventDefault();
                                    onNavigate(link.url as string);
                                }}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        )}
                    </PaginationItem>
                ))}
            </PaginationContent>
        </Pagination>
    );
}
