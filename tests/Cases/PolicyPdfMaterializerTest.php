<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';
require_once __DIR__ . '/../Utils/Helpers.php';
require_once __DIR__ . '/../Fakes/FakeDaktela.php';
require_once __DIR__ . '/../Fakes/NullLogger.php';

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdfMaterializer;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;

test('policy PDF factory calculates file metadata and normalizes its title', function (): void {
    $dir = tempDir();
    $path = $dir . '/source.pdf';
    $body = "%PDF-1.4\nbody";
    file_put_contents($path, $body);

    $pdf = PolicyPdf::fromFile($path, "folder\\Polisa ą\n2026");

    assertSameValue($path, $pdf->path);
    assertSameValue('Polisa ą_2026.pdf', $pdf->title);
    assertSameValue('application/pdf', $pdf->mimeType);
    assertSameValue(strlen($body), $pdf->size);
    assertSameValue('Polisa__2026.pdf', $pdf->downloadFilename());
});


test('policy PDF factory rejects missing and empty files', function (): void {
    $dir = tempDir();
    $emptyPath = $dir . '/empty.pdf';
    $unreadablePath = $dir . '/unreadable.pdf';
    file_put_contents($emptyPath, '');
    file_put_contents($unreadablePath, "%PDF-1.4\nbody");
    chmod($unreadablePath, 0000);

    foreach ([$dir . '/missing.pdf', $emptyPath, $unreadablePath] as $path) {
        try {
            PolicyPdf::fromFile($path, 'policy.pdf');
        } catch (AppException $exception) {
            assertSameValue(500, $exception->statusCode());
            assertSameValue('policy_pdf_not_readable', $exception->errorCode());
            continue;
        }

        throw new RuntimeException('Expected exception.');
    }

    chmod($unreadablePath, 0644);
});


test('policy PDF materializer reuses cache and fresh download replaces it', function (): void {
    $dir = tempDir();
    $downloadCount = 0;
    $fake = new FakeDaktela([
        '/files/scan.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;

            return pdfResponse("%PDF-1.4\ndownload-" . $downloadCount);
        },
    ]);
    $logger = new NullLogger();
    $daktela = new DaktelaModule('https://daktela.example', 'token', $fake, $logger);
    $attachments = new TicketPdfAttachments($daktela, $logger);
    $materializer = new PolicyPdfMaterializer($attachments, $dir . '/var', 1_000_000);
    $attachment = [
        'file' => '/files/scan.pdf',
        'title' => 'Polisa ą.pdf',
        'type' => 'application/pdf',
    ];

    $first = $materializer->cachedOrDownload($attachment, '0');
    $cached = $materializer->cachedOrDownload($attachment, '0');
    $fresh = $materializer->downloadFresh($attachment, '0');

    assertSameValue(2, $downloadCount);
    assertSameValue($first->path, $cached->path);
    assertSameValue($cached->path, $fresh->path);
    assertSameValue('Polisa ą.pdf', $fresh->title);
    assertSameValue("%PDF-1.4\ndownload-2", $materializer->contents($fresh));
});


test('policy PDF materializer rejects a downloaded non-PDF', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/files/not-pdf.txt' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => 'not a PDF',
        ],
    ]);
    $logger = new NullLogger();
    $daktela = new DaktelaModule('https://daktela.example', 'token', $fake, $logger);
    $materializer = new PolicyPdfMaterializer(
        new TicketPdfAttachments($daktela, $logger),
        $dir . '/var',
        1_000_000
    );

    try {
        $materializer->downloadFresh([
            'file' => '/files/not-pdf.txt',
            'title' => 'not-pdf.txt',
            'type' => 'text/plain',
        ], '0');
    } catch (AppException $exception) {
        assertSameValue(422, $exception->statusCode());
        assertSameValue('attachment_is_not_pdf', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});
