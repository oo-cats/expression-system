<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\DisplaySettings;
use Local\MediaLibrary\PublicApi\EmojiAssets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SettingsController implements RequestHandlerInterface
{
    public function __construct(private DisplaySettings $display, private EmojiAssets $images, private ConnectionInterface $db) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $display = DisplaySettings::validate((array) ($body['display'] ?? []));
            $processing = $this->images->validateSettings((array) ($body['processing'] ?? []));
            $this->db->transaction(function () use ($display, $processing) {
                $this->display->save($display);
                $this->images->saveSettings($processing);
            });
        }
        return new JsonResponse([
            'display' => $this->display->values(), 'displaySchema' => DisplaySettings::FIELDS,
            'processing' => $this->images->settings(), 'processingSchema' => $this->images->settingsSchema(),
            'css' => $this->display->css(),
            'capabilities' => ['animatedGif' => true, 'animatedEncoding' => false,
                'uploadMax' => ini_get('upload_max_filesize'), 'postMax' => ini_get('post_max_size')],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
