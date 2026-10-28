<?php

use Flarum\Extend;
use Local\ExpressionSystem\Api;
use Local\ExpressionSystem\ForumContent;

return [
    (new Extend\Routes('api'))
        ->get('/expression-system/catalog', 'local-expression-system.catalog', Api\CatalogController::class)
        ->post('/expression-system/resolve', 'local-expression-system.resolve', Api\ResolveController::class)
        ->get('/expression-system/favorites', 'local-expression-system.favorites', Api\FavoritesController::class)
        ->delete('/expression-system/favorites', 'local-expression-system.favorites-prune', Api\FavoritesController::class)
        ->put('/expression-system/favorites/{kind}/{uuid}', 'local-expression-system.favorite', Api\FavoritesController::class)
        ->delete('/expression-system/favorites/{kind}/{uuid}', 'local-expression-system.unfavorite', Api\FavoritesController::class)
        ->post('/expression-system/texts', 'local-expression-system.text-create', Api\TextController::class)
        ->patch('/expression-system/texts/{uuid}', 'local-expression-system.text-update', Api\TextController::class)
        ->delete('/expression-system/texts/{uuid}', 'local-expression-system.text-delete', Api\TextController::class)
        ->patch('/expression-system/images/{uuid}', 'local-expression-system.image-update', Api\ImageController::class)
        ->post('/expression-system/images/{uuid}/prepare', 'local-expression-system.image-prepare', Api\ImageController::class)
        ->get('/expression-system/settings', 'local-expression-system.settings', Api\SettingsController::class)
        ->post('/expression-system/settings', 'local-expression-system.settings-save', Api\SettingsController::class),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/resources/forum.less')->content(ForumContent::class),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/resources/locale'),
];
