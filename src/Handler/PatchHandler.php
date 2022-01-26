<?php

namespace Tusk\Handler;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tusk\Event\UploadComplete;
use Tusk\FileNotFound;
use Tusk\ShouldNotHappen;
use Tusk\Storage\Storage;
use Tusk\Tusk;
use Webmozart\Assert\Assert;
use Swoole\Http\Request as SwooleHttpRequest;

final class PatchHandler
{
    /** @var Storage */
    private $storage;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    public function __construct(Storage $storage, EventDispatcherInterface $eventDispatcher)
    {
        $this->storage = $storage;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response = $response->withHeader('Tus-Resumable', Tusk::TUS_VERSION);

        if ($request->getHeaderLine('Tus-Resumable') !== Tusk::TUS_VERSION) {
            return $response->withStatus(412)->withHeader('Tus-Version', Tusk::TUS_VERSION);
        }

        $id = $request->getAttribute('id');

        if ($id === null) {
            return $response->withStatus(404);
        }

        if ($request->getHeaderLine('Content-Type') !== 'application/offset+octet-stream') {
            return $response->withStatus(415);
        }

        set_error_handler(static function () {
        });
        $body = fopen('php://temp', 'w+');
        restore_error_handler();
        Assert::resource($body);

        $source = $request->getBody()->detach();
        if ($source instanceof SwooleHttpRequest) {
            fwrite($body, $source->getContent());
        } else {
            Assert::resource($source);
            stream_copy_to_stream($source, $body);
        }
        rewind($body);

        $stat = fstat($body);
        Assert::isArray($stat);

        $size = $stat['size'];

        $clientOffset = (int)$request->getHeaderLine('Upload-Offset');

        try {
            $currentOffset = $this->storage->getOffset($id);

            if ($currentOffset !== $clientOffset) {
                return $response->withStatus(409);
            }

            $this->storage->append($id, $body);
            $newOffset = $this->storage->getOffset($id);
            $length = $this->storage->getLength($id);

            if ($length === $newOffset) {
                $metadata = $this->storage->getMetaData($id);
                $headers = [];
                foreach ($request->getHeaders() as $headerName => $headerValues) {
                    $headers[$headerName] = str_starts_with(strtolower(end($values)), 'x-medialib-');
                }
                $this->storage->complete($id);
                $this->eventDispatcher->dispatch(new UploadComplete(
                    $id,
                    $length,
                    $metadata,
                    $headers
                ));
            }
        } catch (FileNotFound $e) {
            return $response->withStatus(404);
        } finally {
            fclose($body);
        }

        if ($newOffset !== ($clientOffset + $size)) {
            throw new ShouldNotHappen("New offset ({$newOffset}) was not equal to the sum of the offset before ({$clientOffset}) and the number of bytes received ({$size})");
        }

        return $response
            ->withHeader('Upload-Offset', (string)$newOffset)
            ->withStatus(204);
    }
}
