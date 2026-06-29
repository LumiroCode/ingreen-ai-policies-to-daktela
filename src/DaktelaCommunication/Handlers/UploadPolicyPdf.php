<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use CURLFile;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;
use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class UploadPolicyPdf
{
    public function __construct(private readonly DaktelaCommunicationService $communicationService)
    {
    }

    public function execute(PolicyPdf $policyPdf): string
    {
        $responseBody = $this->communicationService->postMultipartFileRaw(
            '/file/upload.php',
            ['type' => 'save'],
            'files',
            new CURLFile($policyPdf->path, $policyPdf->mimeType, $policyPdf->title),
            'daktela_policy_attachment_upload_failed'
        );
        $uploadedName = json_decode($responseBody, true);

        if (!is_string($uploadedName)
            || trim($uploadedName) === ''
            || strlen($uploadedName) > 255
            || preg_match('/^[A-Za-z0-9._-]+$/D', $uploadedName) !== 1
        ) {
            throw new AppException(
                502,
                'invalid_daktela_attachment_upload_response',
                'Daktela returned an invalid file upload response.',
                ['path' => '/file/upload.php']
            );
        }

        return trim($uploadedName);
    }
}
