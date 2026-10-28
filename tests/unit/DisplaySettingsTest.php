<?php

declare(strict_types=1);

namespace Local\ExpressionSystem\Tests\unit;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Local\ExpressionSystem\DisplaySettings;
use PHPUnit\Framework\TestCase;

final class DisplaySettingsTest extends TestCase
{
    public function test_css_rejects_untrusted_enums(): void
    {
        $this->expectException(ValidationException::class);
        DisplaySettings::validate(['stickerAlign' => 'left;}body{display:none']);
    }

    public function test_display_size_and_baseline_are_bounded(): void
    {
        $this->expectException(ValidationException::class);
        DisplaySettings::validate(['inlineCap' => 2000]);
    }

    public function test_sticker_alignment_does_not_change_vertical_alignment_in_a_row(): void
    {
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn ($key, $default = null) => [
            'local-expression-system.stickerLayout' => 'wrap',
            'local-expression-system.stickerAlign' => 'right',
        ][$key] ?? $default);
        $css = (new DisplaySettings($settings))->css();
        $this->assertStringContainsString('--es-sticker-cross:flex-start', $css);
        $this->assertStringContainsString('--es-sticker-justify:flex-end', $css);
        $this->assertStringContainsString('--es-baseline:-0.15em', $css);
    }
}
