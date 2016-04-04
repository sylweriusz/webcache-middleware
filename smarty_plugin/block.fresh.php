<?php

/**
 * Smarty block for caching independently html parts
 *
 *
 * @param                          $params
 * @param                          $text
 * @param Smarty_Internal_Template $template
 * @param                          $repeat
 *
 * @return string
 */
function smarty_block_fresh($params, $text, Smarty_Internal_Template $template, &$repeat)
{
    if (!$repeat)
    {
        $id   = strip_tags($params['id']);
        $hash = rtrim(strtr(base64_encode(hash('sha1', $_SERVER['HTTPS'] . $_SERVER['HTTP_HOST'] . $id, true)), '+/', '-_'), '=');
        $n    = \Slim\Middleware\WebcacheRedis::boxMarkers($hash, $params['readonly'] ? "1" : "0");

        return "\n<!-- $id -->\n" . $n[0] . "\n" . $text . "\n" . $n[1];
    }
}