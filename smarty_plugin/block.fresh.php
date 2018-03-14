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
        $hash = hash('tiger192,3', $_SERVER['HTTPS'] . $_SERVER['HTTP_HOST'] . $id);
        $n    = \Slim\Middleware\WebcacheRedis::boxMarkers($hash, $params['readonly'] ? "1" : "0");

        return "\n<!-- $id -->\n" . $n[0] . "\n" . $text . "\n" . $n[1];
    }
}
