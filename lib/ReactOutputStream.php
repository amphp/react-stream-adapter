<?php

namespace Amp\ReactStreamAdapter;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputStream;
use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use React\Stream\WritableStreamInterface;

class ReactOutputStream implements OutputStream {
    /** @var WritableStreamInterface */
    private $reactStream;

    /** @var Deferred|null */
    private $backpressure;

    /** @var \Throwable|null */
    private $error;

    public function __construct(WritableStreamInterface $stream) {
        $this->reactStream = $stream;

        if ($this->reactStream->isWritable()) {
            $this->attachHandlers();
        } else {
            $this->error = new ClosedException("The stream is no longer writable");
        }
    }

    private function attachHandlers() {
        $this->reactStream->on("error", function (\Throwable $error) use (&$deferred) {
            $this->error = $error;
        });

        $this->reactStream->on("drain", function () {
            if ($this->backpressure) {
                $backpressure = $this->backpressure;
                $this->backpressure = null;
                $backpressure->resolve();
            }
        });
    }

    /** @inheritdoc */
    public function write(string $data): Promise {
        if ($this->error) {
            return new Failure($this->error);
        }

        $shouldStop = $this->reactStream->write($data);

        if (!$shouldStop) {
            return new Success;
        }

        // There might be multiple write calls without the backpressure being resolved
        if (!$this->backpressure) {
            $this->backpressure = new Deferred;
        }

        return $this->backpressure->promise();
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): Promise {
        if ($this->error) {
            return new Failure($this->error);
        }

        $deferred = new Deferred;
        $promise = $deferred->promise();

        $this->reactStream->on("close", function () use ($deferred) {
            if ($this->error) {
                $deferred->fail($this->error);
            } else {
                $deferred->resolve();
            }
        });

        $this->reactStream->end($finalData);

        return $promise;
    }
}