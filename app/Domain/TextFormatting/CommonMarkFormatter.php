<?php

namespace App\Domain\TextFormatting;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * CommonMark with the GFM extensions Redmine 7.0.1 enables:
 * tables, strikethrough, autolinks, task lists, and tag filtering.
 */
final class CommonMarkFormatter
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => true,
            'max_nesting_level' => 100,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $this->converter = new MarkdownConverter($environment);
    }

    public function convert(string $text): string
    {
        return trim($this->converter->convert($text)->getContent());
    }
}
