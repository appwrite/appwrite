import { cn } from '@/lib/utils'

/** Content body font - Inter with website letter-spacing (--web-letter-spacing-tight). */
export const CONTENT_BODY_FONT_CLASS =
  'font-inter font-normal tracking-[-0.0045em]'

/** Shared body copy scale for docs articles. */
export const DOCS_BODY_TEXT_CLASS = cn(
  CONTENT_BODY_FONT_CLASS,
  'text-[16px] leading-[1.7] text-muted-foreground @[640px]:text-[17px] @[640px]:leading-[1.65]',
)

/** Sidebar TOC links - smaller than article body. */
export const DOCS_TOC_LINK_TEXT_CLASS =
  'text-[14px] leading-5 text-muted-foreground'

/** Table body cells - compact relative to article prose. */
export const DOCS_TABLE_CELL_TEXT_CLASS =
  'text-[14px] leading-6 text-muted-foreground'

/** Long-form docs article typography (page chrome + prose body). */

export const DOCS_PAGE_EYEBROW_CLASS =
  'text-[12px] font-semibold uppercase tracking-[0.12em] text-muted-foreground'

export const DOCS_PAGE_TITLE_CLASS =
  'font-aeonik-pro text-balance text-[30px] font-normal leading-[1.3] tracking-tight text-foreground @[640px]:text-[36px] @[640px]:leading-[1.25]'

export const DOCS_STICKY_TITLE_CLASS =
  'min-w-0 truncate font-aeonik-pro text-[17px] font-medium leading-snug text-foreground @[560px]:text-[19px]'

export const DOCS_PAGE_DESCRIPTION_CLASS = cn(
  'mt-4 max-w-2xl',
  DOCS_BODY_TEXT_CLASS,
)

export const DOCS_PROSE_WRAPPER_CLASS = cn('docs-prose', DOCS_BODY_TEXT_CLASS)

export const DOCS_PROSE_DETAIL_CLASSES = [
  '[&>article>*:first-child]:mt-0',
  '[&_p]:my-0 [&_p+p]:mt-4',
  '[&_ul]:my-4 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5',
  '[&_ol]:my-4 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-5',
  '[&_li]:my-0 [&_li]:leading-[1.7] @[640px]:[&_li]:leading-[1.65]',
  '[&_strong]:font-semibold [&_strong]:text-foreground/90',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[13px] [&_code]:text-foreground/85',
  '[&_hr]:my-8 [&_hr]:border-border',
] as const

export const DOCS_TOC_SECTION_TITLE_CLASS =
  'pb-2 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

/** Thread messages - same body scale and prose rhythm as docs articles. */
export const THREAD_PROSE_WRAPPER_CLASS = cn(
  DOCS_PROSE_WRAPPER_CLASS,
  'prose-links-neutral min-w-0 break-words',
)

export const THREAD_PROSE_DETAIL_CLASSES = [
  ...DOCS_PROSE_DETAIL_CLASSES,
  '[&_blockquote]:border-[var(--brand-cta)] [&_blockquote]:italic',
  '[&_img]:inline-block [&_img]:h-5 [&_img]:w-5 [&_img]:align-text-bottom',
] as const
