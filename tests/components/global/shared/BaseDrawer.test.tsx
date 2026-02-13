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

    it('renders open state content and header elements', () => {
        const { container } = render(
            <BaseDrawer
                open={true}
                onOpenChange={vi.fn()}
                title="Details"
                headerActions={<button>Edit</button>}
                contentClassName="custom-class"
                maxWidth="sm:max-w-2xl"
            >
                <div>drawer content</div>
            </BaseDrawer>,
        )
        expect(screen.getByText('drawer content')).toBeDefined()
        expect(screen.getByText('Details')).toBeDefined()
        expect(screen.getByText('Edit')).toBeDefined()
        const divider = container.querySelector('.bg-border')
        expect(divider).not.toBeNull()
        const sheetContent = screen.getByTestId('sheet-content')
        expect(sheetContent.className).toContain('custom-class')
        expect(sheetContent.className).toContain('sm:max-w-2xl')
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

    it('applies default maxWidth', () => {
        render(
            <BaseDrawer open={true} onOpenChange={vi.fn()}>
                <div>content</div>
            </BaseDrawer>,
        )
        const sheetContent = screen.getByTestId('sheet-content')
        expect(sheetContent.className).toContain('sm:max-w-lg')
    })
})
