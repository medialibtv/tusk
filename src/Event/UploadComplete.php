<?php

namespace Tusk\Event;

final class UploadComplete
{
    private string $fileId;
    private int $length;
    private string $metadata;
    private array $headers;

    public function __construct(string $fileId, int $length, string $metadata, array $headers)
    {
        $this->fileId = $fileId;
        $this->metadata = $metadata;
        $this->headers = $headers;
        $this->length = $length;
    }

    public function fileId(): string
    {
        return $this->fileId;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function metadata(): string
    {
        return $this->metadata;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
