<?php

use LeProxy\LeProxy\HttpProxyServer;
use React\Http\ServerRequest;
use React\Http\HttpBodyStream;
use React\Stream\ThroughStream;

class HttpProxyServerTest extends PHPUnit_Framework_TestCase
{
    public function testCtor()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
    }

    public function testRequestWithoutAuthenticationReturnsAuthenticationRequired()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->setAuthArray(array('user' => 'pass'));

        $request = new ServerRequest('GET', '/');

        $response = $server->handleRequest($request);

        $this->assertEquals(407, $response->getStatusCode());
    }

    public function testRequestWithInvalidAuthenticationReturnsAuthenticationRequired()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->setAuthArray(array('user' => 'pass'));

        $request = new ServerRequest('GET', '/', array('Proxy-Authorization' => 'Basic dXNlcg=='));

        $response = $server->handleRequest($request);

        $this->assertEquals(407, $response->getStatusCode());
    }

    public function testRequestWithValidAuthenticationReturnsSuccess()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->setAuthArray(array('user' => 'pass'));

        $request = new ServerRequest('GET', '/', array('Proxy-Authorization' => 'Basic dXNlcjpwYXNz'));

        $response = $server->handleRequest($request);

        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testRequestProtectedLocalhostReturnsSuccess()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->allowUnprotected = false;

        $request = new ServerRequest('GET', '/', array(), null, '1.1', array('REMOTE_ADDR' => '127.0.0.1'));

        $response = $server->handleRequest($request);

        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testRequestProtectedRemoteReturnsForbidden()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->allowUnprotected = false;

        $request = new ServerRequest('GET', '/', array(), null, '1.1', array('REMOTE_ADDR' => '192.168.1.1'));

        $response = $server->handleRequest($request);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testRequestUnprotectedRemoteReturnsSuccess()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $server = new HttpProxyServer($loop, $socket, $connector);
        $server->allowUnprotected = true;

        $request = new ServerRequest('GET', '/', array(), null, '1.1', array('REMOTE_ADDR' => '192.168.1.1'));

        $response = $server->handleRequest($request);

        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testPlainRequestForwardsWithExplicitHeadersAsGiven()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $outgoing = $this->getMockBuilder('React\HttpClient\Request')->disableOriginalConstructor()->getMock();

        $client = $this->getMockBuilder('React\HttpClient\Client')->disableOriginalConstructor()->getMock();
        $client->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/', array('Cookie' => array('name=value'), 'USER-AGENT' => array('TEST')))
            ->willReturn($outgoing);

        $server = new HttpProxyServer($loop, $socket, $connector, $client);

        $request = new ServerRequest('GET', 'http://example.com/', array('Cookie' => 'name=value', 'USER-AGENT' => 'TEST'));
        $request = $request->withRequestTarget((string)$request->getUri());
        $request = $request->withBody(new HttpBodyStream(new ThroughStream(), null));

        $promise = $server->handleRequest($request);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testPlainRequestWithValidAuthenticationForwardsViaHttpClientWithoutAuthorizationHeader()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $outgoing = $this->getMockBuilder('React\HttpClient\Request')->disableOriginalConstructor()->getMock();

        $client = $this->getMockBuilder('React\HttpClient\Client')->disableOriginalConstructor()->getMock();
        $client->expects($this->once())
               ->method('request')
               ->with('GET', 'http://example.com/', array('Cookie' => array('name=value'), 'User-Agent' => array()))
               ->willReturn($outgoing);

        $server = new HttpProxyServer($loop, $socket, $connector, $client);
        $server->setAuthArray(array('user' => 'pass'));

        $request = new ServerRequest('GET', 'http://example.com/', array('Proxy-Authorization' => 'Basic dXNlcjpwYXNz', 'Cookie' => 'name=value'));
        $request = $request->withRequestTarget((string)$request->getUri());
        $request = $request->withBody(new HttpBodyStream(new ThroughStream(), null));

        $promise = $server->handleRequest($request);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }
}
