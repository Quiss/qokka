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

    public function test_it_converts_telegram_inline_styles_and_keeps_code_literal(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml(
            '++Подчеркнуто **жирно**++ и ||секрет *курсивом*||, но ~~удалено~~ и `a < b || c`.',
        );

        $this->assertSame(
            '<u>Подчеркнуто <b>жирно</b></u> и <tg-spoiler>секрет <i>курсивом</i></tg-spoiler>, но <s>удалено</s> и <code>a &lt; b || c</code>.',
            $html,
        );
    }

    public function test_it_converts_fenced_code_with_a_safe_language(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml("```php\nif (\$count < 2) {\n    return;\n}\n```");

        $this->assertSame(
            "<pre><code class=\"language-php\">if (\$count &lt; 2) {\n    return;\n}\n</code></pre>",
            $html,
        );
    }

    public function test_it_converts_expandable_quotes_and_unordered_lists(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml(
            "> [!EXPANDABLE]\n> Первая строка.\n> Вторая строка.\n\n- Один\n- **Два**",
        );

        $this->assertSame(
            "<blockquote expandable>Первая строка.\nВторая строка.</blockquote>\n\n• Один\n• <b>Два</b>",
            $html,
        );
    }

    public function test_it_preserves_ordered_list_numbers_and_inline_formatting(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml(
            "**Приготовление:**\n\n1. Нарезать **кабачки**.\n2. Запечь.\n3. Перемешать.",
        );

        $this->assertSame(
            "<b>Приготовление:</b>\n\n1. Нарезать <b>кабачки</b>.\n2. Запечь.\n3. Перемешать.",
            $html,
        );
    }

    public function test_it_preserves_the_ordered_list_starting_number(): void
    {
        $formatter = new TelegramMessageFormatter;

        $html = $formatter->toHtml("5. Пятый\n6. Шестой");

        $this->assertSame("5. Пятый\n6. Шестой", $html);
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
