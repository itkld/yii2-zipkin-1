<?php

namespace Lxj\Yii2\Zipkin;

use Psr\Http\Message\RequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Component\HttpFoundation\Request;
use yii\console\Application;
use Zipkin\Endpoint;
use Zipkin\Propagation\DefaultSamplingFlags;
use Zipkin\Propagation\RequestHeaders;
use Zipkin\Propagation\TraceContext;
use Zipkin\Reporters\Http;
use Zipkin\Samplers\BinarySampler;
use Zipkin\Span;
use const Zipkin\Tags\ERROR;
use Zipkin\Tracing;
use Zipkin\TracingBuilder;

/**
 * Class Tracer
 * @package Lxj\Yii2\Zipkin
 */
class Tracer extends \yii\base\Component
{
    const HTTP_REQUEST_BODY = 'http.request.body';
    const HTTP_REQUEST_HEADERS = 'http.request.headers';
    const HTTP_REQUEST_PROTOCOL_VERSION = 'http.request.protocol.version';
    const HTTP_REQUEST_SCHEME = 'http.request.scheme';
    const HTTP_RESPONSE_BODY = 'http.response.body';
    const HTTP_RESPONSE_HEADERS = 'http.response.headers';
    const HTTP_RESPONSE_PROTOCOL_VERSION = 'http.response.protocol.version';
    const RUNTIME_START_SYSTEM_LOAD = 'runtime.start_system_load';
    const RUNTIME_FINISH_SYSTEM_LOAD = 'runtime.finish_system_load';
    const RUNTIME_MEMORY = 'runtime.memory';
    const RUNTIME_PHP_VERSION = 'runtime.php.version';
    const DB_QUERY_TIMES = 'db.query.times';
    const DB_QUERY_TOTAL_DURATION = 'db.query.total.duration';
    const FRAMEWORK_VERSION = 'framework.version';

    public $serviceName = 'Yii2';
    public $endpointUrl = 'http://localhost:9411/api/v2/spans';
    public $sampleRate = 0;

    /** @var \Zipkin\Tracer */
    private $tracer;

    /** @var Tracing */
    private $tracing;

    /** @var TraceContext */
    public $rootContext;

    /**
     * Tracer constructor.
     * @param array $config
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->createTracer();
    }

    /**
     * Create zipkin tracer
     */
    private function createTracer()
    {
        $endpoint = Endpoint::createFromGlobals()->withServiceName($this->serviceName);
        $sampler = BinarySampler::createAsAlwaysSample();
        $reporter = new Http(null, ['endpoint_url' => $this->endpointUrl]);
        $tracing = TracingBuilder::create()
            ->havingLocalEndpoint($endpoint)
            ->havingSampler($sampler)
            ->havingReporter($reporter)
            ->build();

        $this->tracing = $tracing;
        $this->tracer = $this->getTracing()->getTracer();
    }

    /**
     * @return Tracing
     */
    public function getTracing()
    {
        return $this->tracing;
    }

    /**
     * @return \Zipkin\Tracer
     */
    public function getTracer()
    {
        return $this->tracer;
    }

