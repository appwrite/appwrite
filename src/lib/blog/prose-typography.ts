import { cn } from '@/lib/utils'

/** Slightly larger than docs/changelog body copy; matches website `prose-large`. */
export const BLOG_BODY_TEXT_SIZE_CLASS =
  'text-[17px] leading-[1.7] @[640px]:text-[18px] @[640px]:leading-[1.65]'

export const BLOG_BODY_TEXT_CLASS = cn(BLOG_BODY_TEXT_SIZE_CLASS, 'text-muted-foreground')

export const BLOG_FAQ_QUESTION_CLASS = cn(
  BLOG_BODY_TEXT_SIZE_CLASS,
  'font-medium text-foreground',
)

export const BLOG_BREADCRUMB_LIST_CLASS = 'text-[14px] sm:text-[15px]'

export const BLOG_PAGE_TITLE_CLASS =
  'font-aeonik-pro text-balance text-[28px] font-normal leading-tight tracking-tight text-foreground sm:text-[34px] sm:leading-[1.15]'

export const BLOG_SECTION_TITLE_CLASS =
  'font-aeonik-pro text-balance text-[30px] font-normal leading-tight tracking-tight text-foreground sm:text-[36px] sm:leading-[1.15]'

export const BLOG_PAGE_DESCRIPTION_CLASS = cn(
  BLOG_BODY_TEXT_SIZE_CLASS,
  'font-medium leading-relaxed text-muted-foreground',
)

export const BLOG_BREADCRUMB_PAGE_CLASS = cn(
  BLOG_BREADCRUMB_LIST_CLASS,
  'font-aeonik-pro font-normal',
)

export const BLOG_TABLE_CELL_TEXT_CLASS = cn(
  BLOG_BODY_TEXT_SIZE_CLASS,
  'leading-6 text-muted-foreground',
)

export const BLOG_PROSE_DETAIL_CLASSES = [
  '[&>article>*:first-child]:mt-0',
  '[&_p]:my-0 [&_p+p]:mt-4',
  '[&_ul]:my-4 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5',
  '[&_ol]:my-4 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-5',
  '[&_li]:my-0 [&_li]:leading-[1.7] @[640px]:[&_li]:leading-[1.65]',
  '[&_strong]:font-semibold [&_strong]:text-foreground/90',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[15px] @[640px]:[&_code]:text-[16px] [&_code]:text-foreground/85',
  '[&_hr]:my-8 [&_hr]:border-border',
] as const

export type MarkdocProseVariant = 'docs' | 'blog'
