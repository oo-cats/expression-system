<?php

declare(strict_types=1);

namespace Local\ExpressionSystem;

use Flarum\Frontend\Document;

final class ForumContent
{
    public function __construct(private DisplaySettings $settings) {}

    public function __invoke(Document $document): void
    {
        $document->head[] = '<style id="expression-system-display">'.$this->settings->css().'</style>';
    }
}
