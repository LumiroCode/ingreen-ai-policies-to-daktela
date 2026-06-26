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

    const attachmentActionButtons = Array.from(
        document.querySelectorAll('.attachment-read-form button[type="submit"], .attachment-refresh-form button[type="submit"]')
    );

    document.querySelectorAll('.attachment-read-form, .attachment-refresh-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            const loadingLabel = form.dataset.loadingLabel || 'Odczytuję...';
            const showProcessingMessage = form.dataset.showProcessingMessage !== 'false';

            if (form.classList.contains('attachment-read-form')) {
                attachmentActionButtons.forEach((actionButton) => {
                    actionButton.disabled = true;
                });
            }

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
        const systemValueButtons = Array.from(form.querySelectorAll('.policy-apply-system-value'));
        const restoreAiValueButtons = Array.from(form.querySelectorAll('.policy-restore-ai-value'));
        const clearValueButtons = Array.from(form.querySelectorAll('.policy-clear-value'));

        const applyInputValue = (input, value) => {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        };

        const parseVehicleValueAmount = (value) => {
            const trimmedValue = value.trim();

            if (trimmedValue === '') {
                return null;
            }

            const matchedAmount = trimmedValue.match(/[-+]?\d(?:[\d\s.,\u00a0]*\d)?/u);

            if (matchedAmount === null) {
                return null;
            }

            let amount = matchedAmount[0].replace(/[\s\u00a0]+/gu, '');
            const commaPosition = amount.lastIndexOf(',');
            const dotPosition = amount.lastIndexOf('.');
            let decimalSeparator = null;

            if (commaPosition !== -1 && dotPosition !== -1) {
                decimalSeparator = commaPosition > dotPosition ? ',' : '.';
            } else if (commaPosition !== -1 && amount.length - commaPosition <= 3) {
                decimalSeparator = ',';
            } else if (dotPosition !== -1 && amount.length - dotPosition <= 3) {
                decimalSeparator = '.';
            }

            if (decimalSeparator !== null) {
                const thousandsSeparator = decimalSeparator === ',' ? '.' : ',';
                amount = amount.replaceAll(thousandsSeparator, '').replace(decimalSeparator, '.');
            } else {
                amount = amount.replace(/[,.]/gu, '');
            }

            const parsedAmount = Number(amount);

            if (!Number.isFinite(parsedAmount)) {
                return null;
            }

            return parsedAmount;
        };

        const formatVehicleValue = (value, sourceValue) => {
            const roundedValue = Math.round(value * 100) / 100;
            const fractionDigits = Math.round(roundedValue * 100) % 100 === 0 ? 0 : 2;
            const formattedValue = roundedValue.toLocaleString('pl-PL', {
                minimumFractionDigits: fractionDigits,
                maximumFractionDigits: fractionDigits,
            });

            return formattedValue + (/(?:PLN|zł)/iu.test(sourceValue.trim()) ? ' PLN' : '');
        };

        const formatGrossValueFromNet = (value) => {
            const parsedAmount = parseVehicleValueAmount(value);

            if (parsedAmount === null) {
                return '';
            }

            return formatVehicleValue(parsedAmount * 1.23, value);
        };

        const formatNetValueFromGross = (value) => {
            const parsedAmount = parseVehicleValueAmount(value);

            if (parsedAmount === null) {
                return '';
            }

            return formatVehicleValue(parsedAmount / 1.23, value);
        };

        const syncCalculatedVehicleValueDescriptions = (selector, formatter) => {
            form.querySelectorAll(selector).forEach((description) => {
                const field = description.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');
                const value = description.querySelector('span');

                if (input === null || value === null) {
                    return;
                }

                const syncCalculatedValue = () => {
                    value.textContent = formatter(input.value);
                };

                input.addEventListener('input', syncCalculatedValue);
                syncCalculatedValue();
            });
        };

        syncCalculatedVehicleValueDescriptions('[data-policy-gross-net-value]', formatNetValueFromGross);
        syncCalculatedVehicleValueDescriptions('[data-policy-net-gross-value]', formatGrossValueFromNet);

        const sync = () => {
            const allLocked = locks.length > 0 && locks.every((checkbox) => checkbox.checked);
            const requiredLocks = locks.filter((checkbox) => {
                const field = checkbox.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');

                return input !== null && input.value.trim() !== '';
            });
            const allRequiredLocked = requiredLocks.every((checkbox) => checkbox.checked);
            const someLocked = locks.some((checkbox) => checkbox.checked);

            locks.forEach((checkbox) => {
                const field = checkbox.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');
                const valueButtons = field === null
                    ? []
                    : Array.from(field.querySelectorAll('.policy-input-action, .policy-apply-system-value'));

                if (field !== null) {
                    field.classList.toggle('locked', checkbox.checked);
                }

                if (input !== null) {
                    input.readOnly = checkbox.checked;
                }

                valueButtons.forEach((button) => {
                    button.disabled = checkbox.checked;
                });
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
                saveButton.disabled = true;
            }

            if (retryButton !== null) {
                retryButton.disabled = allRequiredLocked;
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

        form.querySelectorAll('.policy-input').forEach((input) => {
            input.addEventListener('input', () => {
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

        systemValueButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const field = button.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');
                const value = button.dataset.policyApplyValue;

                if (input !== null && value !== undefined) {
                    applyInputValue(input, value);
                }
            });
        });

        restoreAiValueButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const control = button.closest('.policy-input-control');
                const input = control === null ? null : control.querySelector('.policy-input');
                const value = button.dataset.policyAiValue;

                if (input !== null && value !== undefined) {
                    applyInputValue(input, value);
                }
            });
        });

        clearValueButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const control = button.closest('.policy-input-control');
                const input = control === null ? null : control.querySelector('.policy-input');

                if (input !== null) {
                    applyInputValue(input, '');
                }
            });
        });

        form.addEventListener('submit', (event) => {
            const requiredLocks = locks.filter((checkbox) => {
                const field = checkbox.closest('.policy-field');
                const input = field === null ? null : field.querySelector('.policy-input');

                return input !== null && input.value.trim() !== '';
            });
            const allRequiredLocked = requiredLocks.every((checkbox) => checkbox.checked);
            const confirmation = event.submitter instanceof HTMLButtonElement
                ? event.submitter.value
                : (allRequiredLocked ? 'yes' : 'no');
            let message = '';

            if (confirmation === 'yes' && !allRequiredLocked) {
                message = 'Aby potwierdzić poprawność danych, zaznacz wszystkie niepuste pola jako poprawne.';
            }

            if (confirmation === 'no' && allRequiredLocked) {
                message = 'Nie można zgłosić niepoprawnych danych, gdy wszystkie niepuste pola są oznaczone jako poprawne.';
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
