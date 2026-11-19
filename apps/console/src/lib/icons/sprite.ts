import {
  ArrowDown,
  ArrowRight,
  ArrowUpRight,
  BadgeCheck,
  CheckCircle2,
  ChevronRight,
  Database,
  DatabaseBackup,
  Folder,
  Globe,
  Globe2,
  HeartPulse,
  LockKeyhole,
  Megaphone,
  MessageSquare,
  Pentagon,
  Radio,
  Scale,
  Shield,
  ShieldCheck,
  Users,
  Zap,
  type LucideIcon,
} from 'lucide-react'

/**
 * Icons the home page renders server-side. They are served once from
 * `/icons/sprite.svg` and referenced with `<use>`, so the prerendered HTML
 * does not repeat every path.
 */
const icons: readonly LucideIcon[] = [
  ArrowDown,
  ArrowRight,
  ArrowUpRight,
  BadgeCheck,
  CheckCircle2,
  ChevronRight,
  Database,
  DatabaseBackup,
  Folder,
  Globe,
  Globe2,
  HeartPulse,
  LockKeyhole,
  Megaphone,
  MessageSquare,
  Pentagon,
  Radio,
  Scale,
  Shield,
  ShieldCheck,
  Users,
  Zap,
]

/** Kebab-case Lucide name, matching the `lucide-<name>` class Lucide renders. */
export function getSpriteId(icon: LucideIcon): string {
  return (icon.displayName ?? '')
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/([a-zA-Z])([0-9])/g, '$1-$2')
    .toLowerCase()
}

export const spriteIcons = new Map(
  icons.map((icon) => [getSpriteId(icon), icon]),
)

/** Versioned by the symbol ids, so a deploy that changes the icon set never reads a cached sprite missing a symbol. */
export const SPRITE_URL = `/icons/sprite.svg?v=${[...spriteIcons.keys()]
  .join(',')
  .split('')
  .reduce((hash, char) => (hash * 31 + char.charCodeAt(0)) >>> 0, 7)
  .toString(36)}`
