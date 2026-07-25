<?php

namespace Tests\Unit;

use App\Services\TelegramMessageFormatter;
use PHPUnit\Framework\TestCase;

class TelegramMessageFormatterTest extends TestCase
{
    public function test_it_converts_supported_markdown_to_safe_telegram_html(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml(
            "**Важно** & *срочно*\n\n[ПокаТренд](https://t.me/PokaTrend)\n\n<script>alert('x')</script>",
        );

        $this->assertSame(
            "<b>Важно</b> &amp; <i>срочно</i>\n\n<a href=\"https://t.me/PokaTrend\">ПокаТренд</a>",
            $html,
        );
    }

    public function test_it_does_not_emit_unsafe_links_or_raw_html(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml('[опасная ссылка](javascript:alert(1)) <b>сырой HTML</b>');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('опасная ссылка', $html);
        $this->assertStringContainsString('сырой HTML', $html);
    }

    public function test_it_converts_markdown_quotes_to_telegram_blockquotes(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml(
            "> Первая строка цитаты.\n>\n> Вторая строка с **акцентом**.\n\nОбычный абзац.",
        );

        $this->assertSame(
            "<blockquote>Первая строка цитаты.\n\nВторая строка с <b>акцентом</b>.</blockquote>\n\nОбычный абзац.",
            $html,
        );
    }

    public function test_it_splits_long_messages_by_telegram_text_length(): void
    {
        $formatter = new TelegramMessageFormatter;
        $markdown = str_repeat('а', 3000)."\n\n".str_repeat('б', 3000);

        $chunks = $formatter->chunks($markdown);

        $this->assertCount(2, $chunks);
        $this->assertLessThanOrEqual(4096, mb_strlen(strip_tags($chunks[0])));
        $this->assertLessThanOrEqual(4096, mb_strlen(strip_tags($chunks[1])));
    }
}
