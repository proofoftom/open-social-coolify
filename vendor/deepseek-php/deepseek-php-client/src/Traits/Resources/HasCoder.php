<?php

namespace DeepseekPhp\Traits\Resources;

use DeepseekPhp\Resources\Coder;

trait HasCoder
{
    /**
     * Send the accumulated queries to the code resource.
     *
     * @return string
     */
    public function code(): string
    {
        $requestData = [
            'messages' => $this->queries,
            'model' => $this->model,
            'stream' => $this->stream,
        ];
        $this->queries = [];
        return (new Coder($this->httpClient))->sendRequest($requestData);
    }
}
