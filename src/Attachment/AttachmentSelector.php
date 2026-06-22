<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Attachment;

use Ingreen\DaktelaPolicy\Support\AppException;

final class AttachmentSelector
{
    /**
     * @param list<AttachmentMetadata> $attachments
     */
    public function selectPolicyPdf(array $attachments): AttachmentMetadata
    {
        $candidates = array_values(array_filter(
            $attachments,
            static fn (AttachmentMetadata $attachment): bool => self::isPdfCandidate($attachment)
        ));

        if ($candidates === []) {
            throw new AppException(404, 'policy_pdf_not_found', 'No PDF attachment was found for the entity.');
        }

        usort($candidates, static function (AttachmentMetadata $left, AttachmentMetadata $right): int {
            $leftScore = self::score($left);
            $rightScore = self::score($right);

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            return strcmp($left->stableKey(), $right->stableKey());
        });

        return $candidates[0];
    }

    private static function isPdfCandidate(AttachmentMetadata $attachment): bool
    {
        return self::hasPdfType($attachment) || self::hasPdfExtension($attachment);
    }

    private static function score(AttachmentMetadata $attachment): int
    {
        $score = 0;

        if ($attachment->title !== null && preg_match('/policy|umowa|ubezpieczenie|insurance/i', $attachment->title) === 1) {
            $score += 15;
        }
        if ($attachment->title !== null && preg_match('/ubezp/i', $attachment->title) === 1) {
            $score += 10;
        }
        if ($attachment->title !== null && preg_match('/hestia|pzu|cumulus/i', $attachment->title) === 1) {
            $score += 5;
        }

        return $score;
    }

    private static function hasPdfType(AttachmentMetadata $attachment): bool
    {
        return $attachment->type !== null && str_contains(strtolower($attachment->type), 'pdf');
    }

    private static function hasPdfExtension(AttachmentMetadata $attachment): bool
    {
        return preg_match('/\.pdf(?:$|[?#])/i', $attachment->file) === 1
            || ($attachment->title !== null && preg_match('/\.pdf$/i', $attachment->title) === 1);
    }
}
