<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\UploadsDocuments;
use RuntimeException;

trait UploadsStoreDocuments
{
    use DispatchesFileAdditions;

    /**
     * Upload a document's contents directly into a vector store.
     */
    public function uploadDocumentToStore(string $storeId, StorableFile $file, array $metadata = []): string
    {
        $gateway = $this->storeGateway();

        if (! $gateway instanceof UploadsDocuments) {
            throw new RuntimeException('The provider\'s store gateway does not support direct document uploads.');
        }

        // Direct content uploads have no separate provider file, so no file ID exists yet...
        return $this->dispatchFileAddition(
            $storeId, null,
            fn () => $gateway->uploadDocument($this, $storeId, $file, $metadata),
        );
    }
}
