export function getInitDayCardId(day: number): string {
  return `day-${day}`
}

export function scrollToInitDayCard(day: number) {
  if (typeof document === 'undefined') return
  const el = document.getElementById(getInitDayCardId(day))
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
