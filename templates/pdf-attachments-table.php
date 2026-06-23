<?php

declare(strict_types=1);

/**
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
 * @var string $accessToken
 * @var string $ticketId
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
</body>
</html>
