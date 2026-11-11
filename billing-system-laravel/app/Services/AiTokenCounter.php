<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Counts command text with the same cl100k_base encoding configured by
 * Spring AI's no-argument JTokkitTokenCountEstimator.
 */
final class AiTokenCounter
{
    /** @var list<string> */
    private const SPRING_JTOKKIT_SPECIAL_TOKENS = [
        '<|endoftext|>',
        '<|fim_prefix|>',
        '<|fim_middle|>',
        '<|fim_suffix|>',
        '<|endofprompt|>',
    ];

    private ?object $encoder = null;

    public function count(string $text): int
    {
        // JTokkit's cl100k special encoder rejects these spellings instead of
        // counting them as ordinary text. yethee/tiktoken intentionally does
        // not implement special tokens, so preserve that source behavior here.
        if (str_contains($text, '<|') && str_contains($text, '|>')) {
            foreach (self::SPRING_JTOKKIT_SPECIAL_TOKENS as $specialToken) {
                if (str_contains($text, $specialToken)) {
                    throw new RuntimeException('Encoding special tokens is not supported.');
                }
            }
        }

        if ($this->encoder === null) {
            $provider = new EncoderProvider();
            $provider->setVocabCache(dirname(__DIR__, 2).'/resources/tiktoken');
            $this->encoder = $provider->get('cl100k_base');
        }

        return count($this->encoder->encode($text));
    }

    public function assertWithinLimit(string $text, int $maximum): void
    {
        if ($maximum <= 0) {
            throw new InvalidArgumentException('Maximum input tokens must be greater than zero');
        }

        if ($this->count($text) > $maximum) {
            throw new InvalidArgumentException("Command exceeds the configured limit of {$maximum} input tokens");
        }
    }
}
