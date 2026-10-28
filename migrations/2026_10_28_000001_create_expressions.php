<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('local_expression_media', function (Blueprint $table) {
            $table->char('asset_uuid', 32)->primary();
            $table->string('shortcode', 80)->nullable()->unique();
            $table->string('default_mode', 8)->default('inline');
            $table->unsignedInteger('version')->default(1);
        });
        $schema->create('local_expression_texts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 32)->unique();
            $table->unsignedInteger('user_id');
            $table->string('name', 80);
            $table->string('body', 256);
            $table->string('category', 32)->default('');
            $table->boolean('is_shared')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->index(['user_id', 'id'], 'le_text_owner');
            $table->index(['is_shared', 'id'], 'le_text_shared');
        });
        $schema->create('local_expression_favorites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('kind', 8);
            $table->char('source_id', 32);
            $table->unique(['user_id', 'kind', 'source_id'], 'le_favorite_unique');
            $table->index(['user_id', 'id'], 'le_favorite_page');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('local_expression_favorites');
        $schema->dropIfExists('local_expression_texts');
        $schema->dropIfExists('local_expression_media');
    },
];
