<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class TelegramMessageFormatter
{
    public function toHtml(string $markdown): string
    {
        $commonMarkHtml = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousLibxmlState = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="telegram-root">'.$commonMarkHtml.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
        $root = $document->getElementById('telegram-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        return trim((string) preg_replace(
            "/\n{3,}/u",
            "\n\n",
            $this->renderChildren($root),
        ));
    }

    public function plainText(string $markdown): string
    {
        return trim(html_entity_decode(
            strip_tags($this->toHtml($markdown)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    public function length(string $markdown): int
    {
        return Str::length($this->plainText($markdown));
    }

    /** @return list<string> */
    public function chunks(string $markdown, int $limit = 4096): array
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return [];
        }

        if ($this->length($markdown) <= $limit) {
            return [$this->toHtml($markdown)];
        }

        $paragraphs = preg_split("/\n{2,}/u", $markdown) ?: [$markdown];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            if ($this->length($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $this->toHtml($current);
                $current = '';
            }

            if ($this->length($paragraph) <= $limit) {
                $current = $paragraph;

                continue;
            }

            $plainParagraph = $this->plainText($paragraph);

            while ($plainParagraph !== '') {
                $plainChunk = mb_substr($plainParagraph, 0, $limit);
                $chunks[] = htmlspecialchars($plainChunk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $plainParagraph = mb_substr($plainParagraph, $limit);
            }
        }

        if ($current !== '') {
            $chunks[] = $this->toHtml($current);
        }

        return $chunks;
    }

    private function renderChildren(DOMNode $parent): string
    {
        $result = '';

        foreach ($parent->childNodes as $child) {
            $result .= $this->renderNode($child);
        }

        return $result;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $contents = $this->renderChildren($node);

        return match ($node->tagName) {
            'strong', 'b' => '<b>'.$contents.'</b>',
            'em', 'i' => '<i>'.$contents.'</i>',
            'del', 's' => '<s>'.$contents.'</s>',
            'a' => $this->renderLink($node, $contents),
            'br' => "\n",
            'blockquote' => '<blockquote>'.trim($contents)."</blockquote>\n\n",
            'p', 'div', 'section', 'article', 'li' => $contents."\n\n",
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => '<b>'.$contents."</b>\n\n",
            default => $contents,
        };
    }

    private function renderLink(DOMElement $node, string $contents): string
    {
        $href = $node->getAttribute('href');
        $scheme = Str::lower((string) parse_url($href, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $contents;
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$contents.'</a>';
    }
}
