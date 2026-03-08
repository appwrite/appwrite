/**
 * Tailwind classes for <img> icons from /public/icons/ so they match the same
 * shade as font icons (text-muted-foreground) in light mode.
 * Light: brightness(0) + opacity 0.55 (~muted-foreground). Dark: full brightness.
 */
export const PUBLIC_ICON_MUTED_CLASSES =
  'brightness-0 opacity-[0.55] dark:brightness-100 dark:opacity-100'
