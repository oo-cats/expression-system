<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\Catalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResolveController implements RequestHandlerInterface
{
    public function __construct(private Catalog $catalog) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $keys = $request->getParsedBody()['items'] ?? null;
        if (!is_array($keys)) {
            throw new ValidationException(['items' => '请提供表情标识数组。']);
        }
        return new JsonResponse(['items' => $this->catalog->resolve(RequestUtil::getActor($request), $keys)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
