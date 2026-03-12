import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'

describe('InitialsAvatar', () => {
  it('generates initials from two-word name', () => {
    render(<InitialsAvatar name="John Doe" />)
    expect(screen.getByText('JD')).toBeDefined()
  })

  it('generates single initial from one-word name', () => {
    render(<InitialsAvatar name="Alice" />)
    expect(screen.getByText('A')).toBeDefined()
  })

  it('uses first and last parts for three-word name', () => {
    render(<InitialsAvatar name="John Michael Doe" />)
    expect(screen.getByText('JD')).toBeDefined()
  })

  it('shows Ghost icon for empty name', () => {
    const { container } = render(<InitialsAvatar name="" />)
    // Ghost icon renders as an SVG
    expect(container.querySelector('svg')).not.toBeNull()
    expect(screen.queryByText('?')).toBeNull()
  })

  it('shows Ghost icon for undefined name', () => {
    const { container } = render(<InitialsAvatar />)
    expect(container.querySelector('svg')).not.toBeNull()
  })

  it('strips emojis and uses remaining letters', () => {
    render(<InitialsAvatar name="🎉 John" />)
    expect(screen.getByText('J')).toBeDefined()
  })

  it('returns "?" for special chars only', () => {
    render(<InitialsAvatar name="🎉🎊" />)
    // All non-letter chars stripped → empty → "?"
    expect(screen.getByText('?')).toBeDefined()
  })

  it('applies size variant classes', () => {
    const { container: sm } = render(
      <InitialsAvatar name="John Doe" size="sm" />,
    )
    expect(sm.firstElementChild?.classList.contains('h-6')).toBe(true)

    const { container: lg } = render(
      <InitialsAvatar name="John Doe" size="lg" />,
    )
    expect(lg.firstElementChild?.classList.contains('h-10')).toBe(true)
  })

  it('merges custom className', () => {
    const { container } = render(
      <InitialsAvatar name="John Doe" className="my-class" />,
    )
    expect(container.querySelector('.my-class')).not.toBeNull()
  })
})
