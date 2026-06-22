<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Support;

final class DirectoryPreparer
{
    /**
     * @param list<string> $directories
     */
    public function ensureAll(array $directories): void
    {
        foreach ($directories as $directory) {
            $this->ensure($directory);
        }
    }

    private function ensure(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppException(500, 'directory_create_failed', 'Failed to create application directory.', [
                'directory' => $directory,
            ]);
        }
    }
}
