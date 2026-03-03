import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Search } from 'lucide-react'

describe('EmptyState', () => {
  it('shows default empty title and description', () => {
    render(<EmptyState />)
    expect(screen.getByText('No items yet')).toBeDefined()
    expect(
      screen.getByText('Get started by creating your first item.'),
    ).toBeDefined()
  })

  it('shows filter-specific text when hasFilters=true', () => {
    render(<EmptyState hasFilters />)
    expect(screen.getByText('No results found')).toBeDefined()
    expect(
      screen.getByText(
        'Try adjusting your search or filters to see more results.',
      ),
    ).toBeDefined()
  })

  it('shows "No items found" when isEmpty=false and hasFilters=false', () => {
    render(<EmptyState isEmpty={false} />)
    expect(screen.getByText('No items found')).toBeDefined()
    expect(
      screen.getByText('No items match your criteria.'),
    ).toBeDefined()
  })

  it('uses custom title and description', () => {
    render(
      <EmptyState title="Custom Title" description="Custom desc" />,
    )
    expect(screen.getByText('Custom Title')).toBeDefined()
    expect(screen.getByText('Custom desc')).toBeDefined()
  })

  it('renders icon when provided', () => {
    const { container } = render(<EmptyState icon={Search} />)
    // Icon renders inside a rounded-full container
    expect(container.querySelector('.rounded-full')).not.toBeNull()
  })

  it('does not render icon container when no icon', () => {
    const { container } = render(<EmptyState />)
    expect(container.querySelector('.rounded-full')).toBeNull()
  })

  it('renders children instead of default content', () => {
    render(
      <EmptyState>
        <span>Custom child content</span>
      </EmptyState>,
    )
    expect(screen.getByText('Custom child content')).toBeDefined()
    expect(screen.queryByText('No items yet')).toBeNull()
  })

  it('wraps children in card variant', () => {
    const { container } = render(
      <EmptyState variant="card">
        <span>Card child</span>
      </EmptyState>,
    )
    expect(screen.getByText('Card child')).toBeDefined()
    expect(container.querySelector('.border-dashed')).not.toBeNull()
  })

  it('wraps children in centered variant', () => {
    const { container } = render(
      <EmptyState variant="centered">
        <span>Centered child</span>
      </EmptyState>,
    )
    expect(screen.getByText('Centered child')).toBeDefined()
    expect(container.querySelector('.justify-center')).not.toBeNull()
  })

  it('applies card variant to default content', () => {
    const { container } = render(<EmptyState variant="card" />)
    expect(container.querySelector('.border-dashed')).not.toBeNull()
  })

  it('applies centered variant to default content', () => {
    const { container } = render(<EmptyState variant="centered" />)
    expect(container.querySelector('.justify-center')).not.toBeNull()
  })

  it('merges custom className', () => {
    const { container } = render(<EmptyState className="my-custom-class" />)
    expect(container.querySelector('.my-custom-class')).not.toBeNull()
  })
})