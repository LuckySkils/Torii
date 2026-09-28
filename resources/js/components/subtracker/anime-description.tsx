import { cn } from '@/lib/utils';
import { type ReactNode, useLayoutEffect, useMemo, useRef, useState } from 'react';

const INLINE: Record<string, 'em' | 'strong'> = { I: 'em', EM: 'em', B: 'strong', STRONG: 'strong' };

/**
 * AniList descriptions carry HTML. Rather than sanitising a string and injecting
 * it, the markup is parsed (which also decodes entities) and rebuilt as React
 * nodes: only <br>, <i>/<em> and <b>/<strong> survive; every other element is
 * dropped but its text kept. Nothing from the source reaches the DOM as HTML.
 */
function toNodes(node: Node, key: string): ReactNode {
    if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent;
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return null;
    }

    const element = node as Element;

    if (element.tagName === 'BR') {
        return <br key={key} />;
    }

    if (element.tagName === 'SCRIPT' || element.tagName === 'STYLE') {
        return null;
    }

    const children = Array.from(element.childNodes).map((child, index) => toNodes(child, `${key}.${index}`));
    const Tag = INLINE[element.tagName];

    return Tag ? <Tag key={key}>{children}</Tag> : <span key={key}>{children}</span>;
}

export function sanitizeAniListHtml(html: string): ReactNode[] {
    const body = new DOMParser().parseFromString(html, 'text/html').body;

    return Array.from(body.childNodes).map((child, index) => toNodes(child, String(index)));
}

export function AnimeDescription({ html, className }: { html: string; className?: string }) {
    const nodes = useMemo(() => sanitizeAniListHtml(html), [html]);
    const [expanded, setExpanded] = useState(false);
    const [overflows, setOverflows] = useState(false);
    const ref = useRef<HTMLParagraphElement>(null);

    useLayoutEffect(() => {
        const element = ref.current;

        if (element && !expanded) {
            setOverflows(element.scrollHeight > element.clientHeight + 1);
        }
    }, [nodes, expanded]);

    return (
        <div className={cn('flex flex-col items-start gap-1', className)}>
            <p ref={ref} className={cn('text-sm leading-relaxed break-words text-muted-foreground large:md:max-w-[75ch] large:md:text-base', !expanded && 'line-clamp-4 large:md:line-clamp-6')}>
                {nodes}
            </p>
            {(overflows || expanded) && (
                <button type="button" className="cursor-pointer text-sm font-medium hover:underline large:md:text-base" onClick={() => setExpanded((value) => !value)}>
                    {expanded ? 'less' : 'more'}
                </button>
            )}
        </div>
    );
}
