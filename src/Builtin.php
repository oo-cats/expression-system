<?php

declare(strict_types=1);

namespace Local\ExpressionSystem;

final class Builtin
{
    public static function all(): array
    {
        static $items;
        if ($items === null) {
            $items = [];
            foreach (require __DIR__.'/../resources/expressions.php' as $kind => $categories) {
                foreach ($categories as $category => $entries) {
                    foreach ($entries as [$name, $text]) {
                        $id = md5($kind."\0".$text);
                        $items[$kind.':'.$id] = compact('kind', 'id', 'name', 'text', 'category');
                    }
                }
            }
        }
        return $items;
    }
}
