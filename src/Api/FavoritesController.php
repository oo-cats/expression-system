<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\Preferences;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FavoritesController implements RequestHandlerInterface
{
    public function __construct(private Preferences $preferences) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        if ($request->getMethod() === 'GET') {
            $data = $this->preferences->favorites($actor, $params);
        } elseif (!isset($params['kind'])) {
            $data = ['removed' => $this->preferences->pruneFavorites($actor)];
        } else {
            $this->preferences->favorite($actor, (string) $params['kind'], (string) $params['uuid'], $request->getMethod() === 'DELETE');
            $data = ['saved' => true];
        }
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
