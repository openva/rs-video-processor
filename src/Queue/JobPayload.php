<?php

namespace RichmondSunlight\VideoProcessor\Queue;

class JobPayload
{
    public function __construct(
        public string $type,
        public int $fileId,
        public array $context = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'file_id' => $this->fileId,
            'context' => $this->context,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['type'], $data['file_id'])) {
            throw new \InvalidArgumentException('Malformed job payload.');
        }

        $context = is_array($data['context'] ?? null) ? $data['context'] : [];

        return new self(
            (string) $data['type'],
            (int) $data['file_id'],
            $context
        );
    }
}
