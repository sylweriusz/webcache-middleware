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
$app->add(new \Slim\Middleware\WebcacheRedis('tcp://192.168.1.12:6379/?database=2'));
```

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

Cache will try to detect ID of document, first numerical value in url parts. 




