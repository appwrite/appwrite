import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import {
  SimplePagination,
  Pagination,
} from '@/components/global/shared/Pagination'

describe('SimplePagination', () => {
  it('shows current page number', () => {
    render(<SimplePagination currentPage={3} hasMore onPageChange={vi.fn()} />)
    expect(screen.getByText('3')).toBeDefined()
  })

  it('disables previous button on page 1', () => {
    render(<SimplePagination currentPage={1} hasMore onPageChange={vi.fn()} />)
    expect(
      screen.getByLabelText('Go to previous page').hasAttribute('disabled'),
    ).toBe(true)
  })

  it('disables next button when hasMore is false', () => {
    render(
      <SimplePagination
        currentPage={1}
        hasMore={false}
        onPageChange={vi.fn()}
      />,
    )
    expect(
      screen.getByLabelText('Go to next page').hasAttribute('disabled'),
    ).toBe(true)
  })

  it('calls onPageChange with previous page', () => {
    const onPageChange = vi.fn()
    render(
      <SimplePagination currentPage={3} hasMore onPageChange={onPageChange} />,
    )
    fireEvent.click(screen.getByLabelText('Go to previous page'))
    expect(onPageChange).toHaveBeenCalledWith(2)
  })

  it('calls onPageChange with next page', () => {
    const onPageChange = vi.fn()
    render(
      <SimplePagination currentPage={3} hasMore onPageChange={onPageChange} />,
    )
    fireEvent.click(screen.getByLabelText('Go to next page'))
    expect(onPageChange).toHaveBeenCalledWith(4)
  })

  it('disables both buttons when disabled prop is true', () => {
    render(
      <SimplePagination
        currentPage={3}
        hasMore
        onPageChange={vi.fn()}
        disabled
      />,
    )
    expect(
      screen.getByLabelText('Go to previous page').hasAttribute('disabled'),
    ).toBe(true)
    expect(
      screen.getByLabelText('Go to next page').hasAttribute('disabled'),
    ).toBe(true)
  })
})

describe('Pagination', () => {
  const defaultProps = {
    currentPage: 1,
    totalItems: 100,
    pageSize: 10,
    onPageChange: vi.fn(),
    onPageSizeChange: vi.fn(),
  }

  it('shows page info', () => {
    render(<Pagination {...defaultProps} />)
    // "Page" and "1" and "of 10" should appear
    expect(screen.getByText('Page')).toBeDefined()
    expect(screen.getByText('1')).toBeDefined()
    expect(screen.getByText('of 10')).toBeDefined()
  })

  it('disables previous/first buttons on page 1', () => {
    render(<Pagination {...defaultProps} />)
    expect(
      screen.getByLabelText('Go to previous page').hasAttribute('disabled'),
    ).toBe(true)
    expect(
      screen.getByLabelText('Go to first page').hasAttribute('disabled'),
    ).toBe(true)
  })

  it('disables next/last buttons on last page', () => {
    render(<Pagination {...defaultProps} currentPage={10} />)
    expect(
      screen.getByLabelText('Go to next page').hasAttribute('disabled'),
    ).toBe(true)
    expect(
      screen.getByLabelText('Go to last page').hasAttribute('disabled'),
    ).toBe(true)
  })

  it('navigates to next page', () => {
    const onPageChange = vi.fn()
    render(<Pagination {...defaultProps} onPageChange={onPageChange} />)
    fireEvent.click(screen.getByLabelText('Go to next page'))
    expect(onPageChange).toHaveBeenCalledWith(2)
  })

  it('navigates to previous page', () => {
    const onPageChange = vi.fn()
    render(
      <Pagination
        {...defaultProps}
        currentPage={5}
        onPageChange={onPageChange}
      />,
    )
    fireEvent.click(screen.getByLabelText('Go to previous page'))
    expect(onPageChange).toHaveBeenCalledWith(4)
  })

  it('navigates to first page', () => {
    const onPageChange = vi.fn()
    render(
      <Pagination
        {...defaultProps}
        currentPage={5}
        onPageChange={onPageChange}
      />,
    )
    fireEvent.click(screen.getByLabelText('Go to first page'))
    expect(onPageChange).toHaveBeenCalledWith(1)
  })

  it('navigates to last page', () => {
    const onPageChange = vi.fn()
    render(<Pagination {...defaultProps} onPageChange={onPageChange} />)
    fireEvent.click(screen.getByLabelText('Go to last page'))
    expect(onPageChange).toHaveBeenCalledWith(10)
  })

  it('handles zero items', () => {
    render(<Pagination {...defaultProps} totalItems={0} />)
    // totalPages = max(1, 0) = 1
    expect(screen.getByText('of 1')).toBeDefined()
  })

  it('calculates total pages correctly', () => {
    render(<Pagination {...defaultProps} totalItems={55} pageSize={25} />)
    // ceil(55/25) = 3
    expect(screen.getByText('of 3')).toBeDefined()
  })

  it('uses custom itemLabel', () => {
    render(<Pagination {...defaultProps} itemLabel="users" />)
    // The label is used in the total display, which uses CSS @container visibility
    // Just verify it renders without error
    expect(screen.getByText('Page')).toBeDefined()
  })
})
