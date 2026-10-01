<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Contracts;

interface AiTranslator
{
    /** @param string[] $targets @return array<string,string> */
    public function translate(string $text, string $source, array $targets): array;

    /**
     * @param array<string,string> $items
     * @param string[] $targets
     * @return array<string,array<string,string>>
     */
    public function translateBatch(array $items, string $source, array $targets): array;
}
