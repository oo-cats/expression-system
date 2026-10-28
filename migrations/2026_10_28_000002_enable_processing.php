<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->updateOrInsert(['key' => 'local-media-library.emoji.enabled'], ['value' => '1']);
    },
    'down' => function () {
        // Keep the media library's policy and already posted protected variants.
    },
];
