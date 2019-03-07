<?php

namespace Lxj\Yii2\Zipkin;

use GuzzleHttp\Client as GuzzleHttpClient;
use Psr\Http\Message\RequestInterface;
use Zipkin\Span;
use const Zipkin\Tags\HTTP_HOST;
use const Zipkin\Tags\HTTP_METHOD;
use const Zipkin\Tags\HTTP_PATH;
use const Zipkin\Tags\HTTP_STATUS_CODE;

/**
 * Class HttpClient
 * @package Lxj\Yii2\Zipkin
 */
class HttpClient extends GuzzleHttpClient
{
    /**
     * Send http request with zipkin trace
     *
     * @param RequestInterface $request
     * @param array $options
     * @param string $spanName
     * @return mixed|\Psr\Http\Message\ResponseInterface|null
     * @throws \Exception
     */
    public function send(RequestInterface $request, array $options = [], $spanName = null)
    {
        /** @var Tracer $yiiTracer */
        $yiiTracer = \Yii::$app->zipkin;
        $path = $request->getUri()->getPath();

        return $yiiTracer->span(
            isset($spanName) ? $spanName : $yiiTracer->formatRoutePath($path),
            function (Span $span) use ($request, $options, $yiiTracer, $path) {
                //Inject trace context to api psr request
                $yiiTracer->injectContextToRequest($span->getContext(), $request);

                if ($span->getContext()->isSampled()) {
                    $yiiTracer->addTag($span, HTTP_HOST, $request->getUri()->getHost());
                    $yiiTracer->addTag($span, HTTP_PATH, $path);
                    $yiiTracer->addTag($span, Tracer::HTTP_QUERY_STRING, (string)$request->getUri()->getQuery());
                    $yiiTracer->addTag($span, HTTP_METHOD, $request->getMethod());
                    $httpRequestBodyLen = $request->getBody()->getSize();
                    $yiiTracer->addTag($span, Tracer::HTTP_REQUEST_BODY_SIZE, $httpRequestBodyLen);
                    $yiiTracer->addTag($span, Tracer::HTTP_REQUEST_BODY, $yiiTracer->formatHttpBody($request->getBody()->getContents(), $httpRequestBodyLen));
                    $request->getBody()->seek(0);
                    $yiiTracer->addTag($span, Tracer::HTTP_REQUEST_HEADERS, json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE));
                    $yiiTracer->addTag(
                        $span,
                        Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                        $yiiTracer->formatHttpProtocolVersion($request->getProtocolVersion())
                    );
                    $yiiTracer->addTag($span, Tracer::HTTP_REQUEST_SCHEME, $request->getUri()->getScheme());
                }

                $response = null;
                try {
                    $response = parent::send($request, $options);
                    return $response;
                } catch (\Exception $e) {
                    \Yii::error('CURL ERROR ' . $e->getMessage(), 'zipkin');
                    throw new \Exception('CURL ERROR ' . $e->getMessage());
                } finally {
                    if ($response) {
                        if ($span->getContext()->isSampled()) {
                            $yiiTracer->addTag($span, HTTP_STATUS_CODE, $response->getStatusCode());
                            $httpResponseBodyLen = $response->getBody()->getSize();
                            $yiiTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY_SIZE, $httpResponseBodyLen);
                            $yiiTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY, $yiiTracer->formatHttpBody($response->getBody()->getContents(), $httpResponseBodyLen));
                            $response->getBody()->seek(0);
                            $yiiTracer->addTag($span, Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->getHeaders(), JSON_UNESCAPED_UNICODE));
                            $yiiTracer->addTag(
                                $span,
                                Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                                $yiiTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                            );
                        }
                    }
                }
            }, null, \Zipkin\Kind\CLIENT);
    }
}
