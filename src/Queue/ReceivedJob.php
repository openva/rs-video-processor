<?php

namespace RichmondSunlight\VideoProcessor\Queue;

class ReceivedJob
{
    public function __construct(
        public string $receiptHandle,
        public JobPayload $payload
    ) {
    }
}
