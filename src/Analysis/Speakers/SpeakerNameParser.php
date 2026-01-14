<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

class SpeakerNameParser
{
    public function parse(string $text): ?string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $text);
        $value = preg_replace('/[^A-Za-z0-9 .\'-]/', ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '') {
            return null;
        }

        $value = strtr($value, ['0' => 'O', '1' => 'I']);
        $value = $this->stripLeadingTitles($value);
        $value = trim(preg_replace('/\s+/', ' ', $value));

        if ($value === '') {
            return null;
        }

        if (!preg_match('/[A-Za-z]/', $value)) {
            return null;
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $value);
        if ($letters === '' || strlen($letters) < 3) {
            return null;
        }

        return $value;
    }

    private function stripLeadingTitles(string $value): string
    {
        $pattern = '/^(delegate|del\.?|senator|sen\.?|chair|rep\.?)\s+/i';
        while (preg_match($pattern, $value)) {
            $value = preg_replace($pattern, '', $value);
        }
        return $value;
    }
}
