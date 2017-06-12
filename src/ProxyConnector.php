<?php

namespace Clue\React\HttpProxy;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use RingCentral\Psr7;
use React\Promise;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\FixedUriConnector;

/**
 * A simple Connector that uses an HTTP CONNECT proxy to create plain TCP/IP connections to any destination
 *
 * [you] -> [proxy] -> [destination]
 *
 * This is most frequently used to issue HTTPS requests to your destination.
 * However, this is actually performed on a higher protocol layer and this
 * connector is actually inherently a general-purpose plain TCP/IP connector.
 *
 * Note that HTTP CONNECT proxies often restrict which ports one may connect to.
 * Many (public) proxy servers do in fact limit this to HTTPS (443) only.
 *
 * If you want to establish a TLS connection (such as HTTPS) between you and
 * your destination, you may want to wrap this connector in a SecureConnector
 * instance.
 *
 * Note that communication between the client and the proxy is usually via an
 * unencrypted, plain TCP/IP HTTP connection. Note that this is the most common
 * setup, because you can still establish a TLS connection between you and the
 * destination host as above.
 *
 * If you want to connect to a (rather rare) HTTPS proxy, you may want use its
 * HTTPS port (443) and use a SecureConnector instance to create a secure
 * connection to the proxy.
 *
 * @link https://tools.ietf.org/html/rfc7231#section-4.3.6
 */
class ProxyConnector implements ConnectorInterface
{
    private $connector;
    private $proxyUri;
    private $proxyAuth = '';
    /** @var array */
    private $proxyHeaders;

    /**
     * Instantiate a new ProxyConnector which uses the given $proxyUrl
     *
     * @param string $proxyUrl The proxy URL may or may not contain a scheme and
     *     port definition. The default port will be `80` for HTTP (or `443` for
     *     HTTPS), but many common HTTP proxy servers use custom ports.
     * @param ConnectorInterface $connector In its most simple form, the given
     *     connector will be a \React\Socket\Connector if you want to connect to
     *     a given IP address.
     * @param array $httpHeaders Custom HTTP headers to be sent to the proxy.
     * @throws InvalidArgumentException if the proxy URL is invalid
     */
    public function __construct($proxyUrl, ConnectorInterface $connector, array $httpHeaders = array())
    {
        // support `http+unix://` scheme for Unix domain socket (UDS) paths
        if (preg_match('/^http\+unix:\/\/(.*?@)?(.+?)$/', $proxyUrl, $match)) {
            // rewrite URI to parse authentication from dummy host
            $proxyUrl = 'http://' . $match[1] . 'localhost';

            // connector uses Unix transport scheme and explicit path given
            $connector = new FixedUriConnector(
                'unix://' . $match[2],
                $connector
            );
        }

        if (strpos($proxyUrl, '://') === false) {
            $proxyUrl = 'http://' . $proxyUrl;
        }

        $parts = parse_url($proxyUrl);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || ($parts['scheme'] !== 'http' && $parts['scheme'] !== 'https')) {
            throw new InvalidArgumentException('Invalid proxy URL "' . $proxyUrl . '"');
        }

        // apply default port and TCP/TLS transport for given scheme
        if (!isset($parts['port'])) {
            $parts['port'] = $parts['scheme'] === 'https' ? 443 : 80;
        }
        $parts['scheme'] = $parts['scheme'] === 'https' ? 'tls' : 'tcp';

        $this->connector = $connector;
        $this->proxyUri = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'];

