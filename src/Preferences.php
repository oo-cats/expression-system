<?php

declare(strict_types=1);

namespace Local\ExpressionSystem;

use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class Preferences
{
    public function __construct(private ConnectionInterface $db, private Catalog $catalog) {}

    public function favorites(User $actor, array $params): array
    {
        $actor->assertRegistered();
        $limit = min(50, max(1, (int) ($params['limit'] ?? 24)));
        $query = $this->db->table('local_expression_favorites')->where('user_id', $actor->id);
        if (isset($params['before'])) {
            $query->where('id', '<', max(0, (int) $params['before']));
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        return [
            'items' => $this->catalog->resolve($actor, $rows->map(fn ($r) => $r->kind.':'.$r->source_id)->all()),
            'next' => $more ? (string) $rows->last()->id : null,
        ];
    }

    public function favorite(User $actor, string $kind, string $id, bool $remove): void
    {
        $actor->assertRegistered();
        if (!in_array($kind, Catalog::KINDS, true) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new ValidationException(['favorite' => '无效的收藏标识。']);
        }
        $key = ['user_id' => $actor->id, 'kind' => $kind, 'source_id' => $id];
        if ($remove) {
            $this->db->table('local_expression_favorites')->where($key)->delete();
            return;
        }
        if (!$this->catalog->resolve($actor, [$kind.':'.$id])) {
            throw new ModelNotFoundException();
        }
        $this->db->transaction(function () use ($actor, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($this->db->table('local_expression_favorites')->where($key)->exists()) {
                return;
            }
            if ($this->db->table('local_expression_favorites')->where('user_id', $actor->id)->count() >= 500) {
                throw new ValidationException(['favorite' => '收藏最多 500 项。']);
            }
            $this->db->table('local_expression_favorites')->insert($key);
        });
    }

    public function pruneFavorites(User $actor): int
    {
        $actor->assertRegistered();
        $rows = $this->db->table('local_expression_favorites')->where('user_id', $actor->id)->orderBy('id')->limit(500)->get();
        $available = [];
        foreach ($rows->chunk(50) as $chunk) {
            foreach ($this->catalog->resolve($actor, $chunk->map(fn ($r) => $r->kind.':'.$r->source_id)->all()) as $item) {
                $available[$item['key']] = true;
            }
        }
        $ids = $rows->filter(fn ($r) => !isset($available[$r->kind.':'.$r->source_id]))->pluck('id')->all();
        return $ids ? $this->db->table('local_expression_favorites')->where('user_id', $actor->id)->whereIn('id', $ids)->delete() : 0;
    }

    public function text(User $actor, ?string $id, array $input, bool $remove = false): ?array
    {
        $actor->assertRegistered();
        $uuid = $id ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D', $uuid)) {
            throw new ValidationException(['text' => '无效的文字条目标识。']);
        }
        $this->db->transaction(function () use ($actor, $id, $uuid, $input, $remove) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $query = $this->db->table('local_expression_texts')->where('uuid', $uuid);
            $row = $query->lockForUpdate()->first();
            if ($id && (!$row || ((int) $row->user_id !== (int) $actor->id && !$actor->isAdmin()))) {
                throw new ModelNotFoundException();
            }
            if ($id && (int) ($input['version'] ?? 0) !== (int) $row->version) {
                throw new ValidationException(['version' => '文字条目已经改变，请刷新。']);
            }
            if ($remove) {
                $query->delete();
                return;
            }
            if (!$row && $this->db->table('local_expression_texts')->where('user_id', $actor->id)->count() >= 500) {
                throw new ValidationException(['text' => '个人文字条目最多 500 项。']);
            }
            $data = [];
            foreach (['name' => 80, 'body' => 256, 'category' => 32] as $key => $max) {
                $value = $input[$key] ?? '';
                if (!is_string($value) || mb_strlen($value) > $max || ($key !== 'category' && trim($value) === '')
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
                    throw new ValidationException(['text' => '文字名称、内容或分类不符合长度要求。']);
                }
                $data[$key] = $value;
            }
            $data['is_shared'] = ($input['shared'] ?? false) === true;
            $data['version'] = ($row->version ?? 0) + 1;
            if ($row) {
                $query->update($data);
            } else {
                $query->insert($data + ['uuid' => $uuid, 'user_id' => $actor->id]);
            }
        });
        return $remove ? null : $this->catalog->resolve($actor, ['text:'.$uuid])[0];
    }
}
