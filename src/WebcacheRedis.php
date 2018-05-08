<?php

namespace Slim\Middleware;


class WebcacheRedis
{

    public $redis = false;
    public $connected = false;
    public static $boxname = '';
    public static $maxttl = 3600;
    public static $minttl = 60;
    public $redisArray = false;
    public $tryonce = false;
    public $artid = false;
    public $ver = 3;

    public function __invoke($request, $response, $next)
    {
        $oldResponse = $response;
        if (!$response = $this->getPageFromCache($request, $response))
        {
            $response = $next($request, $oldResponse);

            $response = $this->save($request, $response);
        }

        return $response;
    }

    public function __construct($server = '127.0.0.1:6379', $boxname = "BOX", $slimContainer = false)
    {
        self::$boxname = $boxname;

        $this->server = $server;
        $this->connect();

        if ($slimContainer)
        {
            if ($response = $this->getPageFromCache($slimContainer->request, $slimContainer->response))
            {
                echo $response->getBody()->__toString();
                exit;
            }
        }

    }

    private function connect()
    {
        if (\is_array($this->server) && \count($this->server))
        {
            $this->redis = new \RedisArray($this->server, ['lazy_connect' => true, 'connect_timeout' => 0.5, 'read_timeout' => 0.5]);
            $this->connected = true;
            $this->redisArray = true;
        }
        else
        {
            $this->redis = new \Redis();
            $this->connected = $this->redis->connect($this->server, 6379, 0.5);
        }
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        $this->redis->select(2);
    }

    public function delete_all()
    {
        if ($this->connected)
        {
            $this->redis->flushdb();
        }
    }

    public function delete($artId)
    {
        if ($this->connected)
        {
            $cacheKey = 'www:' . $artId . ':*';
            if ($this->redisArray)
            {
                foreach ($this->redis->_hosts() as $host){
                    $keys = $this->redis->_instance($host)->keys($cacheKey);
                    if (\is_array($keys) && \count($keys))
                    {
                        foreach ($keys as $key)
                        {
                            //expire after 10 seconds
                            $this->redis->_instance($host)->expire($key, 10);
                        }
                    }
                }
            }
            else
            {
                $keys = $this->redis->keys($cacheKey);
                if (\is_array($keys) && \count($keys))
                {
                    foreach ($keys as $key)
                    {
                        //expire after 10 seconds
                        $this->redis->expire($key, 10);
                    }
                }
            }
        }
    }

    public static function setTtl($maxttl, $minttl = 60)
    {
        self::$maxttl = $maxttl;
        self::$minttl = $minttl;
    }

    public static function boxMarkers($boxId, $readOnly = 0)
    {
        $n[0] = "<!-- BEGIN " . self::$boxname . " $boxId $readOnly -->";
        $n[1] = "<!-- END " . self::$boxname . " $boxId $readOnly -->";

        return $n;
    }

    private function save($request, $response)
    {
        $content = $response->getBody()->__toString();

        if (self::$maxttl > 0 //set $maxttl to 0 if You need disable cache for this request
            && $response->getStatusCode() == 200 //dont save error pages
            && $request->isGet() //dont save anything other then get requests
            && !$request->isXhr() //dont save ajax requests
        )
        {
            $parts = $this->boxParts($content);
            if (count($parts) && is_array($parts))
            {
                $this->saveParts($parts, $content);
            }
            $key = $this->cacheKey($request);
            $compressed = gzcompress(json_encode([
                'page' => $this->urlString($request),
                'time' => time(),
                'html' => $content,
            ]), 9);

            if ($this->artid == 0 && self::$maxttl > 600)
            {
                $ttl = 120;
            }
            else
            {
                $ttl = self::$maxttl;
            }

            $this->redis->setex($key, $ttl, $compressed);
        }

        $content = $this->insertParts($content, 0);
        $content = $this->insertParts($content, 1);

        $newStream = new \GuzzleHttp\Psr7\LazyOpenStream('php://temp', 'r+');
        $response = $response->withBody($newStream);
        $response->getBody()->write($content);

        return $response;
    }

