(() => {
    const TOAST_STACK_ID = 'installer-toast-stack';
    const DEFAULT_TIMEOUT = 5000;
    const MAX_TOASTS = 3;
    const ICONS = {
        error: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0m-7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-1-9a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1" clip-rule="evenodd"/></svg>',
        close: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M5.293 5.293a1 1 0 0 1 1.414 0L10 8.586l3.293-3.293a1 1 0 1 1 1.414 1.414L11.414 10l3.293 3.293a1 1 0 0 1-1.414 1.414L10 11.414l-3.293 3.293a1 1 0 0 1-1.414-1.414L8.586 10 5.293 6.707a1 1 0 0 1 0-1.414" clip-rule="evenodd"/></svg>'
    };

    const getStack = () => document.getElementById(TOAST_STACK_ID);

    const dismissToast = (toast) => {
        if (!toast) return;
        if (toast.classList.contains('is-leaving')) return;
        toast.classList.add('is-leaving');
        const remove = () => toast.remove();
        toast.addEventListener('transitionend', remove, { once: true });
        setTimeout(remove, 450);
    };

    const showToast = ({
        title = '',
        description = '',
        status = 'error',
        dismissible = true,
        timeout = DEFAULT_TIMEOUT
    } = {}) => {
        const stack = getStack();
        if (!stack) return;
        const visibleToasts = Array.from(
            stack.querySelectorAll('.installer-toast:not(.is-leaving)')
        );
        if (visibleToasts.length >= MAX_TOASTS) {
            dismissToast(visibleToasts[0]);
        }

        const toast = document.createElement('div');
        toast.className = 'installer-toast is-entering';
        toast.dataset.status = status;
        toast.setAttribute('role', status === 'error' ? 'alert' : 'status');

        const content = document.createElement('div');
        content.className = 'installer-toast-content';

        const icon = document.createElement('span');
        icon.className = 'installer-toast-icon';
        icon.dataset.status = status;
        icon.innerHTML = ICONS.error;
        content.appendChild(icon);

        const body = document.createElement('section');
        body.className = 'installer-toast-body';

        if (title) {
            const titleNode = document.createElement('p');
            titleNode.className = 'installer-toast-title typography-text-m-500';
            titleNode.textContent = title;
            body.appendChild(titleNode);
        }

        if (description) {
            const descNode = document.createElement('p');
            descNode.className = 'installer-toast-description typography-text-m-400';
            descNode.textContent = description;
            body.appendChild(descNode);
        }

        content.appendChild(body);
        toast.appendChild(content);

        if (dismissible) {
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'installer-toast-close';
            close.setAttribute('aria-label', 'Dismiss notification');
            close.innerHTML = ICONS.close;
            close.addEventListener('click', () => dismissToast(toast));
            toast.appendChild(close);
        }

        stack.appendChild(toast);
        toast.getBoundingClientRect();
        requestAnimationFrame(() => {
            toast.classList.remove('is-entering');
        });

        if (timeout > 0) {
            setTimeout(() => dismissToast(toast), timeout);
        }
    };

    window.InstallerToast = Object.freeze({
        showToast
    });
})();
