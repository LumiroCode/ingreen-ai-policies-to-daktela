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
    <?php
        require __DIR__ . '/pdf-attachments-table.php';
        require __DIR__ . '/feedback-message.php';
    ?>
</body>
</html>
