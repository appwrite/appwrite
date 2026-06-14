import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_PAGE_DESCRIPTION_CLASS,
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_TABLE_CELL_TEXT_CLASS,
} from '@/lib/docs/prose-typography'

/** Blog prose matches docs/changelog defaults. */
export const BLOG_BODY_TEXT_SIZE_CLASS =
  'text-[16px] leading-[1.7] @[640px]:text-[17px] @[640px]:leading-[1.65]'

export const BLOG_BODY_TEXT_CLASS = DOCS_BODY_TEXT_CLASS

export const BLOG_FAQ_QUESTION_CLASS = 'text-[14px] font-medium text-foreground'

export const BLOG_PAGE_TITLE_CLASS =
  'font-aeonik-pro text-[28px] font-normal leading-[1.3] tracking-tight text-foreground sm:text-[34px] sm:leading-[1.25]'

export const BLOG_CATEGORY_TITLE_CLASS =
  'text-[28px] font-semibold leading-[1.3] tracking-tight text-foreground sm:text-[34px] sm:leading-[1.25]'

export const BLOG_CATEGORY_CARD_TITLE_CLASS =
  'text-[16px] font-semibold leading-snug text-foreground'

export const BLOG_SECTION_TITLE_CLASS = BLOG_PAGE_TITLE_CLASS

export const BLOG_PAGE_DESCRIPTION_CLASS = DOCS_PAGE_DESCRIPTION_CLASS

export const BLOG_TABLE_CELL_TEXT_CLASS = DOCS_TABLE_CELL_TEXT_CLASS

export const BLOG_PROSE_DETAIL_CLASSES = DOCS_PROSE_DETAIL_CLASSES

export type MarkdocProseVariant = 'docs' | 'blog'
