(() => {
    const tooltipPortals = new Set();

    const positionTooltipPortal = (tooltip, anchor) => {
        if (!tooltip || !anchor) return;
        const rect = anchor.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const offset = Number(tooltip.dataset.tooltipOffset || 6);
        const padding = 8;
        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
        left = Math.max(padding, Math.min(left, window.innerWidth - tooltipRect.width - padding));
        const top = rect.bottom + offset;
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    };

    const attachTooltipPortal = (tooltip) => {
        if (!tooltip || tooltip.dataset.portalInitialized === 'true') return;
        const anchor = tooltip.parentElement;
        if (!anchor) return;

        tooltip.dataset.portalInitialized = 'true';
        tooltip.classList.add('tooltip-portal');
        document.body.appendChild(tooltip);

        const show = () => {
            tooltip.classList.add('is-open');
            positionTooltipPortal(tooltip, anchor);
        };
        const hide = () => {
            tooltip.classList.remove('is-open');
        };
        const refresh = () => {
            if (tooltip.classList.contains('is-open')) {
                positionTooltipPortal(tooltip, anchor);
            }
        };

        anchor.addEventListener('mouseenter', show);
        anchor.addEventListener('mouseleave', hide);
        anchor.addEventListener('focusin', show);
        anchor.addEventListener('focusout', hide);
        window.addEventListener('scroll', refresh, true);
        window.addEventListener('resize', refresh);

        tooltipPortals.add({
            tooltip,
            cleanup: () => {
                anchor.removeEventListener('mouseenter', show);
                anchor.removeEventListener('mouseleave', hide);
                anchor.removeEventListener('focusin', show);
                anchor.removeEventListener('focusout', hide);
                window.removeEventListener('scroll', refresh, true);
                window.removeEventListener('resize', refresh);
                if (tooltip.parentElement) {
                    tooltip.parentElement.removeChild(tooltip);
                }
            }
        });
    };

    const setupTooltipPortals = (root) => {
        if (!root) return;
        const portalTooltips = root.querySelectorAll('.tooltip[data-tooltip-portal]');
        portalTooltips.forEach((tooltip) => attachTooltipPortal(tooltip));
    };

    const cleanupTooltipPortals = () => {
        tooltipPortals.forEach((entry) => entry.cleanup());
        tooltipPortals.clear();
    };

    window.InstallerTooltips = {
        setupTooltipPortals,
        cleanupTooltipPortals
    };
})();
