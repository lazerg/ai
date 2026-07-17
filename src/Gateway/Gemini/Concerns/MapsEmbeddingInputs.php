<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;

trait MapsEmbeddingInputs
{
    /**
     * Normalize model names accepted by the Gemini API.
     */
    protected function normalizeEmbeddingModel(string $model): string
    {
        return Str::chopStart($model, 'models/');
    }

    /**
     * Map a Laravel embeddings input to a Gemini content part.
     */
    protected function mapEmbeddingInput(mixed $input): array
    {
        return match (true) {
            is_string($input) => ['text' => $input],
            $input instanceof RemoteImage,
            $input instanceof RemoteDocument,
            $input instanceof RemoteAudio => [
                'inlineData' => [
                    'mimeType' => $input->mimeType() ?: 'application/octet-stream',
                    'data' => base64_encode($input->content()),
                ],
            ],
            default => $this->mapAttachment($input),
        };
    }
}
