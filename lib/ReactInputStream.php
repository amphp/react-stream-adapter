<?php

namespace Amp\ReactStreamAdapter;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Promise;
use React\Stream\ReadableStreamInterface;

class ReactInputStream implements InputStream {
    /** @var ReadableStreamInterface */
    private $reactStream;

    /** @var Emitter|null */
    private $emitter;

    /** @var IteratorStream */
    private $iteratorStream;

    public function __construct(ReadableStreamInterface $stream) {
        $this->reactStream = $stream;
        $this->emitter = new Emitter;
        $this->iteratorStream = new IteratorStream($this->emitter->iterate());

        if ($stream->isReadable()) {
            $this->attachHandlers();
        } else {
            $this->emitter->complete();
        }
    }

    private function attachHandlers() {
        $this->reactStream->on("data", function (string $chunk) {
            $this->reactStream->pause();
            $this->emitter->emit($chunk)->onResolve(function () {
                $this->reactStream->resume();
            });
        });

        $this->reactStream->on("end", function () {
            if ($this->emitter) {
                $emitter = $this->emitter;
                $this->emitter = null;
                $emitter->complete();
            }
        });

        $this->reactStream->on("error", function (\Throwable $error) {
            if ($this->emitter) {
                $emitter = $this->emitter;
                $this->emitter = null;
                $emitter->fail($error);
            }
        });

        // Catches any streams that neither emit "end" nor "error", e.g. by being explicitly closed
        $this->reactStream->on("close", function () {
            if ($this->emitter) {
                $emitter = $this->emitter;
                $this->emitter = null;
                $emitter->complete();
            }
        });
    }

    /** @inheritdoc */
    public function read(): Promise {
        return $this->iteratorStream->read();
    }
}