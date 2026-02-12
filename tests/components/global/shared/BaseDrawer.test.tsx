import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'

vi.mock('@/components/ui/sheet', () => ({
    Sheet: ({ children, open }: any) =>
        open ? <div data-testid="sheet">{children}</div> : null,
    SheetContent: ({ children, className }: any) => (
        <div data-testid="sheet-content" className={className}>
            {children}
        </div>
    ),
    SheetHeader: ({ children, className }: any) => (
        <div data-testid="sheet-header" className={className}>
            {children}
        </div>
    ),
    SheetTitle: ({ children, className }: any) => (
        <h2 className={className}>{children}</h2>
    ),
    SheetClose: ({ children }: any) => <div>{children}</div>,
}))

describe('BaseDrawer', () => {
    it('renders nothing when closed', () => {
        render(
            <BaseDrawer open={false} onOpenChange={vi.fn()}>
                <div>content</div>
            </BaseDrawer>,
        )
        expect(screen.queryByText('content')).toBeNull()
    })

    it('renders children when open', () => {
        render(
            <BaseDrawer open={true} onOpenChange={vi.fn()}>
                <div>drawer content</div>
            </BaseDrawer>,
        )
        expect(screen.getByText('drawer content')).toBeDefined()
    })

    it('renders title when provided', () => {
        render(
            <BaseDrawer open={true} onOpenChange={vi.fn()} title="Details">
                <div>content</div>
            </BaseDrawer>,
        )
        expect(screen.getByText('Details')).toBeDefined()
    })

    it('renders header actions', () => {
        render(
            <BaseDrawer
                open={true}
                onOpenChange={vi.fn()}
                headerActions={<button>Edit</button>}
            >
                <div>content</div>
            </BaseDrawer>,
        )
        expect(screen.getByText('Edit')).toBeDefined()
    })

    it('renders divider when headerActions provided', () => {
        const { container } = render(
            <BaseDrawer
                open={true}
                onOpenChange={vi.fn()}
                headerActions={<button>Edit</button>}
            >
                <div>content</div>
            </BaseDrawer>,
        )
        const divider = container.querySelector('.bg-border')
        expect(divider).not.toBeNull()
    })

    it('does not render divider without headerActions', () => {
        const { container } = render(
            <BaseDrawer open={true} onOpenChange={vi.fn()}>
                <div>content</div>
            </BaseDrawer>,
        )
        const divider = container.querySelector('.bg-border')
        expect(divider).toBeNull()
    })

    it('applies contentClassName to sheet content', () => {
        render(
            <BaseDrawer
                open={true}
                onOpenChange={vi.fn()}
                contentClassName="custom-class"
            >
                <div>content</div>
            </BaseDrawer>,
        )
        const sheetContent = screen.getByTestId('sheet-content')
        expect(sheetContent.className).toContain('custom-class')
    })

    it('applies default maxWidth', () => {
        render(
            <BaseDrawer open={true} onOpenChange={vi.fn()}>
                <div>content</div>
            </BaseDrawer>,
        )
        const sheetContent = screen.getByTestId('sheet-content')
        expect(sheetContent.className).toContain('sm:max-w-lg')
    })

    it('applies custom maxWidth', () => {
        render(
            <BaseDrawer open={true} onOpenChange={vi.fn()} maxWidth="sm:max-w-2xl">
                <div>content</div>
            </BaseDrawer>,
        )
        const sheetContent = screen.getByTestId('sheet-content')
        expect(sheetContent.className).toContain('sm:max-w-2xl')
    })
})
