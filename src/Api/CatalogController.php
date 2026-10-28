<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\Catalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CatalogController implements RequestHandlerInterface
{
    public function __construct(private Catalog $catalog) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $data = array_key_exists('shortcode', $params)
            ? ['items' => $this->catalog->shortcuts($actor, (string) $params['shortcode']), 'next' => null]
            : $this->catalog->page($actor, $params);
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
