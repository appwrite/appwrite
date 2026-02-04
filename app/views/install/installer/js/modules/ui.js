(() => {
    const { TIMINGS } = window.InstallerStepsContext || {};
    const { formState } = window.InstallerStepsState || {};

    const clearFieldErrors = (root) => {
        if (!root) return;
        root.querySelectorAll('.field-error').forEach((node) => {
            node.classList.remove('is-visible');
        });
        root.querySelectorAll('.input-field.is-error, .input-action.is-error').forEach((node) => {
            node.classList.remove('is-error');
        });
        root.querySelectorAll('.field-helper').forEach((helper) => {
            helper.style.display = '';
        });
    };

    const setFieldError = (input, message) => {
        if (!input) return;
        const group = input.closest('.input-group');
        if (!group) return;
        let error = group.querySelector('.field-error');
        let errorText = error?.querySelector('.field-error-text');
        const hasSameMessage = Boolean(errorText && errorText.textContent === message);
        const alreadyVisible = Boolean(error && error.classList.contains('is-visible'));

        if (hasSameMessage && alreadyVisible) {
            return;
        }

        if (!error) {
            const template = document.getElementById('field-error-template');
            if (template && template.content) {
                const fragment = template.content.cloneNode(true);
                error = fragment.querySelector('.field-error');
                group.appendChild(fragment);
            }
            errorText = error?.querySelector('.field-error-text');
        }
        if (errorText) {
            errorText.textContent = message;
        }

        if (!alreadyVisible) {
            requestAnimationFrame(() => {
                error.classList.add('is-visible');
            });
        }

        input.classList.add('is-error');
        const actionWrapper = input.closest('.input-action');
        if (actionWrapper) {
            actionWrapper.classList.add('is-error');
        }
        const helper = group.querySelector('.field-helper');
        if (helper) {
            helper.style.display = 'none';
        }
    };

    const bindErrorClear = (input) => {
        if (!input) return;
        const handler = () => {
            const group = input.closest('.input-group');
            const error = group?.querySelector('.field-error');
            if (error) {
                error.classList.remove('is-visible');
            }
            input.classList.remove('is-error');
            const actionWrapper = input.closest('.input-action');
            if (actionWrapper) {
                actionWrapper.classList.remove('is-error');
            }
            const helper = group?.querySelector('.field-helper');
            if (helper) {
                helper.style.display = '';
            }
        };
        input.addEventListener('input', handler);
        input.addEventListener('change', handler);
    };

    const toDatabaseLabel = (value) => {
        if (!value) return '';
        return value.toLowerCase() === 'mariadb' ? 'MariaDB' : 'MongoDB';
    };

    const updateDatabaseSelection = (radio, root) => {
        if (!radio || !root) return;
        const allOptions = root.querySelectorAll('.selector-card');
        allOptions.forEach((option) => option.classList.remove('selected'));
        const selectedOption = radio.closest('.selector-card');
        if (selectedOption) {
            selectedOption.classList.add('selected');
        }
    };

    const syncResetButton = (input, button) => {
        const defaultValue = input.dataset.default ?? '';
        button.disabled = input.value === defaultValue;
    };

    const setupResetButtons = (root) => {
        const inputs = root.querySelectorAll('.input-field[data-default]');
        inputs.forEach((input) => {
            const button = root.querySelector(`[data-reset-target="${input.id}"]`);
            if (!button) return;

            syncResetButton(input, button);

            input.addEventListener('input', () => syncResetButton(input, button));
            button.addEventListener('click', () => {
                input.value = input.dataset.default ?? '';
                syncResetButton(input, button);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    };

    const toggleAccordion = (button) => {
        const content = button.nextElementSibling;
        const icon = button.querySelector('.accordion-chevron');
        const isOpen = button.classList.contains('is-open');

        button.classList.toggle('is-open', !isOpen);
        button.setAttribute('aria-expanded', String(!isOpen));

        if (content) {
            if (!isOpen) {
                content.classList.add('open');
                content.style.maxHeight = `${content.scrollHeight}px`;
            } else {
                content.style.maxHeight = '0px';
                content.classList.remove('open');
            }
        }

        if (icon) {
            icon.setAttribute('data-open', String(!isOpen));
        }
    };

    const setupAccordion = (root) => {
        const buttons = root.querySelectorAll('.accordion-toggle');
        buttons.forEach((button) => {
            button.addEventListener('click', () => toggleAccordion(button));
        });
    };

    const openAccordion = (root) => {
        const toggle = root.querySelector('.accordion-toggle');
        const content = root.querySelector('.accordion-content');
        if (!toggle || !content) return;
        if (!toggle.classList.contains('is-open')) {
            toggle.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            content.classList.add('open');
            content.style.maxHeight = `${content.scrollHeight}px`;
        }
    };

    const disableControls = (root) => {
        const inputs = root.querySelectorAll('input, select, textarea');
        inputs.forEach((input) => {
            if (input.type === 'radio' || input.type === 'checkbox') {
                input.disabled = true;
            } else {
                input.readOnly = true;
                input.setAttribute('aria-disabled', 'true');
            }
        });

        const buttons = root.querySelectorAll('button');
        buttons.forEach((button) => {
            if (button.matches('[data-copy-target]')) return;
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });

        root.classList.add('is-locked');
    };

    const generateSecretKey = () => {
        const array = new Uint8Array(32);
        window.crypto.getRandomValues(array);
        return Array.from(array, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const copyToClipboard = (value, input) => {
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value);
            return;
        }
        if (input) {
            input.select();
            document.execCommand('copy');
            input.setSelectionRange(0, 0);
            return;
        }
        const textArea = document.createElement('textarea');
        textArea.value = value;
        textArea.style.position = 'fixed';
        textArea.style.top = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
        } catch (error) {} finally {
            document.body.removeChild(textArea);
        }
    };

    const setTooltipText = (wrapper, message) => {
        if (!wrapper) return;
        const tooltip = wrapper.querySelector('.tooltip');
        if (tooltip && message) {
            tooltip.textContent = message;
        }
    };

    const resetTooltipText = (wrapper) => {
        if (!wrapper) return;
        const defaultText = wrapper.dataset.tooltipDefault;
        if (!defaultText) return;
        setTooltipText(wrapper, defaultText);
    };

    const updateReviewSummary = (root) => {
        if (!root) return;
        const valueNodes = root.querySelectorAll('[data-review-value]');
        valueNodes.forEach((node) => {
            const key = node.dataset.reviewValue;
            if (!key) return;
            let value = formState?.[key];
            if (key === 'database') {
                value = toDatabaseLabel(formState?.database);
            }
            if (value) {
                node.textContent = value;
            }
        });

        const badge = root.querySelector('[data-review-badge]');
        if (badge) {
            const hasKey = Boolean((formState?.opensslKey || '').trim());
            badge.textContent = hasKey ? 'Generated' : 'Missing';
            badge.classList.remove('badge-success', 'badge-warning');
            badge.classList.add(hasKey ? 'badge-success' : 'badge-warning');
        }

        const assistantBadge = root.querySelector('[data-review-assistant-badge]');
        if (assistantBadge) {
            const hasAssistantKey = Boolean((formState?.assistantOpenAIKey || '').trim());
            assistantBadge.textContent = hasAssistantKey ? 'Enabled' : 'Disabled';
            assistantBadge.classList.remove('badge-success', 'badge-neutral');
            assistantBadge.classList.add(hasAssistantKey ? 'badge-success' : 'badge-neutral');
        }
    };

    window.InstallerStepsUI = {
        clearFieldErrors,
        setFieldError,
        bindErrorClear,
        toDatabaseLabel,
        updateDatabaseSelection,
        setupResetButtons,
        setupAccordion,
        openAccordion,
        disableControls,
        generateSecretKey,
        copyToClipboard,
        setTooltipText,
        resetTooltipText,
        updateReviewSummary
    };
})();
