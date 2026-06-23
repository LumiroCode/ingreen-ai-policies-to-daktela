<?php

declare(strict_types=1);

/**
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
 * @var string $accessToken
 * @var string $ticketId
 * @var array{type:string,text:string}|null $message
 */

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Załączniki PDF</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
        }

        table {
            border-collapse: collapse;
            min-width: 560px;
        }

        th,
        td {
            border: 1px solid #d0d0d0;
            padding: 8px 10px;
            text-align: left;
        }

        th {
            background: #f4f4f4;
        }

        button {
            cursor: default;
            padding: 6px 12px;
        }

        button[disabled] {
            opacity: 0.7;
        }

        .message {
            margin-top: 12px;
            padding: 10px 12px;
            max-width: 560px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .message.success {
            background: #edf7ed;
            border: 1px solid #8bc58b;
            color: #1f5b1f;
        }

        .message.error {
            background: #fdecec;
            border: 1px solid #d98a8a;
            color: #7a1f1f;
        }

        .message.processing {
            align-items: center;
            background: #eef4ff;
            border: 1px solid #8aa9d9;
            color: #1f3f70;
            display: flex;
            gap: 8px;
        }

        .message[hidden] {
            display: none;
        }

        .spinner {
            animation: spin 0.8s linear infinite;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            display: inline-block;
            height: 14px;
            width: 14px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>Nr</th>
                <th>Nazwa</th>
                <th>Akcja</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($attachments === []): ?>
                <tr>
                    <td colspan="3">Brak załączników PDF.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($attachments as $index => $attachment): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($attachment['title'] ?? basename($attachment['file']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form method="get">
                            <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="attachment" value="<?= $index ?>">
                            <input type="hidden" name="access_token" value="<?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit">odczytaj</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($message !== null): ?>
        <div class="message <?= htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <div id="processing-message" class="message processing" hidden role="status" aria-live="polite">
        <span class="spinner" aria-hidden="true"></span>
        <span>Trwa odczyt danych z polisy. Proszę czekać...</span>
    </div>
    <script>
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                var message = document.getElementById('processing-message');

                if (button !== null) {
                    button.disabled = true;
                    button.textContent = 'odczytuję...';
                }

                if (message !== null) {
                    message.hidden = false;
                }
            });
        });
    </script>
</body>
</html>
