<?php

declare(strict_types=1);

namespace Local\ExpressionSystem;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;

final class DisplaySettings
{
    /** Shared by server validation, the admin form and forum CSS. */
    public const FIELDS = [
        'inlineEm' => ['label' => '行内表情高度', 'default' => 1.5, 'min' => .8, 'max' => 2.5, 'step' => .1, 'unit' => 'em'],
        'inlineCap' => ['label' => '行内高度上限', 'default' => 32, 'min' => 16, 'max' => 64, 'unit' => 'px'],
        'inlineWidth' => ['label' => '行内宽度上限', 'default' => 3, 'min' => 1, 'max' => 6, 'step' => .5, 'unit' => 'em'],
        'baseline' => ['label' => '基线向下偏移', 'default' => .15, 'min' => -.15, 'max' => .35, 'step' => .05, 'unit' => 'em'],
        'gap' => ['label' => '行内左右间距', 'default' => .1, 'min' => 0, 'max' => .5, 'step' => .05, 'unit' => 'em'],
        'stickerWidth' => ['label' => '贴纸最大宽度', 'default' => 160, 'min' => 64, 'max' => 320, 'unit' => 'px'],
        'stickerHeight' => ['label' => '贴纸最大高度', 'default' => 160, 'min' => 64, 'max' => 320, 'unit' => 'px'],
        'stickerGap' => ['label' => '贴纸间距', 'default' => 8, 'min' => 0, 'max' => 24, 'unit' => 'px'],
        'stickerLayout' => ['label' => '贴纸排版', 'default' => 'block', 'options' => ['block' => '独立成块', 'wrap' => '连续同排']],
        'stickerAlign' => ['label' => '贴纸对齐', 'default' => 'left', 'options' => ['left' => '左对齐', 'center' => '居中', 'right' => '右对齐']],
    ];

    public function __construct(private SettingsRepositoryInterface $settings) {}

    public function values(): array
    {
        $values = [];
        foreach (self::FIELDS as $key => $field) {
            $value = $this->settings->get('local-expression-system.'.$key, $field['default']);
            $values[$key] = isset($field['options'])
                ? (array_key_exists((string) $value, $field['options']) ? $value : $field['default'])
                : (is_numeric($value) ? max($field['min'], min($field['max'], (float) $value)) : $field['default']);
        }
        return $values;
    }

    public static function validate(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $field = self::FIELDS[$key] ?? null;
            if (!$field || (isset($field['options'])
                ? !is_string($value) || !array_key_exists($value, $field['options'])
                : !is_numeric($value) || !is_finite((float) $value) || $value < $field['min'] || $value > $field['max'])) {
                throw new ValidationException(['settings' => '无效的显示设置：'.$key]);
            }
            $result[$key] = isset($field['options']) ? $value : (float) $value;
        }
        return $result;
    }

    public function save(array $values): void
    {
        foreach (self::validate($values) as $key => $value) {
            $this->settings->set('local-expression-system.'.$key, (string) $value);
        }
    }

    public function css(): string
    {
        $v = $this->values();
        $align = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$v['stickerAlign']];
        return ':root{--es-inline-height:'.$v['inlineEm'].'em;--es-inline-cap:'.$v['inlineCap'].'px;'
            .'--es-inline-width:'.$v['inlineWidth'].'em;--es-baseline:'.(-$v['baseline']).'em;--es-gap:'.$v['gap'].'em;'
            .'--es-sticker-width:'.$v['stickerWidth'].'px;--es-sticker-height:'.$v['stickerHeight'].'px;'
            .'--es-sticker-gap:'.$v['stickerGap'].'px;--es-sticker-align:'.$align.';'
            .'--es-sticker-cross:'.($v['stickerLayout'] === 'wrap' ? 'flex-start' : $align).';'
            .'--es-sticker-justify:'.($v['stickerLayout'] === 'wrap' ? $align : 'flex-start').';'
            .'--es-sticker-direction:'.($v['stickerLayout'] === 'wrap' ? 'row' : 'column').';'
            .'--es-sticker-wrap:'.($v['stickerLayout'] === 'wrap' ? 'wrap' : 'nowrap').';}';
    }
}
