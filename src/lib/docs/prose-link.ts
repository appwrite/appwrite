import { cn } from '@/lib/utils'

/** Shared dotted underline used by docs inline and heading anchor links. */
export const DOCS_LINK_DOTTED_DECORATION_CLASS =
  'underline decoration-dotted decoration-muted-foreground/60 underline-offset-[3px] [text-decoration-thickness:1px] hover:decoration-muted-foreground hover:[text-decoration-thickness:2px] transition-[text-decoration-thickness,text-decoration-color,color] duration-150'

/** Inline links in docs prose (articles, FAQ answers, AI section copy). */
export const DOCS_PROSE_LINK_CLASS = cn(
  'font-medium text-foreground/85 hover:text-foreground/90',
  DOCS_LINK_DOTTED_DECORATION_CLASS,
)

/** Heading anchor link text — inherits heading color with the same dotted underline. */
export const DOCS_HEADING_LINK_TEXT_CLASS = cn(
  'text-inherit hover:text-inherit',
  DOCS_LINK_DOTTED_DECORATION_CLASS,
)