    /**
     * Create a trace
     *
     * @param string $name
     * @param callable $callback
     * @param null|TraceContext|DefaultSamplingFlags $parentContext
     * @param null|string $kind
     * @param bool $isRoot
     * @param bool $flush
     * @return mixed
     * @throws \Exception
     */
    public function span($name, $callback, $parentContext = null, $kind = null, $isRoot = false, $flush = false)
    {
        if (!$parentContext) {
            $parentContext = $this->getParentContext();
        }

        $span = $this->getSpan($parentContext);
        $span->setName($name);
        if ($kind) {
            $span->setKind($kind);
        }

        $span->start();

        if ($isRoot) {
            $this->rootContext = $span->getContext();
        }

        $startMemory = 0;
        if ($span->getContext()->isSampled()) {
            $startMemory = memory_get_usage();
            $this->beforeSpanTags($span);
        }

        try {
            return call_user_func_array($callback, ['span' => $span]);
        } catch (\Exception $e) {
            if ($span->getContext()->isSampled()) {
                $span->tag(ERROR, $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            }
            throw $e;
        } finally {
            if ($span->getContext()->isSampled()) {
                $span->tag(static::RUNTIME_MEMORY, round((memory_get_usage() - $startMemory) / 1000000, 2) . 'MB');
                $this->afterSpanTags($span);
            }

            $span->finish();

            if ($flush) {
                $this->flushTracer();
            }
        }
    }

    /**
     * Create a root trace
     *
     * @param string $name
     * @param callable $callback
     * @param null|TraceContext|DefaultSamplingFlags $parentContext
     * @param null|string $kind
     * @param bool $flush
     * @return mixed
     * @throws \Exception
     */
    public function rootSpan($name, $callback, $parentContext = null, $kind = null, $flush = false)
    {
        return $this->span($name, $callback, $parentContext, $kind, true, $flush);
    }

    /**
     * Formatting http protocol version
     *
     * @param $protocolVersion
     * @return string
     */
    public function formatHttpProtocolVersion($protocolVersion)
    {
        if (stripos($protocolVersion, 'HTTP/') !== 0) {
            return 'HTTP/' . $protocolVersion;
        }

        return strtoupper($protocolVersion);
    }

    /**
     * Formatting http host
     *
     * @param $httpHost
     * @return string
     */
    public function formatHttpHost($httpHost)
    {
        $pathInfo = parse_url($httpHost);
        $httpHost = $pathInfo['host'];
        if (!empty($pathInfo['port'])) {
            $httpHost .= ':' . $pathInfo['port'];
        }

        return $httpHost;
    }

    /**
     * Formatting http path
     *
     * @param $httpPath
     * @return string
     */
    public function formatHttpPath($httpPath)
    {
        if (strpos($httpPath, '/') !== 0) {
            $httpPath = '/' . $httpPath;
        }

        return $httpPath;
    }

    /**
     * Inject trace context to psr request
     *
     * @param TraceContext $context
     * @param RequestInterface $request
     */
    public function injectContextToRequest($context, &$request)
    {
        $injector = $this->getTracing()->getPropagation()->getInjector(new RequestHeaders());
        $injector($context, $request);
    }

    /**
     * Extract trace context from http psr request
     *
     * @param RequestInterface $request
     * @return TraceContext|DefaultSamplingFlags
     */
    public function extractRequestToContext($request)
    {
        $extractor = $this->getTracing()->getPropagation()->getExtractor(new RequestHeaders());
        return $extractor($request);
    }

    /**
     * @return TraceContext|DefaultSamplingFlags|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getParentContext()
    {
        $parentContext = null;
        if ($this->rootContext) {
            $parentContext = $this->rootContext;
        } else {
            if (!(\Yii::$app instanceof Application)) {
                $yiiRequest = \Yii::$app->getRequest();

                //Convert yii request to symfony request
                $symfonyRequest = new Request(
                    $yiiRequest->getQueryParams(),
                    $yiiRequest->getBodyParams(),
                    [],
                    $yiiRequest->getCookies()->toArray(),
                    $_FILES,
                    $_SERVER,
                    $yiiRequest->getRawBody()
                );

                //Convert symfony request to psr request
                $psrRequest = (new DiactorosFactory())->createRequest($symfonyRequest);

                //Extract trace context from http psr request
                $parentContext = $this->extractRequestToContext($psrRequest);
            }
        }

        return $parentContext;
    }

    /**
     * @param TraceContext|DefaultSamplingFlags $parentContext
     * @return \Zipkin\Span
     */
    public function getSpan($parentContext)
    {
        $tracer = $this->getTracer();

        if (!$parentContext) {
            $span = $tracer->newTrace($this->getDefaultSamplingFlags());
        } else {
            if ($parentContext instanceof TraceContext) {
                $span = $tracer->newChild($parentContext);
            } else {
                if (is_null($parentContext->isSampled())) {
                    $samplingFlags = $this->getDefaultSamplingFlags();
                } else {
                    $samplingFlags = $parentContext;
                }

                $span = $tracer->newTrace($samplingFlags);
            }
        }

        return $span;
    }

    /**
     * @return DefaultSamplingFlags
     */
    private function getDefaultSamplingFlags()
    {
        $sampleRate = $this->sampleRate;
        if ($sampleRate >= 1) {
            $samplingFlags = DefaultSamplingFlags::createAsEmpty(); //Sample config determined by sampler
        } elseif ($sampleRate <= 0) {
            $samplingFlags = DefaultSamplingFlags::createAsNotSampled();
        } else {
            mt_srand(time());
            if (mt_rand() / mt_getrandmax() <= $sampleRate) {
                $samplingFlags = DefaultSamplingFlags::createAsEmpty(); //Sample config determined by sampler
            } else {
                $samplingFlags = DefaultSamplingFlags::createAsNotSampled();
            }
        }

        return $samplingFlags;
    }

    /**
     * @param Span $span
     */
    private function startSysLoadTag($span)
    {
        $startSystemLoad = sys_getloadavg();
        foreach ($startSystemLoad as $k => $v) {
            $startSystemLoad[$k] = round($v, 2);
        }
        $span->tag(static::RUNTIME_START_SYSTEM_LOAD, implode(',', $startSystemLoad));
    }

    /**
     * @param Span $span
     */
    private function finishSysLoadTag($span)
    {
        $finishSystemLoad = sys_getloadavg();
        foreach ($finishSystemLoad as $k => $v) {
            $finishSystemLoad[$k] = round($v, 2);
        }
        $span->tag(static::RUNTIME_FINISH_SYSTEM_LOAD, implode(',', $finishSystemLoad));
    }

    /**
     * @param Span $span
     */
    public function beforeSpanTags($span)
    {
        $span->tag(self::FRAMEWORK_VERSION, 'Yii2-' . \Yii::$app->getVersion());
        $span->tag(self::RUNTIME_PHP_VERSION, PHP_VERSION);

        $this->startSysLoadTag($span);
    }

    /**
     * @param Span $span
     */
    public function afterSpanTags($span)
    {
        $this->finishSysLoadTag($span);
    }

    public function flushTracer()
    {
        try {
            $this->getTracer()->flush();
        } catch (\Exception $e) {
            \Yii::error('Zipkin report error ' . $e->getMessage(), 'zipkin');
        }
    }

    public function __destruct()
    {
        $this->flushTracer();
    }
}