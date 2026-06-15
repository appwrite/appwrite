import { cn } from '@/lib/utils'

/** Global CSS class — defined in styles.css @layer components. */
export const LINK_NEUTRAL_CLASS = 'link-neutral'

/** Subtle dashed underline for inline text links. */
export const LINK_DASHED_DECORATION_CLASS =
  'underline decoration-dashed decoration-muted-foreground/60 underline-offset-[3px] [text-decoration-thickness:1px] hover:decoration-muted-foreground transition-[text-decoration-color,color] duration-150'

/** Default inline link: neutral foreground with a dashed underline. */
export const INLINE_LINK_CLASS = LINK_NEUTRAL_CLASS

/** Heading anchor links inherit color with the same dashed underline. */
export const HEADING_LINK_TEXT_CLASS = cn(
  'text-inherit hover:text-inherit',
  LINK_DASHED_DECORATION_CLASS,
)

/** Wrapper class — styles child anchors via styles.css (see .prose-links-neutral). */
export const PROSE_LINKS_NEUTRAL_CLASS = 'prose-links-neutral'

/** In-card link hint (e.g. "View day", "Learn more") — neutral, not brand pink. */
export const CARD_LINK_HINT_CLASS =
  'inline-flex items-center gap-1 font-medium text-muted-foreground transition-colors group-hover:text-foreground'