    private function getPageFromCache($request, $response)
    {
        if (!$this->tryonce)
        {
            if ($this->connected)
            {
                $this->tryonce = true;
                if ($request->isGet() && !$request->isXhr())
                {
                    //ctrl+F5 always refreshes cache
                    if ($request->getHeaderLine('Cache-Control') <> 'max-age=0')
                    {
                        $key = $this->cacheKey($request);
                        if ($body = $this->redis->get($key))
                        {
                            $data = json_decode(gzuncompress($body), true);
                            $html = $data['html'];

                            header("X-From-Cache: " . gmdate("D, d M Y H:i:s", $data['time']) . " GMT");

                            $html = $this->insertParts($html, 0);
                            $html = $this->insertParts($html, 1);

                            $response->getBody()->write($html);

                            return $response;
                        }
                    }
                    else
                    {
                        header("X-From-Cache: refresh");
                    }
                }
            }
        }

        return false;
    }

    private function cacheKey($request)
    {
        $url = $this->urlString($request);

        $parts = explode('/', $url);
        $this->artid = 0;
        if (is_array($parts) && count($parts))
        {
            foreach ($parts as $part)
            {
                if (is_numeric($part) and $part > 0)
                {
                    $this->artid = $part;
                    break;
                }
            }
        }

        return "www:" . $this->artid . ":" . hash('tiger192,3', $url);
    }

    private function urlString($request)
    {
        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getHost() . ':' . $uri->getPort() . $uri->getPath() . '?' . $uri->getQuery();
    }

    private function cache_part_key($partId)
    {
        return "www:parts:$partId";
    }

    private function saveParts($parts, $content)
    {
        if (is_array($parts) && count($parts))
        {
            foreach ($parts as $p)
            {
                foreach ($p as $partId => $mode)
                {
                    if ($mode == 0)
                    {
                        $p = $this->getPart($partId, $content);
                        $key = $this->cache_part_key($partId);
                        $this->redis->set($key, gzcompress($p, 9));
                    }
                }
            }
        }

    }

    private function getPart($partId, $content, $mode = 0)
    {
        $n = $this->boxMarkers($partId, $mode);

        return $this->getBetween($content, $n[0], $n[1]);
    }

    private function replaceBetween($str, $stringStart, $stringEnd, $replacement)
    {
        $pos = strpos($str, $stringStart);
        $start = $pos === false ? 0 : $pos + strlen($stringStart);

        $pos = strpos($str, $stringEnd, $start);
        $end = $pos === false ? strlen($str) : $pos;

        return substr_replace($str, $replacement, $start, $end - $start);
    }

    private function getBetween($str, $stringStart, $stringEnd)
    {
        $pos = strpos($str, $stringStart);
        $start = $pos === false ? 0 : $pos + strlen($stringStart);

        $pos = strpos($str, $stringEnd, $start);
        $end = $pos === false ? strlen($str) : $pos;

        return substr($str, $start, $end - $start);
    }

    private function insertParts($html, $onlyReadOnly = 0)
    {
        $partsList = $this->boxParts($html, $onlyReadOnly);

        if (count($partsList) && is_array($partsList))
        {
            foreach ($partsList as $p)
            {
                foreach ($p as $idKey => $mode)
                {
                    $key = $this->cache_part_key($idKey);
                    if ($part = $this->redis->get($key))
                    {
                        $n = $this->boxMarkers($idKey, $mode);
                        $html = $this->replaceBetween($html, $n[0], $n[1], gzuncompress($part));
                    }
                }
            }
        }

        return $html;
    }

    private function boxParts($content = '', $onlyReadOnly = 0)
    {
        $cacheIds = [];
        preg_match_all('/<!-- BEGIN ' . self::$boxname . '(.|\s)*?-->/', $content, $list, PREG_SET_ORDER);


        if (count($list) && is_array($list))
        {
            foreach ($list as $item)
            {
                $parts = explode(" ", $item[0]);
                if (!$onlyReadOnly || trim($parts[4]) == '1')
                {
                    $cacheIds[] = [trim($parts[3]) => trim($parts[4])];
                }
            }
        }

        return $cacheIds;
    }
}

