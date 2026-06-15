import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_PAGE_DESCRIPTION_CLASS,
  DOCS_PAGE_TITLE_CLASS,
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_STICKY_TITLE_CLASS,
  DOCS_TABLE_CELL_TEXT_CLASS,
} from '@/lib/docs/prose-typography'

/** Blog prose matches docs/changelog defaults. */
export const BLOG_BODY_TEXT_SIZE_CLASS =
  'text-[16px] leading-[1.7] @[640px]:text-[17px] @[640px]:leading-[1.65]'

export const BLOG_BODY_TEXT_CLASS = DOCS_BODY_TEXT_CLASS

export const BLOG_FAQ_QUESTION_CLASS = 'text-[14px] font-medium text-foreground'

export const BLOG_PAGE_TITLE_CLASS = DOCS_PAGE_TITLE_CLASS

export const BLOG_CATEGORY_TITLE_CLASS = DOCS_PAGE_TITLE_CLASS

export const BLOG_STICKY_TITLE_CLASS = DOCS_STICKY_TITLE_CLASS

export const BLOG_CATEGORY_CARD_TITLE_CLASS =
  'text-[16px] font-semibold leading-snug text-foreground'

/** Reserve two lines on blog index cards for consistent grid alignment. */
export const BLOG_INDEX_CARD_TITLE_LINES_CLASS = 'line-clamp-2 min-h-[2lh]'

export const BLOG_SECTION_TITLE_CLASS = BLOG_PAGE_TITLE_CLASS

export const BLOG_PAGE_DESCRIPTION_CLASS = DOCS_PAGE_DESCRIPTION_CLASS

export const BLOG_TABLE_CELL_TEXT_CLASS = DOCS_TABLE_CELL_TEXT_CLASS

export const BLOG_PROSE_DETAIL_CLASSES = DOCS_PROSE_DETAIL_CLASSES

export type MarkdocProseVariant = 'docs' | 'blog'
