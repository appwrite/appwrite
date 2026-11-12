/**
 * Analytics colours, taken from the Appwrite brand palette (the same values
 * as the product tones in styles.css: pink 253 54 110, purple 124 103 254,
 * mint 133 219 216, peach 254 149 103). Hex so the PDF export can share them.
 *
 * Humans are brand purple; bot types take the other brand hues in a fixed
 * order, then a neutral grey for anything past them ("Other bots").
 */
export const ANALYTICS_HUMAN_COLOR = '#7C67FE'

export const ANALYTICS_BOT_COLORS = ['#FE9567', '#85DBD8', '#FD366E'] as const

export const ANALYTICS_BOT_FALLBACK_COLOR = '#97979B'

export function analyticsBotColor(index: number): string {
  return ANALYTICS_BOT_COLORS[index] ?? ANALYTICS_BOT_FALLBACK_COLOR
}
