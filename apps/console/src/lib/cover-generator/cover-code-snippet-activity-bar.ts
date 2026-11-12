import { COVER_CODE_SNIPPET } from '@/lib/cover-generator/code-snippet/constants'

type ActivityBarIcon = {
  id: string
  active?: boolean
}

const ACTIVITY_BAR_ICONS: ActivityBarIcon[] = [
  { id: 'files', active: true },
  { id: 'search' },
  { id: 'git' },
  { id: 'extensions' },
  { id: 'settings' },
]

function buildActivityBarIconSvg(
  icon: ActivityBarIcon,
  centerX: number,
  centerY: number,
  size: number,
  inactiveColor: string,
  activeColor: string,
): string {
  const color = icon.active ? activeColor : inactiveColor
  const opacity = icon.active ? 0.88 : 0.38
  const s = size
  const half = s / 2

  switch (icon.id) {
    case 'files':
      return `
        <rect x="${centerX - half + 1}" y="${centerY - half + 2}" width="${s - 2}" height="${s - 1}" rx="2" fill="none" stroke="${color}" stroke-width="1.5" opacity="${opacity}" />
        <rect x="${centerX - half + 4}" y="${centerY - half + 6}" width="${s - 8}" height="1.5" rx="0.75" fill="${color}" opacity="${opacity * 0.7}" />
        <rect x="${centerX - half + 4}" y="${centerY - half + 9}" width="${s - 10}" height="1.5" rx="0.75" fill="${color}" opacity="${opacity * 0.5}" />
      `
    case 'search':
      return `
        <circle cx="${centerX - 1}" cy="${centerY - 1}" r="${half - 3}" fill="none" stroke="${color}" stroke-width="1.75" opacity="${opacity}" />
        <line x1="${centerX + half - 5}" y1="${centerY + half - 5}" x2="${centerX + half - 1}" y2="${centerY + half - 1}" stroke="${color}" stroke-width="1.75" stroke-linecap="round" opacity="${opacity}" />
      `
    case 'git':
      return `
        <circle cx="${centerX - 3}" cy="${centerY - 4}" r="2.25" fill="${color}" opacity="${opacity}" />
        <circle cx="${centerX + 3}" cy="${centerY + 4}" r="2.25" fill="${color}" opacity="${opacity}" />
        <path d="M ${centerX - 3} ${centerY - 1.75} V ${centerY + 1.75}" stroke="${color}" stroke-width="1.5" stroke-linecap="round" opacity="${opacity}" />
        <path d="M ${centerX - 3} ${centerY + 1.75} Q ${centerX} ${centerY + 1.75} ${centerX + 3} ${centerY + 1.75}" fill="none" stroke="${color}" stroke-width="1.5" stroke-linecap="round" opacity="${opacity}" />
      `
    case 'extensions':
      const grid = s - 6
      const cell = grid / 2 - 1
      const gx = centerX - grid / 2
      const gy = centerY - grid / 2
      return `
        <rect x="${gx}" y="${gy}" width="${cell}" height="${cell}" rx="1.5" fill="${color}" opacity="${opacity}" />
        <rect x="${gx + cell + 2}" y="${gy}" width="${cell}" height="${cell}" rx="1.5" fill="${color}" opacity="${opacity * 0.75}" />
        <rect x="${gx}" y="${gy + cell + 2}" width="${cell}" height="${cell}" rx="1.5" fill="${color}" opacity="${opacity * 0.75}" />
        <rect x="${gx + cell + 2}" y="${gy + cell + 2}" width="${cell}" height="${cell}" rx="1.5" fill="${color}" opacity="${opacity * 0.55}" />
      `
    case 'settings':
      return `
        <circle cx="${centerX}" cy="${centerY}" r="${half - 2}" fill="none" stroke="${color}" stroke-width="1.5" opacity="${opacity}" />
        <circle cx="${centerX}" cy="${centerY}" r="2" fill="${color}" opacity="${opacity * 0.85}" />
        <circle cx="${centerX}" cy="${centerY - half + 2}" r="1.5" fill="${color}" opacity="${opacity}" />
        <circle cx="${centerX + half - 2}" cy="${centerY}" r="1.5" fill="${color}" opacity="${opacity}" />
        <circle cx="${centerX}" cy="${centerY + half - 2}" r="1.5" fill="${color}" opacity="${opacity}" />
        <circle cx="${centerX - half + 2}" cy="${centerY}" r="1.5" fill="${color}" opacity="${opacity}" />
      `
    default:
      return ''
  }
}

export function buildCoverCodeSnippetActivityBarSvg(options: {
  x: number
  y: number
  width: number
  dividerX: number
  dividerY: number
  dividerHeight: number
  borderColor: string
  mutedIconColor: string
  activeIconColor: string
  accentColor: string
}): string {
  const {
    x,
    y,
    width,
    dividerX,
    dividerY,
    dividerHeight,
    borderColor,
    mutedIconColor,
    activeIconColor,
    accentColor,
  } = options

  const {
    activityBarIconSize,
    activityBarIconGap,
    activityBarPaddingTop,
  } = COVER_CODE_SNIPPET

  const iconCenterX = x + width / 2

  const icons = ACTIVITY_BAR_ICONS.map((icon, index) => {
    const iconY =
      y +
      activityBarPaddingTop +
      index * (activityBarIconSize + activityBarIconGap) +
      activityBarIconSize / 2

    const activeIndicator =
      icon.active
        ? `<rect x="${x + 4}" y="${iconY - activityBarIconSize / 2 + 2}" width="2" height="${activityBarIconSize - 4}" rx="1" fill="${accentColor}" opacity="0.95" />`
        : ''

    return `
      ${activeIndicator}
      ${buildActivityBarIconSvg(
        icon,
        iconCenterX,
        iconY,
        activityBarIconSize,
        mutedIconColor,
        activeIconColor,
      )}
    `
  }).join('')

  return `
    <line
      x1="${dividerX}"
      y1="${dividerY}"
      x2="${dividerX}"
      y2="${dividerY + dividerHeight}"
      stroke="${borderColor}"
      stroke-width="1"
      opacity="0.35"
    />
    ${icons}
  `
}