        // prepare Proxy-Authorization header if URI contains username/password
        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->proxyAuth = 'Basic ' . base64_encode(
                rawurldecode($parts['user'] . ':' . (isset($parts['pass']) ? $parts['pass'] : ''))
            );
        }

        $this->proxyHeaders = $httpHeaders;
    }

    public function connect($uri)
    {
        if (strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }

        $parts = parse_url($uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            return Promise\reject(new InvalidArgumentException('Invalid target URI specified'));
        }

        $target = $parts['host'] . ':' . $parts['port'];

        // construct URI to HTTP CONNECT proxy server to connect to
        $proxyUri = $this->proxyUri;

        // append path from URI if given
        if (isset($parts['path'])) {
            $proxyUri .= $parts['path'];
        }

        // parse query args
        $args = array();
        if (isset($parts['query'])) {
            parse_str($parts['query'], $args);
        }

        // append hostname from URI to query string unless explicitly given
        if (!isset($args['hostname'])) {
            $args['hostname'] = trim($parts['host'], '[]');
        }

        // append query string
        $proxyUri .= '?' . http_build_query($args, '', '&');

        // append fragment from URI if given
        if (isset($parts['fragment'])) {
            $proxyUri .= '#' . $parts['fragment'];
        }

        $connecting = $this->connector->connect($proxyUri);

        $deferred = new Deferred(function ($_, $reject) use ($connecting) {
            $reject(new RuntimeException(
                'Connection cancelled while waiting for proxy (ECONNABORTED)',
                defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
            ));

            // either close active connection or cancel pending connection attempt
            $connecting->then(function (ConnectionInterface $stream) {
                $stream->close();
            });
            $connecting->cancel();
        });

        $auth = $this->proxyAuth;
        $headers = $this->proxyHeaders;
        $connecting->then(function (ConnectionInterface $stream) use ($target, $auth, $headers, $deferred) {
            // keep buffering data until headers are complete
            $buffer = '';
            $stream->on('data', $fn = function ($chunk) use (&$buffer, $deferred, $stream, &$fn) {
                $buffer .= $chunk;

                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    // end of headers received => stop buffering
                    $stream->removeListener('data', $fn);
                    $fn = null;

                    // try to parse headers as response message
                    try {
                        $response = Psr7\parse_response(substr($buffer, 0, $pos));
                    } catch (Exception $e) {
                        $deferred->reject(new RuntimeException('Invalid response received from proxy (EBADMSG)', defined('SOCKET_EBADMSG') ? SOCKET_EBADMSG: 71, $e));
                        $stream->close();
                        return;
                    }

                    if ($response->getStatusCode() === 407) {
                        // map status code 407 (Proxy Authentication Required) to EACCES
                        $deferred->reject(new RuntimeException('Proxy denied connection due to invalid authentication ' . $response->getStatusCode() . ' (' . $response->getReasonPhrase() . ') (EACCES)', defined('SOCKET_EACCES') ? SOCKET_EACCES : 13));
                        $stream->close();
                        return;
                    } elseif ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                        // map non-2xx status code to ECONNREFUSED
                        $deferred->reject(new RuntimeException('Proxy refused connection with HTTP error code ' . $response->getStatusCode() . ' (' . $response->getReasonPhrase() . ') (ECONNREFUSED)', defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111));
                        $stream->close();
                        return;
                    }

                    // all okay, resolve with stream instance
                    $deferred->resolve($stream);

                    // emit remaining incoming as data event
                    $buffer = (string)substr($buffer, $pos + 4);
                    if ($buffer !== '') {
                        $stream->emit('data', array($buffer));
                        $buffer = '';
                    }
                    return;
                }

                // stop buffering when 8 KiB have been read
                if (isset($buffer[8192])) {
                    $deferred->reject(new RuntimeException('Proxy must not send more than 8 KiB of headers (EMSGSIZE)', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90));
                    $stream->close();
                }
            });

            $stream->on('error', function (Exception $e) use ($deferred) {
                $deferred->reject(new RuntimeException('Stream error while waiting for response from proxy (EIO)', defined('SOCKET_EIO') ? SOCKET_EIO : 5, $e));
            });

            $stream->on('close', function () use ($deferred) {
                $deferred->reject(new RuntimeException('Connection to proxy lost while waiting for response (ECONNRESET)', defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104));
            });

            $headers['Host'] = $target;
            if ($auth !== '') {
                $headers['Proxy-Authorization'] = $auth;
            }
            $request = new Psr7\Request('CONNECT', $target, $headers);
            $request = $request->withRequestTarget($target);
            $stream->write(Psr7\str($request));
        }, function (Exception $e) use ($deferred) {
            $deferred->reject($e = new RuntimeException(
                'Unable to connect to proxy (ECONNREFUSED)',
                defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111,
                $e
            ));

            // avoid garbage references by replacing all closures in call stack.
            // what a lovely piece of code!
            $r = new \ReflectionProperty('Exception', 'trace');
            $r->setAccessible(true);
            $trace = $r->getValue($e);
            foreach ($trace as &$one) {
                foreach ($one['args'] as &$arg) {
                    if ($arg instanceof \Closure) {
                        $arg = 'Object(' . get_class($arg) . ')';
                    }
                }
            }
            $r->setValue($e, $trace);
        });

        return $deferred->promise();
    }
}
