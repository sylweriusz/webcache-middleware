# webcache-middleware
Static pages cache for Slim3 middleware stack

WebCache will save only GET request with status 200. 

## Install

```sh
$ composer require sylweriusz/webcache-middleware
```

## Usage

Declare middleware
```php
$app->add(new \Slim\Middleware\WebcacheRedis('192.168.1.12:6379'));
```

Cache will try to detect ID of document, first numerical value in url parts. 

Example: http://example.org/article/123456/title.html
after detection ID = 123456

and if it fail to detect it will assume ID = 0


if You want to delete all articles with this ID You should do something like this
```php
$webcache = new \Slim\Middleware\WebcacheRedis('192.168.1.12:6379');
$webcache->delete(123456);
```

Disabling cache inside application route 
```php
\Slim\Middleware\WebcacheRedis::setTtl(0);
```

Change default TTL (in seconds) inside application route 
```php
\Slim\Middleware\WebcacheRedis::setTtl(600);
```

## Smarty plugin

Define parts of html that should be always fresh, no mather what. 
```smarty
<body>
<div class="right-content">
{fresh id="reusable_box"}some html content{/fresh}
</div>
```

You can even declare them empty (as readonly) on another page and hope it will just work.
```smarty
<body>
<div class="right-content">
{fresh id="reusable_box" readonly=1}{/fresh}
</div>
```
