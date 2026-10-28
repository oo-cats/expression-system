<?php

declare(strict_types=1);

// Explicitly disposable fixture; refuses every forum except compat/dev.
$forum = realpath(__DIR__.'/../../../../../flarum-dev/.local/compat/sites/dev');
if (!$forum || PHP_SAPI !== 'cli') { exit(1); }
$config = require $forum.'/config.php';
if (($config['url'] ?? '') !== 'http://127.0.0.1:8890' || ($config['database']['database'] ?? '') !== 'flarum_compat_dev') {
    throw new RuntimeException('Only compat/dev is allowed.');
}
require $forum.'/vendor/autoload.php';
$container = (require $forum.'/site.php')->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$statePath = __DIR__.'/../../.local/browser-fixture.json';
$action = $argv[1] ?? '';
if (in_array($action, ['prepare', 'prepare-gif'], true)) {
    if (is_file($statePath) || \Flarum\User\User::where('username', 'expression-ui')->exists()) {
        throw new RuntimeException('Clean the existing fixture first.');
    }
    $password = bin2hex(random_bytes(16));
    $user = \Flarum\User\User::register('expression-ui', 'expression-ui@example.test', $password);
    $user->is_email_confirmed = true;
    $user->save();
    $user->groups()->attach(1);
    $state = ['user' => $user->id, 'password' => $password, 'assets' => []];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    if ($action === 'prepare-gif') {
        $images = $container->make(\Local\MediaLibrary\PublicApi\EmojiAssets::class);
        $state['originalProcessing'] = ['gifPolicy' => $images->settings()['gifPolicy']];
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        $images->saveSettings(['gifPolicy' => 'animated']);
        $bytes = file_get_contents(__DIR__.'/../fixtures/animated.gif');
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
        $stream->write($bytes); $stream->rewind();
        $asset = $container->make(\Local\MediaLibrary\Library::class)->upload($user,
            new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'animation-check.gif'), ['emoji']);
        $state['assets'][] = $asset->uuid;
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        $tag = \Flarum\Tags\Tag::where('slug', 'general')->firstOrFail();
        $discussion = $db->transaction(function () use ($container, $user, $tag, $asset) {
            return $container->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(
                new \Flarum\Discussion\Command\StartDiscussion($user, [
                    'attributes' => ['title' => '动态 GIF 验证（临时）',
                        'content' => '行内动画 [memoji='.$asset->uuid."]\n\n[msticker=".$asset->uuid.']'],
                    'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $tag->id]]]],
                ], '127.0.0.1')
            );
        });
        $state['discussion'] = $discussion->id;
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        echo "Disposable GIF fixture prepared; cleanup restores the previous policy.\n";
        exit(0);
    }
    foreach ([[240,240], [480,240], [120,240]] as $index => [$width,$height]) {
        $image = imagecreatetruecolor($width,$height);
        $bg = imagecolorallocate($image, 65 + $index * 45, 100, 180 - $index * 30);
        imagefill($image, 0, 0, $bg);
        $white = imagecolorallocate($image, 245, 247, 255);
        imagerectangle($image, 4, 4, $width-5, $height-5, $white);
        imagestring($image, 5, 12, 12, $width.':'.$height, $white);
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
        $stream->write($bytes); $stream->rewind();
        $upload = new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'layout-'.$index.'.png');
        $asset = $container->make(\Local\MediaLibrary\Library::class)->upload($user,$upload,['emoji','public','排版验证']);
        $state['assets'][] = $asset->uuid;
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    }
    echo "Disposable expression UI fixture prepared.\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) { exit(0); }
    $state = json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
    $user = \Flarum\User\User::whereKey($state['user'])->where('username','expression-ui')->firstOrFail();
    if (isset($state['originalDisplay'])) {
        $container->make(\Local\ExpressionSystem\DisplaySettings::class)->save($state['originalDisplay']);
    }
    if (isset($state['originalProcessing'])) {
        $container->make(\Local\MediaLibrary\PublicApi\EmojiAssets::class)->saveSettings($state['originalProcessing']);
    }
    $discussionIds = $db->table('discussions')->where('user_id',$user->id)->pluck('id');
    if ($db->table('posts')->whereIn('discussion_id',$discussionIds)->where('user_id','<>',$user->id)->exists()) {
        throw new RuntimeException('Another user has replied; manual review is required.');
    }
    $bus = $container->make(\Illuminate\Contracts\Bus\Dispatcher::class);
    $db->transaction(function () use ($discussionIds, $bus, $user) {
        foreach ($discussionIds as $discussionId) {
            $bus->dispatch(new \Flarum\Discussion\Command\DeleteDiscussion($discussionId, $user));
        }
    });
    $assets = \Local\MediaLibrary\Asset::where('user_id',$user->id)->get();
    foreach ($assets as $asset) {
        if ($asset->posts()->exists()) { throw new RuntimeException('Fixture asset has outside references.'); }
        $db->table('local_expression_media')->where('asset_uuid',$asset->uuid)->delete();
        $container->make(\Local\MediaLibrary\PrivateStorage::class)->remove($asset->uuid);
        $asset->delete();
    }
    foreach (['local_expression_favorites','local_expression_texts','access_tokens','group_user','discussion_user'] as $table) {
        $db->table($table)->where('user_id',$user->id)->delete();
    }
    $db->table('users')->where('id',$user->id)->delete();
    unlink($statePath);
    echo "Disposable expression UI fixture removed.\n";
} else { throw new RuntimeException('Use prepare, prepare-gif or cleanup.'); }
