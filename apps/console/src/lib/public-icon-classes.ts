/**
 * Tailwind classes for <img> icons from /public/icons/ so they match the same
 * shade as font icons (text-muted-foreground) in light mode.
 * Light: brightness(0) + opacity 0.55 (~muted-foreground). Dark: full brightness.
 */
export const PUBLIC_ICON_MUTED_CLASSES =
  'brightness-0 opacity-[0.55] dark:brightness-100 dark:opacity-100'

/**
 * Grayscale + partial invert for catalog logos (marketplace apps, OAuth providers).
 * Black SVG/PNG artwork maps to muted-foreground contrast in light and dark mode.
 */
export const PUBLIC_CATALOG_LOGO_IMAGE_CLASSES =
  'h-full w-full object-contain grayscale invert-[.44] dark:invert-[.63]'

/** Full-color logo inside a marketplace-style tile (user-published apps). */
export const PUBLIC_CATALOG_LOGO_COLOR_CLASSES =
  'h-full w-full object-contain'
