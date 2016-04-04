<?php

namespace Slim\Middleware;


class WebcacheRedis
{

    private $redis = false;
    private $connected = false;
    private static $boxname = '';
    private static $maxttl = 86400;
    private static $minttl = 60;

    public function __invoke($request, $response, $next)
    {
        $old_response = $response;
        if (!$response = $this->show_from_cache($request, $response))
        {
            $response = $next($request, $old_response);

            $response = $this->save($request, $response);
        }

        return $response;
    }

    public function __construct($server = 'tcp://127.0.0.1:6379', $boxname = "BOX")
    {
        self::$boxname = $boxname;
        try
        {
            $this->redis = new \Predis\Client($server);
            $this->redis->ping();
            $this->connected = true;
        } catch (Exception $e)
        {
            $this->connected = false;
        }
    }

    public function delete($id)
    {

        if ($this->connected)
        {
            $key_ar = 'www:' . $id . ':*';
            $keys   = $this->redis->keys($key_ar);
            if (is_array($keys) && count($keys))
            {
                foreach ($keys as $key)
                {
                    //expire after 10 seconds
                    $this->redis->expire($key, 10);
                }
            }
        }
    }

    public static function set_ttl($ttl, $minttl = 60)
    {
        self::$maxttl = $ttl;
        self::$minttl = $minttl;
    }

    public static function box_markers($id, $ro = 0)
    {
        $n[0] = "<!-- BEGIN " . self::$boxname . " $id $ro -->";
        $n[1] = "<!-- END " . self::$boxname . " $id $ro -->";

        return $n;
    }

    private function save($request, $response)
    {
        $content = $response->getBody()->__toString();

        if (self::$maxttl //set $maxttl to 0 if You need disable cache for this request
            && $response->getStatusCode() == 200 //dont save error pages
            && $request->isGet() //dont save anything other then get requests
            && !$request->isXhr() //dont save ajax requests
            && $_SERVER['HTTP_X_API'] <> 'on' //example for other excludes
        )
        {
            $parts = $this->list_html_box_parts($content);
            if (count($parts) && is_array($parts))
            {
                $this->save_parts($parts, $content);
            }
            $key        = $this->cache_key($request);
            $compressed = gzcompress(json_encode([
                'page' => $this->url_string($request),
                'time' => time(),
                'html' => $content
            ]), 9);
            $this->redis->setex($key, self::$maxttl, $compressed);
        }

        $content = $this->insert_parts($content, 0);
        $content = $this->insert_parts($content, 1);

        $newStream = new \GuzzleHttp\Psr7\LazyOpenStream('php://temp', 'r+');
        $response  = $response->withBody($newStream);
        $response->getBody()->write($content);

        return $response;
    }

    private function show_from_cache($request, $response)
    {
        if ($this->connected)
        {
            if ($request->isGet() && !$request->isXhr() && $_SERVER['HTTP_X_API'] <> 'on')
            {
                //ctrl+F5 always refreshes cache
                if ($request->getHeaderLine('HTTP_CACHE_CONTROL') <> 'max-age=0')
                {
                    $key = $this->cache_key($request);
                    if ($body = $this->redis->get($key))
                    {
                        $data = json_decode(gzuncompress($body), true);
                        $html = $data['html'];

                        header("Last-Modified: " . gmdate("D, d M Y H:i:s", $data['time']) . " GMT");
                        if (self::$minttl)
                        {
                            header("Cache-Control: public, max-age=" . self::$minttl);
                            header("Expires: " . gmdate("D, d M Y H:i:s", time() + self::$minttl) . " GMT");
                            header("Pragma: public, max-age=" . self::$minttl);
                        }

                        $html = $this->insert_parts($html, 0);
                        $html = $this->insert_parts($html, 1);

                        $response->getBody()->write($html);

                        return $response;
                    }
                }
            }
        }

        return false;
    }

    private function cache_key($request)
    {
        $url = $this->url_string($request);

        $parts = explode('/', $url);
        $id    = 0;
        if (is_array($parts) && count($parts))
        {
            foreach ($parts as $part)
            {
                if (is_numeric($part) and $part > 0)
                {
                    $id = $part;
                    break;
                }
            }
        }
        $key = "www:" . $id . ":" . rtrim(strtr(base64_encode(hash('sha256', $url, true)), '+/', '-_'), '=');

        return $key;
    }

    private function url_string($request)
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() . ':' . $uri->getPort() . $uri->getPath() . '?' . $uri->getQuery();
    }

    private function cache_part_key($id)
    {
        return "www:parts:$id";
    }


    private function save_parts($parts, $content)
    {
        if (is_array($parts) && count($parts))
        {
            foreach ($parts as $p)
            {
                foreach ($p as $id => $mode)
                {
                    if ($mode == 0)
                    {
                        $p   = $this->get_part($id, $content);
                        $key = $this->cache_part_key($id);
                        $this->redis->set($key, gzcompress($p, 9));
                    }
                }
            }
        }

    }

    private function get_part($id, $content, $mode = 0)
    {
        $n = $this->box_markers($id, $mode);

        return $this->get_between($content, $n[0], $n[1]);
    }


    private function replace_between($str, $needle_start, $needle_end, $replacement)
    {
        $pos   = strpos($str, $needle_start);
        $start = $pos === false ? 0 : $pos + strlen($needle_start);

        $pos = strpos($str, $needle_end, $start);
        $end = $pos === false ? strlen($str) : $pos;

        return substr_replace($str, $replacement, $start, $end - $start);
    }

    private function get_between($str, $needle_start, $needle_end)
    {
        $pos   = strpos($str, $needle_start);
        $start = $pos === false ? 0 : $pos + strlen($needle_start);

        $pos = strpos($str, $needle_end, $start);
        $end = $pos === false ? strlen($str) : $pos;

        return substr($str, $start, $end - $start);
    }

    private function insert_parts($html, $only_ro = 0)
    {
        $parts_list = $this->list_html_box_parts($html, $only_ro);

        if (count($parts_list) && is_array($parts_list))
        {
            foreach ($parts_list as $p)
            {
                foreach ($p as $id => $mode)
                {
                    $key = $this->cache_part_key($id);
                    if ($part = $this->redis->get($key))
                    {
                        $n    = $this->box_markers($id, $mode);
                        $html = $this->replace_between($html, $n[0], $n[1], gzuncompress($part));
                    }
                }
            }
        }

        return $html;
    }


    private function list_html_box_parts($content = '', $only_ro = 0)
    {
        $ids = [];
        preg_match_all('/<!-- BEGIN ' . self::$boxname . '(.|\s)*?-->/', $content, $list, PREG_SET_ORDER);


        if (count($list) && is_array($list))
        {
            foreach ($list as $item)
            {
                $parts = explode(" ", $item[0]);
                if (!$only_ro || trim($parts[4]) == '1')
                {
                    $ids[] = [trim($parts[3]) => trim($parts[4])];
                }
            }
        }

        return $ids;
    }
}