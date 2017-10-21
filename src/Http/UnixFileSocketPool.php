<?php

namespace Docker\Http;

use Amp\CancellationToken;
use Amp\CancelledException;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\ClientConnectContext;
use Amp\Socket\ClientSocket;
use function Amp\Socket\connect;
use Amp\Socket\SocketPool;
use Amp\Struct;
use Amp\Success;
use function Amp\call;

final class UnixFileSocketPool implements SocketPool {
    private $sockets = [];
    private $socketIdUriMap = [];
    private $pendingCount = [];

    private $idleTimeout;
    private $socketContext;
    private $file;

    public function __construct($file, int $idleTimeout = 10000, ClientConnectContext $socketContext = null) {
        $this->idleTimeout = $idleTimeout;
        $this->socketContext = $socketContext ?? new ClientConnectContext;
        $this->file = $file;
    }

    /** @inheritdoc */
    public function checkout(string $uri, CancellationToken $token = null): Promise {
        // A request might already be cancelled before we reach the checkout, so do not even attempt to checkout in that
        // case. The weird logic is required to throw the token's exception instead of creating a new one.
        if ($token && $token->isRequested()) {
            try {
                $token->throwIfRequested();
            } catch (CancelledException $e) {
                return new Failure($e);
            }
        }

        if (empty($this->sockets[$uri])) {
            return $this->checkoutNewSocket($token);
        }

        foreach ($this->sockets[$uri] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            } elseif (!\is_resource($socket->resource) || \feof($socket->resource)) {
                $this->clear(new ClientSocket($socket->resource));
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success($socket->resource);
        }

        return $this->checkoutNewSocket($token);
    }

    private function checkoutNewSocket(CancellationToken $token = null): Promise {
        return call(function () use ($token) {
            $uri = 'unix:///' . $this->file;

            if (!file_exists($this->file)) {
                throw new \Exception(sprintf('The socket file %s does not exist', $this->file));
            }

            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            try {
                /** @var ClientSocket $rawSocket */
                $rawSocket = yield connect($uri, $this->socketContext, $token);
            } finally {
                if (--$this->pendingCount[$uri] === 0) {
                    unset($this->pendingCount[$uri]);
                }
            }

            $socketId = (int) $rawSocket->getResource();

            var_dump($rawSocket->getLocalAddress());
            var_dump($rawSocket->getRemoteAddress());

            $socket = new class {
                use Struct;

                public $id;
                public $uri;
                public $resource;
                public $isAvailable;
                public $idleWatcher;
            };

            $socket->id = $socketId;
            $socket->uri = $uri;
            $socket->resource = $rawSocket;
            $socket->isAvailable = false;

            $this->sockets[$uri][$socketId] = $socket;
            $this->socketIdUriMap[$socketId] = $uri;

            return $rawSocket;
        });
    }

    /** @inheritdoc */
    public function clear(ClientSocket $socket) {
        $socketId = (int) $socket->getResource();

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                sprintf('Unknown socket: %d', $socketId)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];
        $socket = $this->sockets[$uri][$socketId];

        if ($socket->idleWatcher) {
            Loop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$uri][$socketId],
            $this->socketIdUriMap[$socketId]
        );

        if (empty($this->sockets[$uri])) {
            unset($this->sockets[$uri]);
        }
    }

    /** @inheritdoc */
    public function checkin(ClientSocket $socket) {
        $socketId = (int) $socket->getResource();

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];

        if (!\is_resource($socket->getResource()) || \feof($socket->getResource())) {
            $this->clear($socket);
            return;
        }

        $socket = $this->sockets[$uri][$socketId];
        $socket->isAvailable = true;

        if (isset($socket->idleWatcher)) {
            Loop::enable($socket->idleWatcher);
        } else {
            $socket->idleWatcher = Loop::delay($this->idleTimeout, function () use ($socket) {
                $this->clear($socket->resource);
            });

            Loop::unreference($socket->idleWatcher);
        }
    }
}
