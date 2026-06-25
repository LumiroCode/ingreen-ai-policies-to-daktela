document.addEventListener('DOMContentLoaded', () => {
    const processingMessage = document.getElementById('processing-message');

    const closeToast = (toast) => {
        toast.hidden = true;
    };

    document.querySelectorAll('.toast').forEach((toast) => {
        const closeButton = toast.querySelector('.toast-close');

        if (closeButton !== null) {
            closeButton.addEventListener('click', () => closeToast(toast));
        }
    });

    document.querySelectorAll('section.panel .panel-toggle').forEach((toggle) => {
        const contentId = toggle.getAttribute('aria-controls');
        const content = contentId === null ? null : document.getElementById(contentId);
        const panel = toggle.closest('section.panel');

        if (content === null || panel === null) {
            return;
        }

        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') !== 'false';

            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            content.hidden = expanded;
            panel.classList.toggle('collapsed', expanded);
        });
    });

    document.querySelectorAll('.attachment-read-form, .attachment-refresh-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            const loadingLabel = form.dataset.loadingLabel || 'Odczytuję...';
            const showProcessingMessage = form.dataset.showProcessingMessage !== 'false';

            if (button !== null) {
                button.disabled = true;
                button.textContent = loadingLabel;
            }

            if (processingMessage !== null && showProcessingMessage) {
                processingMessage.hidden = false;
            }
        });
    });

    document.querySelectorAll('.policy-review-form').forEach((form) => {
        const feedback = form.querySelector('.policy-review-feedback');
        const locks = Array.from(form.querySelectorAll('.policy-review-lock'));
        const lockAll = form.querySelector('.policy-review-lock-all');
        const groupLocks = Array.from(form.querySelectorAll('.policy-review-lock-group'));
        const saveButton = form.querySelector('button[name="confirmation"][value="yes"]');
        const retryButton = form.querySelector('button[name="confirmation"][value="no"]');

        const sync = () => {
            const allLocked = locks.length > 0 && locks.every((checkbox) => checkbox.checked);
            const someLocked = locks.some((checkbox) => checkbox.checked);

            locks.forEach((checkbox) => {
                const field = checkbox.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');

                if (field !== null) {
                    field.classList.toggle('locked', checkbox.checked);
                }

                if (input !== null) {
                    input.readOnly = checkbox.checked;
                }
            });

            if (lockAll !== null) {
                lockAll.checked = allLocked;
                lockAll.indeterminate = someLocked && !allLocked;
            }

            groupLocks.forEach((groupLock) => {
                const group = groupLock.closest('.policy-field-group');
                const groupFieldLocks = group === null
                    ? []
                    : Array.from(group.querySelectorAll('.policy-review-lock'));
                const allGroupLocked = groupFieldLocks.length > 0 && groupFieldLocks.every((checkbox) => checkbox.checked);
                const someGroupLocked = groupFieldLocks.some((checkbox) => checkbox.checked);

                groupLock.checked = allGroupLocked;
                groupLock.indeterminate = someGroupLocked && !allGroupLocked;
            });

            if (saveButton !== null) {
                saveButton.disabled = !allLocked;
            }

            if (retryButton !== null) {
                retryButton.disabled = allLocked;
            }
        };

        locks.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (feedback !== null) {
                    feedback.hidden = true;
                    feedback.textContent = '';
                }

                sync();
            });
        });

        if (lockAll !== null) {
            lockAll.addEventListener('change', () => {
                if (feedback !== null) {
                    feedback.hidden = true;
                    feedback.textContent = '';
                }

                locks.forEach((checkbox) => {
                    checkbox.checked = lockAll.checked;
                });

                sync();
            });
        }

        groupLocks.forEach((groupLock) => {
            groupLock.addEventListener('change', () => {
                if (feedback !== null) {
                    feedback.hidden = true;
                    feedback.textContent = '';
                }

                const group = groupLock.closest('.policy-field-group');
                const groupFieldLocks = group === null
                    ? []
                    : Array.from(group.querySelectorAll('.policy-review-lock'));

                groupFieldLocks.forEach((checkbox) => {
                    checkbox.checked = groupLock.checked;
                });

                sync();
            });
        });

        form.addEventListener('submit', (event) => {
            const allLocked = locks.length > 0 && locks.every((checkbox) => checkbox.checked);
            const confirmation = event.submitter instanceof HTMLButtonElement
                ? event.submitter.value
                : (allLocked ? 'yes' : 'no');
            let message = '';

            if (confirmation === 'yes' && !allLocked) {
                message = 'Aby potwierdzić poprawność danych, zaznacz wszystkie pola jako poprawne.';
            }

            if (confirmation === 'no' && allLocked) {
                message = 'Nie można zgłosić niepoprawnych danych, gdy wszystkie pola są oznaczone jako poprawne.';
            }

            if (message !== '') {
                event.preventDefault();

                if (feedback !== null) {
                    feedback.textContent = message;
                    feedback.hidden = false;
                }

                return;
            }

            if (processingMessage !== null && confirmation === 'no') {
                processingMessage.hidden = false;
            }
        });

        sync();
    });
});
