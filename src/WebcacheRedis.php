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
    public static $ver = 3;

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
            $this->redis = new \RedisArray($this->server, [
                'lazy_connect'    => true,
                'retry_timeout'   => 100,
                'read_timeout'    => 1,
                'connect_timeout' => 1,
            ]);

            $this->connected = true;
            $this->redisArray = true;
        }
        else
        {
            $this->redis = new \Redis();
            $this->connected = $this->redis->connect($this->server, 6379, 1, null, 100);
        }
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
            if ($artId === 0){
                $this->redis->del('www:0:0', 'www:0:1', 'www:0:2', 'www:0:3', 'www:0:4', 'www:0:5', 'www:0:6', 'www:0:7', 
                    'www:0:8', 'www:0:9', 'www:0:a', 'www:0:b', 'www:0:c', 'www:0:d', 'www:0:e', 'www:0:f');
            }
            $this->redis->del("www:" . $artId);
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

            if ($this->artid===0){
                $partition = ':' . substr(md5($key),0,1);
            }

            $this->redis->hSet("www:" . $this->artid . $partition, $key, $compressed);
            $this->redis->expire("www:" . $this->artid . $partition, self::$maxttl);
            header("X-Save-To-RI: " . gmdate("D, d M Y H:i:s", $data['time']) . " GMT");
            header("cache-control: max-age=360, public, stale-while-revalidate=59, stale-if-error=7200");
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
                        if ($this->artid===0){
                            $partition = ':' . substr(md5($key),0,1);
                        }
                        if ($body = $this->redis->hGet("www:" . $this->artid . $partition, $key))
                        {
                            $data = json_decode(gzuncompress($body), true);

                            if (($data['time']+self::$maxttl)<time())
                            {
                                $this->redis->hDel("www:" . $this->artid . $partition, $key);
                                header("X-From-Cache: to old");
                                return false;
                            }

                            $html = $data['html'];

                            header("X-From-RI: " . gmdate("D, d M Y H:i:s", $data['time']) . " GMT");
                            header("cache-control: max-age=300, public, stale-while-revalidate=59, stale-if-error=7200");

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

        return hash('tiger192,3', $url);
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
