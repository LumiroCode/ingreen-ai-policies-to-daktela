document.addEventListener('DOMContentLoaded', () => {
    const processingMessage = document.getElementById('processing-message');

    document.querySelectorAll('.attachment-read-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            const loadingLabel = form.dataset.loadingLabel || 'Odczytuję...';

            if (button !== null) {
                button.disabled = true;
                button.textContent = loadingLabel;
            }

            if (processingMessage !== null) {
                processingMessage.hidden = false;
            }
        });
    });

    document.querySelectorAll('.policy-review-form').forEach((form) => {
        const feedback = form.querySelector('.policy-review-feedback');
        const locks = Array.from(form.querySelectorAll('.policy-review-lock'));
        const saveButton = form.querySelector('button[name="confirmation"][value="yes"]');
        const retryButton = form.querySelector('button[name="confirmation"][value="no"]');

        const sync = () => {
            const allLocked = locks.length > 0 && locks.every((checkbox) => checkbox.checked);

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
