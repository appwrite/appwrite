import {
  CARD_LINK_HINT_CLASS,
  HEADING_LINK_TEXT_CLASS,
  INLINE_LINK_CLASS,
  LINK_DASHED_DECORATION_CLASS,
  LINK_NEUTRAL_CLASS,
  PROSE_LINKS_NEUTRAL_CLASS,
} from '@/lib/link-styles'

/** @deprecated Use {@link LINK_DASHED_DECORATION_CLASS} */
export const DOCS_LINK_DOTTED_DECORATION_CLASS = LINK_DASHED_DECORATION_CLASS

/** Inline links in docs prose (articles, FAQ answers, AI section copy). */
export const DOCS_PROSE_LINK_CLASS = INLINE_LINK_CLASS

/** Heading anchor link text - inherits heading color with the same dashed underline. */
export const DOCS_HEADING_LINK_TEXT_CLASS = HEADING_LINK_TEXT_CLASS

export { LINK_NEUTRAL_CLASS, LINK_DASHED_DECORATION_CLASS, INLINE_LINK_CLASS, PROSE_LINKS_NEUTRAL_CLASS, CARD_LINK_HINT_CLASS }
