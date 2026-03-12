import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, act } from '@testing-library/react'
import { CopyableId } from '@/components/global/shared/CopyableId'

const mockWriteText = vi.fn().mockResolvedValue(undefined)

describe('CopyableId', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    Object.assign(navigator, {
      clipboard: { writeText: mockWriteText },
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('renders the ID text', () => {
    render(<CopyableId id="abc-123" />)
    expect(screen.getByText('abc-123')).toBeDefined()
  })

  it('copies to clipboard on click', () => {
    render(<CopyableId id="abc-123" />)
    fireEvent.click(screen.getByRole('button'))
    expect(mockWriteText).toHaveBeenCalledWith('abc-123')
  })

  it('shows check icon after copying', () => {
    const { container } = render(<CopyableId id="abc-123" />)
    // Initially no emerald (success) icon
    expect(container.querySelector('.text-emerald-500')).toBeNull()

    fireEvent.click(screen.getByRole('button'))

    // After click, success icon appears
    expect(container.querySelector('.text-emerald-500')).not.toBeNull()
  })

  it('reverts icon after 2 seconds', () => {
    const { container } = render(<CopyableId id="abc-123" />)
    fireEvent.click(screen.getByRole('button'))

    expect(container.querySelector('.text-emerald-500')).not.toBeNull()

    act(() => {
      vi.advanceTimersByTime(2000)
    })

    expect(container.querySelector('.text-emerald-500')).toBeNull()
  })

  it('applies size variant classes', () => {
    const { container: xs } = render(<CopyableId id="id" size="xs" />)
    expect(xs.querySelector('.text-\\[10px\\]')).not.toBeNull()

    const { container: md } = render(<CopyableId id="id" size="md" />)
    expect(md.querySelector('.text-\\[12px\\]')).not.toBeNull()
  })

  it('stops event propagation on click', () => {
    const outerHandler = vi.fn()
    render(
      <div onClick={outerHandler}>
        <CopyableId id="abc-123" />
      </div>,
    )
    fireEvent.click(screen.getByRole('button'))
    expect(outerHandler).not.toHaveBeenCalled()
  })

  it('applies custom maxWidth via inline style', () => {
    render(<CopyableId id="abc-123" maxWidth={200} />)
    const span = screen.getByText('abc-123')
    expect(span.style.maxWidth).toBe('200px')
  })
})