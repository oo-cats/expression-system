<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\Catalog;
use Local\MediaLibrary\PublicApi\EmojiAssets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ImageController implements RequestHandlerInterface
{
    public function __construct(private Catalog $catalog, private EmojiAssets $images) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (string) $request->getQueryParams()['uuid'];
        if ($request->getMethod() === 'POST') {
            $this->images->prepare($actor, $id);
            $data = $this->catalog->resolve($actor, ['image:'.$id])[0];
        } else {
            $data = $this->catalog->updateImage($actor, $id, (array) $request->getParsedBody());
        }
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
