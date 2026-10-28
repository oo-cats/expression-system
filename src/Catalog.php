<?php

declare(strict_types=1);

namespace Local\ExpressionSystem;

use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\MediaLibrary\PublicApi\EmojiAssets;

final class Catalog
{
    public const KINDS = ['image', 'text', 'unicode', 'kaomoji'];

    public function __construct(private ConnectionInterface $db, private EmojiAssets $images) {}

    public function page(User $actor, array $params): array
    {
        $actor->assertRegistered();
        $scope = $params['scope'] ?? 'kaomoji';
        if (in_array($scope, ['mine', 'shared'], true)) {
            $page = $this->images->page($actor, $params);
            $page['items'] = $this->decorate($actor, $page['items']);
            return $page;
        }
        if (in_array($scope, ['unicode', 'kaomoji'], true)) {
            $q = mb_strtolower(mb_substr(trim((string) ($params['q'] ?? '')), 0, 80));
            $category = trim((string) ($params['tag'] ?? ''));
            $items = array_values(array_filter(Builtin::all(), fn ($item) => $item['kind'] === $scope
                && ($category === '' || $category === $item['category'])
                && ($q === '' || str_contains(mb_strtolower($item['name'].' '.$item['text'].' '.$item['category']), $q))));
            return ['items' => $this->decorate($actor, $items), 'next' => null];
        }
        if (!in_array($scope, ['my-text', 'shared-text'], true)) {
            throw new ValidationException(['scope' => '无效的表情列表。']);
        }
        $query = $this->db->table('local_expression_texts');
        $scope === 'my-text' ? $query->where('user_id', $actor->id) : $query->where('is_shared', true);
        if ($q = trim((string) ($params['q'] ?? ''))) {
            $query->where('name', 'like', self::prefix($q));
        }
        if ($tag = trim((string) ($params['tag'] ?? ''))) {
            $query->where('category', $tag);
        }
        if (isset($params['before'])) {
            $query->where('id', '<', max(0, (int) $params['before']));
        }
        $limit = min(50, max(1, (int) ($params['limit'] ?? 24)));
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        return [
            'items' => $this->decorate($actor, $rows->map(fn ($row) => self::textItem($row, $actor))->all()),
            'next' => $more ? (string) $rows->last()->id : null,
        ];
    }

    /** One page, one batch query per source type; no per-image database lookups. */
    public function resolve(User $actor, array $keys): array
    {
        $actor->assertRegistered();
        if (count($keys) > 50) {
            throw new ValidationException(['items' => '一次最多读取 50 个表情。']);
        }
        $ids = [];
        $requested = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^(image|text|unicode|kaomoji):([a-f0-9]{32})$/D', $key, $match)) {
                throw new ValidationException(['items' => '无效的表情标识。']);
            }
            $ids[$match[1]][] = $match[2];
            $requested[$key] = true;
        }
        $found = array_intersect_key(Builtin::all(), $requested);
        if (isset($ids['image'])) {
            foreach ($this->images->resolve($actor, $ids['image']) as $item) {
                $found['image:'.$item['id']] = $item;
            }
        }
        if (isset($ids['text'])) {
            $rows = $this->db->table('local_expression_texts')->whereIn('uuid', $ids['text'])
                ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('is_shared', true))->get();
            foreach ($rows as $row) {
                $found['text:'.$row->uuid] = self::textItem($row, $actor);
            }
        }
        $items = [];
        foreach (array_keys($requested) as $key) {
            if (isset($found[$key])) {
                $items[] = $found[$key];
            }
        }
        return $this->decorate($actor, $items);
    }

    private function decorate(User $actor, array $items): array
    {
        if (!$items) {
            return [];
        }
        $ids = array_column($items, 'id');
        $options = $this->db->table('local_expression_media')->whereIn('asset_uuid', $ids)->get()->keyBy('asset_uuid');
        $favorites = $this->db->table('local_expression_favorites')->where('user_id', $actor->id)
            ->whereIn('source_id', $ids)->get()->mapWithKeys(fn ($row) => [$row->kind.':'.$row->source_id => true])->all();
        foreach ($items as &$item) {
            $item['key'] = $item['kind'].':'.$item['id'];
            $item['favorite'] = isset($favorites[$item['key']]);
            if ($item['kind'] === 'image') {
                $option = $options->get($item['id']);
                $item['shortcode'] = $option->shortcode ?? null;
                $item['defaultMode'] = $option->default_mode ?? 'inline';
                $item['optionVersion'] = $option->version ?? 0;
            }
        }
        unset($item);
        return $items;
    }

    public function updateImage(User $actor, string $id, array $input): array
    {
        $mode = $input['mode'] ?? 'inline';
        $alias = trim((string) ($input['alias'] ?? ''));
        if (!in_array($mode, ['inline', 'sticker'], true) || ($alias !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $alias))) {
            throw new ValidationException(['image' => '短码限 1–32 位小写字母、数字、下划线和连字符。']);
        }
        $this->images->withManaged($actor, $id, (int) ($input['assetVersion'] ?? 0), function (array $image) use ($id, $input, $mode, $alias) {
            $row = $this->db->table('local_expression_media')->where('asset_uuid', $id)->lockForUpdate()->first();
            if ((int) ($input['version'] ?? 0) !== (int) ($row->version ?? 0)) {
                throw new ValidationException(['version' => '表情设置已经改变，请刷新。']);
            }
            $shortcode = $alias === '' ? null : ':u'.$image['ownerId'].'_'.$alias.':';
            $previous = $shortcode ? $this->db->table('local_expression_media')->where('shortcode', $shortcode)->where('asset_uuid', '<>', $id)->first() : null;
            if ($previous) {
                if ($this->images->registeredIds([$previous->asset_uuid])) {
                    throw new ValidationException(['alias' => '该短码已经用于另一张图片。']);
                }
                $this->db->table('local_expression_media')->where('asset_uuid', $previous->asset_uuid)->delete();
            }
            $this->db->table('local_expression_media')->updateOrInsert(['asset_uuid' => $id], [
                'shortcode' => $shortcode, 'default_mode' => $mode, 'version' => ($row->version ?? 0) + 1,
            ]);
        });
        return $this->resolve($actor, ['image:'.$id])[0];
    }

    public function shortcuts(User $actor, string $prefix): array
    {
        $actor->assertRegistered();
        if (!preg_match('/^:?[a-z0-9_-]{0,64}$/D', $prefix)) {
            return [];
        }
        $ids = $this->db->table('local_expression_media')->where('shortcode', 'like', self::prefix(':'.ltrim($prefix, ':')))
            ->orderBy('shortcode')->limit(30)->pluck('asset_uuid')->all();
        return array_slice($this->resolve($actor, array_map(fn ($id) => 'image:'.$id, $ids)), 0, 8);
    }

    private static function prefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($value, 0, 180)).'%';
    }

    private static function textItem(object $row, User $actor): array
    {
        return ['kind' => 'text', 'id' => $row->uuid, 'name' => $row->name, 'text' => $row->body,
            'category' => $row->category, 'shared' => (bool) $row->is_shared, 'version' => (int) $row->version,
            'canManage' => (int) $row->user_id === (int) $actor->id || $actor->isAdmin()];
    }
}
