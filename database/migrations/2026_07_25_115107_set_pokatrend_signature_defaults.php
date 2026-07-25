<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $publication = DB::table('publications')
            ->where('slug', 'pokatrend')
            ->first(['id', 'tone_prompt']);

        if ($publication !== null && is_string($publication->tone_prompt)) {
            $tonePrompt = rtrim($publication->tone_prompt);
            $updatedTonePrompt = preg_replace(
                '/(?:\R){2}'.preg_quote($this->legacySignatureInstruction(), '/').'$/u',
                '',
                $tonePrompt,
            );

            if (is_string($updatedTonePrompt) && $updatedTonePrompt !== $tonePrompt) {
                DB::table('publications')
                    ->where('id', $publication->id)
                    ->update(['tone_prompt' => $updatedTonePrompt]);
            }
        }

        DB::table('publications')
            ->where('slug', 'pokatrend')
            ->update([
                'signature_mode' => 'link',
                'signature_label' => 'ПокаТренд',
            ]);

        DB::table('destinations')
            ->whereRaw('LOWER(external_id) = ?', ['@pokatrend'])
            ->update(['external_id' => '@PokaTrend']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $publication = DB::table('publications')
            ->where('slug', 'pokatrend')
            ->first(['id', 'tone_prompt']);

        if ($publication !== null && is_string($publication->tone_prompt) && ! str_contains($publication->tone_prompt, $this->legacySignatureInstruction())) {
            DB::table('publications')
                ->where('id', $publication->id)
                ->update([
                    'tone_prompt' => rtrim($publication->tone_prompt)."\n\n".$this->legacySignatureInstruction(),
                ]);
        }

        DB::table('publications')
            ->where('slug', 'pokatrend')
            ->update([
                'signature_mode' => 'none',
                'signature_label' => null,
            ]);

        DB::table('destinations')
            ->where('external_id', '@PokaTrend')
            ->update(['external_id' => '@pokatrend']);
    }

    private function legacySignatureInstruction(): string
    {
        return 'После смысловой концовки оставь пустую строку и добавь отдельной последней строкой строго @PokaTrend — без точки, запятой, эмодзи и любых других знаков после подписи.';
    }
};
