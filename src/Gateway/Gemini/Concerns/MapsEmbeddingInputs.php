<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;

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
     * Map a Laravel embeddings input to a Gemini content part, downloading and inlining remote media.
     */
    protected function mapEmbeddingInput(mixed $input): array
    {
        return match (true) {
            is_string($input) => ['text' => $input],
            $input instanceof RemoteVideo => $this->isYouTubeUrl($input->url)
                ? $this->mapAttachment($input)
                : $this->inlineRemoteEmbeddingInput($input),
            $input instanceof RemoteImage,
            $input instanceof RemoteDocument,
            $input instanceof RemoteAudio => $this->inlineRemoteEmbeddingInput($input),
            default => $this->mapAttachment($input),
        };
    }

    /**
     * Map a remote embeddings input to an inline Gemini content part.
     */
    protected function inlineRemoteEmbeddingInput(RemoteAudio|RemoteDocument|RemoteImage|RemoteVideo $input): array
    {
        return [
            'inlineData' => [
                'mimeType' => $input->mimeType() ?: 'application/octet-stream',
                'data' => base64_encode($input->content()),
            ],
        ];
    }

    /**
     * Determine if the given URL is a YouTube URL, which Gemini accepts as a file URI.
     */
    protected function isYouTubeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'youtu.be' || $host === 'youtube.com' || str_ends_with($host, '.youtube.com');
    }
}
