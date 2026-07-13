<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\FileAddedToStore;

trait DispatchesFileAdditions
{
    /**
     * Dispatch the add-to-store lifecycle events around the given upload.
     *
     * The identifier is the provider file ID for pre-uploaded files, or null
     * for direct content uploads that have no separate provider file.
     */
    protected function dispatchFileAddition(string $storeId, ?string $identifier, Closure $upload): string
    {
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(new AddingFileToStore(
            $invocationId, $this, $storeId, $identifier
        ));

        return tap($upload(), function (string $documentId) use ($invocationId, $storeId, $identifier) {
            $this->events->dispatch(new FileAddedToStore(
                $invocationId, $this, $storeId, $identifier, $documentId,
            ));
        });
    }
}
