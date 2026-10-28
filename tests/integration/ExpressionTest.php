<?php

declare(strict_types=1);

namespace Local\ExpressionSystem\Tests\integration;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use Local\ExpressionSystem\Builtin;
use Local\ExpressionSystem\Catalog;
use Local\ExpressionSystem\Preferences;
use Local\MediaLibrary\Asset;
use Local\MediaLibrary\ImageProfiles;
use Local\MediaLibrary\Library;
use Local\MediaLibrary\PrivateStorage;
use Local\MediaLibrary\Variants;
use Local\MediaLibrary\PruneCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ExpressionTest extends TestCase
{
    private array $created = [];

    protected function request(string $method, string $path, array $options = []): \Psr\Http\Message\ServerRequestInterface
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        return parent::request($method, $path, $options)->withQueryParams($query);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('local-media-library', 'local-expression-system', 'flarum-markdown', 'flarum-emoji');
        $this->extend((new \Flarum\Extend\ThrottleApi())->remove('postTimeout'));
        $this->setting('local-media-library.emoji.enabled', '1');
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'owner', 'email' => 'owner@example.test', 'is_email_confirmed' => 1, 'joined_at' => '2026-01-01'],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test', 'is_email_confirmed' => 1, 'joined_at' => '2026-01-01'],
            ],
            'group_permission' => [
                ['group_id' => 2, 'permission' => 'viewForum'],
                ['group_id' => 3, 'permission' => 'startDiscussion'],
                ['group_id' => 3, 'permission' => 'discussion.reply'],
                ['group_id' => 3, 'permission' => 'discussion.editPosts'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach ($this->created as $id) {
                $this->service(PrivateStorage::class)->remove($id);
            }
        }
        parent::tearDown();
    }

    private function service(string $class)
    {
        return $this->app()->getContainer()->make($class);
    }

    private function image(array $tags = ['emoji'], int $owner = 2): Asset
    {
        $image = imagecreatetruecolor(320, 160);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 50, 30, 200, 120, imagecolorallocatealpha($image, 90, 150, 190, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $stream = new Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $asset = $this->service(Library::class)->upload(User::findOrFail($owner), new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'cat.png'), $tags);
        $this->created[] = $asset->uuid;
        return $asset;
    }

    private function api(string $method, string $path, ?int $actor = 2, array $body = [])
    {
        return $this->send($this->request($method, '/api/expression-system/'.$path, ['authenticatedAs' => $actor, 'json' => $body]));
    }

    private function gif(array $tags = ['emoji'], string $fixture = 'animated.gif'): Asset
    {
        $bytes = file_get_contents(__DIR__.'/../fixtures/'.$fixture);
        $stream = new Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $asset = $this->service(Library::class)->upload(User::findOrFail(2),
            new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $fixture), $tags);
        $this->created[] = $asset->uuid;
        return $asset;
    }

    public function test_admin_can_preserve_gif_animation_with_static_picker_and_protected_variants(): void
    {
        $response = $this->api('POST', 'settings', 1, ['processing' => ['gifPolicy' => 'animated']]);
        $this->assertSame(200, $response->getStatusCode(), $this->summary($response));
        $settings = json_decode((string) $response->getBody(), true);
        $this->assertTrue($settings['capabilities']['animatedGif']);
        $this->assertSame(['static', 'animated'], array_keys($settings['processingSchema']['gifPolicy']['options']));
        $asset = $this->gif();
        $bytes = file_get_contents(__DIR__.'/../fixtures/animated.gif');
        foreach (['inline', 'sticker'] as $kind) {
            $file = $this->file($asset, $kind, 2);
            $this->assertSame('image/gif', $file->getHeaderLine('Content-Type'));
            $this->assertSame($bytes, (string) $file->getBody());
            $this->assertSame(404, $this->file($asset, $kind, 3)->getStatusCode());
        }
        $this->assertSame('image/webp', $this->file($asset, 'picker', 2)->getHeaderLine('Content-Type'));
        $post = $this->post('[memoji='.$asset->uuid.']' . "\n\n" . '[msticker='.$asset->uuid.']');
        $this->assertSame($bytes, (string) $this->file($asset, 'inline', null, $post)->getBody());
        $rows = $this->database()->table('local_media_variants')->where('asset_id', $asset->id)->get();
        $this->assertSame((int) $rows->sum('size'), $asset->derived_bytes);
        $this->assertSame($asset->size + $asset->derived_bytes, (int) User::find(2)->local_media_bytes);
        $this->assertSame($asset->sha256, hash_file('sha256', $this->service(PrivateStorage::class)->path($asset->uuid)));
    }

    public function test_gif_upload_budget_applies_to_ordinary_attachments_before_registration(): void
    {
        $this->service(ImageProfiles::class)->save(['gifMaxFrames' => 2]);
        try {
            $this->gif([], 'three-frames.gif');
            $this->fail('Expected ordinary GIF upload to obey the frame budget.');
        } catch (ValidationException $error) {
            $this->assertSame(0, $this->database()->table('local_media_assets')->count());
            $this->assertSame(0, (int) User::find(2)->local_media_bytes);
            $this->assertSame([], iterator_to_array($this->service(PrivateStorage::class)->storedIds()));
        }
    }

    public function test_existing_gif_changes_policy_only_on_explicit_rebuild(): void
    {
        $asset = $this->gif();
        $this->assertSame('image/webp', $this->file($asset, 'inline', 2)->getHeaderLine('Content-Type'));
        $this->service(ImageProfiles::class)->save(['gifPolicy' => 'animated']);
        $this->assertSame('image/webp', $this->file($asset, 'inline', 2)->getHeaderLine('Content-Type'));
        $changed = $this->service(Variants::class)->prepare(User::find(2), $asset, true);
        $this->assertNotSame($asset->emoji_profile, $changed->emoji_profile);
        $this->assertSame('image/gif', $this->file($changed, 'inline', 2)->getHeaderLine('Content-Type'));
        $this->service(ImageProfiles::class)->save(['gifMaxFrames' => 2, 'gifMaxPixels' => 65536]);
        $this->assertSame(422, $this->api('POST', 'settings', 1, ['processing' => ['gifPolicy' => 'reject']])->getStatusCode());
    }

    public function test_media_upload_api_rejects_gif_above_its_budget_even_below_the_general_limit(): void
    {
        $bytes = file_get_contents(__DIR__.'/../fixtures/animated.gif').str_repeat('x', 1048576);
        $stream = new Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $request = $this->request('POST', '/api/media-library/assets', ['authenticatedAs' => 2])
            ->withParsedBody(['tags' => '[]'])
            ->withUploadedFiles(['file' => new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'large.gif')]);
        $response = $this->send($request);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('GIF', $this->summary($response));
        $this->assertSame(0, $this->database()->table('local_media_assets')->count());
        $this->assertSame(0, (int) User::find(2)->local_media_bytes);
    }

    private function post(string $text, int $actor = 2): int
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $actor,
            'json' => ['data' => ['attributes' => ['title' => 'Expression test', 'content' => $text]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), $this->summary($response));
        return (int) json_decode((string) $response->getBody(), true)['data']['relationships']['firstPost']['data']['id'];
    }

    private function file(Asset $asset, string $variant, ?int $actor, ?int $post = null)
    {
        $query = http_build_query(['variant' => $variant] + ($post ? ['post' => $post] : []));
        return $this->send($this->request('GET', '/api/media-library/files/'.$asset->uuid.'?'.$query, ['authenticatedAs' => $actor]));
    }

    private function summary($response): string
    {
        $data = json_decode((string) $response->getBody(), true);
        return strtok((string) ($data['errors'][0]['detail'] ?? ''), "\n") ?: '';
    }

    public function test_upload_prepares_bounded_variants_and_accounts_for_them(): void
    {
        $asset = $this->image();
        $this->assertNotEmpty($asset->emoji_profile);
        $rows = $this->database()->table('local_media_variants')->where('asset_id', $asset->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame((int) $rows->sum('size'), $asset->derived_bytes);
        $this->assertSame($asset->size + $asset->derived_bytes, (int) User::find(2)->local_media_bytes);
        foreach (['picker', 'inline', 'sticker'] as $kind) {
            $this->assertSame(200, $this->file($asset, $kind, 2)->getStatusCode());
            $this->assertSame(404, $this->file($asset, $kind, 3)->getStatusCode());
        }
        $path = $this->service(PrivateStorage::class)->path($asset->uuid);
        $this->assertSame($asset->sha256, hash_file('sha256', $path));
    }

    public function test_sticker_and_inline_use_one_reference_and_real_post_permissions(): void
    {
        $asset = $this->image();
        $post = $this->post('Hello [memoji='.$asset->uuid.'] world'."\n\n".'[msticker='.$asset->uuid.'][msticker='.$asset->uuid.']');
        $this->assertSame(1, $this->database()->table('local_media_post_refs')->where('post_id', $post)->count());
        $html = Post::find($post)->formatContent();
        $this->assertStringContainsString('MediaLibrary-emoji', $html);
        $this->assertStringContainsString('MediaLibrary-stickerGroup', $html);
        $this->assertStringContainsString('variant=sticker', $html);
        $this->assertStringContainsString('width="320"', $html);
        $this->assertSame(200, $this->file($asset, 'inline', null, $post)->getStatusCode());
        $unrelated = $this->post('No attachment');
        $this->assertSame(404, $this->file($asset, 'sticker', 3, $unrelated)->getStatusCode());
        $this->database()->table('posts')->where('id', $post)->update(['hidden_at' => Carbon::now()]);
        $this->assertSame(404, $this->file($asset, 'picker', null, $post)->getStatusCode());
    }

    public function test_revoke_shared_emoji_hides_favorite_but_preserves_old_post(): void
    {
        $asset = $this->image(['public', 'emoji']);
        $this->assertSame(200, $this->api('PUT', 'favorites/image/'.$asset->uuid, 3)->getStatusCode());
        $post = $this->post('[memoji='.$asset->uuid.']', 3);
        $fresh = Asset::find($asset->id);
        $this->service(Library::class)->update(User::find(2), $asset->uuid, ['version' => $fresh->version, 'tags' => []]);
        $favorites = json_decode((string) $this->api('GET', 'favorites', 3)->getBody(), true);
        $this->assertSame([], $favorites['items']);
        $this->assertSame(200, $this->file($asset, 'inline', null, $post)->getStatusCode());
        $response = $this->send($this->request('PATCH', '/api/posts/'.$post, [
            'authenticatedAs' => 3, 'json' => ['data' => ['attributes' => ['content' => '[memoji='.$asset->uuid.'] edited']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), $this->summary($response));
        $new = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 3, 'json' => ['data' => ['attributes' => ['title' => 'Forbidden', 'content' => '[msticker='.$asset->uuid.']']]],
        ]));
        $this->assertSame(422, $new->getStatusCode());
    }

    public function test_text_crud_permissions_versions_and_shared_favorites(): void
    {
        $created = $this->api('POST', 'texts', 2, ['name' => '开心', 'body' => '(*^▽^*)', 'category' => '开心', 'shared' => true]);
        $this->assertSame(201, $created->getStatusCode(), $this->summary($created));
        $text = json_decode((string) $created->getBody(), true);
        $this->assertSame(200, $this->api('PUT', 'favorites/text/'.$text['id'], 3)->getStatusCode());
        $this->assertSame(404, $this->api('PATCH', 'texts/'.$text['id'], 3, ['version' => 1])->getStatusCode());
        $this->assertSame(422, $this->api('PATCH', 'texts/'.$text['id'], 2, ['version' => 9])->getStatusCode());
        $deleted = $this->api('DELETE', 'texts/'.$text['id'], 2, ['version' => 1]);
        $this->assertSame(200, $deleted->getStatusCode(), $this->summary($deleted));
        $this->assertSame([], json_decode((string) $this->api('GET', 'favorites', 3)->getBody(), true)['items']);
        $cleaned = $this->api('DELETE', 'favorites', 3);
        $this->assertSame(1, json_decode((string) $cleaned->getBody(), true)['removed']);
        $this->assertSame(0, $this->database()->table('local_expression_favorites')->where('user_id', 3)->count());
    }

    public function test_image_shortcodes_are_namespaced_and_owner_checked(): void
    {
        $asset = $this->image(['emoji', 'public']);
        $input = ['alias' => 'cat_wave', 'mode' => 'sticker', 'version' => 0, 'assetVersion' => $asset->version];
        $this->assertSame(403, $this->api('PATCH', 'images/'.$asset->uuid, 3, $input)->getStatusCode());
        $response = $this->api('PATCH', 'images/'.$asset->uuid, 2, $input);
        $this->assertSame(200, $response->getStatusCode(), $this->summary($response));
        $item = json_decode((string) $response->getBody(), true);
        $this->assertSame(':u2_cat_wave:', $item['shortcode']);
        $this->assertSame('sticker', $item['defaultMode']);
        $this->assertSame(422, $this->api('PATCH', 'images/'.$asset->uuid, 2, $input)->getStatusCode());
        $list = json_decode((string) $this->api('GET', 'catalog?shortcode=%3Au2_cat', 3)->getBody(), true);
        $this->assertSame([$asset->uuid], array_column($list['items'], 'id'));
    }

    public function test_images_are_keyset_paged_and_queries_do_not_grow_with_page_size(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->image(['emoji', 'public', '猫猫']);
        }
        $catalog = $this->service(Catalog::class);
        $db = $this->database();
        $db->enableQueryLog();
        $first = $catalog->page(User::find(3), ['scope' => 'shared', 'tag' => '猫猫', 'limit' => 1]);
        $firstQueries = count($db->getQueryLog());
        $db->flushQueryLog();
        $whole = $catalog->page(User::find(3), ['scope' => 'shared', 'tag' => '猫猫', 'limit' => 24]);
        $wholeQueries = count($db->getQueryLog());
        $db->disableQueryLog();
        $this->assertCount(24, $whole['items']);
        $this->assertSame($firstQueries, $wholeQueries);
        // Includes actor loading and core permission lookups, not just catalog data.
        $this->assertLessThanOrEqual(7, $wholeQueries);
        $second = $catalog->page(User::find(3), ['scope' => 'shared', 'tag' => '猫猫', 'limit' => 1, 'before' => $first['next']]);
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
    }

    public function test_admin_settings_validate_and_are_not_writable_by_members(): void
    {
        $this->assertSame(403, $this->api('GET', 'settings', 2)->getStatusCode());
        $response = $this->api('POST', 'settings', 1, ['display' => ['inlineEm' => 2, 'stickerAlign' => 'center'], 'processing' => ['quality' => 70, 'qualityFloor' => 50]]);
        $this->assertSame(200, $response->getStatusCode(), $this->summary($response));
        $settings = json_decode((string) $response->getBody(), true);
        $this->assertEquals(2, $settings['display']['inlineEm']);
        $this->assertSame(70, $settings['processing']['quality']);
        $this->assertSame(422, $this->api('POST', 'settings', 1, ['processing' => ['quality' => 30, 'qualityFloor' => 60]])->getStatusCode());
        $this->assertSame(422, $this->api('POST', 'settings', 1, ['display' => ['stickerAlign' => 'center;}body{']])->getStatusCode());
    }

    public function test_rebuild_is_idempotent_and_old_profiles_count_until_retired(): void
    {
        $asset = $this->image();
        $old = $asset->emoji_profile;
        $service = $this->service(Variants::class);
        $before = (int) User::find(2)->local_media_bytes;
        $service->prepare(User::find(2), $asset, true);
        $this->assertSame($before, (int) User::find(2)->local_media_bytes);
        $this->service(ImageProfiles::class)->save(['quality' => 75]);
        $changed = $service->prepare(User::find(2), $asset, true);
        $this->assertNotSame($old, $changed->emoji_profile);
        $this->assertSame(6, $this->database()->table('local_media_variants')->where('asset_id', $asset->id)->count());
        $this->database()->table('local_media_variants')->where('profile', $old)->update(['created_at' => Carbon::now()->subDays(2)]);
        $released = $service->pruneRetired($changed);
        $this->assertGreaterThan(0, $released);
        $this->assertSame(3, $this->database()->table('local_media_variants')->where('asset_id', $asset->id)->count());
        $this->assertSame($asset->sha256, hash_file('sha256', $this->service(PrivateStorage::class)->path($asset->uuid)));
    }

    public function test_failed_processing_retains_original_and_cannot_be_inserted(): void
    {
        $asset = $this->image([]);
        $this->service(ImageProfiles::class)->save(['maxPixels' => 65536]);
        // Use a stricter edge limit than this source to force a real processing failure.
        $this->service(ImageProfiles::class)->save(['maxEdge' => 128]);
        $asset = $this->service(Library::class)->update(User::find(2), $asset->uuid, ['version' => $asset->version, 'tags' => ['emoji']]);
        $this->assertNull($asset->emoji_profile);
        $this->assertNotEmpty($asset->processing_error);
        $this->assertFileExists($this->service(PrivateStorage::class)->path($asset->uuid));
        $this->assertSame(0, $this->database()->table('local_media_variants')->count());
        $this->assertSame(404, $this->file($asset, 'inline', 2)->getStatusCode());
        $this->assertSame(422, $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2, 'json' => ['data' => ['attributes' => ['title' => 'Not ready', 'content' => '[memoji='.$asset->uuid.']']]],
        ]))->getStatusCode());
    }

    public function test_repeated_occurrences_are_capped_and_code_blocks_do_not_count(): void
    {
        $asset = $this->image();
        $this->service(ImageProfiles::class)->save(['maxOccurrences' => 10, 'maxStickers' => 2]);
        $tooMany = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2, 'json' => ['data' => ['attributes' => ['title' => 'Too many', 'content' => str_repeat('[memoji='.$asset->uuid.']', 11)]]],
        ]));
        $this->assertSame(422, $tooMany->getStatusCode());
        $post = $this->post('~~~'."\n".str_repeat('[msticker='.$asset->uuid.']', 4)."\n~~~");
        $this->assertSame(0, $this->database()->table('local_media_post_refs')->where('post_id', $post)->count());
    }

    public function test_unauthenticated_catalog_and_oversized_resolve_are_rejected(): void
    {
        $this->assertSame(401, $this->api('GET', 'catalog?scope=shared', null)->getStatusCode());
        $this->assertSame(401, $this->api('GET', 'favorites', null)->getStatusCode());
        $keys = array_fill(0, 51, array_key_first(Builtin::all()));
        $this->assertSame(422, $this->api('POST', 'resolve', 2, ['items' => $keys])->getStatusCode());
        $this->assertSame(422, $this->api('POST', 'resolve', 2, ['items' => ['image:../../secret']])->getStatusCode());
    }

    public function test_archive_purge_releases_original_and_all_derived_bytes(): void
    {
        $asset = $this->image();
        $storage = $this->service(PrivateStorage::class);
        $this->assertCount(3, $storage->variantPaths($asset->uuid));
        $this->service(Library::class)->update(User::find(2), $asset->uuid, ['version' => $asset->version], true);
        $this->database()->table('local_media_assets')->where('id', $asset->id)->update(['archived_at' => Carbon::now()->subDays(8)]);
        $command = new CommandTester($this->service(PruneCommand::class));
        $command->execute(['--apply' => true]);
        $this->assertNull(Asset::find($asset->id));
        $this->assertSame(0, (int) User::find(2)->local_media_bytes);
        $this->assertSame([], $storage->variantPaths($asset->uuid));
        $this->assertFileDoesNotExist($storage->path($asset->uuid));
    }

    public function test_quota_failure_does_not_replace_a_working_processing_profile(): void
    {
        $asset = $this->image();
        $old = $asset->emoji_profile;
        $this->database()->table('users')->where('id', 2)->update(['local_media_bytes' => 1048575]);
        $this->service(\Flarum\Settings\SettingsRepositoryInterface::class)->set('local-media-library.quotaMb', '1');
        $this->service(ImageProfiles::class)->save(['quality' => 70]);
        try {
            $this->service(Variants::class)->prepare(User::find(2), $asset, true);
            $this->fail('Expected quota validation.');
        } catch (ValidationException $error) {
            $this->assertSame($old, Asset::find($asset->id)->emoji_profile);
            $this->assertSame(3, $this->database()->table('local_media_variants')->count());
            $this->assertSame(200, $this->file($asset, 'inline', 2)->getStatusCode());
        }
    }

    public function test_favorite_cap_is_serialized_and_duplicate_put_is_idempotent(): void
    {
        $texts = $favorites = [];
        for ($i = 0; $i < 500; $i++) {
            $id = md5('owned-text-'.$i);
            $texts[] = ['uuid' => $id, 'user_id' => 2, 'name' => 'text'.$i, 'body' => '(≧▽≦)'];
            $favorites[] = ['user_id' => 2, 'kind' => 'text', 'source_id' => $id];
        }
        $this->app();
        $this->database()->table('local_expression_texts')->insert($texts);
        $this->database()->table('local_expression_favorites')->insert($favorites);
        $preferences = $this->service(Preferences::class);
        $preferences->favorite(User::find(2), 'text', $texts[0]['uuid'], false);
        $this->assertSame(500, $this->database()->table('local_expression_favorites')->count());
        $builtin = array_values(Builtin::all())[0];
        $response = $this->api('PUT', 'favorites/'.$builtin['kind'].'/'.$builtin['id']);
        $this->assertSame(422, $response->getStatusCode());
        $preferences->favorite(User::find(2), 'text', $texts[0]['uuid'], true);
        $this->assertSame(200, $this->api('PUT', 'favorites/'.$builtin['kind'].'/'.$builtin['id'])->getStatusCode());
    }

    public function test_crash_variant_orphans_are_reconciled_without_touching_tracked_files(): void
    {
        $asset = $this->image();
        $storage = $this->service(PrivateStorage::class);
        $path = $storage->variantPath($asset->uuid, str_repeat('a', 64), 'picker');
        $storage->writeVariant($path, 'crash leftover');
        touch($path, time() - 172800);
        $orphans = $this->service(Variants::class)->orphanPaths([$asset->id => $asset->uuid]);
        $this->assertSame([$path], $orphans);
        $command = new CommandTester($this->service(\Local\MediaLibrary\OrphansCommand::class));
        $command->execute(['--apply' => true]);
        $this->assertFileDoesNotExist($path);
        $this->assertCount(3, $storage->variantPaths($asset->uuid));
        $this->assertSame(3, $this->database()->table('local_media_variants')->count());
    }
}
