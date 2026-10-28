<?php
declare(strict_types=1);
namespace Local\ExpressionSystem\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\ExpressionSystem\Preferences;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TextController implements RequestHandlerInterface
{
    public function __construct(private Preferences $preferences) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->preferences->text(RequestUtil::getActor($request), $request->getQueryParams()['uuid'] ?? null,
            (array) $request->getParsedBody(), $request->getMethod() === 'DELETE');
        return new JsonResponse($data ?? ['removed' => true], $request->getMethod() === 'POST' ? 201 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
