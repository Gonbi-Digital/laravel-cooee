<?php

namespace GonbiDigital\Cooee\Transport;

interface Transport
{
    /**
     * Delivers one report and says what came back. Must not throw: a transport that fails loudly
     * inside an exception handler turns one error into two.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): Result;
}
