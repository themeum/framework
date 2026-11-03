<?php

namespace Framework\Tests\Support\Http;

use Framework\Http\Client\Pool;
use WpOrg\Requests\Response as RequestsResponse;

/**
 * A Pool test double that records each batch instead of calling request_multiple().
 *
 * Results come from the responder: a callable that receives a Requests payload and
 * returns a Requests response or exception. The default answers 200 with the URL as
 * the body, so each response can be traced back to its request.
 */
class RecordingPool extends Pool
{
    /**
     * The batches that reached the transport seam, in order.
     *
     * @var array<int, array>
     */
    public array $batches = [];

    /**
     * Produces the Requests result for one payload.
     *
     * @var callable|null
     */
    public $responder;

    protected function send_batch(array $requests)
    {
        $this->batches[] = $requests;

        $responder = $this->responder ?? function (array $request) {
            return new RequestsResponse(200, $request['url']);
        };

        return array_map($responder, $requests);
    }
}
